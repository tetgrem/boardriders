<?php

namespace App\Services\Loyalty;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only знімок бонусного гаманця для майбутнього checkout.
 *
 * Не конвертує бонуси в гроші: курс та обмеження
 * визначатимуться окремою версіонованою політикою.
 * Нічого не резервує та не змінює в ledger.
 */
final class LoyaltyWalletPreviewService
{
    /**
     * @return array<string, mixed>|null
     */
    public function forVerifiedUser(
        int $userId,
        string $marketCode
    ): ?array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user identity');
        }

        $market = DB::table('markets')
            ->where('code', $marketCode)
            ->where('is_active', true)
            ->first(['id', 'code', 'currency_code']);

        if ($market === null) {
            return null;
        }

        $now = CarbonImmutable::now('UTC');

        // Клієнт не може передати wallet_id чи account_id.
        // Прив'язка виключно до перевіреного користувача.
        $accountId = DB::table('loyalty_accounts')
            ->where('user_id', $userId)
            ->where('identity_state', 'verified')
            ->value('id');

        $wallet = $accountId === null
            ? null
            : DB::table('loyalty_wallets')
                ->where('loyalty_account_id', $accountId)
                ->where('market_id', $market->id)
                ->first(['id', 'status']);

        $result = [
            'market_code' => $market->code,
            'currency_code' => $market->currency_code,
            'wallet_status' => $wallet?->status,
            'pending_units' => 0,
            'available_units' => 0,
            'reserved_units' => 0,
            'debt_units' => 0,
            'spendable_units' => 0,
            'blocked_reason' => 'no_verified_wallet',
            'informational_only' => true,
            'calculated_at' => $now->toIso8601String(),
        ];

        if ($wallet === null) {
            return $result;
        }

        $balances = DB::table('loyalty_ledger_entries')
            ->where('wallet_id', $wallet->id)
            ->where('market_id', $market->id)
            ->selectRaw('COALESCE(SUM(pending_delta), 0)::bigint AS pending')
            ->selectRaw('COALESCE(SUM(available_delta), 0)::bigint AS available')
            ->selectRaw('COALESCE(SUM(reserved_delta), 0)::bigint AS reserved')
            ->selectRaw('COALESCE(SUM(debt_delta), 0)::bigint AS debt')
            ->first();

        $result['pending_units'] = (int) $balances->pending;
        $result['available_units'] = (int) $balances->available;
        $result['reserved_units'] = (int) $balances->reserved;
        $result['debt_units'] = (int) $balances->debt;

        if ($wallet->status !== 'active') {
            $result['blocked_reason'] = 'wallet_not_active';

            return $result;
        }

        if ($result['debt_units'] > 0) {
            $result['blocked_reason'] = 'bonus_debt';

            return $result;
        }

        /*
         * Як і під час reserve(), враховуємо лише невичерпані
         * партії з актуальним строком дії. Останнє коригування
         * expires_at має пріоритет над початковою датою.
         *
         * Сума available з ledger сама по собі недостатня:
         * партія могла вже сплинути, а expiration job ще ні.
         */
        $spendable = DB::selectOne(<<<'SQL'
            SELECT COALESCE(SUM(x.units), 0)::bigint AS units
            FROM (
                SELECT
                    g.id,
                    GREATEST(
                        COALESCE(SUM(l.available_delta), 0),
                        0
                    )::bigint AS units
                FROM loyalty_grants g
                LEFT JOIN loyalty_ledger_entries l
                    ON l.grant_id = g.id
                    AND l.wallet_id = g.wallet_id
                    AND l.market_id = g.market_id
                LEFT JOIN LATERAL (
                    SELECT a.id, a.new_expires_at
                    FROM loyalty_grant_expiry_adjustments a
                    WHERE a.grant_id = g.id
                    ORDER BY a.id DESC
                    LIMIT 1
                ) latest ON TRUE
                WHERE
                    g.wallet_id = ?
                    AND g.market_id = ?
                    AND (
                        CASE
                            WHEN latest.id IS NULL THEN g.expires_at
                            ELSE latest.new_expires_at
                        END IS NULL
                        OR
                        CASE
                            WHEN latest.id IS NULL THEN g.expires_at
                            ELSE latest.new_expires_at
                        END > ?
                    )
                GROUP BY g.id
            ) x
        SQL, [
            $wallet->id,
            $market->id,
            $now->format('Y-m-d H:i:s.uP'),
        ]);

        $result['spendable_units'] = min(
            max(0, $result['available_units']),
            max(0, (int) $spendable->units)
        );

        $result['blocked_reason'] = null;

        return $result;
    }
}
