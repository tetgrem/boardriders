<?php

namespace App\Services\Promotions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Атомарне резервування квот промокодів.
 *
 * Усі фінальні списання промокодів повинні використовувати
 * той самий механізм блокування promotion row.
 *
 * Сервіс внутрішній: не створює замовлення і не перевіряє
 * платіжну подію. Виклик лише з довіреного checkout / OMS.
 */
final class PromotionReservationService
{
    public function __construct(
        private readonly PromotionService $promotions,
    ) {
    }

    /**
     * @param array<int,array{variant_id:int,quantity:int}> $items
     * @return array<string,mixed>
     */
    public function reserve(
        int $orderId,
        string $reservationKey,
        string $promotionCode,
        array $items,
        string $priceListCode,
        DateTimeInterface $expiresAt,
        ?int $userId = null,
    ): array {
        if ($orderId <= 0 || ($userId !== null && $userId <= 0)) {
            throw new InvalidArgumentException('Invalid order or customer');
        }

        $reservationKey = strtolower(trim($reservationKey));
        $promotionCode = strtoupper(trim($promotionCode));
        $priceListCode = trim($priceListCode);

        if (!Str::isUuid($reservationKey)
            || !preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/D', $promotionCode)
            || $priceListCode === '' || strlen($priceListCode) > 80
            || $items === [] || count($items) > 200
        ) {
            throw new InvalidArgumentException('Invalid promotion reservation request');
        }

        $quantities = [];

        foreach ($items as $item) {
            if (!is_array($item)
                || !isset($item['variant_id'], $item['quantity'])
                || !is_int($item['variant_id']) || $item['variant_id'] <= 0
                || !is_int($item['quantity']) || $item['quantity'] < 1
                || $item['quantity'] > 99
                || isset($quantities[$item['variant_id']])
            ) {
                throw new InvalidArgumentException('Invalid or duplicate promotion item');
            }

            $quantities[$item['variant_id']] = $item['quantity'];
        }

        ksort($quantities, SORT_NUMERIC);

        $normalizedItems = [];

        foreach ($quantities as $variantId => $quantity) {
            $normalizedItems[] = [
                'variant_id' => (int) $variantId,
                'quantity' => $quantity,
            ];
        }

        $expires = CarbonImmutable::instance($expiresAt)->utc();

        if ($expires->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new InvalidArgumentException(
                'Promotion reservation expiry must be future'
            );
        }

        // Однакові параметри => однаковий fingerprint.
        $requestHash = hash('sha256', json_encode([
            'promotion-reserve-v1',
            $orderId,
            $reservationKey,
            $promotionCode,
            $normalizedItems,
            $priceListCode,
            $userId,
            $expires->format('Y-m-d H:i:s.uP'),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $orderId,
            $reservationKey,
            $promotionCode,
            $normalizedItems,
            $priceListCode,
            $userId,
            $expires,
            $requestHash
        ): array {
            // Порядок блокування: order -> promotion -> code.
            $order = DB::table('orders')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null) {
                throw new DomainException('Order does not exist');
            }

            // Ідемпотентний повтор не створює другий резерв.
            $existing = DB::table('promotion_reservations')
                ->where('order_id', $orderId)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->reservation_key !== $reservationKey
                    || !hash_equals(
                        (string) $existing->request_sha256,
                        $requestHash
                    )
                ) {
                    throw new DomainException(
                        'Order has a different promotion reservation'
                    );
                }

                return $this->read((int) $existing->id);
            }

            if ($order->payment_status !== 'pending') {
                throw new DomainException(
                    'Promotion requires a pending order'
                );
            }

            if (
                DB::table('promotion_reservations')
                    ->where('reservation_key', $reservationKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Reservation key belongs to another order'
                );
            }

            $codeLookup = DB::table('promotion_codes')
                ->where('code', $promotionCode)
                ->first(['id', 'promotion_id']);

            if ($codeLookup === null) {
                throw new DomainException('Promotion code not found');
            }

            // Блокуємо акцію для всіх її промокодів.
            // Це серіалізує рішення щодо загального ліміту.
            $promotion = DB::table('promotions')
                ->where('id', $codeLookup->promotion_id)
                ->lockForUpdate()
                ->first([
                    'id',
                    'price_list_id',
                    'is_active',
                    'starts_at',
                    'ends_at',
                    'usage_limit',
                    'per_customer_limit',
                ]);

            $code = DB::table('promotion_codes')
                ->where('id', $codeLookup->id)
                ->lockForUpdate()
                ->first([
                    'id',
                    'promotion_id',
                    'code',
                    'is_active',
                    'usage_limit',
                ]);

            if (
                $promotion === null
                || $code === null
                || (int) $code->promotion_id !== (int) $promotion->id
                || $code->code !== $promotionCode
                || !$this->enabled($promotion->is_active)
                || !$this->enabled($code->is_active)
            ) {
                throw new DomainException('Promotion is unavailable');
            }

            $list = DB::table('price_lists as pl')
                ->join(
                    'currencies as c',
                    'c.code',
                    '=',
                    'pl.currency_code'
                )
                ->where('pl.code', $priceListCode)
                ->where('pl.type', 'retail')
                ->where('pl.is_active', true)
                ->where('c.is_active', true)
                ->first(['pl.id', 'pl.currency_code']);

            if (
                $list === null
                || (int) $promotion->price_list_id !== (int) $list->id
            ) {
                throw new DomainException(
                    'Promotion price list mismatch'
                );
            }

            $now = CarbonImmutable::now('UTC');

            if (
                (
                    $promotion->starts_at !== null
                    && CarbonImmutable::parse($promotion->starts_at)
                        ->greaterThan($now)
                )
                || (
                    $promotion->ends_at !== null
                    && CarbonImmutable::parse($promotion->ends_at)
                        ->lessThanOrEqualTo($now)
                )
            ) {
                throw new DomainException(
                    'Promotion is outside its validity period'
                );
            }

            if ($expires->lessThanOrEqualTo($now)) {
                throw new DomainException(
                    'Reservation expired during calculation'
                );
            }

            // Повторно рахуємо знижку за актуальними
            // цінами та залишками всередині транзакції.
            //
            // Checkout перед цим також повинен заблокувати
            // рядки variant_prices.
            $quote = $this->promotions->preview(
                $promotionCode,
                $normalizedItems,
                $userId,
                $priceListCode
            );

            if (
                ($quote['valid'] ?? false) !== true
                || ($quote['code'] ?? null) !== $promotionCode
                || ($quote['currency'] ?? null) !== $list->currency_code
                || !is_int($quote['discount_minor'] ?? null)
                || $quote['discount_minor'] <= 0
            ) {
                throw new DomainException(
                    'Promotion preview failed: '
                    . ($quote['reason'] ?? 'invalid_result')
                );
            }

            $promotionId = (int) $promotion->id;
            $codeId = (int) $code->id;

            // ========================================
            // 1. ЗАГАЛЬНИЙ ЛІМІТ АКЦІЇ
            // ========================================

            $this->checkLimit(
                $promotion->usage_limit,
                $this->redemptions($promotionId, null, null)
                + $this->pending($promotionId, null, null, $now),
                'Promotion usage limit reached'
            );

            // ========================================
            // 2. ЛІМІТ КОНКРЕТНОГО ПРОМОКОДУ
            // ========================================

            $this->checkLimit(
                $code->usage_limit,
                $this->redemptions($promotionId, $codeId, null)
                + $this->pending($promotionId, $codeId, null, $now),
                'Promotion code usage limit reached'
            );

            // ========================================
            // 3. ЛІМІТ НА ПОКУПЦЯ
            // ========================================

            if ($promotion->per_customer_limit !== null) {
                if ($userId === null) {
                    throw new DomainException(
                        'Promotion requires authenticated customer'
                    );
                }

                $this->checkLimit(
                    $promotion->per_customer_limit,
                    $this->redemptions($promotionId, null, $userId)
                    + $this->pending($promotionId, null, $userId, $now),
                    'Customer promotion limit reached'
                );
            }

            // Резервуємо одну доступну квоту.
            $id = (int) DB::table('promotion_reservations')
                ->insertGetId([
                    'reservation_key' => $reservationKey,
                    'order_id' => $orderId,
                    'promotion_id' => $promotionId,
                    'promotion_code_id' => $codeId,
                    'user_id' => $userId,
                    'currency_code' => $list->currency_code,
                    'discount_minor' => $quote['discount_minor'],
                    'request_sha256' => $requestHash,
                    'expires_at' => $expires,
                ]);

            return $this->read($id);
        }, 3);
    }

    /**
     * Викликати тільки після верифікованої платіжної події.
     *
     * @return array<string,mixed>
     */
    public function capture(
        int $reservationId,
        string $operationKey,
        ?DateTimeInterface $confirmedPaidAt = null
    ): array {
        return $this->finish(
            $reservationId,
            $operationKey,
            'captured',
            $confirmedPaidAt
        );
    }

    /**
     * Звільнити квоту скасованого pending-замовлення.
     *
     * @return array<string,mixed>
     */
    public function release(
        int $reservationId,
        string $operationKey
    ): array {
        return $this->finish(
            $reservationId,
            $operationKey,
            'released'
        );
    }

    /** @return array<string,mixed> */
    private function finish(
        int $reservationId,
        string $operationKey,
        string $type,
        ?DateTimeInterface $confirmedPaidAt = null
    ): array {
        $operationKey = strtolower(trim($operationKey));

        $paidAt = $confirmedPaidAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($confirmedPaidAt)->utc();

        if (
            $reservationId <= 0
            || !Str::isUuid($operationKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid promotion operation'
            );
        }

        return DB::transaction(function () use (
            $reservationId,
            $operationKey,
            $type,
            $paidAt
        ): array {
            // Дані резерву незмінні. Спочатку читаємо
            // ідентифікатори для правильного порядку блокувань.
            $reference = DB::table('promotion_reservations')
                ->where('id', $reservationId)
                ->first(['order_id', 'promotion_id']);

            if ($reference === null) {
                throw new DomainException(
                    'Promotion reservation not found'
                );
            }

            $order = DB::table('orders')
                ->where('id', $reference->order_id)
                ->lockForUpdate()
                ->first(['payment_status']);

            DB::table('promotions')
                ->where('id', $reference->promotion_id)
                ->lockForUpdate()
                ->first(['id']);

            $reservation = DB::table('promotion_reservations')
                ->where('id', $reservationId)
                ->lockForUpdate()
                ->first();

            if ($order === null || $reservation === null) {
                throw new DomainException(
                    'Order or promotion reservation missing'
                );
            }

            $closed = DB::table('promotion_reservation_events')
                ->where('reservation_id', $reservationId)
                ->first();

            // Одна резервна квота може завершитися лише раз.
            if ($closed !== null) {
                if (
                    $closed->event_type !== $type
                    || $closed->operation_key !== $operationKey
                ) {
                    throw new DomainException(
                        'Promotion reservation already finalized'
                    );
                }

                return $this->read($reservationId);
            }

            if (
                DB::table('promotion_reservation_events')
                    ->where('operation_key', $operationKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Promotion operation key already used'
                );
            }

            if ($type === 'captured') {
                // Статус paid має виставляти лише перевірений
                // обробник платіжної події.
                if ($order->payment_status !== 'paid') {
                    throw new DomainException(
                        'Promotion capture requires paid order'
                    );
                }

                // Перевіряємо момент фактичної оплати,
// а не момент отримання webhook.
                if (
                    $paidAt->lessThan(
                        CarbonImmutable::parse($reservation->created_at)
                    )
                    || $paidAt->greaterThanOrEqualTo(
                        CarbonImmutable::parse($reservation->expires_at)
                    )
                    || $paidAt->greaterThan(CarbonImmutable::now('UTC'))
                ) {
                    throw new DomainException(
                        'Promotion capture is outside reservation validity'
                    );
                }

                // Історія використання поповнюється лише
                // після успішної підтвердженої оплати.
                $redemptionId = (int) DB::table(
                    'promotion_redemptions'
                )->insertGetId([
                    'redemption_key' => $operationKey,
                    'promotion_code_id' =>
                        $reservation->promotion_code_id,
                    'order_id' => $reservation->order_id,
                    'user_id' => $reservation->user_id,
                    'discount_minor' => $reservation->discount_minor,
                    'currency_code' => $reservation->currency_code,
                ]);
            } else {
                if ($order->payment_status !== 'pending') {
                    throw new DomainException(
                        'Cannot release promotion of a paid/review order'
                    );
                }

                $redemptionId = null;
            }

            DB::table('promotion_reservation_events')->insert([
                'reservation_id' => $reservationId,
                'operation_key' => $operationKey,
                'event_type' => $type,
                'redemption_id' => $redemptionId,
            ]);

            return $this->read($reservationId);
        }, 3);
    }

    /** @return array<string,mixed>|null */
    public function find(int $reservationId): ?array
    {
        if ($reservationId <= 0) {
            throw new InvalidArgumentException(
                'Invalid promotion reservation ID'
            );
        }

        return DB::table('promotion_reservations')
            ->where('id', $reservationId)
            ->exists()
            ? $this->read($reservationId)
            : null;
    }

    /** @return array<string,mixed> */
    private function read(int $reservationId): array
    {
        $row = DB::table('promotion_reservations as r')
            ->join(
                'promotion_codes as c',
                'c.id',
                '=',
                'r.promotion_code_id'
            )
            ->where('r.id', $reservationId)
            ->first([
                'r.id',
                'r.reservation_key',
                'r.order_id',
                'r.promotion_id',
                'r.promotion_code_id',
                'r.user_id',
                'r.currency_code',
                'r.discount_minor',
                'r.request_sha256',
                'r.expires_at',
                'c.code',
            ]);

        if ($row === null) {
            throw new DomainException(
                'Promotion reservation not found'
            );
        }

        $event = DB::table('promotion_reservation_events')
            ->where('reservation_id', $reservationId)
            ->first();

        $status = $event?->event_type ?? (
        CarbonImmutable::parse($row->expires_at)
            ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ? 'expired'
            : 'reserved'
        );

        return [
            'reservation_id' => (int) $row->id,
            'reservation_key' => (string) $row->reservation_key,
            'order_id' => (int) $row->order_id,
            'promotion_id' => (int) $row->promotion_id,
            'promotion_code_id' => (int) $row->promotion_code_id,
            'promotion_code' => (string) $row->code,
            'user_id' => $row->user_id === null
                ? null
                : (int) $row->user_id,
            'currency_code' => (string) $row->currency_code,
            'discount_minor' => (int) $row->discount_minor,
            'request_sha256' => (string) $row->request_sha256,
            'expires_at' => (string) $row->expires_at,
            'status' => $status,
            'redemption_id' => $event?->redemption_id === null
                ? null
                : (int) $event->redemption_id,
        ];
    }

    /**
     * Кількість активних незавершених резервів.
     */
    private function pending(
        int $promotionId,
        ?int $codeId,
        ?int $userId,
        CarbonImmutable $now
    ): int {
        $query = DB::table('promotion_reservations as r')
            ->where('r.promotion_id', $promotionId)
            ->where('r.expires_at', '>', $now)
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('promotion_reservation_events as e')
                    ->whereColumn(
                        'e.reservation_id',
                        'r.id'
                    );
            });

        if ($codeId !== null) {
            $query->where('r.promotion_code_id', $codeId);
        }

        if ($userId !== null) {
            $query->where('r.user_id', $userId);
        }

        return (int) $query->count();
    }

    /**
     * Кількість уже підтверджених використань.
     */
    private function redemptions(
        int $promotionId,
        ?int $codeId,
        ?int $userId
    ): int {
        $query = DB::table('promotion_redemptions as r')
            ->join(
                'promotion_codes as c',
                'c.id',
                '=',
                'r.promotion_code_id'
            )
            ->where('c.promotion_id', $promotionId);

        if ($codeId !== null) {
            $query->where('r.promotion_code_id', $codeId);
        }

        if ($userId !== null) {
            $query->where('r.user_id', $userId);
        }

        return (int) $query->count();
    }

    private function checkLimit(
        mixed $limit,
        int $used,
        string $message
    ): void {
        if (
            $limit !== null
            && $used >= (int) $limit
        ) {
            throw new DomainException($message);
        }
    }

    private function enabled(mixed $value): bool
    {
        return in_array(
            $value,
            [true, 1, '1', 't', 'true'],
            true
        );
    }
}
