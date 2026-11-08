<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records an UNVERIFIED Nova Poshta delivery preference for a COD checkout.
 * Does not request a shipping label, extend reservations, ship inventory,
 * calculate delivery fees, or mark the order paid.
 * Only trusted checkout code should call this after customer authorization.
 */
final class CheckoutCodDeliverySelectionService
{
    public function __construct(
        private readonly OrderFinancialSnapshotService $snapshots,
    ) {
    }

    /** @return array<string,mixed> */
    public function select(
        string $orderKey,
        string $selectionKey,
        string $deliveryType,
        string $destinationRef,
        string $carrierCode = 'nova_poshta',
    ): array {
        $orderKey = strtolower(trim($orderKey));
        $selectionKey = strtolower(trim($selectionKey));
        $deliveryType = strtolower(trim($deliveryType));
        $destinationRef = strtolower(trim($destinationRef));
        $carrierCode = strtolower(trim($carrierCode));

        if (!Str::isUuid($orderKey) || !Str::isUuid($selectionKey)) {
            throw new InvalidArgumentException('Invalid COD delivery order/selection UUID');
        }
        if (
            $carrierCode !== 'nova_poshta'
            || !in_array($deliveryType, ['branch', 'parcel_locker'], true)
            || !Str::isUuid($destinationRef)
        ) {
            throw new InvalidArgumentException('Unsupported or invalid COD delivery destination');
        }

        return DB::transaction(function () use (
            $orderKey, $selectionKey, $deliveryType, $destinationRef, $carrierCode
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null) {
                throw new DomainException('COD order not found');
            }
            $orderId = (int) $order->id;

            $existing = DB::table('checkout_cod_delivery_selections')
                ->where('order_id', $orderId)->first();
            if ($existing !== null) {
                if (
                    $existing->selection_key !== $selectionKey
                    || $existing->carrier_code !== $carrierCode
                    || $existing->delivery_type !== $deliveryType
                    || strtolower((string) $existing->destination_ref) !== $destinationRef
                ) {
                    throw new DomainException('Order already has a different delivery selection');
                }
                return $this->present($existing, $orderKey, (string) $order->payment_status);
            }

            if (DB::table('checkout_cod_delivery_selections')
                ->where('selection_key', $selectionKey)->exists()
            ) {
                throw new DomainException('Delivery selection key belongs to another order');
            }

            $cod = DB::table('checkout_cod_requests')
                ->where('order_id', $orderId)->first();
            $prep = DB::table('checkout_order_preparations')
                ->where('order_id', $orderId)->first();
            $now = CarbonImmutable::now('UTC');

            if (
                $order->payment_status !== 'pending'
                || $cod === null || $prep === null
                || CarbonImmutable::parse($prep->expires_at)->lessThanOrEqualTo($now)
                || !CarbonImmutable::parse($cod->checkout_expires_at)
                    ->equalTo(CarbonImmutable::parse($prep->expires_at))
            ) {
                throw new DomainException('COD checkout is no longer available');
            }

            $choice = DB::table('checkout_payment_choices')
                ->where('id', $cod->payment_choice_id)
                ->where('order_id', $orderId)
                ->first();
            if (
                $choice === null
                || $choice->payment_method !== 'cash_on_delivery'
                || $choice->provider_code !== null
                || DB::table('checkout_payment_attempts')->where('order_id', $orderId)->exists()
                || DB::table('checkout_payment_capture_receipts')->where('order_id', $orderId)->exists()
            ) {
                throw new DomainException('COD conflicts with online payment');
            }

            $financial = $this->snapshots->findForOrder($orderId);
            if (
                $financial === null
                || $financial['id'] !== (int) $prep->financial_snapshot_id
                || $financial['id'] !== (int) $cod->financial_snapshot_id
                || $financial['goods_payable_minor'] !== (int) $cod->goods_due_minor
                || $financial['currency_code'] !== (string) $cod->currency_code
                || !hash_equals($financial['sha256'], (string) $cod->snapshot_sha256)
            ) {
                throw new DomainException('COD financial snapshot mismatch');
            }

            // Order -> reservation -> stock lock order, matching inventory operations.
            // A delivery preference is never a substitute for long-term stock hold.
            $links = DB::table('checkout_order_stock_reservations')
                ->where('checkout_preparation_id', $prep->id)
                ->orderBy('inventory_reservation_id')
                ->get(['inventory_reservation_id', 'quantity']);
            $reservations = DB::table('inventory_reservations')
                ->where('order_id', $orderId)
                ->orderBy('id')->lockForUpdate()->get();

            if ($links->isEmpty() || $links->count() !== $reservations->count()) {
                throw new DomainException('COD stock reservation set mismatch');
            }

            $byId = $reservations->keyBy('id');
            $stocks = DB::table('inventory_stocks')
                ->whereIn('id', $reservations->pluck('inventory_stock_id')->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($links as $link) {
                $reservation = $byId->get($link->inventory_reservation_id);
                $stock = $reservation === null
                    ? null : $stocks->get($reservation->inventory_stock_id);
                $quantity = (int) $link->quantity;
                if (
                    $reservation === null || $stock === null
                    || $quantity <= 0
                    || (int) $reservation->quantity !== $quantity
                    || (int) $reservation->shipped_qty !== 0
                    || (int) $reservation->released_qty !== 0
                    || $reservation->expires_at === null
                    || CarbonImmutable::parse($reservation->expires_at)->lessThanOrEqualTo($now)
                    || (int) $stock->reserved_qty < $quantity
                    || (int) $stock->on_hand_qty < $quantity
                ) {
                    throw new DomainException('COD stock is no longer fully reserved');
                }
            }

            $id = (int) DB::table('checkout_cod_delivery_selections')->insertGetId([
                'selection_key' => $selectionKey,
                'order_id' => $orderId,
                'cod_request_id' => $cod->id,
                'carrier_code' => $carrierCode,
                'delivery_type' => $deliveryType,
                'destination_ref' => $destinationRef,
                'goods_due_minor' => $financial['goods_payable_minor'],
                'currency_code' => $financial['currency_code'],
                'snapshot_sha256' => $financial['sha256'],
                'checkout_expires_at' => $prep->expires_at,
            ]);

            $row = DB::table('checkout_cod_delivery_selections')
                ->where('id', $id)->first();
            if ($row === null) {
                throw new DomainException('COD delivery selection missing after insert');
            }

            return $this->present($row, $orderKey, (string) $order->payment_status);
        }, 3);
    }

