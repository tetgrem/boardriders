<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Внутрішня заявка на післяплату.
 *
 * Не підтверджує відправку, не списує бонуси,
 * не змінює payment_status і не продовжує резерви.
 *
 * Реальна відправка потребує окремого логістичного процесу.
 */
final class CheckoutCashOnDeliveryRequestService
{
    public function __construct(
        private readonly OrderFinancialSnapshotService $snapshots,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function request(
        string $orderKey,
        string $requestKey,
    ): array {
        $orderKey = strtolower(trim($orderKey));
        $requestKey = strtolower(trim($requestKey));

        if (
            !Str::isUuid($orderKey)
            || !Str::isUuid($requestKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid COD order or request UUID'
            );
        }

        return DB::transaction(function () use (
            $orderKey,
            $requestKey
        ): array {
            // ========================================
            // 1. БЛОКУВАННЯ ЗАМОВЛЕННЯ
            // ========================================

            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null) {
                throw new DomainException('Order not found');
            }

            $orderId = (int) $order->id;

            // ========================================
            // 2. ІДЕМПОТЕНТНІСТЬ
            // ========================================

            $existing = DB::table('checkout_cod_requests')
                ->where('order_id', $orderId)
                ->first();

            if ($existing !== null) {
                if ($existing->request_key !== $requestKey) {
                    throw new DomainException(
                        'Order already has a different COD request'
                    );
                }

                return $this->present(
                    $existing,
                    $orderKey
                );
            }

            if (
                DB::table('checkout_cod_requests')
                    ->where('request_key', $requestKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'COD request key belongs to another order'
                );
            }

            // ========================================
            // 3. ПЕРЕВІРКА ПІДГОТОВКИ
            // ========================================

            $preparation = DB::table(
                'checkout_order_preparations'
            )
                ->where('order_id', $orderId)
                ->first();

            $now = CarbonImmutable::now('UTC');

            if (
                $order->payment_status !== 'pending'
                || $preparation === null
                || CarbonImmutable::parse(
                    $preparation->expires_at
                )->lessThanOrEqualTo($now)
            ) {
                throw new DomainException(
                    'COD checkout is no longer available'
                );
            }

            // ========================================
            // 4. СПОСІБ ОПЛАТИ
            // ========================================

            $choice = DB::table('checkout_payment_choices')
                ->where('order_id', $orderId)
                ->first();

            if (
                $choice === null
                || $choice->payment_method !== 'cash_on_delivery'
                || $choice->provider_code !== null
            ) {
                throw new DomainException(
                    'Cash on delivery was not selected'
                );
            }

            // Для COD не повинно існувати жодної
            // онлайн-спроби або отриманого capture.
            if (
                DB::table('checkout_payment_attempts')
                    ->where('order_id', $orderId)
                    ->exists()
                || DB::table(
                    'checkout_payment_capture_receipts'
                )
                    ->where('order_id', $orderId)
                    ->exists()
            ) {
                throw new DomainException(
                    'COD conflicts with online payment'
                );
            }

            // ========================================
            // 5. НЕЗМІННИЙ ФІНАНСОВИЙ SNAPSHOT
            // ========================================

            $financial = $this->snapshots->findForOrder(
                $orderId
            );

            if (
                $financial === null
                || $financial['id'] !==
                (int) $preparation->financial_snapshot_id
            ) {
                throw new DomainException(
                    'COD financial snapshot mismatch'
                );
            }

            $amount = (int) $financial['goods_payable_minor'];
            $currency = (string) $financial['currency_code'];
            $sha256 = (string) $financial['sha256'];

            if (
                $amount < 0
                || !preg_match('/^[A-Z]{3}$/D', $currency)
                || !preg_match('/^[a-f0-9]{64}$/D', $sha256)
            ) {
                throw new DomainException(
                    'Invalid COD financial snapshot'
                );
            }

            // ========================================
            // 6. ПЕРЕВІРКА ТОВАРНИХ РЕЗЕРВІВ
            // ========================================

            $stockLinks = DB::table(
                'checkout_order_stock_reservations'
            )
                ->where(
                    'checkout_preparation_id',
                    $preparation->id
                )
                ->orderBy('inventory_reservation_id')
                ->get([
                    'inventory_reservation_id',
                    'quantity',
                ]);

            if ($stockLinks->isEmpty()) {
                throw new DomainException(
                    'COD order has no stock reservations'
                );
            }

            $reservations = DB::table(
                'inventory_reservations'
            )
                ->where('order_id', $orderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'quantity',
                    'shipped_qty',
                    'released_qty',
                    'expires_at',
                ]);

            if (
                $reservations->count() !==
                $stockLinks->count()
            ) {
                throw new DomainException(
                    'COD stock reservation count mismatch'
                );
            }

            $byId = $reservations->keyBy('id');

            foreach ($stockLinks as $link) {
                $reservation = $byId->get(
                    $link->inventory_reservation_id
                );

                if (
                    $reservation === null
                    || (int) $reservation->quantity
                    !== (int) $link->quantity
                    || (int) $reservation->shipped_qty !== 0
                    || (int) $reservation->released_qty !== 0
                    || $reservation->expires_at === null
                    || CarbonImmutable::parse(
                        $reservation->expires_at
                    )->lessThanOrEqualTo($now)
                ) {
                    throw new DomainException(
                        'COD stock reservation is no longer active'
                    );
                }
            }

            // ========================================
            // 7. ЗБЕРЕЖЕННЯ ЗАЯВКИ
            // ========================================

            $id = (int) DB::table(
                'checkout_cod_requests'
            )->insertGetId([
                'request_key' => $requestKey,
                'order_id' => $orderId,
                'payment_choice_id' => $choice->id,
                'financial_snapshot_id' => $financial['id'],
                'goods_due_minor' => $amount,
                'currency_code' => $currency,
                'snapshot_sha256' => $sha256,
                'checkout_expires_at' => $preparation->expires_at,
            ]);

            $saved = DB::table('checkout_cod_requests')
                ->where('id', $id)
                ->first();

            if ($saved === null) {
                throw new DomainException(
                    'COD request was not saved'
                );
            }

            return $this->present($saved, $orderKey);
        }, 3);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException(
                'Invalid order ID'
            );
        }

        $row = DB::table('checkout_cod_requests')
            ->where('order_id', $orderId)
            ->first();

        if ($row === null) {
            return null;
        }

        $orderKey = DB::table('orders')
            ->where('id', $orderId)
            ->value('order_key');

        if (!is_string($orderKey)) {
            throw new DomainException(
                'COD order not found'
            );
        }

        return $this->present($row, $orderKey);
    }

    /**
     * @return array<string,mixed>
     */
    private function present(
        object $row,
        string $orderKey
    ): array {
        $paymentStatus = DB::table('orders')
            ->where('id', $row->order_id)
            ->value('payment_status');

        $expired = CarbonImmutable::parse(
            $row->checkout_expires_at
        )->lessThanOrEqualTo(
            CarbonImmutable::now('UTC')
        );

        $status = match (true) {
            $paymentStatus === 'canceled' => 'canceled',
            $paymentStatus !== 'pending' => 'not_pending',
            $expired => 'expired',
            default => 'awaiting_logistics',
        };

        return [
            'id' => (int) $row->id,
            'request_key' => (string) $row->request_key,
            'order_id' => (int) $row->order_id,
            'order_key' => $orderKey,
            'payment_method' => 'cash_on_delivery',
            'payment_status' => (string) $paymentStatus,
            'status' => $status,
            'goods_due_minor' => (int) $row->goods_due_minor,
            'currency_code' => (string) $row->currency_code,
            'snapshot_sha256' => (string) $row->snapshot_sha256,
            'checkout_expires_at' =>
                (string) $row->checkout_expires_at,

            // Не можна відправляти до впровадження
            // довгострокового COD fulfillment.
            'ready_for_dispatch' => false,
            'requires_online_capture' => false,
            'shipping_amount_finalized' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
