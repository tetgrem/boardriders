<?php

namespace App\Services\Pricing;

use DomainException;
use InvalidArgumentException;
use JsonException;

/**
 * Канонічна підготовка фінансової частини замовлення із server-side quote.
 *
 * Не зберігає дані, не створює order і не резервує нічого.
 * Фіксація в append-only таблиці буде окремим етапом.
 */
final class CheckoutFinancialSnapshotBuilder
{
    /**
     * @param array<string,mixed> $quote
     * @return array{snapshot:array<string,mixed>,sha256:string}
     */
    public function build(array $quote): array
    {
        if (($quote['informational_only'] ?? null) !== true) {
            throw new InvalidArgumentException(
                'Expected a server-side informational checkout quote'
            );
        }

        $market = $this->text($quote, 'market_code');
        $priceList = $this->text($quote, 'price_list_code');
        $currency = $this->text($quote, 'currency_code');

        $subtotal = $this->amount($quote, 'subtotal_minor');
        $promotionDiscount = $this->amount(
            $quote,
            'promotion_discount_minor'
        );
        $loyaltyDiscount = $this->amount(
            $quote,
            'loyalty_discount_minor'
        );
        $payable = $this->amount($quote, 'goods_payable_minor');

        $eligibleAfterPromotion = $this->amount(
            $quote,
            'eligible_goods_after_promotions_minor'
        );

        $sourceLines = $quote['lines'] ?? null;
        $revisions = $quote['price_revisions'] ?? null;

        if (
            !is_array($sourceLines)
            || $sourceLines === []
            || count($sourceLines) > 200
            || !array_is_list($sourceLines)
            || !is_array($revisions)
        ) {
            throw new InvalidArgumentException(
                'Invalid quote lines or price revisions'
            );
        }

        $lines = [];
        $seen = [];

        $sumSubtotal = 0;
        $sumPromotion = 0;
        $sumLoyalty = 0;
        $sumPayable = 0;
        $sumEligible = 0;

        foreach ($sourceLines as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException(
                    'Invalid checkout line'
                );
            }

            $id = $this->positive($line, 'variant_id');
            $quantity = $this->positive($line, 'quantity');
            $unitPrice = $this->amount($line, 'unit_price_minor');
            $lineSubtotal = $this->amount($line, 'subtotal_minor');

            $linePromo = $this->amount(
                $line,
                'promotion_discount_minor'
            );

            $lineLoyalty = $this->amount(
                $line,
                'loyalty_discount_minor'
            );

            $linePayable = $this->amount(
                $line,
                'goods_payable_minor'
            );

            $eligible = $line['loyalty_eligible'] ?? null;

            if (
                isset($seen[$id])
                || $quantity > 99
                || !is_bool($eligible)
                || $unitPrice > intdiv(PHP_INT_MAX, $quantity)
                || $unitPrice * $quantity !== $lineSubtotal
                || $linePromo > $lineSubtotal
                || $lineLoyalty > $lineSubtotal - $linePromo
                || $linePayable !== $lineSubtotal - $linePromo - $lineLoyalty
                || (!$eligible && $lineLoyalty > 0)
            ) {
                throw new DomainException(
                    'Inconsistent financial checkout line'
                );
            }

            $revision = $revisions[$id] ?? null;

            if (!is_int($revision) || $revision < 0) {
                throw new InvalidArgumentException(
                    'Missing or invalid price revision'
                );
            }

            $seen[$id] = true;

            // Окремий розподіл знижок по кожній
            // одиниці товару для часткових повернень.
            $units = $this->allocateUnits(
                $quantity,
                $unitPrice,
                $linePromo,
                $lineLoyalty
            );

            $lines[] = [
                'variant_id' => $id,
                'quantity' => $quantity,
                'unit_price_minor' => $unitPrice,
                'price_revision' => $revision,
                'subtotal_minor' => $lineSubtotal,
                'promotion_discount_minor' => $linePromo,
                'loyalty_discount_minor' => $lineLoyalty,
                'goods_payable_minor' => $linePayable,
                'loyalty_eligible' => $eligible,
                'units' => $units,
            ];

            $sumSubtotal = $this->add(
                $sumSubtotal,
                $lineSubtotal
            );

            $sumPromotion = $this->add(
                $sumPromotion,
                $linePromo
            );

            $sumLoyalty = $this->add(
                $sumLoyalty,
                $lineLoyalty
            );

            $sumPayable = $this->add(
                $sumPayable,
                $linePayable
            );

            if ($eligible) {
                $sumEligible = $this->add(
                    $sumEligible,
                    $lineSubtotal - $linePromo
                );
            }
        }

        // ========================================
        // 1. ЗВІРКА ВСІХ ФІНАНСОВИХ СУМ
        // ========================================

        if (
            $sumSubtotal !== $subtotal
            || $sumPromotion !== $promotionDiscount
            || $sumLoyalty !== $loyaltyDiscount
            || $sumPayable !== $payable
            || $sumEligible !== $eligibleAfterPromotion
        ) {
            throw new DomainException(
                'Checkout totals do not reconcile'
            );
        }

