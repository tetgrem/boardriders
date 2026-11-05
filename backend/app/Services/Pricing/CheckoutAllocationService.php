<?php

namespace App\Services\Pricing;

use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Розподіл знижок між товарними позиціями.
 *
 * Порядок:
 * 1. Товарна вартість.
 * 2. Промознижка.
 * 3. Бонусне списання.
 *
 * Усі суми — integer minor units.
 * Доставка тут не враховується.
 *
 * Вхідні суми та прапорці eligibility мають надходити
 * виключно з довіреного серверного розрахунку.
 */
final class CheckoutAllocationService
{
    /**
     * @param array<int, array{
     *     variant_id:int,
     *     quantity:int,
     *     unit_price_minor:int,
     *     promotion_eligible:bool,
     *     loyalty_eligible:bool
     * }> $items
     *
     * @return array<string, mixed>
     */
    public function allocate(
        array $items,
        int $promotionDiscountMinor,
        int $loyaltyDiscountMinor
    ): array {
        if (
            $promotionDiscountMinor < 0
            || $loyaltyDiscountMinor < 0
        ) {
            throw new InvalidArgumentException(
                'Discounts cannot be negative'
            );
        }

        if (count($items) > 200) {
            throw new InvalidArgumentException(
                'Too many checkout items'
            );
        }

        $lines = [];
        $seen = [];
        $subtotal = 0;

        foreach ($items as $item) {
            foreach ([
                         'variant_id',
                         'quantity',
                         'unit_price_minor',
                         'promotion_eligible',
                         'loyalty_eligible',
                     ] as $field) {
                if (!array_key_exists($field, $item)) {
                    throw new InvalidArgumentException(
                        "Missing checkout field: {$field}"
                    );
                }
            }

            $id = $item['variant_id'];
            $quantity = $item['quantity'];
            $unitPrice = $item['unit_price_minor'];

            if (
                !is_int($id)
                || $id <= 0
                || !is_int($quantity)
                || $quantity < 1
                || $quantity > 99
                || !is_int($unitPrice)
                || $unitPrice < 0
                || !is_bool($item['promotion_eligible'])
                || !is_bool($item['loyalty_eligible'])
            ) {
                throw new InvalidArgumentException(
                    'Invalid checkout item'
                );
            }

            if (isset($seen[$id])) {
                throw new InvalidArgumentException(
                    'Duplicate checkout variant'
                );
            }

            $seen[$id] = true;

            // Перевірка переповнення до множення.
            if (
                $unitPrice >
                intdiv(PHP_INT_MAX, $quantity)
            ) {
                throw new DomainException(
                    'Checkout amount overflow'
                );
            }

            $lineSubtotal = $unitPrice * $quantity;

            if ($lineSubtotal > PHP_INT_MAX - $subtotal) {
                throw new DomainException(
                    'Checkout subtotal overflow'
                );
            }

            $subtotal += $lineSubtotal;

            $lines[] = [
                'variant_id' => $id,
                'quantity' => $quantity,
                'unit_price_minor' => $unitPrice,
                'subtotal_minor' => $lineSubtotal,
                'promotion_eligible' =>
                    $item['promotion_eligible'],
                'loyalty_eligible' =>
                    $item['loyalty_eligible'],
                'promotion_discount_minor' => 0,
                'loyalty_discount_minor' => 0,
                'goods_payable_minor' => $lineSubtotal,
            ];
        }

        // ----------------------------------------
        // 1. РОЗПОДІЛ ПРОМОЗНИЖКИ
        // ----------------------------------------

        $promoWeights = [];

        foreach ($lines as $line) {
            $promoWeights[] = $line['promotion_eligible']
                ? $line['subtotal_minor']
                : 0;
        }

        $promoParts = $this->distribute(
            $lines,
            $promoWeights,
            $promotionDiscountMinor
        );

        foreach ($lines as $index => &$line) {
            $line['promotion_discount_minor'] =
                $promoParts[$index];

            $line['goods_payable_minor'] -=
                $promoParts[$index];
        }

        unset($line);

        // ----------------------------------------
        // 2. БАЗА ДЛЯ БОНУСНОГО СПИСАННЯ
        // ----------------------------------------

        $loyaltyWeights = [];
        $eligibleAfterPromotions = 0;

        foreach ($lines as $line) {
            $weight = $line['loyalty_eligible']
                ? $line['goods_payable_minor']
                : 0;

            $loyaltyWeights[] = $weight;
            $eligibleAfterPromotions += $weight;
        }

        // ----------------------------------------
        // 3. РОЗПОДІЛ БОНУСНОГО СПИСАННЯ
        // ----------------------------------------

        $loyaltyParts = $this->distribute(
            $lines,
            $loyaltyWeights,
            $loyaltyDiscountMinor
        );

        foreach ($lines as $index => &$line) {
            $line['loyalty_discount_minor'] =
                $loyaltyParts[$index];

            $line['goods_payable_minor'] -=
                $loyaltyParts[$index];
        }

        unset($line);

        return [
            'subtotal_minor' => $subtotal,

            'promotion_discount_minor' =>
                $promotionDiscountMinor,

            'eligible_goods_after_promotions_minor' =>
                $eligibleAfterPromotions,

            'loyalty_discount_minor' =>
                $loyaltyDiscountMinor,

            'goods_payable_minor' =>
                $subtotal
                - $promotionDiscountMinor
                - $loyaltyDiscountMinor,

            'lines' => $lines,
        ];
    }

