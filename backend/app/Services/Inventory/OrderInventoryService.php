<?php

namespace App\Services\Inventory;

use App\Models\InventoryReservation;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Minimal order/payment boundary for inventory.
 * No HTTP route must expose registerCapturedPayment() to customers.
 * It must only be called by a verified payment provider event handler.
 */
class OrderInventoryService
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function createPendingOrder(string $orderKey): int
    {
        $orderKey = $this->normalizeKey($orderKey);

        return DB::transaction(function () use ($orderKey): int {
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['order-create:' . $orderKey]
            );

            $existing = DB::table('orders')
                ->where('order_key', $orderKey)
                ->first();

            if ($existing !== null) {
                return (int) $existing->id;
            }

            return DB::table('orders')->insertGetId([
                'order_key' => $orderKey,
                'payment_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    /**
     * Reserves one SKU for a specific pending order.
     * The order row is locked first, matching the expiration lock order.
     */
    public function reserveLine(
        string $orderKey,
        int $warehouseId,
        int $variantId,
        int $quantity,
        string $reference,
        string $reservationKey,
        DateTimeInterface $expiresAt
    ): InventoryReservation {
        $orderKey = $this->normalizeKey($orderKey);

        return DB::transaction(function () use (
            $orderKey,
            $warehouseId,
            $variantId,
            $quantity,
            $reference,
            $reservationKey,
            $expiresAt
        ): InventoryReservation {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new DomainException('Замовлення не знайдено.');
            }

            if ($order->payment_status !== 'pending') {
                throw new DomainException(
                    'Для оплаченого або проблемного замовлення резерв змінювати не можна.'
                );
            }

            $reservation = $this->inventory->reserveForOrder(
                $warehouseId,
                $variantId,
                $quantity,
                $reference,
                $reservationKey,
                $expiresAt
            );

            $reservation = InventoryReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $reservation->order_id !== null
                && (int) $reservation->order_id !== (int) $order->id
            ) {
                throw new InvalidArgumentException(
                    'Резервування вже належить іншому замовленню.'
                );
            }

            if (
                $reservation->active_qty !== $quantity
                || $reservation->shipped_qty > 0
                || $reservation->released_qty > 0
                || $reservation->expires_at === null
                || $reservation->expires_at->getTimestamp() <= now()->getTimestamp()
            ) {
                throw new DomainException(
                    'Резервування вже змінено або строк його дії минув.'
                );
            }

            if ($reservation->order_id === null) {
                $reservation->order_id = (int) $order->id;
                $reservation->save();
            }

            return $reservation->refresh();
        }, 3);
    }

    /**
     * Call ONLY after verified payment capture from an authenticated provider.
     * An expired/released reservation enters review instead of being treated
     * as a paid order with guaranteed stock.
     *
     * Returns 'paid' or 'review'.
     */
    public function registerCapturedPayment(string $orderKey): string
    {
        $orderKey = $this->normalizeKey($orderKey);

        return DB::transaction(function () use ($orderKey): string {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new DomainException('Замовлення не знайдено.');
            }

            // Нові checkout-orders мають проходити тільки через
// CheckoutPaymentFinalizationService, де перевіряються
// сума, валюта, промокод і бонусний резерв.
            if (
                DB::table('checkout_order_preparations')
                    ->where('order_id', $order->id)
                    ->exists()
            ) {
                throw new DomainException(
                    'Prepared checkout requires verified payment finalization'
                );
            }

            if ($order->payment_status === 'paid') {
                return 'paid';
            }

            if ($order->payment_status === 'review') {
                return 'review';
            }

            if ($order->payment_status !== 'pending') {
                throw new DomainException('Неприпустимий стан оплати.');
            }

            $reservations = InventoryReservation::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($reservations->isEmpty()) {
                throw new DomainException(
                    'Замовлення не має складських резервувань.'
                );
            }

            $valid = $reservations->every(
                fn (InventoryReservation $reservation): bool =>
                    $reservation->active_qty > 0
                    && $reservation->shipped_qty === 0
                    && $reservation->released_qty === 0
                    && $reservation->expires_at !== null
                    && $reservation->expires_at->getTimestamp() > now()->getTimestamp()
            );

            $status = $valid ? 'paid' : 'review';

            DB::table('orders')
                ->where('id', $order->id)
                ->update([
                    'payment_status' => $status,
                    'updated_at' => now(),
                ]);

            return $status;
        }, 3);
    }

    private function normalizeKey(string $key): string
    {
        if (!Str::isUuid($key)) {
            throw new InvalidArgumentException('Некоректний UUID замовлення.');
        }

        return strtolower($key);
    }
}