        // ========================================
        // 2. SNAPSHOT ПРОМОКОДУ
        // ========================================

        $promotionCode = null;

        if ($promotionDiscount > 0) {
            $promotion = $quote['promotion'] ?? null;

            if (
                !is_array($promotion)
                || ($promotion['valid'] ?? null) !== true
                || ($promotion['discount_minor'] ?? null)
                !== $promotionDiscount
            ) {
                throw new DomainException(
                    'Promotion snapshot does not match applied discount'
                );
            }

            $promotionCode = $this->text(
                $promotion,
                'code'
            );
        }

        // ========================================
        // 3. SNAPSHOT БОНУСНОЇ ПОЛІТИКИ
        // ========================================

        $spentUnits = 0;
        $policySnapshot = null;
        $eligibilitySnapshot = null;

        if ($loyaltyDiscount > 0) {
            $loyalty = $quote['loyalty'] ?? null;
            $eligibility = $quote['loyalty_eligibility'] ?? null;

            if (!is_array($loyalty) || !is_array($eligibility)) {
                throw new DomainException(
                    'Missing loyalty and eligibility snapshots'
                );
            }

            $spentUnits = $this->positive(
                $loyalty,
                'applied_units'
            );

            $policy = $loyalty['policy'] ?? null;

            if (!is_array($policy)) {
                throw new DomainException(
                    'Missing loyalty policy'
                );
            }

            $policyId = $this->positive($policy, 'id');

            $bonusStep = $this->positive(
                $policy,
                'bonus_units_per_step'
            );

            $moneyStep = $this->positive(
                $policy,
                'money_minor_per_step'
            );

            $bps = $this->amount(
                $policy,
                'max_goods_share_bps'
            );

            $minimumCash = $this->amount(
                $policy,
                'minimum_cash_minor'
            );

            $cap = $policy['max_discount_minor'] ?? null;

            if (
                $bps > 10000
                || (
                    $cap !== null
                    && (!is_int($cap) || $cap < 0)
                )
            ) {
                throw new DomainException(
                    'Invalid loyalty policy limits'
                );
            }

            // ========================================
            // 4. ПОВТОРНА ПЕРЕВІРКА КУРСУ І ЛІМІТІВ
            // ========================================

            $steps = intdiv($spentUnits, $bonusStep);

            $shareLimit =
                intdiv($eligibleAfterPromotion, 10000) * $bps
                + intdiv(
                    ($eligibleAfterPromotion % 10000) * $bps,
                    10000
                );

            $cashLimit = max(
                0,
                $eligibleAfterPromotion - $minimumCash
            );

            $monetaryLimit = min(
                $shareLimit,
                $cashLimit,
                $cap ?? PHP_INT_MAX
            );

            if (
                $loyaltyDiscount > $monetaryLimit
                || $spentUnits > $this->amount(
                    $loyalty,
                    'spendable_wallet_units'
                )
                || $spentUnits % $bonusStep !== 0
                || $steps > intdiv(PHP_INT_MAX, $moneyStep)
                || $steps * $moneyStep !== $loyaltyDiscount
                || ($loyalty['discount_minor'] ?? null)
                !== $loyaltyDiscount
                || ($loyalty['eligible_goods_minor'] ?? null)
                !== $eligibleAfterPromotion
                || ($eligibility['policy_id'] ?? null)
                !== $policyId
                || ($eligibility['rule_version'] ?? null)
                !== ($policy['rule_version'] ?? null)
            ) {
                throw new DomainException(
                    'Loyalty policy, units or eligibility mismatch'
                );
            }

            // ========================================
            // 5. ПЕРЕВІРКА ДОЗВОЛЯЮЧИХ ПРАВИЛ SKU
            // ========================================

            $approved = $eligibility['approved_variant_ids'] ?? null;
            $matched = $eligibility['matched_rule_ids'] ?? null;

            if (!is_array($approved) || !is_array($matched)) {
                throw new DomainException(
                    'Missing eligibility audit information'
                );
            }

            foreach ($lines as $line) {
                if ($line['loyalty_discount_minor'] === 0) {
                    continue;
                }

                $id = $line['variant_id'];

                if (
                    !in_array($id, $approved, true)
                    || !is_int($matched[$id] ?? null)
                    || $matched[$id] <= 0
                ) {
                    throw new DomainException(
                        'Redeemed SKU lacks a matched eligibility rule'
                    );
                }
            }

            // ========================================
            // 6. НЕЗМІННИЙ ЗМІСТ ВЕРСІЇ ПРАВИЛ
            // ========================================

            $policySnapshot = [
                'id' => $policyId,
                'program_code' => $this->text(
                    $policy,
                    'program_code'
                ),
                'rule_version' => $this->text(
                    $policy,
                    'rule_version'
                ),
                'bonus_units_per_step' => $bonusStep,
                'money_minor_per_step' => $moneyStep,
                'max_goods_share_bps' => $bps,
                'max_discount_minor' => $cap,
                'minimum_cash_minor' => $minimumCash,
            ];

            $ruleIds = [];

            foreach ($lines as $line) {
                if ($line['loyalty_discount_minor'] > 0) {
                    $ruleIds[(string) $line['variant_id']] =
                        $matched[$line['variant_id']];
                }
            }

            $eligibilitySnapshot = [
                'policy_id' => $policyId,
                'rule_version' => $policySnapshot['rule_version'],
                'applied_rule_ids_by_variant' => $ruleIds,
            ];
        } elseif (
            ($quote['loyalty']['applied_units'] ?? 0) !== 0
        ) {
            throw new DomainException(
                'Applied loyalty units without monetary discount'
            );
        }

