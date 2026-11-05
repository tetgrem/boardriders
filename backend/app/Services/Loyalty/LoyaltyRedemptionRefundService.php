<?php

namespace App\Services\Loyalty;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Повернення раніше списаних бонусів після перевіреного повернення OMS.
 *
 * Не викликати безпосередньо з публічного API.
 * Суми у units передає тільки серверний облік позицій замовлення.
 */
final class LoyaltyRedemptionRefundService
{
    /**
     * @param array<int,int> $unitsByGrant
     * @param array<int,DateTimeInterface> $newExpiryByGrant
     * @param array<string,mixed> $context
     */
    public function refundCapturedBonuses(
        int $walletId,
        int $reservationId,
        string $operationKey,
        string $refundReference,
        array $unitsByGrant,
        array $newExpiryByGrant = [],
        array $context = []
    ): array {
        $this->requiredText($operationKey, 'operationKey', 120);
        $this->requiredText($refundReference, 'refundReference', 191);

        if ($unitsByGrant === []) {
            throw new InvalidArgumentException(
                'At least one grant allocation is required'
            );
        }

        $allocations = [];

        foreach ($unitsByGrant as $grantId => $units) {
            if (
                !is_int($grantId)
                || $grantId <= 0
                || !is_int($units)
                || $units <= 0
            ) {
                throw new InvalidArgumentException(
                    'Allocations must map positive grant IDs to positive integer units'
                );
            }

            $allocations[$grantId] = $units;
        }

        ksort($allocations, SORT_NUMERIC);

        $extensions = [];

        foreach ($newExpiryByGrant as $grantId => $date) {
            if (
                !is_int($grantId)
                || !array_key_exists($grantId, $allocations)
                || !$date instanceof DateTimeInterface
            ) {
                throw new InvalidArgumentException(
                    'Expiry overrides must reference refunded grants and contain dates'
                );
            }

            $extensions[$grantId] = $this->utc($date);
        }

        ksort($extensions, SORT_NUMERIC);

        $request = [
            'reservation_id' => $reservationId,
            'refund_reference' => $refundReference,
            'allocations' => $allocations,
            'expiry_overrides' => $extensions,
            'context' => $context,
        ];

        $hash = hash(
            'sha256',
            json_encode(
                ['redemption_refund', $request],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            )
        );

        return DB::transaction(function () use (
            $walletId,
            $reservationId,
            $operationKey,
            $refundReference,
            $allocations,
            $extensions,
            $context,
            $hash
        ): array {
            // Єдиний row lock для всіх бонусних операцій.
            $wallet = DB::table('loyalty_wallets')
                ->where('id', $walletId)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                throw new DomainException(
                    'Loyalty wallet not found'
                );
            }

            // Повторний запит з тим самим ключем
            // повертає раніше зафіксований результат.
            $existing = DB::table('loyalty_operations')
                ->where('wallet_id', $walletId)
                ->where('operation_key', $operationKey)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->operation_type !== 'redemption_refund'
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

            if (
                !in_array(
                    $wallet->status,
                    ['active', 'frozen'],
                    true
                )
            ) {
                throw new DomainException(
                    'Closed wallet requires a separate reviewed refund process'
                );
            }

            // Резерв має належати саме цьому гаманцю та ринку.
            $reservation = DB::table('loyalty_reservations')
                ->where('id', $reservationId)
                ->where('wallet_id', $walletId)
                ->where('market_id', $wallet->market_id)
                ->first();

            if ($reservation === null) {
                throw new DomainException(
                    'Reservation belongs to another wallet or market'
                );
            }

            $lines = DB::table('loyalty_reservation_lines')
                ->where('reservation_id', $reservationId)
                ->pluck('units', 'grant_id');

            // Перевіряємо тільки фактично списані бонуси.
            $captured = DB::table('loyalty_ledger_entries')
                ->where('reservation_id', $reservationId)
                ->where('entry_type', 'reservation_captured')
                ->select('grant_id')
                ->selectRaw(
                    'COALESCE(SUM(-reserved_delta),0)::bigint AS units'
                )
                ->groupBy('grant_id')
                ->pluck('units', 'grant_id');

            $totalCaptured = 0;

            foreach ($lines as $grantId => $reservedUnits) {
                $capturedUnits = (int) (
                    $captured[$grantId] ?? 0
                );

                if ($capturedUnits !== (int) $reservedUnits) {
                    throw new DomainException(
                        'Reservation has not been fully captured'
                    );
                }

                $totalCaptured += $capturedUnits;
            }

            if (
                $totalCaptured !== (int) $reservation->units_total
            ) {
                throw new DomainException(
                    'Reservation capture does not reconcile'
                );
            }

            // Враховуємо всі попередні часткові повернення.
            $alreadyRefunded = DB::table('loyalty_redemption_refunds')
                ->where('reservation_id', $reservationId)
                ->select('grant_id')
                ->selectRaw(
                    'COALESCE(SUM(units),0)::bigint AS units'
                )
                ->groupBy('grant_id')
                ->pluck('units', 'grant_id');

            $now = CarbonImmutable::now('UTC');
            $operationId = (string) Str::uuid();

            $totalRefunded = 0;
            $totalDebtRepaid = 0;
            $items = [];

            $walletDebt = (int) DB::table('loyalty_ledger_entries')
                ->where('wallet_id', $walletId)
                ->sum('debt_delta');

            if ($walletDebt < 0) {
                throw new DomainException(
                    'Bonus debt balance is inconsistent'
                );
            }

            foreach ($allocations as $grantId => $units) {
                $notYetRefunded = (int) (
                        $captured[$grantId] ?? 0
                    ) - (int) (
                        $alreadyRefunded[$grantId] ?? 0
                    );

                if (
                    !isset($lines[$grantId])
                    || $units > $notYetRefunded
                ) {
                    throw new DomainException(
                        'Refund exceeds captured, not-yet-refunded bonus units'
                    );
                }

                $grant = DB::table('loyalty_grants')
                    ->where('id', $grantId)
                    ->where('wallet_id', $walletId)
                    ->where('market_id', $wallet->market_id)
                    ->first();

                if ($grant === null) {
                    throw new DomainException(
                        'Refund grant does not belong to wallet and market'
                    );
                }

                // Бонусний борг погашається лише тоді,
                // коли це дозволяють правила типу нарахування.
                $rules = json_decode(
                    $grant->rule_snapshot,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                $repaymentAllowed =
                    $rules['refund_repay_bonus_debt']
                    ?? ($grant->grant_type === 'purchase');

                if (!is_bool($repaymentAllowed)) {
                    throw new DomainException(
                        'Invalid refund debt repayment rule'
                    );
                }

                $repaid = $repaymentAllowed
                    ? min($walletDebt, $units)
                    : 0;

                $available = $units - $repaid;
                $walletDebt -= $repaid;

                // Визначаємо чинний строк дії партії.
                $lastExpiry = DB::table(
                    'loyalty_grant_expiry_adjustments'
                )
                    ->where('grant_id', $grantId)
                    ->orderByDesc('id')
                    ->first();

                $currentExpiry = $lastExpiry === null
                    ? $grant->expires_at
                    : $lastExpiry->new_expires_at;

                $isExpired = $currentExpiry !== null
                    && CarbonImmutable::parse(
                        $currentExpiry
                    ) <= $now;

                if ($available > 0 && $isExpired) {
                    $newExpiry = $extensions[$grantId] ?? null;

                    if (
                        $newExpiry === null
                        || CarbonImmutable::parse($newExpiry) <= $now
                    ) {
                        throw new DomainException(
                            'Approved future expiration required for restored expired bonuses'
                        );
                    }

                    // Не дозволяємо продовженню строку
                    // випадково оживити старі прострочені бонуси.
                    $state = DB::table('loyalty_ledger_entries')
                        ->where('grant_id', $grantId)
                        ->selectRaw(
                            'COALESCE(SUM(pending_delta),0)::bigint AS pending'
                        )
                        ->selectRaw(
                            'COALESCE(SUM(available_delta),0)::bigint AS available'
                        )
                        ->selectRaw(
                            'COALESCE(SUM(reserved_delta),0)::bigint AS reserved'
                        )
                        ->first();

                    $pending = (int) $state->pending;
                    $unreserved = (int) $state->available;
                    $reserved = (int) $state->reserved;

                    if (
                        $pending < 0
                        || $unreserved < 0
                        || $reserved < 0
                    ) {
                        throw new DomainException(
                            'Grant balance is inconsistent'
                        );
                    }

                    if ($reserved > 0) {
                        throw new DomainException(
                            'Resolve existing grant reservations before expiration extension'
                        );
                    }

                    // Прострочені незарезервовані одиниці
                    // списуються окремою незмінною проводкою.
                    if ($pending > 0 || $unreserved > 0) {
                        DB::table('loyalty_ledger_entries')->insert([
                            'wallet_id' => $walletId,
                            'market_id' => $wallet->market_id,
                            'grant_id' => $grantId,
                            'reservation_id' => null,
                            'operation_id' => $operationId,
                            'entry_key' => $operationKey
                                . ':expire-before-refund:'
                                . $grantId,
                            'entry_type' => 'grant_expiration',
                            'pending_delta' => -$pending,
                            'available_delta' => -$unreserved,
                            'reserved_delta' => 0,
                            'debt_delta' => 0,
                            'origin' =>
                                'loyalty_redemption_refund_service',
                            'rule_version' => $grant->rule_version,
                            'occurred_at' => $now,
                        ]);
                    }

                    // Зберігаємо новий строк без зміни
                    // початкового loyalty_grants.expires_at.
                    DB::table(
                        'loyalty_grant_expiry_adjustments'
                    )->insert([
                        'grant_id' => $grantId,
                        'wallet_id' => $walletId,
                        'market_id' => $wallet->market_id,
                        'adjustment_key' => $operationKey
                            . ':refund-expiry:'
                            . $grantId,
                        'old_expires_at' => $currentExpiry,
                        'new_expires_at' => $newExpiry,
                        'reason_code' => 'redemption_refund_grace',
                        'rule_version' => $grant->rule_version,
                        'actor_user_id' => null,
                        'context' => json_encode([
                            'operation_id' => $operationId,
                            'refund_reference' => $refundReference,
                            'policy' =>
                                $context['expiry_policy'] ?? null,
                        ], JSON_THROW_ON_ERROR),
                        'effective_at' => $now,
                    ]);
                } elseif (
                    array_key_exists($grantId, $extensions)
                ) {
                    throw new DomainException(
                        'Expiry override is not required for this refund allocation'
                    );
                }

                // Відновлюємо списані одиниці за початковою
                // партією, без повторного обчислення курсу.
                DB::table('loyalty_ledger_entries')->insert([
                    'wallet_id' => $walletId,
                    'market_id' => $wallet->market_id,
                    'grant_id' => $grantId,
                    'reservation_id' => $reservationId,
                    'operation_id' => $operationId,
                    'entry_key' => $operationKey
                        . ':refund:'
                        . $grantId,
                    'entry_type' => 'redemption_refund',
                    'pending_delta' => 0,
                    'available_delta' => $available,
                    'reserved_delta' => 0,
                    'debt_delta' => -$repaid,
                    'origin' =>
                        'loyalty_redemption_refund_service',
                    'rule_version' => $grant->rule_version,
                    'context' => json_encode([
                        'refund_reference' => $refundReference,
                        'original_reservation_id' => $reservationId,
                        'original_grant_id' => $grantId,
                        'original_units' => $units,
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => $now,
                ]);

                // Окрема історія повернення для перевірки,
                // скільки ще можна відновити.
                DB::table('loyalty_redemption_refunds')->insert([
                    'wallet_id' => $walletId,
                    'market_id' => $wallet->market_id,
                    'reservation_id' => $reservationId,
                    'grant_id' => $grantId,
                    'operation_id' => $operationId,
                    'refund_reference' => $refundReference,
                    'units' => $units,
                    'debt_repaid_units' => $repaid,
                    'context' => json_encode(
                        $context,
                        JSON_THROW_ON_ERROR
                    ),
                ]);

                $totalRefunded += $units;
                $totalDebtRepaid += $repaid;

                $items[] = [
                    'grant_id' => $grantId,
                    'restored_units' => $units,
                    'available_units' => $available,
                    'debt_repaid_units' => $repaid,
                ];
            }

            $result = [
                'reservation_id' => $reservationId,
                'refund_reference' => $refundReference,
                'refunded_units' => $totalRefunded,
                'debt_repaid_units' => $totalDebtRepaid,
                'available_units' =>
                    $totalRefunded - $totalDebtRepaid,
                'allocations' => $items,
                'operation_id' => $operationId,
            ];

            DB::table('loyalty_operations')->insert([
                'id' => $operationId,
                'wallet_id' => $walletId,
                'market_id' => $wallet->market_id,
                'operation_key' => $operationKey,
                'operation_type' => 'redemption_refund',
                'request_hash' => $hash,
                'result' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR
                ),
            ]);

            return $result;
        }, 3);
    }

    private function requiredText(
        string $value,
        string $field,
        int $maxLength
    ): void {
        if (
            trim($value) === ''
            || mb_strlen($value) > $maxLength
        ) {
            throw new InvalidArgumentException(
                "Invalid {$field}"
            );
        }
    }

    private function utc(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)
            ->utc()
            ->format('Y-m-d H:i:s.uP');
    }
}