    /**
     * Пропорційний розподіл методом найбільших залишків.
     *
     * Останні копійки отримують позиції з найбільшим
     * дробовим залишком. За рівності — менший variant_id.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param array<int, int> $weights
     * @return array<int, int>
     */
    private function distribute(
        array $lines,
        array $weights,
        int $discount
    ): array {
        $count = count($lines);
        $parts = array_fill(0, $count, 0);

        if ($discount === 0) {
            return $parts;
        }

        $totalWeight = 0;

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new DomainException(
                    'Negative allocation weight'
                );
            }

            if ($weight > PHP_INT_MAX - $totalWeight) {
                throw new DomainException(
                    'Allocation weight overflow'
                );
            }

            $totalWeight += $weight;
        }

        if (
            $totalWeight === 0
            || $discount > $totalWeight
        ) {
            throw new DomainException(
                'Discount exceeds eligible goods'
            );
        }

        $ranked = [];
        $allocated = 0;

        foreach ($weights as $index => $weight) {
            if ($weight === 0) {
                continue;
            }

            [$share, $remainder] = $this->multiplyDivide(
                $discount,
                $weight,
                $totalWeight
            );

            $parts[$index] = $share;
            $allocated += $share;

            $ranked[] = [
                'index' => $index,
                'remainder' => $remainder,
                'variant_id' => $lines[$index]['variant_id'],
            ];
        }

        $remaining = $discount - $allocated;

        usort(
            $ranked,
            static fn (array $a, array $b): int =>
            ($b['remainder'] <=> $a['remainder'])
                ?: ($a['variant_id'] <=> $b['variant_id'])
        );

        foreach ($ranked as $candidate) {
            if ($remaining === 0) {
                break;
            }

            $parts[$candidate['index']]++;
            $remaining--;
        }

        if ($remaining !== 0) {
            throw new RuntimeException(
                'Incomplete discount allocation'
            );
        }

        return $parts;
    }

    /**
     * Точне floor(a * b / denominator) та залишок
     * без проміжного множення великих integer.
     *
     * Передумови: 0 <= a <= denominator,
     * 0 <= b <= denominator, denominator > 0.
     *
     * @return array{int, int}
     */
    private function multiplyDivide(
        int $a,
        int $b,
        int $denominator
    ): array {
        $bits = [];

        while ($b > 0) {
            $bits[] = $b % 2;
            $b = intdiv($b, 2);
        }

        $quotient = 0;
        $remainder = 0;

        for ($i = count($bits) - 1; $i >= 0; $i--) {
            // Подвоєння остачі без переповнення.
            $distance = $denominator - $remainder;

            if ($remainder >= $distance) {
                $remainder -= $distance;
                $carry = 1;
            } else {
                $remainder += $remainder;
                $carry = 0;
            }

            $quotient = $quotient * 2 + $carry;

            if ($bits[$i] === 1) {
                $distance = $denominator - $a;

                if ($remainder >= $distance) {
                    $remainder -= $distance;
                    $quotient++;
                } else {
                    $remainder += $a;
                }
            }
        }

        return [$quotient, $remainder];
    }
}
