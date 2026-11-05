<?php

namespace App\Services\Pricing;

use App\Services\Inventory\InventoryService;
use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Promotions\PromotionReservationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Атомарне закриття підготовленого pending-order.
 *
 * Порядок:
 * order -> promotion -> inventory -> loyalty -> canceled.
 *
 * Не є HTTP API. Авторизація покупця та підтвердження рішення
 * про скасування належать довіреному OMS / checkout handler.
 */
final class CheckoutOrderCancellationService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PromotionReservationService $promotions,
        private readonly LoyaltyTransactionService $loyalty,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function cancel(
        string $orderKey,
        string $operationKey,
        string $reason = 'customer_cancel',
    ): array {
        if (!Str::isUuid($orderKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException(
                'Invalid order or cancellation UUID'
            );
        }

        if (!in_array($reason, [
            'customer_cancel',
            'merchant_cancel',
            'reservation_timeout',
        ], true)) {
            throw new InvalidArgumentException(
                'Invalid cancellation reason'
            );
        }

        $orderKey = strtolower($orderKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $orderKey,
            $operationKey,
            $reason
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

            $existing = DB::table('checkout_order_cancellations')
                ->where('order_id', $orderId)
                ->first();

            // Повторний запит не звільняє резерви вдруге.
            if ($existing !== null) {
                if (
                    $existing->operation_key !== $operationKey
                    || $existing->reason_code !== $reason
                ) {
                    throw new DomainException(
                        'Order already canceled by another operation'
                    );
                }

                return $this->read($orderId, $orderKey);
            }

            if ($order->payment_status !== 'pending') {
                throw new DomainException(
                    'Only pending orders can be canceled'
                );
            }

            $preparation = DB::table('checkout_order_preparations')
                ->where('order_id', $orderId)
                ->first();

            if ($preparation === null) {
                throw new DomainException(
                    'Checkout preparation not found'
                );
            }

            // Автоматичний timeout не може спрацювати раніше строку.
            if (
                $reason === 'reservation_timeout'
                && CarbonImmutable::parse($preparation->expires_at)
                    ->greaterThan(CarbonImmutable::now('UTC'))
            ) {
                throw new DomainException(
                    'Checkout preparation has not expired'
                );
            }

            // ========================================
            // 2. ПЕРЕВІРКА ФІНАНСОВОГО SNAPSHOT
            // ========================================

            $financial = DB::table('order_financial_snapshots')
                ->where('id', $preparation->financial_snapshot_id)
                ->where('order_id', $orderId)
                ->first(['snapshot_json']);

            if ($financial === null) {
                throw new DomainException(
                    'Checkout financial snapshot missing'
                );
            }

            try {
                $snapshot = json_decode(
                    $financial->snapshot_json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                throw new DomainException(
                    'Invalid checkout snapshot',
                    0,
                    $exception
                );
            }

            if (
                !is_array($snapshot)
                || !is_array($snapshot['lines'] ?? null)
                || $snapshot['lines'] === []
            ) {
                throw new DomainException(
                    'Invalid checkout snapshot lines'
                );
            }

            // ========================================
            // 3. ЗВІРКА ПРОМОКОДУ
            // ========================================

            $promotionRow = DB::table('promotion_reservations as r')
                ->join(
                    'promotion_codes as c',
                    'c.id',
                    '=',
                    'r.promotion_code_id'
                )
                ->where('r.order_id', $orderId)
                ->first([
                    'r.id',
                    'r.discount_minor',
                    'r.currency_code',
                    'c.code',
                ]);

            $expectedCode = $snapshot['promotion_code'] ?? null;
            $expectedPromo = $snapshot['promotion_discount_minor'] ?? null;

            if (
                ($promotionRow === null && $expectedCode !== null)
                || (
                    $promotionRow !== null
                    && (
                        $promotionRow->code !== $expectedCode
                        || (int) $promotionRow->discount_minor
                        !== $expectedPromo
                        || $promotionRow->currency_code
                        !== ($snapshot['currency_code'] ?? null)
                    )
                )
            ) {
                throw new DomainException(
                    'Promotion reservation does not match snapshot'
                );
            }

            // ========================================
            // 4. ЗВІЛЬНЕННЯ ПРОМОКОДУ
            // ========================================

            // Спочатку промокод: так зберігаємо той самий
            // порядок блокувань, що і в order preparation.
            $promotionReservationId = null;

            if ($promotionRow !== null) {
                $promotionReservationId = (int) $promotionRow->id;

                $promotion = $this->promotions->find(
                    $promotionReservationId
                );

                if ($promotion['status'] === 'captured') {
                    throw new DomainException(
                        'Captured promotion cannot be canceled'
                    );
                }

                if ($promotion['status'] !== 'released') {
                    $this->promotions->release(
                        $promotionReservationId,
                        $operationKey
                    );
                }
            }

            // ========================================
            // 5. ЗВІРКА СКЛАДСЬКИХ РЕЗЕРВІВ
            // ========================================

            $stocks = DB::table('checkout_order_stock_reservations as s')
                ->join(
                    'inventory_reservations as r',
                    'r.id',
                    '=',
                    's.inventory_reservation_id'
                )
                ->where(
                    's.checkout_preparation_id',
                    $preparation->id
                )
                ->orderBy('s.variant_id')
                ->get([
                    's.variant_id',
                    's.quantity as prepared_qty',
                    'r.order_id',
                    'r.reservation_key',
                    'r.quantity',
                    'r.shipped_qty',
                    'r.released_qty',
                ]);

            if (
                $stocks->isEmpty()
                || DB::table('inventory_reservations')
                    ->where('order_id', $orderId)
                    ->count() !== $stocks->count()
                || $stocks->count() !== count($snapshot['lines'])
            ) {
                throw new DomainException(
                    'Checkout stock reservation set is inconsistent'
                );
            }

            $snapshotQuantities = [];

            foreach ($snapshot['lines'] as $line) {
                if (
                    !is_array($line)
                    || !is_int($line['variant_id'] ?? null)
                    || !is_int($line['quantity'] ?? null)
                ) {
                    throw new DomainException(
                        'Invalid snapshot line quantities'
                    );
                }

                $id = $line['variant_id'];

                if (isset($snapshotQuantities[$id])) {
                    throw new DomainException(
                        'Duplicated snapshot variant'
                    );
                }

                $snapshotQuantities[$id] = $line['quantity'];
            }

            // ========================================
            // 6. ЗВІЛЬНЕННЯ СКЛАДСЬКИХ РЕЗЕРВІВ
            // ========================================

            $releasedStockQty = 0;

            foreach ($stocks as $stock) {
                $variantId = (int) $stock->variant_id;
                $reservedQty = (int) $stock->quantity;
                $shippedQty = (int) $stock->shipped_qty;
                $releasedQty = (int) $stock->released_qty;

                if (
                    (int) $stock->order_id !== $orderId
                    || (int) $stock->prepared_qty !== $reservedQty
                    || ($snapshotQuantities[$variantId] ?? null)
                    !== $reservedQty
                    || $shippedQty > 0
                    || $releasedQty > $reservedQty
                ) {
                    throw new DomainException(
                        'Stock reservation cannot be canceled safely'
                    );
                }

                $remaining = $reservedQty - $releasedQty;

                // Резерв міг раніше частково закінчитися.
                // Повторно вже звільнені одиниці не чіпаємо.
                if ($remaining > 0) {
                    $this->inventory->releaseOrderReservation(
                        $stock->reservation_key,
                        $remaining,
                        (string) Str::uuid()
                    );

                    $releasedStockQty += $remaining;
                }
            }

            // ========================================
            // 7. ПЕРЕВІРКА БОНУСНОГО РЕЗЕРВУ
            // ========================================

            $bonusReservationId = null;
            $releasedBonusUnits = 0;
            $expiredBonusUnits = 0;

            $expectedBonusUnits =
                (int) ($snapshot['loyalty_units_spent'] ?? 0);

            if (
                (int) $preparation->bonus_units_reserved
                !== $expectedBonusUnits
            ) {
                throw new DomainException(
                    'Bonus reservation does not match snapshot'
                );
            }

            if ($expectedBonusUnits > 0) {
                $walletId = (int) $preparation->loyalty_wallet_id;
                $bonusReservationId =
                    (int) $preparation->loyalty_reservation_id;

                if ($walletId <= 0 || $bonusReservationId <= 0) {
                    throw new DomainException(
                        'Bonus reservation reference missing'
                    );
                }

                $bonus = DB::table('loyalty_reservations')
                    ->where('id', $bonusReservationId)
                    ->first([
                        'wallet_id',
                        'order_reference',
                        'units_total',
                    ]);

                if (
                    $bonus === null
                    || (int) $bonus->wallet_id !== $walletId
                    || (int) $bonus->units_total
                    !== $expectedBonusUnits
                    || $bonus->order_reference
                    !== 'checkout-order:' . $orderKey
                ) {
                    throw new DomainException(
                        'Invalid checkout bonus reservation'
                    );
                }

                // ========================================
                // 8. ЗВІЛЬНЕННЯ БОНУСІВ
                // ========================================

                $openReserved = (int) DB::table(
                    'loyalty_ledger_entries'
                )
                    ->where(
                        'reservation_id',
                        $bonusReservationId
                    )
                    ->sum('reserved_delta');

                if (
                    $openReserved < 0
                    || $openReserved > $expectedBonusUnits
                ) {
                    throw new DomainException(
                        'Inconsistent bonus reservation balance'
                    );
                }

                if ($openReserved > 0) {
                    $released = $this->loyalty->releaseReservation(
                        $walletId,
                        $bonusReservationId,
                        'checkout-cancel:' . $orderKey
                    );

                    $releasedBonusUnits =
                        (int) $released['released_units'];

                    $expiredBonusUnits =
                        (int) $released['expired_units'];
                } else {
                    // Якщо резерв уже закрито, він не повинен
                    // бути списаний на користь оплати.
                    $wasCaptured = DB::table('loyalty_ledger_entries')
                        ->where(
                            'reservation_id',
                            $bonusReservationId
                        )
                        ->where(
                            'entry_type',
                            'reservation_captured'
                        )
                        ->exists();

                    if ($wasCaptured) {
                        throw new DomainException(
                            'Captured bonuses cannot be canceled'
                        );
                    }
                }
            } elseif (
                $preparation->loyalty_wallet_id !== null
                || $preparation->loyalty_reservation_id !== null
            ) {
                throw new DomainException(
                    'Unexpected bonus reservation references'
                );
            }

            // ========================================
            // 9. ТЕРМІНАЛЬНИЙ СТАТУС ЗАМОВЛЕННЯ
            // ========================================

            DB::table('orders')
                ->where('id', $orderId)
                ->update([
                    'payment_status' => 'canceled',
                    'updated_at' => now(),
                ]);

            // ========================================
            // 10. НЕЗМІННА ІСТОРІЯ СКАСУВАННЯ
            // ========================================

            DB::table('checkout_order_cancellations')->insert([
                'order_id' => $orderId,
                'operation_key' => $operationKey,
                'reason_code' => $reason,
                'released_stock_qty' => $releasedStockQty,
                'promotion_reservation_id' => $promotionReservationId,
                'loyalty_reservation_id' => $bonusReservationId,
                'released_bonus_units' => $releasedBonusUnits,
                'expired_bonus_units' => $expiredBonusUnits,
            ]);

            return $this->read($orderId, $orderKey);
        }, 3);
    }

    /**
     * @return array<string,mixed>
     */
    private function read(int $orderId, string $orderKey): array
    {
        $row = DB::table('checkout_order_cancellations')
            ->where('order_id', $orderId)
            ->first();

        if ($row === null) {
            throw new DomainException(
                'Checkout cancellation record missing'
            );
        }

        return [
            'order_id' => $orderId,
            'order_key' => $orderKey,
            'payment_status' => 'canceled',
            'operation_key' => (string) $row->operation_key,
            'reason_code' => (string) $row->reason_code,
            'released_stock_qty' => (int) $row->released_stock_qty,
            'promotion_reservation_id' =>
                $row->promotion_reservation_id === null
                    ? null
                    : (int) $row->promotion_reservation_id,
            'loyalty_reservation_id' =>
                $row->loyalty_reservation_id === null
                    ? null
                    : (int) $row->loyalty_reservation_id,
            'released_bonus_units' => (int) $row->released_bonus_units,
            'expired_bonus_units' => (int) $row->expired_bonus_units,
            'created_at' => (string) $row->created_at,
        ];
    }
}
