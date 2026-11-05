<?php

namespace App\Services\Loyalty;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Внутрішній сервіс бонусних операцій.
 *
 * Не підключати безпосередньо до публічного HTTP API.
 * Кожна зміна гаманця відбувається під row lock.
 */
final class LoyaltyTransactionService
{
    /**
     * @param array<string, mixed> $grant
     */
    public function grantPending(
        int $walletId,
        string $operationKey,
        array $grant
    ): array {
        foreach ([
                     'grant_key',
                     'grant_type',
                     'source_type',
                     'program_code',
                     'rule_version',
                     'units_total',
                     'rule_snapshot',
                 ] as $key) {
            if (!array_key_exists($key, $grant)) {
                throw new InvalidArgumentException(
                    "Missing grant field: {$key}"
                );
            }
        }

        $units = $this->positiveUnits($grant['units_total']);

        foreach ([
                     'grant_key',
                     'grant_type',
                     'source_type',
                     'program_code',
                     'rule_version',
                 ] as $key) {
            $this->requiredText(
                $grant[$key],
                $key,
                [
                    'grant_key' => 191,
                    'grant_type' => 48,
                    'source_type' => 48,
                    'program_code' => 64,
                    'rule_version' => 80,
                ][$key]
            );
        }

        if (isset($grant['source_ref'])) {
            $this->requiredText(
                $grant['source_ref'],
                'source_ref',
                191
            );
        }

        if (!is_array($grant['rule_snapshot'])
            || $grant['rule_snapshot'] === []) {
            throw new InvalidArgumentException(
                'rule_snapshot must contain approved rule data'
            );
        }

        $activation = $this->dateOrNull(
            $grant['activation_not_before_at'] ?? null
        );

        $expiry = $this->dateOrNull(
            $grant['expires_at'] ?? null
        );

        if ($activation && $expiry && $activation >= $expiry) {
            throw new DomainException(
                'Activation must precede expiration'
            );
        }

        $request = [
            'grant_key' => $grant['grant_key'],
            'grant_type' => $grant['grant_type'],
            'source_type' => $grant['source_type'],
            'source_ref' => $grant['source_ref'] ?? null,
            'program_code' => $grant['program_code'],
            'rule_version' => $grant['rule_version'],
            'units_total' => $units,
            'activation_at' => $activation,
            'expires_at' => $expiry,
            'activation_requirements' =>
                $grant['activation_requirements'] ?? [],
            'rule_snapshot' => $grant['rule_snapshot'],
            'context' => $grant['context'] ?? [],
        ];

        return $this->command(
            $walletId,
            $operationKey,
            'grant',
            $request,
            function (
                object $wallet,
                string $opId,
                CarbonImmutable $now
            ) use ($grant, $request, $units, $operationKey): array {
                if (
                    $request['expires_at'] !== null
                    && CarbonImmutable::parse(
                        $request['expires_at']
                    ) <= $now
                ) {
                    throw new DomainException(
                        'Grant expiration must be in the future'
                    );
                }

                if (
                    DB::table('loyalty_grants')
                        ->where('wallet_id', $wallet->id)
                        ->where('grant_key', $grant['grant_key'])
                        ->exists()
                ) {
                    throw new DomainException(
                        'Grant key already belongs to another operation'
                    );
                }

                $grantId = (int) DB::table('loyalty_grants')
                    ->insertGetId([
                        'wallet_id' => $wallet->id,
                        'market_id' => $wallet->market_id,
                        'grant_key' => $grant['grant_key'],
                        'grant_type' => $grant['grant_type'],
                        'source_type' => $grant['source_type'],
                        'source_ref' => $grant['source_ref'] ?? null,
                        'program_code' => $grant['program_code'],
                        'rule_version' => $grant['rule_version'],
                        'units_total' => $units,
                        'granted_at' => $now,
                        'activation_not_before_at' =>
                            $request['activation_at'],
                        'expires_at' => $request['expires_at'],
                        'activation_requirements' => json_encode(
                            $request['activation_requirements'],
                            JSON_THROW_ON_ERROR
                        ),
                        'rule_snapshot' => json_encode(
                            $request['rule_snapshot'],
                            JSON_THROW_ON_ERROR
                        ),
                        'context' => json_encode(
                            $grant['context'] ?? [],
                            JSON_THROW_ON_ERROR
                        ),
                    ]);

                $this->post(
                    $wallet,
                    $opId,
                    $operationKey . ':grant',
                    'grant_pending',
                    $grantId,
                    null,
                    $units,
                    0,
                    0,
                    0,
                    $grant['rule_version'],
                    $now
                );

                return [
                    'grant_id' => $grantId,
                    'pending_units' => $units,
                ];
            }
        );
    }

