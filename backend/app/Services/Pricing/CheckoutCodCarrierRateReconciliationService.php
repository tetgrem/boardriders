<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Append-only re-rating after the saved parcel and local geometry assessment.
 * Trusted internal use only. No payment, hold extension, TTN or dispatch.
 */
final class CheckoutCodCarrierRateReconciliationService
{
    public function __construct(
        private readonly CheckoutCodDeliverySelectionService $selections,
        private readonly CheckoutCodParcelManifestService $manifests,
        private readonly CheckoutCodPackageAssessmentService $assessments,
        private readonly OrderFinancialSnapshotService $snapshots,
        private readonly NovaPoshtaMeasuredTariffService $provider,
    ) {
    }

    /** @return array<string, mixed> */
    public function reconcile(string $orderKey, string $operationKey): array
    {
        if (!Str::isUuid($orderKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid COD rate operation UUID');
        }

        $orderKey = strtolower($orderKey);
        $operationKey = strtolower($operationKey);

        $context = DB::transaction(fn (): array => $this->context($orderKey), 3);

        $requestHash = hash('sha256', json_encode(
            ['cod-measured-rate-v1', $orderKey, $context],
            JSON_THROW_ON_ERROR
        ));

        $existing = DB::table('checkout_cod_measured_rates')
            ->where('order_id', $context['order_id'])->first();

        if ($existing !== null) {
            return $this->existing($existing, $operationKey, $requestHash);
        }

        // No order/inventory lock is held during the remote call.
        $rate = $this->provider->estimate(
            $context['sender_city_ref'],
            $context['recipient_city_ref'],
            $context['delivery_type'],
            $context['gross_grams'],
            $context['length_mm'],
            $context['width_mm'],
            $context['height_mm'],
            $context['goods_due_minor'],
            $context['goods_due_minor'],
        );

        if (
            ($rate['currency_code'] ?? null) !== 'UAH'
            || ($rate['sender_city_ref'] ?? null) !== $context['sender_city_ref']
            || ($rate['recipient_city_ref'] ?? null) !== $context['recipient_city_ref']
            || ($rate['actual_weight_grams'] ?? null) !== $context['gross_grams']
            || ($rate['length_mm'] ?? null) !== $context['length_mm']
            || ($rate['width_mm'] ?? null) !== $context['width_mm']
            || ($rate['height_mm'] ?? null) !== $context['height_mm']
            || ($rate['service_type'] ?? null) !== $context['service_type']
            || ($rate['declared_value_minor'] ?? null) !== $context['goods_due_minor']
            || ($rate['cod_amount_minor'] ?? null) !== $context['goods_due_minor']
            || !is_int($rate['delivery_cost_minor'] ?? null)
            || $rate['delivery_cost_minor'] < 0
            || $rate['delivery_cost_minor'] > 100000000
            || (!is_int($rate['cod_fee_minor'] ?? null)
                && ($rate['cod_fee_minor'] ?? null) !== null)
            || (is_int($rate['cod_fee_minor'] ?? null) && (
                    $rate['cod_fee_minor'] < 0 || $rate['cod_fee_minor'] > 100000000
                ))
            || ($rate['cod_fee_available'] ?? null) !== (
            is_int($rate['cod_fee_minor'] ?? null)
            )
            || !is_string($rate['provider_request_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $rate['provider_request_sha256'])
            || ($rate['estimated_only'] ?? null) !== true
            || ($rate['provider_dimensions_confirmed'] ?? null) !== false
            || ($rate['cod_eligibility_confirmed'] ?? null) !== false
            || ($rate['ready_for_dispatch'] ?? null) !== false
        ) {
            throw new RuntimeException('Invalid measured carrier rate response');
        }

        return DB::transaction(function () use (
            $orderKey, $operationKey, $context, $requestHash, $rate
        ): array {
            $current = $this->context($orderKey);

            if ($current !== $context) {
                throw new DomainException('COD context changed while rating');
            }

            $existing = DB::table('checkout_cod_measured_rates')
                ->where('order_id', $current['order_id'])->first();

            if ($existing !== null) {
                return $this->existing($existing, $operationKey, $requestHash);
            }

            if (DB::table('checkout_cod_measured_rates')
                ->where('operation_key', $operationKey)->exists()
            ) {
                throw new DomainException('COD rate operation key already used');
            }

            $oldCost = $current['legacy_cost_minor'];

            $id = DB::table('checkout_cod_measured_rates')->insertGetId([
                'operation_key' => $operationKey,
                'order_id' => $current['order_id'],
                'assessment_id' => $current['assessment_id'],
                'manifest_id' => $current['manifest_id'],
                'legacy_quote_id' => $current['legacy_quote_id'],
                'sender_city_ref' => $current['sender_city_ref'],
                'recipient_city_ref' => $current['recipient_city_ref'],
                'service_type' => $current['service_type'],
                'goods_due_minor' => $current['goods_due_minor'],
                'snapshot_sha256' => $current['snapshot_sha256'],
                'gross_grams' => $current['gross_grams'],
                'chargeable_grams' => $current['chargeable_grams'],
                'length_mm' => $current['length_mm'],
                'width_mm' => $current['width_mm'],
                'height_mm' => $current['height_mm'],
                'delivery_cost_minor' => $rate['delivery_cost_minor'],
                'cod_fee_minor' => $rate['cod_fee_minor'],
                'legacy_cost_minor' => $oldCost,
                'delta_from_legacy_minor' => $oldCost === null
                    ? null : $rate['delivery_cost_minor'] - $oldCost,
                'request_sha256' => $requestHash,
                'provider_request_sha256' => $rate['provider_request_sha256'],
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('checkout_cod_measured_rates')
                    ->where('id', $id)->first()
            );
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('checkout_cod_measured_rates')
            ->where('order_id', $orderId)->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    private function context(string $orderKey): array
    {
        $order = DB::table('orders')->where('order_key', $orderKey)
            ->lockForUpdate()->first(['id', 'payment_status']);

        if ($order === null || $order->payment_status !== 'pending') {
            throw new DomainException('COD order is not pending');
        }

        $orderId = (int) $order->id;

        $sender = config('services.nova_poshta.sender_city_ref');

        if (!is_string($sender) || !Str::isUuid($sender)) {
            throw new RuntimeException('Sender delivery city is not configured');
        }

        $selection = $this->selections->findForOrder($orderId);
        $manifest = $this->manifests->findForOrder($orderId);
        $assessment = $this->assessments->findForOrder($orderId);
        $snapshot = $this->snapshots->findForOrder($orderId);

        $recipient = DB::table('checkout_cod_recipients')
            ->where('order_id', $orderId)
            ->first(['id', 'city_ref', 'cod_delivery_selection_id']);

        $preparation = DB::table('checkout_order_preparations')
            ->where('order_id', $orderId)
            ->first(['id', 'expires_at', 'bonus_units_reserved']);

        if (
            $selection === null || $manifest === null || $assessment === null
            || $snapshot === null || $recipient === null || $preparation === null
            || ($selection['status'] ?? null) !== 'awaiting_carrier_verification'
            || ($selection['carrier_code'] ?? null) !== 'nova_poshta'
            || !in_array(
                $selection['delivery_type'] ?? null,
                ['branch', 'parcel_locker'], true
            )
            || (int) $recipient->cod_delivery_selection_id !== (int) $selection['id']
            || !Str::isUuid((string) $recipient->city_ref)
            || (int) $manifest['recipient_id'] !== (int) $recipient->id
            || (int) $manifest['order_id'] !== $orderId
            || (int) $assessment['order_id'] !== $orderId
            || (int) $assessment['manifest_id'] !== (int) $manifest['id']
            || (int) $assessment['cod_delivery_selection_id'] !== (int) $selection['id']
            || ($assessment['destination_type'] ?? null) !== $selection['delivery_type']
            || ($assessment['local_result'] ?? null) !== 'within_standard_limits'
            || (int) $assessment['estimated_gross_grams'] !== (int) $manifest['gross_grams']
            || (int) $assessment['chargeable_weight_grams'] < (int) $manifest['gross_grams']
            || ($snapshot['currency_code'] ?? null) !== 'UAH'
            || ($snapshot['goods_payable_minor'] ?? null) !== (int) $selection['goods_due_minor']
            || ($snapshot['sha256'] ?? null) !== (string) $selection['snapshot_sha256']
            || (int) $snapshot['goods_payable_minor'] < 100
            || (int) $snapshot['goods_payable_minor'] > 100000000
            || (int) $preparation->bonus_units_reserved !== 0
            || CarbonImmutable::parse($preparation->expires_at)
                ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            || CarbonImmutable::parse($manifest['checkout_expires_at'])
                ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
        ) {
            throw new DomainException('COD package context is not rateable');
        }

        $items = $manifest['items'];

        if (!is_array($items) || $items === [] || count($items) > 200) {
            throw new DomainException('COD parcel items missing');
        }

        $reserved = DB::table('checkout_order_stock_reservations as s')
            ->join(
                'inventory_reservations as r',
                'r.id', '=', 's.inventory_reservation_id'
            )
            ->where('s.checkout_preparation_id', $preparation->id)
            ->orderBy('s.variant_id')
            ->get([
                's.variant_id',
                's.warehouse_id',
                's.quantity',
                's.inventory_reservation_id',
                'r.order_id as reservation_order_id',
                'r.quantity as reserved_quantity',
                'r.shipped_qty',
                'r.released_qty',
                'r.expires_at',
            ]);

        if ($reserved->count() !== count($items)) {
            throw new DomainException('COD parcel/reservation line mismatch');
        }

        foreach ($reserved as $i => $reservation) {
            $item = $items[$i];

            if (
                (int) $reservation->variant_id !== ($item['variant_id'] ?? null)
                || (int) $reservation->warehouse_id !== ($item['warehouse_id'] ?? null)
                || (int) $reservation->inventory_reservation_id
                !== ($item['inventory_reservation_id'] ?? null)
                || (int) $reservation->quantity !== ($item['quantity'] ?? null)
                || (int) $reservation->reserved_quantity !== (int) $reservation->quantity
                || (int) $reservation->reservation_order_id !== $orderId
                || (int) $reservation->shipped_qty !== 0
                || (int) $reservation->released_qty !== 0
                || $reservation->expires_at === null
                || CarbonImmutable::parse($reservation->expires_at)
                    ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ) {
                throw new DomainException('COD stock no longer fully reserved');
            }
        }

        $legacy = DB::table('checkout_cod_shipping_quotes')
            ->where('order_id', $orderId)
            ->first(['id', 'delivery_cost_minor']);

        return [
            'order_id' => $orderId,
            'assessment_id' => (int) $assessment['id'],
            'manifest_id' => (int) $manifest['id'],
            'legacy_quote_id' => $legacy === null ? null : (int) $legacy->id,
            'legacy_cost_minor' => $legacy === null
                ? null : (int) $legacy->delivery_cost_minor,
            'sender_city_ref' => strtolower($sender),
            'recipient_city_ref' => strtolower((string) $recipient->city_ref),
            'delivery_type' => $selection['delivery_type'],
            'service_type' => $selection['delivery_type'] === 'branch'
                ? 'WarehouseWarehouse' : 'WarehousePostomat',
            'goods_due_minor' => (int) $snapshot['goods_payable_minor'],
            'snapshot_sha256' => (string) $snapshot['sha256'],
            'gross_grams' => (int) $manifest['gross_grams'],
            'chargeable_grams' => (int) $assessment['chargeable_weight_grams'],
            'length_mm' => (int) $assessment['length_mm'],
            'width_mm' => (int) $assessment['width_mm'],
            'height_mm' => (int) $assessment['height_mm'],
        ];
    }

    /** @return array<string, mixed> */
    private function existing(
        object $row,
        string $key,
        string $requestHash
    ): array {
        if (
            (string) $row->operation_key !== $key
            || !hash_equals(
                (string) $row->request_sha256,
                $requestHash
            )
        ) {
            throw new DomainException('Measured COD rate is immutable');
        }

        return $this->present($row);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'operation_key' => (string) $row->operation_key,
            'assessment_id' => (int) $row->assessment_id,
            'manifest_id' => (int) $row->manifest_id,
            'legacy_quote_id' => $row->legacy_quote_id === null
                ? null : (int) $row->legacy_quote_id,
            'sender_city_ref' => (string) $row->sender_city_ref,
            'recipient_city_ref' => (string) $row->recipient_city_ref,
            'service_type' => (string) $row->service_type,
            'gross_grams' => (int) $row->gross_grams,
            'chargeable_grams' => (int) $row->chargeable_grams,
            'length_mm' => (int) $row->length_mm,
            'width_mm' => (int) $row->width_mm,
            'height_mm' => (int) $row->height_mm,
            'goods_due_minor' => (int) $row->goods_due_minor,
            'delivery_cost_minor' => (int) $row->delivery_cost_minor,
            'cod_fee_minor' => $row->cod_fee_minor === null
                ? null : (int) $row->cod_fee_minor,
            'legacy_cost_minor' => $row->legacy_cost_minor === null
                ? null : (int) $row->legacy_cost_minor,
            'delta_from_legacy_minor' => $row->delta_from_legacy_minor === null
                ? null : (int) $row->delta_from_legacy_minor,
            'provider_request_sha256' => (string) $row->provider_request_sha256,
            'status' => $row->cod_fee_minor === null
                ? 'cod_fee_missing' : 'advisory_only',
            'estimated_only' => true,
            'provider_dimensions_confirmed' => false,
            'declared_value_policy_confirmed' => false,
            'cod_eligibility_confirmed' => false,
            'delivery_fee_finalized' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
