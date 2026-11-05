<?php

namespace App\Services\Loyalty;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Внутрішній read-only розрахунок бонусного списання.
 *
 * eligibleGoodsMinor має бути отриманий із серверного
 * Pricing/Promotions Engine ПІСЛЯ знижок, БЕЗ доставки.
 * Ніколи не брати цю суму безпосередньо з HTTP-запиту.
 *
 * Не резервує бонуси. Checkout повинен повторити розрахунок
 * під блокуванням та зафіксувати незмінний snapshot правил.
 */
final class LoyaltyRedemptionQuoteService
{
    public function __construct(
        private readonly LoyaltyWalletPreviewService $walletPreview
    ) {
    }

    /** @return array<string, mixed>|null */
    public function quoteForVerifiedUser(
        int $userId,
        string $marketCode,
        string $currencyCode,
        int $eligibleGoodsMinor,
        ?int $requestedUnits = null
    ): ?array {
        if (
            $userId <= 0
            || $eligibleGoodsMinor < 0
            || (
                $requestedUnits !== null
                && $requestedUnits < 0
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid loyalty quote input'
            );
        }

        $market = DB::table('markets')
            ->where('code', $marketCode)
            ->where('is_active', true)
            ->first([
                'id',
                'code',
                'currency_code',
            ]);

        if ($market === null) {
            return null;
        }

        if ($market->currency_code !== $currencyCode) {
            throw new DomainException(
                'Market and cart currency do not match'
            );
        }

        $now = CarbonImmutable::now('UTC');

        $wallet = $this->walletPreview->forVerifiedUser(
            $userId,
            $marketCode
        );

        // Остання версія, яка вже набрала чинності.
        $policy = DB::table('loyalty_redemption_policies')
            ->where('market_id', $market->id)
            ->where('effective_from', '<=', $now)
            ->orderByDesc('effective_from')
            ->first();

        $result = [
            'market_code' => $market->code,
            'currency_code' => $currencyCode,
            'eligible_goods_minor' => $eligibleGoodsMinor,

            'spendable_wallet_units' =>
                $wallet['spendable_units'],

            'requested_units' => $requestedUnits,

            'max_redeemable_units' => 0,
            'max_discount_minor' => 0,

            'applied_units' => 0,
            'discount_minor' => 0,

            'goods_to_pay_minor' => $eligibleGoodsMinor,

            'policy' => null,
            'reason' => null,
            'informational_only' => true,
        ];

        if ($policy === null) {
            $result['reason'] = 'no_active_policy';

            return $result;
        }

        if ($policy->currency_code !== $currencyCode) {
            $result['reason'] = 'policy_currency_mismatch';

            return $result;
        }

        // Знімок застосованої версії правил.
        $result['policy'] = [
            'id' => (int) $policy->id,

            'program_code' => $policy->program_code,
            'rule_version' => $policy->rule_version,

            'bonus_units_per_step' =>
                (int) $policy->bonus_units_per_step,

            'money_minor_per_step' =>
                (int) $policy->money_minor_per_step,

            'max_goods_share_bps' =>
                (int) $policy->max_goods_share_bps,

            'max_discount_minor' =>
                $policy->max_discount_minor === null
                    ? null
                    : (int) $policy->max_discount_minor,

            'minimum_cash_minor' =>
                (int) $policy->minimum_cash_minor,
        ];

        // PostgreSQL може повертати boolean у різних
        // представленнях залежно від драйвера.
        $enabled = in_array(
            $policy->is_enabled,
            [true, 1, '1', 't', 'true'],
            true
        );

        if (!$enabled) {
            $result['reason'] = 'policy_disabled';

            return $result;
        }

        // Статус, борг і строк дії бонусів уже перевірені
        // в LoyaltyWalletPreviewService.
        if ($wallet['blocked_reason'] !== null) {
            $result['reason'] = $wallet['blocked_reason'];

            return $result;
        }

        if ($eligibleGoodsMinor === 0) {
            $result['reason'] = 'no_eligible_goods';

            return $result;
        }

        // ----------------------------------------
        // 1. МАКСИМАЛЬНА ЧАСТКА ОПЛАТИ БОНУСАМИ
        // ----------------------------------------

        $bps = (int) $policy->max_goods_share_bps;

        // floor(amount * bps / 10000), без float.
        $shareLimit =
            intdiv($eligibleGoodsMinor, 10000) * $bps
            +
            intdiv(
                ($eligibleGoodsMinor % 10000) * $bps,
                10000
            );

        // ----------------------------------------
        // 2. МІНІМАЛЬНИЙ ЗАЛИШОК ДО ОПЛАТИ ГРОШИМА
        // ----------------------------------------

        $cashLimit = max(
            0,
            $eligibleGoodsMinor
            - (int) $policy->minimum_cash_minor
        );

        $moneyLimit = min(
            $shareLimit,
            $cashLimit
        );

        // ----------------------------------------
        // 3. АБСОЛЮТНИЙ ЛІМІТ ЗНИЖКИ
        // ----------------------------------------

        if ($policy->max_discount_minor !== null) {
            $moneyLimit = min(
                $moneyLimit,
                (int) $policy->max_discount_minor
            );
        }

        // ----------------------------------------
        // 4. КУРС КОНВЕРТАЦІЇ
        // ----------------------------------------

        $bonusStep =
            (int) $policy->bonus_units_per_step;

        $moneyStep =
            (int) $policy->money_minor_per_step;

        // Скільки кроків може покрити бонусний баланс.
        $walletSteps = intdiv(
            max(0, $wallet['spendable_units']),
            $bonusStep
        );

        // Скільки кроків дозволяє грошовий ліміт.
        $moneySteps = intdiv(
            max(0, $moneyLimit),
            $moneyStep
        );

        $steps = min(
            $walletSteps,
            $moneySteps
        );

        $result['max_redeemable_units'] =
            $steps * $bonusStep;

        $result['max_discount_minor'] =
            $steps * $moneyStep;

        // Без запиту на списання повертаємо лише
        // максимально дозволені суми.
        if (
            $requestedUnits === null
            || $requestedUnits === 0
        ) {
            return $result;
        }

        // Не допускаємо часткового кроку.
        if ($requestedUnits % $bonusStep !== 0) {
            $result['reason'] = 'invalid_bonus_step';

            return $result;
        }

        // Не округлюємо завищений запит мовчки.
        if (
            $requestedUnits >
            $result['max_redeemable_units']
        ) {
            $result['reason'] =
                'requested_units_exceed_limit';

            return $result;
        }

        // ----------------------------------------
        // 5. ФІНАЛЬНИЙ ІНФОРМАЦІЙНИЙ РОЗРАХУНОК
        // ----------------------------------------

        $appliedSteps = intdiv(
            $requestedUnits,
            $bonusStep
        );

        $discountMinor =
            $appliedSteps * $moneyStep;

        $result['applied_units'] = $requestedUnits;
        $result['discount_minor'] = $discountMinor;

        $result['goods_to_pay_minor'] =
            $eligibleGoodsMinor - $discountMinor;

        return $result;
    }
}