        // ========================================
        // 7. КАНОНІЧНИЙ ФІНАНСОВИЙ ЗНІМОК
        // ========================================

        // Фіксований порядок полів забезпечує
        // стабільний SHA-256 для однакових даних.
        $snapshot = [
            'schema_version' => 1,

            'market_code' => $market,
            'price_list_code' => $priceList,
            'currency_code' => $currency,

            'subtotal_minor' => $subtotal,
            'promotion_discount_minor' => $promotionDiscount,
            'loyalty_discount_minor' => $loyaltyDiscount,
            'goods_payable_minor' => $payable,

            'eligible_goods_after_promotions_minor' =>
                $eligibleAfterPromotion,

            'loyalty_units_spent' => $spentUnits,

            'promotion_code' => $promotionCode,
            'loyalty_policy' => $policySnapshot,
            'loyalty_eligibility' => $eligibilitySnapshot,

            'lines' => $lines,
        ];

        try {
            $json = json_encode(
                $snapshot,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'Cannot encode checkout snapshot',
                0,
                $exception
            );
        }

        return [
            'snapshot' => $snapshot,

            // Контрольна сума, а не криптографічний
            // підпис чи підтвердження оплати.
            'sha256' => hash('sha256', $json),
        ];
    }

    // ========================================
    // 8. РОЗПОДІЛ ЗНИЖОК ПО ОДИНИЦЯХ
    // ========================================

    /**
     * @return array<int,array{
     *   unit_index:int,
     *   gross_minor:int,
     *   promotion_discount_minor:int,
     *   loyalty_discount_minor:int,
     *   cash_minor:int
     * }>
     */
    private function allocateUnits(
        int $quantity,
        int $unitPrice,
        int $promotion,
        int $loyalty
    ): array {
        $promoBase = intdiv($promotion, $quantity);
        $promoRemainder = $promotion % $quantity;

        $loyaltyBase = intdiv($loyalty, $quantity);
        $loyaltyRemainder = $loyalty % $quantity;

        $units = [];

        for ($i = 0; $i < $quantity; $i++) {
            $promo =
                $promoBase
                + ($i < $promoRemainder ? 1 : 0);

            $capacity = $unitPrice - $promo;

            if ($loyaltyBase > $capacity) {
                throw new DomainException(
                    'Loyalty amount cannot fit units'
                );
            }

            $units[] = [
                'unit_index' => $i + 1,
                'gross_minor' => $unitPrice,
                'promotion_discount_minor' => $promo,
                'loyalty_discount_minor' => $loyaltyBase,
                'cash_minor' => $capacity - $loyaltyBase,
            ];
        }

        // Залишкові копійки бонусної знижки
        // розподіляємо за найбільшою місткістю.
        // За рівності — менший номер одиниці.
        $indices = array_keys($units);

        usort(
            $indices,
            static function (
                int $left,
                int $right
            ) use ($units): int {
                return (
                    $units[$right]['cash_minor']
                    <=> $units[$left]['cash_minor']
                ) ?: ($left <=> $right);
            }
        );

        foreach ($indices as $index) {
            if ($loyaltyRemainder === 0) {
                break;
            }

            if ($units[$index]['cash_minor'] > 0) {
                $units[$index]['loyalty_discount_minor']++;
                $units[$index]['cash_minor']--;
                $loyaltyRemainder--;
            }
        }

        if ($loyaltyRemainder !== 0) {
            throw new DomainException(
                'Cannot distribute loyalty remainder'
            );
        }

        return $units;
    }

    // ========================================
    // 9. ДОПОМІЖНІ ПЕРЕВІРКИ
    // ========================================

    /**
     * @param array<string,mixed> $source
     */
    private function amount(
        array $source,
        string $name
    ): int {
        $value = $source[$name] ?? null;

        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException(
                "Invalid amount: {$name}"
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     */
    private function positive(
        array $source,
        string $name
    ): int {
        $value = $this->amount($source, $name);

        if ($value === 0) {
            throw new InvalidArgumentException(
                "Expected positive number: {$name}"
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     */
    private function text(
        array $source,
        string $name
    ): string {
        $value = $source[$name] ?? null;

        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > 100
        ) {
            throw new InvalidArgumentException(
                "Invalid text: {$name}"
            );
        }

        return $value;
    }

    private function add(
        int $first,
        int $second
    ): int {
        if ($second > PHP_INT_MAX - $first) {
            throw new DomainException(
                'Checkout amount overflow'
            );
        }

        return $first + $second;
    }
}
