<?php

namespace App\Services\Loyalty;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Інформаційний перегляд бонусного гаманця без побічних ефектів.
 *
 * Не конвертує бонуси в гроші: для цього потрібні затверджені
 * правила курсу, обмежень та винятків для конкретного ринку.
 */
final class LoyaltyWalletPreviewService
{
    /** @return array<string, mixed>|null */
    public function forVerifiedUser(int $userId, string $marketCode): ?array
    {
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

        // Ідентичність беремо з авторизованого користувача,
        // а не з переданих клієнтом wallet_id / account_id.
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

        // Це суми ledger. available_units може включати бонуси,
        // що вже сплили, але expiration job ще не обробив їх.
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

        // Беремо лише невитрачені частини партій, строк яких
        // ще діє. Остання зміна строку має пріоритет над
        // початковим expires_at (навіть якщо новий строк NULL).
        //
        // Той самий базовий критерій строків, що й у
        // LoyaltyTransactionService::spendableGrants().
        // Це лише preview: reserve() повторно перевірить усе
        // під row lock на момент підтвердження замовлення.
        $eligible = DB::selectOne(<<<'SQL'
            SELECT COALESCE(SUM(part.available_units), 0)::bigint AS units
            FROM (
                SELECT
                    g.id,
                    GREATEST(
                        COALESCE(SUM(l.available_delta), 0),
                        0
                    )::bigint AS available_units
                FROM loyalty_grants AS g
                LEFT JOIN LATERAL (
                    SELECT a.id, a.new_expires_at
                    FROM loyalty_grant_expiry_adjustments AS a
                    WHERE a.grant_id = g.id
                    ORDER BY a.id DESC
                    LIMIT 1
                ) AS latest ON TRUE
                LEFT JOIN loyalty_ledger_entries AS l
                    ON l.grant_id = g.id
                    AND l.wallet_id = g.wallet_id
                    AND l.market_id = g.market_id
                WHERE g.wallet_id = ?
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
                        END > ?::timestamptz
                    )
                GROUP BY g.id
            ) AS part
        SQL, [
            $wallet->id,
            $market->id,
            $now->format('Y-m-d H:i:s.uP'),
        ]);

        $result['spendable_units'] = min(
            max(0, $result['available_units']),
            max(0, (int) $eligible->units)
        );

        $result['blocked_reason'] = null;

        return $result;
    }
}