    /** @return array<string,mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }
        $row = DB::table('checkout_cod_delivery_selections')
            ->where('order_id', $orderId)->first();
        if ($row === null) {
            return null;
        }
        $order = DB::table('orders')->where('id', $orderId)
            ->first(['order_key', 'payment_status']);
        if ($order === null) {
            throw new DomainException('COD delivery order missing');
        }
        return $this->present($row, (string) $order->order_key, (string) $order->payment_status);
    }

    /** @return array<string,mixed> */
    private function present(object $row, string $orderKey, string $paymentStatus): array
    {
        $expired = CarbonImmutable::parse($row->checkout_expires_at)
            ->lessThanOrEqualTo(CarbonImmutable::now('UTC'));

        $status = match (true) {
            $paymentStatus === 'canceled' => 'canceled',
            $paymentStatus !== 'pending' => 'blocked',
            $expired => 'expired',
            default => 'awaiting_carrier_verification',
        };

        return [
            'id' => (int) $row->id,
            'selection_key' => (string) $row->selection_key,
            'order_id' => (int) $row->order_id,
            'order_key' => $orderKey,
            'cod_request_id' => (int) $row->cod_request_id,
            'carrier_code' => (string) $row->carrier_code,
            'delivery_type' => (string) $row->delivery_type,
            'destination_ref' => (string) $row->destination_ref,
            'goods_due_minor' => (int) $row->goods_due_minor,
            'currency_code' => (string) $row->currency_code,
            'snapshot_sha256' => (string) $row->snapshot_sha256,
            'checkout_expires_at' => (string) $row->checkout_expires_at,
            'payment_status' => $paymentStatus,
            'status' => $status,
            'destination_verified' => false,
            'cod_supported_by_destination' => false,
            'delivery_fee_finalized' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