    public function activateGrant(
        int $walletId,
        int $grantId,
        int $units,
        string $operationKey
    ): array {
        $units = $this->positiveUnits($units);

        return $this->command(
            $walletId,
            $operationKey,
            'activate',
            compact('grantId', 'units'),
            function (
                object $wallet,
                string $opId,
                CarbonImmutable $now
            ) use ($grantId, $units, $operationKey): array {
                $grant = $this->grant($wallet, $grantId);

                $requirements = json_decode(
                    $grant->activation_requirements ?? 'null',
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (!empty($requirements)) {
                    throw new DomainException(
                        'Grant requires a verified approval workflow before activation'
                    );
                }

                if (
                    $grant->activation_not_before_at !== null
                    && CarbonImmutable::parse(
                        $grant->activation_not_before_at
                    ) > $now
                ) {
                    throw new DomainException(
                        'Activation waiting period has not ended'
                    );
                }

                if ($this->isExpired(
                    $grantId,
                    $grant->expires_at,
                    $now
                )) {
                    throw new DomainException(
                        'Expired grant cannot be activated'
                    );
                }

                if (
                    $this->grantState($grantId)->pending < $units
                ) {
                    throw new DomainException(
                        'Insufficient pending units in grant'
                    );
                }

                // Борг погашається після активації,
                // а не під час pending-нарахування.
                $ruleSnapshot = json_decode(
                    $grant->rule_snapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

// За замовчуванням борг погашають бонуси за покупки.
// Для інших типів це дозволяється лише затвердженим
// знімком правил бонусної програми.
                $repayAllowed = $ruleSnapshot['repay_bonus_debt']
                    ?? ($grant->grant_type === 'purchase');

                if (!is_bool($repayAllowed)) {
                    throw new DomainException(
                        'Invalid debt repayment rule'
                    );
                }

                $repay = $repayAllowed
                    ? min($units, max(0, $this->walletDebt($wallet->id)))
                    : 0;

                $this->post(
                    $wallet,
                    $opId,
                    $operationKey . ':activate',
                    'grant_activation',
                    $grantId,
                    null,
                    -$units,
                    $units - $repay,
                    0,
                    -$repay,
                    $grant->rule_version,
                    $now
                );

                return [
                    'grant_id' => $grantId,
                    'activated_units' => $units,
                    'debt_repaid_units' => $repay,
                    'available_added_units' => $units - $repay,
                ];
            }
        );
    }

    public function reserve(
        int $walletId,
        string $operationKey,
        string $reservationKey,
        string $orderReference,
        int $units,
        DateTimeInterface $expiresAt
    ): array {
        $units = $this->positiveUnits($units);

        $this->requiredText(
            $reservationKey,
            'reservationKey',
            120
        );

        $this->requiredText(
            $orderReference,
            'orderReference',
            191
        );

        $expires = $this->utc($expiresAt);

        return $this->command(
            $walletId,
            $operationKey,
            'reserve',
            compact(
                'reservationKey',
                'orderReference',
                'units',
                'expires'
            ),
            function (
                object $wallet,
                string $opId,
                CarbonImmutable $now
            ) use (
                $operationKey,
                $reservationKey,
                $orderReference,
                $units,
                $expires
            ): array {
                if (CarbonImmutable::parse($expires) <= $now) {
                    throw new DomainException(
                        'Reservation must expire in the future'
                    );
                }

                if ($this->walletDebt($wallet->id) > 0) {
                    throw new DomainException(
                        'Bonus redemption is blocked while bonus debt exists'
                    );
                }

                if (
                    DB::table('loyalty_reservations')
                        ->where('wallet_id', $wallet->id)
                        ->where('reservation_key', $reservationKey)
                        ->exists()
                ) {
                    throw new DomainException(
                        'Reservation key already belongs to another operation'
                    );
                }

                $candidates = $this->spendableGrants(
                    $wallet->id,
                    $now
                );

                $remaining = $units;
                $allocations = [];

                foreach ($candidates as $candidate) {
                    if ($remaining === 0) {
                        break;
                    }

                    $take = min(
                        $remaining,
                        (int) $candidate->available_units
                    );

                    if ($take > 0) {
                        $allocations[] = [
                            'grant_id' => (int) $candidate->id,
                            'units' => $take,
                            'rule_version' =>
                                $candidate->rule_version,
                        ];

                        $remaining -= $take;
                    }
                }

                if ($remaining > 0) {
                    throw new DomainException(
                        'Insufficient eligible bonus units'
                    );
                }

                $reservationId = (int) DB::table(
                    'loyalty_reservations'
                )->insertGetId([
                    'wallet_id' => $wallet->id,
                    'market_id' => $wallet->market_id,
                    'reservation_key' => $reservationKey,
                    'order_reference' => $orderReference,
                    'units_total' => $units,
                    'expires_at' => $expires,
                ]);

                foreach ($allocations as $part) {
                    DB::table(
                        'loyalty_reservation_lines'
                    )->insert([
                        'reservation_id' => $reservationId,
                        'wallet_id' => $wallet->id,
                        'market_id' => $wallet->market_id,
                        'grant_id' => $part['grant_id'],
                        'units' => $part['units'],
                    ]);

                    $this->post(
                        $wallet,
                        $opId,
                        $operationKey . ':reserve:'
                        . $part['grant_id'],
                        'reservation_created',
                        $part['grant_id'],
                        $reservationId,
                        0,
                        -$part['units'],
                        $part['units'],
                        0,
                        $part['rule_version'],
                        $now
                    );
                }

                return [
                    'reservation_id' => $reservationId,
                    'reserved_units' => $units,
                    'expires_at' => $expires,
                    'allocations' => $allocations,
                ];
            }
        );
    }

    /**
     * Викликати лише після перевірки платіжної події
     * та звірки замовлення з OMS.
     */
    public function captureReservation(
        int $walletId,
        int $reservationId,
        string $operationKey,
        DateTimeInterface $confirmedPaidAt
    ): array {
        $paidAt = $this->utc($confirmedPaidAt);

        return $this->command(
            $walletId,
            $operationKey,
            'capture',
            compact('reservationId', 'paidAt'),
            function (
                object $wallet,
                string $opId,
                CarbonImmutable $now
            ) use (
                $reservationId,
                $operationKey,
                $paidAt
            ): array {
                $reservation = $this->reservation(
                    $wallet,
                    $reservationId
                );

                if (
                    CarbonImmutable::parse($paidAt)
                    > CarbonImmutable::parse(
                        $reservation->expires_at
                    )
                    || CarbonImmutable::parse($paidAt)
                    < CarbonImmutable::parse(
                        $reservation->created_at
                    )
                ) {
                    throw new DomainException(
                        'Confirmed payment is outside reservation validity'
                    );
                }

                $allocations =
                    $this->openReservationAllocations(
                        $reservation
                    );

                foreach ($allocations as $part) {
                    $grant = $this->grant(
                        $wallet,
                        $part['grant_id']
                    );

                    $this->post(
                        $wallet,
                        $opId,
                        $operationKey . ':capture:'
                        . $part['grant_id'],
                        'reservation_captured',
                        $part['grant_id'],
                        $reservationId,
                        0,
                        0,
                        -$part['units'],
                        0,
                        $grant->rule_version,
                        $now
                    );
                }

                return [
                    'reservation_id' => $reservationId,
                    'captured_units' =>
                        (int) $reservation->units_total,
                ];
            },
            allowFrozen: true
        );
    }

    /**
     * Викликати лише після підтвердженої відміни,
     * відмови або таймауту в OMS.
     */
    public function releaseReservation(
        int $walletId,
        int $reservationId,
        string $operationKey
    ): array {
        return $this->command(
            $walletId,
            $operationKey,
            'release',
            compact('reservationId'),
            function (
                object $wallet,
                string $opId,
                CarbonImmutable $now
            ) use ($reservationId, $operationKey): array {
                $reservation = $this->reservation(
                    $wallet,
                    $reservationId
                );

                $allocations =
                    $this->openReservationAllocations(
                        $reservation
                    );

                $released = 0;
                $expired = 0;

                foreach ($allocations as $part) {
                    $grant = $this->grant(
                        $wallet,
                        $part['grant_id']
                    );

                    $isExpired = $this->isExpired(
                        $grant->id,
                        $grant->expires_at,
                        $now
                    );

                    $this->post(
                        $wallet,
                        $opId,
                        $operationKey . ':release:'
                        . $part['grant_id'],
                        $isExpired
                            ? 'reservation_expired'
                            : 'reservation_released',
                        $part['grant_id'],
                        $reservationId,
                        0,
                        $isExpired ? 0 : $part['units'],
                        -$part['units'],
                        0,
                        $grant->rule_version,
                        $now
                    );

                    $expired += $isExpired
                        ? $part['units']
                        : 0;

                    $released += $isExpired
                        ? 0
                        : $part['units'];
                }

                return [
                    'reservation_id' => $reservationId,
                    'released_units' => $released,
                    'expired_units' => $expired,
                ];
            },
            allowFrozen: true
        );
    }

    /**
     * Анулює лише незарезервовані одиниці партії.
     */
    public function expireGrant(
        int $walletId,
        int $grantId,
        string $operationKey
    ): array {
        return $this->command(
            $walletId,
            $operationKey,
            'expire',
            compact('grantId'),
            function (
                object $wallet,
                string $opId,
                CarbonImmutable $now
            ) use ($grantId, $operationKey): array {
                $grant = $this->grant(
                    $wallet,
                    $grantId
                );

                if (!$this->isExpired(
                    $grantId,
                    $grant->expires_at,
                    $now
                )) {
                    throw new DomainException(
                        'Grant is not expired'
                    );
                }

                $state = $this->grantState($grantId);

                if (
                    $state->pending < 0
                    || $state->available < 0
                    || (
                        $state->pending === 0
                        && $state->available === 0
                    )
                ) {
                    throw new DomainException(
                        'No unreserved units to expire'
                    );
                }

                $this->post(
                    $wallet,
                    $opId,
                    $operationKey . ':expire',
                    'grant_expiration',
                    $grantId,
                    null,
                    -$state->pending,
                    -$state->available,
                    0,
                    0,
                    $grant->rule_version,
                    $now
                );

                return [
                    'grant_id' => $grantId,
                    'expired_pending_units' =>
                        $state->pending,
                    'expired_available_units' =>
                        $state->available,
                ];
            },
            allowFrozen: true
        );
    }

    /**
     * @param array<string,mixed> $request
     */
    private function command(
        int $walletId,
        string $key,
        string $type,
        array $request,
        Closure $work,
        bool $allowFrozen = false
    ): array {
        $this->requiredText(
            $key,
            'operationKey',
            120
        );

        $fingerprint = hash(
            'sha256',
            json_encode(
                [$type, $request],
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
            )
        );

        return DB::transaction(
            function () use (
                $walletId,
                $key,
                $type,
                $fingerprint,
                $work,
                $allowFrozen
            ): array {
                $wallet = DB::table('loyalty_wallets')
                    ->where('id', $walletId)
                    ->lockForUpdate()
                    ->first();

                if ($wallet === null) {
                    throw new DomainException(
                        'Loyalty wallet not found'
                    );
                }

                $existing = DB::table('loyalty_operations')
                    ->where('wallet_id', $walletId)
                    ->where('operation_key', $key)
                    ->first();

                if ($existing !== null) {
                    if (
                        $existing->request_hash !== $fingerprint
                        || $existing->operation_type !== $type
                    ) {
                        throw new DomainException(
                            'Idempotency key reused with different parameters'
                        );
                    }

                    return json_decode(
                        $existing->result,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                }

                if (
                    $wallet->status !== 'active'
                    && !(
                        $allowFrozen
                        && $wallet->status === 'frozen'
                    )
                ) {
                    throw new DomainException(
                        'Loyalty wallet cannot accept this operation'
                    );
                }

                $operationId = (string) Str::uuid();

                $result = $work(
                    $wallet,
                    $operationId,
                    CarbonImmutable::now('UTC')
                );

                $result['operation_id'] = $operationId;

                // Після всіх проводок.
                // FK у ledger — DEFERRABLE.
                DB::table('loyalty_operations')->insert([
                    'id' => $operationId,
                    'wallet_id' => $wallet->id,
                    'market_id' => $wallet->market_id,
                    'operation_key' => $key,
                    'operation_type' => $type,
                    'request_hash' => $fingerprint,
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

    private function grant(
        object $wallet,
        int $grantId
    ): object {
        $grant = DB::table('loyalty_grants')
            ->where('id', $grantId)
            ->where('wallet_id', $wallet->id)
            ->where('market_id', $wallet->market_id)
            ->first();

        if ($grant === null) {
            throw new DomainException(
                'Grant does not belong to the wallet and market'
            );
        }

        return $grant;
    }

    private function reservation(
        object $wallet,
        int $reservationId
    ): object {
        $reservation = DB::table('loyalty_reservations')
            ->where('id', $reservationId)
            ->where('wallet_id', $wallet->id)
            ->where('market_id', $wallet->market_id)
            ->first();

        if ($reservation === null) {
            throw new DomainException(
                'Reservation does not belong to the wallet and market'
            );
        }

        return $reservation;
    }

    /**
     * @return array<int, array{grant_id:int,units:int}>
     */
    private function openReservationAllocations(
        object $reservation
    ): array {
        $lines = DB::table('loyalty_reservation_lines')
            ->where('reservation_id', $reservation->id)
            ->orderBy('grant_id')
            ->get();

        $balances = DB::table('loyalty_ledger_entries')
            ->where('reservation_id', $reservation->id)
            ->select('grant_id')
            ->selectRaw(
                'SUM(reserved_delta)::bigint AS units'
            )
            ->groupBy('grant_id')
            ->pluck('units', 'grant_id');

        $total = 0;
        $allocations = [];

        foreach ($lines as $line) {
            $units = (int) (
                $balances[$line->grant_id] ?? 0
            );

            if ($units !== (int) $line->units) {
                throw new DomainException(
                    'Reservation is already closed or has inconsistent allocations'
                );
            }

            $total += $units;

            $allocations[] = [
                'grant_id' => (int) $line->grant_id,
                'units' => $units,
            ];
        }

        if (
            $total !== (int) $reservation->units_total
            || $total === 0
        ) {
            throw new DomainException(
                'Reservation is already closed or inconsistent'
            );
        }

        return $allocations;
    }

    /**
     * @return array<int, object>
     */
    private function spendableGrants(
        int $walletId,
        CarbonImmutable $now
    ): array {
        $rows = DB::select(<<<'SQL'
            SELECT
                g.id,
                g.rule_version,
                g.granted_at,
                CASE
                    WHEN a.id IS NULL THEN g.expires_at
                    ELSE a.new_expires_at
                END AS effective_expires_at,
                COALESCE(
                    SUM(l.available_delta),
                    0
                )::bigint AS available_units
            FROM loyalty_grants g
            LEFT JOIN LATERAL (
                SELECT
                    id,
                    new_expires_at
                FROM loyalty_grant_expiry_adjustments
                WHERE grant_id = g.id
                ORDER BY id DESC
                LIMIT 1
            ) a ON TRUE
            LEFT JOIN loyalty_ledger_entries l
                ON l.grant_id = g.id
            WHERE g.wallet_id = ?
            GROUP BY
                g.id,
                a.id,
                a.new_expires_at
            HAVING COALESCE(
                SUM(l.available_delta),
                0
            ) > 0
        SQL, [$walletId]);

        $rows = array_values(
            array_filter(
                $rows,
                fn (object $r): bool =>
                    $r->effective_expires_at === null
                    || CarbonImmutable::parse(
                        $r->effective_expires_at
                    ) > $now
            )
        );

        usort(
            $rows,
            static function (
                object $a,
                object $b
            ): int {
                if (
                    $a->effective_expires_at === null
                    && $b->effective_expires_at !== null
                ) {
                    return 1;
                }

                if (
                    $a->effective_expires_at !== null
                    && $b->effective_expires_at === null
                ) {
                    return -1;
                }

                $byExpiry =
                    $a->effective_expires_at === null
                        ? 0
                        : CarbonImmutable::parse(
                            $a->effective_expires_at
                        ) <=> CarbonImmutable::parse(
                            $b->effective_expires_at
                        );

                return $byExpiry
                    ?: (
                        CarbonImmutable::parse(
                            $a->granted_at
                        )
                        <=> CarbonImmutable::parse(
                            $b->granted_at
                        )
                    )
                        ?: ($a->id <=> $b->id);
            }
        );

        return $rows;
    }

    private function grantState(int $grantId): object
    {
        $row = DB::table('loyalty_ledger_entries')
            ->where('grant_id', $grantId)
            ->selectRaw(
                'COALESCE(SUM(pending_delta),0)::bigint AS pending'
            )
            ->selectRaw(
                'COALESCE(SUM(available_delta),0)::bigint AS available'
            )
            ->first();

        return (object) [
            'pending' => (int) $row->pending,
            'available' => (int) $row->available,
        ];
    }

    private function walletDebt(int $walletId): int
    {
        return (int) DB::table('loyalty_ledger_entries')
            ->where('wallet_id', $walletId)
            ->sum('debt_delta');
    }

    private function isExpired(
        int $grantId,
        ?string $originalExpiry,
        CarbonImmutable $now
    ): bool {
        $adjustment = DB::table(
            'loyalty_grant_expiry_adjustments'
        )
            ->where('grant_id', $grantId)
            ->orderByDesc('id')
            ->first();

        $expiry = $adjustment === null
            ? $originalExpiry
            : $adjustment->new_expires_at;

        return $expiry !== null
            && CarbonImmutable::parse($expiry) <= $now;
    }

    private function post(
        object $wallet,
        string $opId,
        string $entryKey,
        string $type,
        int $grantId,
        ?int $reservationId,
        int $pending,
        int $available,
        int $reserved,
        int $debt,
        string $ruleVersion,
        CarbonImmutable $now
    ): void {
        if (
            $pending === 0
            && $available === 0
            && $reserved === 0
            && $debt === 0
        ) {
            throw new RuntimeException(
                'Zero ledger entry is not permitted'
            );
        }

        DB::table('loyalty_ledger_entries')->insert([
            'wallet_id' => $wallet->id,
            'market_id' => $wallet->market_id,
            'grant_id' => $grantId,
            'reservation_id' => $reservationId,
            'operation_id' => $opId,
            'entry_key' => $entryKey,
            'entry_type' => $type,
            'pending_delta' => $pending,
            'available_delta' => $available,
            'reserved_delta' => $reserved,
            'debt_delta' => $debt,
            'origin' => 'loyalty_service',
            'rule_version' => $ruleVersion,
            'occurred_at' => $now,
        ]);
    }

    private function positiveUnits(mixed $units): int
    {
        if (
            !is_int($units)
            || $units <= 0
        ) {
            throw new InvalidArgumentException(
                'Bonus units must be a positive integer'
            );
        }

        return $units;
    }

    private function requiredText(
        mixed $value,
        string $name,
        int $max
    ): void {
        if (
            !is_string($value)
            || trim($value) === ''
            || mb_strlen($value) > $max
        ) {
            throw new InvalidArgumentException(
                "Invalid {$name}"
            );
        }
    }

    private function dateOrNull(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if (!$date instanceof DateTimeInterface) {
            throw new InvalidArgumentException(
                'Dates must be DateTimeInterface objects'
            );
        }

        return $this->utc($date);
    }

    private function utc(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)
            ->utc()
            ->format('Y-m-d H:i:s.uP');
    }
}
