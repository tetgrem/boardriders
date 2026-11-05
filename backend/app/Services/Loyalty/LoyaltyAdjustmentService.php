<?php

namespace App\Services\Loyalty;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Службові коригування бонусних партій.
 *
 * Не підключати до HTTP-контролерів без перевірених прав доступу,
 * підтвердження події OMS і погодженої політики коригувань.
 * Усі методи серіалізуються row lock за wallet_id.
 */
final class LoyaltyAdjustmentService
{
    /**
     * Анулювання частини/всього бонусного нарахування.
     * Сума units повинна походити з підтвердженого рядка повернення.
     * Спочатку OMS має завершити всі відкриті резерви цієї партії.
     */
    public function reverseGrant(
        int $walletId,
        int $grantId,
        int $units,
        string $operationKey,
        string $reasonCode,
        string $sourceReference,
        array $context = []
    ): array {
        $this->positiveUnits($units);
        $this->requiredText($reasonCode, 'reasonCode', 80);
        $this->requiredText($sourceReference, 'sourceReference', 191);

        return $this->execute(
            $walletId,
            $operationKey,
            'grant_reversal',
            compact('grantId', 'units', 'reasonCode', 'sourceReference', 'context'),
            function (object $wallet, string $operationId, CarbonImmutable $now) use (
                $grantId,
                $units,
                $operationKey,
                $reasonCode,
                $sourceReference,
                $context
            ): array {
                $grant = $this->grant($wallet, $grantId);

                $previous = DB::table('loyalty_grant_reversals')
                    ->where('grant_id', $grantId)
                    ->selectRaw('COALESCE(SUM(units_total), 0)::bigint AS total')
                    ->selectRaw('COALESCE(SUM(expired_units), 0)::bigint AS expired')
                    ->first();

                $previousUnits = (int) $previous->total;

                if ($units > (int) $grant->units_total - $previousUnits) {
                    throw new DomainException(
                        'Grant reversal exceeds outstanding original units'
                    );
                }

                $state = DB::table('loyalty_ledger_entries')
                    ->where('grant_id', $grantId)
                    ->selectRaw(
                        'COALESCE(SUM(pending_delta), 0)::bigint AS pending'
                    )
                    ->selectRaw(
                        'COALESCE(SUM(available_delta), 0)::bigint AS available'
                    )
                    ->selectRaw(
                        'COALESCE(SUM(reserved_delta), 0)::bigint AS reserved'
                    )
                    ->selectRaw(<<<'SQL'
                        COALESCE(SUM(
                            CASE
                                WHEN entry_type = 'grant_expiration'
                                    THEN -pending_delta - available_delta
                                WHEN entry_type = 'reservation_expired'
                                    THEN -reserved_delta
                                ELSE 0
                            END
                        ), 0)::bigint AS expired
                    SQL)
                    ->first();

                $pending = (int) $state->pending;
                $available = (int) $state->available;
                $reserved = (int) $state->reserved;
                $expired = (int) $state->expired
                    - (int) $previous->expired;

                if (
                    $pending < 0
                    || $available < 0
                    || $reserved < 0
                    || $expired < 0
                ) {
                    throw new DomainException(
                        'Grant ledger is inconsistent'
                    );
                }

                if ($reserved > 0) {
                    throw new DomainException(
                        'Release or capture active reservations before reversing a grant'
                    );
                }

                // Невикористана + прострочена + фактично спожита
                // або спрямована на погашення попереднього боргу частини.
                $consumed = (int) $grant->units_total
                    - $previousUnits
                    - $pending
                    - $available
                    - $reserved
                    - $expired;

                if ($consumed < 0) {
                    throw new DomainException(
                        'Grant accounting does not reconcile'
                    );
                }

                $remaining = $units;

                $fromPending = min($remaining, $pending);
                $remaining -= $fromPending;

                $fromAvailable = min($remaining, $available);
                $remaining -= $fromAvailable;

                $fromExpired = min($remaining, $expired);
                $remaining -= $fromExpired;

                $fromSpent = $remaining;

                if ($fromSpent > $consumed) {
                    throw new DomainException(
                        'Reversal cannot be allocated to grant units'
                    );
                }

                // Борг — тільки внутрішня бонусна заборгованість,
                // а не грошовий борг покупця.
                if (
                    $fromPending > 0
                    || $fromAvailable > 0
                    || $fromSpent > 0
                ) {
                    DB::table('loyalty_ledger_entries')->insert([
                        'wallet_id' => $wallet->id,
                        'market_id' => $wallet->market_id,
                        'grant_id' => $grantId,
                        'reservation_id' => null,
                        'operation_id' => $operationId,
                        'entry_key' => $operationKey . ':reverse',
                        'entry_type' => 'grant_reversal',
                        'pending_delta' => -$fromPending,
                        'available_delta' => -$fromAvailable,
                        'reserved_delta' => 0,
                        'debt_delta' => $fromSpent,
                        'origin' => 'loyalty_adjustment_service',
                        'rule_version' => $grant->rule_version,
                        'context' => json_encode([
                            'reason_code' => $reasonCode,
                            'source_reference' => $sourceReference,
                        ], JSON_THROW_ON_ERROR),
                        'occurred_at' => $now,
                    ]);
                }

                $reversalId = (int) DB::table(
                    'loyalty_grant_reversals'
                )->insertGetId([
                    'grant_id' => $grantId,
                    'wallet_id' => $wallet->id,
                    'market_id' => $wallet->market_id,
                    'operation_id' => $operationId,
                    'reversal_key' => $operationKey,
                    'reason_code' => $reasonCode,
                    'source_reference' => $sourceReference,
                    'rule_version' => $grant->rule_version,
                    'units_total' => $units,
                    'pending_units' => $fromPending,
                    'available_units' => $fromAvailable,
                    'expired_units' => $fromExpired,
                    'debt_units' => $fromSpent,
                    'context' => json_encode(
                        $context,
                        JSON_THROW_ON_ERROR
                    ),
                ]);

                return [
                    'reversal_id' => $reversalId,
                    'grant_id' => $grantId,
                    'reversed_units' => $units,
                    'pending_removed' => $fromPending,
                    'available_removed' => $fromAvailable,
                    'already_expired_units' => $fromExpired,
                    'bonus_debt_created' => $fromSpent,
                ];
            }
        );
    }

    /**
     * Змінює ефективний строк дії, не змінюючи початковий grant.
     * Не повертає одиниці, які вже списані як прострочені.
     */
    public function changeGrantExpiry(
        int $walletId,
        int $grantId,
        string $operationKey,
        ?DateTimeInterface $newExpiry,
        string $reasonCode,
        string $ruleVersion,
        ?int $actorUserId = null,
        array $context = []
    ): array {
        $this->requiredText($reasonCode, 'reasonCode', 80);
        $this->requiredText($ruleVersion, 'ruleVersion', 80);

        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new InvalidArgumentException(
                'Invalid actorUserId'
            );
        }

        $newExpiryUtc = $newExpiry === null
            ? null
            : $this->utc($newExpiry);

        return $this->execute(
            $walletId,
            $operationKey,
            'grant_expiry_adjustment',
            compact(
                'grantId',
                'newExpiryUtc',
                'reasonCode',
                'ruleVersion',
                'actorUserId',
                'context'
            ),
            function (object $wallet, string $operationId, CarbonImmutable $now) use (
                $grantId,
                $newExpiryUtc,
                $operationKey,
                $reasonCode,
                $ruleVersion,
                $actorUserId,
                $context
            ): array {
                $grant = $this->grant($wallet, $grantId);

                $last = DB::table(
                    'loyalty_grant_expiry_adjustments'
                )
                    ->where('grant_id', $grantId)
                    ->orderByDesc('id')
                    ->first();

                $previousExpiry = $last === null
                    ? $grant->expires_at
                    : $last->new_expires_at;

                $oldUtc = $previousExpiry === null
                    ? null
                    : $this->utc(
                        CarbonImmutable::parse($previousExpiry)
                    );

                if ($oldUtc === $newExpiryUtc) {
                    throw new DomainException(
                        'New expiration must differ from current expiration'
                    );
                }

                if (
                    $newExpiryUtc !== null
                    && CarbonImmutable::parse($newExpiryUtc) <= $now
                ) {
                    throw new DomainException(
                        'A new expiration must be in the future'
                    );
                }

                $adjustmentId = (int) DB::table(
                    'loyalty_grant_expiry_adjustments'
                )->insertGetId([
                    'grant_id' => $grantId,
                    'wallet_id' => $wallet->id,
                    'market_id' => $wallet->market_id,
                    'adjustment_key' => $operationKey,
                    'old_expires_at' => $oldUtc,
                    'new_expires_at' => $newExpiryUtc,
                    'reason_code' => $reasonCode,
                    'rule_version' => $ruleVersion,
                    'actor_user_id' => $actorUserId,
                    'context' => json_encode([
                        'operation_id' => $operationId,
                        'details' => $context,
                    ], JSON_THROW_ON_ERROR),
                    'effective_at' => $now,
                ]);

                return [
                    'adjustment_id' => $adjustmentId,
                    'grant_id' => $grantId,
                    'old_expires_at' => $oldUtc,
                    'new_expires_at' => $newExpiryUtc,
                ];
            }
        );
    }

    /**
     * @param array<string,mixed> $request
     */
    private function execute(
        int $walletId,
        string $operationKey,
        string $type,
        array $request,
        callable $work
    ): array {
        $this->requiredText(
            $operationKey,
            'operationKey',
            120
        );

        $hash = hash(
            'sha256',
            json_encode(
                [$type, $request],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            )
        );

        return DB::transaction(
            function () use (
                $walletId,
                $operationKey,
                $type,
                $hash,
                $work
            ): array {
                $wallet = DB::table('loyalty_wallets')
                    ->where('id', $walletId)
                    ->lockForUpdate()
                    ->first();

                if ($wallet === null) {
                    throw new DomainException(
                        'Wallet not found'
                    );
                }

                $existing = DB::table('loyalty_operations')
                    ->where('wallet_id', $walletId)
                    ->where('operation_key', $operationKey)
                    ->first();

                if ($existing !== null) {
                    if (
                        $existing->operation_type !== $type
                        || $existing->request_hash !== $hash
                    ) {
                        throw new DomainException(
                            'Idempotency key conflict'
                        );
                    }

                    return json_decode(
                        $existing->result,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                }

                // Коригування дозволені також для
                // замороженого гаманця.
                if (
                    !in_array(
                        $wallet->status,
                        ['active', 'frozen'],
                        true
                    )
                ) {
                    throw new DomainException(
                        'Wallet is closed'
                    );
                }

                $operationId = (string) Str::uuid();

                $result = $work(
                    $wallet,
                    $operationId,
                    CarbonImmutable::now('UTC')
                );

                $result['operation_id'] = $operationId;

                DB::table('loyalty_operations')->insert([
                    'id' => $operationId,
                    'wallet_id' => $wallet->id,
                    'market_id' => $wallet->market_id,
                    'operation_key' => $operationKey,
                    'operation_type' => $type,
                    'request_hash' => $hash,
                    'result' => json_encode(
                        $result,
                        JSON_THROW_ON_ERROR
                    ),
                ]);

                return $result;
            },
            3
        );
    }

    private function grant(object $wallet, int $grantId): object
    {
        $grant = DB::table('loyalty_grants')
            ->where('id', $grantId)
            ->where('wallet_id', $wallet->id)
            ->where('market_id', $wallet->market_id)
            ->first();

        if ($grant === null) {
            throw new DomainException(
                'Grant does not belong to this wallet and market'
            );
        }

        return $grant;
    }

    private function positiveUnits(int $units): void
    {
        if ($units <= 0) {
            throw new InvalidArgumentException(
                'units must be positive'
            );
        }
    }

    private function requiredText(
        string $value,
        string $name,
        int $max
    ): void {
        if (
            trim($value) === ''
            || mb_strlen($value) > $max
        ) {
            throw new InvalidArgumentException(
                "Invalid {$name}"
            );
        }
    }

    private function utc(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)
            ->utc()
            ->format('Y-m-d H:i:s.uP');
    }
}
