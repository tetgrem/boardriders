<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Reads the existing one-parcel COD manifest; never touches carrier HTTP. */
final class CheckoutCodWarehousePackReadinessService
{
    public function __construct(
        private readonly CheckoutCodTtnDispatchReadinessService $dispatch,
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid warehouse pack order UUID');
        }

        $orderKey = strtolower($orderKey);
        $dispatch = $this->dispatch->inspect($orderKey);
        $orderId = (int) $dispatch['order_id'];

        $base = [
            'order_id' => $orderId,
            'order_key' => $orderKey,
            'intent_id' => $dispatch['intent_id'],
            'plan_id' => null,
            'manifest_id' => null,
            'assessment_id' => null,
            'source_ready' => false,
            'source_hmac' => null,
            'items_sha256' => null,
            'total_units' => null,
            'gross_grams' => null,
            'length_mm' => null,
            'width_mm' => null,
            'height_mm' => null,
            'items' => [],
            'blockers' => ['dispatch_plan_or_pack_source_not_ready'],
            'carrier_ttn_label_available' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];

        if (
            ($dispatch['local_context_current'] ?? false) !== true
            || ($dispatch['events'] ?? []) !== []
            || $dispatch['intent_id'] === null
        ) {
            return $base;
        }

        $plan = DB::table('checkout_cod_ttn_dispatch_plans as p')
            ->join(
                'checkout_cod_ttn_dispatch_reviews as r',
                'r.plan_id',
                '=',
                'p.id'
            )
            ->where('p.intent_id', $dispatch['intent_id'])
            ->where('p.order_id', $orderId)
            ->where('r.decision', 'reviewed_for_future_submission')
            ->first(['p.id', 'p.context_hmac', 'p.request_hmac']);

        if (
            $plan === null
            || !hash_equals(
                (string) $dispatch['context_hmac'],
                (string) $plan->context_hmac
            )
        ) {
            return $base;
        }

        $base['plan_id'] = (int) $plan->id;

        $draft = DB::table('checkout_cod_waybill_drafts')
            ->where('order_id', $orderId)
            ->first([
                'id',
                'manifest_id',
                'assessment_id',
                'request_hmac',
            ]);

        if ($draft === null) {
            return $base;
        }

        $manifest = DB::table('checkout_cod_parcel_manifests')
            ->where('id', $draft->manifest_id)
            ->where('order_id', $orderId)
            ->first([
                'id',
                'warehouse_id',
                'items_json',
                'items_sha256',
                'total_units',
                'gross_grams',
                'checkout_expires_at',
            ]);

        $assessment = DB::table('checkout_cod_package_assessments')
            ->where('id', $draft->assessment_id)
            ->where('order_id', $orderId)
            ->first([
                'id',
                'manifest_id',
                'length_mm',
                'width_mm',
                'height_mm',
                'manifest_items_sha256',
                'local_result',
                'request_sha256',
            ]);

        if (
            $manifest === null
            || $assessment === null
            || (int) $assessment->manifest_id !== (int) $manifest->id
            || $assessment->local_result !== 'within_standard_limits'
            || !hash_equals(
                (string) $manifest->items_sha256,
                (string) $assessment->manifest_items_sha256
            )
            || !hash_equals(
                hash('sha256', (string) $manifest->items_json),
                (string) $manifest->items_sha256
            )
            || CarbonImmutable::parse($manifest->checkout_expires_at)
                ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
        ) {
            return $base;
        }

        $items = json_decode(
            (string) $manifest->items_json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($items)
            || $items === []
            || count($items) > 200
        ) {
            return $base;
        }

        $expected = [];

        foreach ($items as $item) {
            if (
                !is_array($item)
                || !is_int($item['inventory_reservation_id'] ?? null)
                || !is_int($item['variant_id'] ?? null)
                || !is_int($item['warehouse_id'] ?? null)
                || !is_int($item['quantity'] ?? null)
                || $item['quantity'] <= 0
                || isset($expected[$item['inventory_reservation_id']])
            ) {
                return $base;
            }

            $expected[$item['inventory_reservation_id']] = $item;
        }

        $holds = DB::table('checkout_order_stock_reservations as s')
            ->join(
                'checkout_order_preparations as p',
                'p.id',
                '=',
                's.checkout_preparation_id'
            )
            ->join(
                'inventory_reservations as r',
                'r.id',
                '=',
                's.inventory_reservation_id'
            )
            ->where('p.order_id', $orderId)
            ->orderBy('r.id')
            ->get([
                'r.id',
                'r.order_id',
                'r.quantity',
                'r.shipped_qty',
                'r.released_qty',
                'r.expires_at',
                's.variant_id',
                's.warehouse_id',
                's.quantity as line_quantity',
            ]);

        if ($holds->count() !== count($expected)) {
            return $base;
        }

        $stockEvidence = [];

        foreach ($holds as $hold) {
            $item = $expected[(int) $hold->id] ?? null;

            if (
                $item === null
                || (int) $hold->order_id !== $orderId
                || (int) $hold->variant_id !== $item['variant_id']
                || (int) $hold->warehouse_id !== $item['warehouse_id']
                || (int) $hold->warehouse_id !== (int) $manifest->warehouse_id
                || (int) $hold->quantity !== $item['quantity']
                || (int) $hold->line_quantity !== $item['quantity']
                || (int) $hold->shipped_qty !== 0
                || (int) $hold->released_qty !== 0
                || $hold->expires_at === null
                || CarbonImmutable::parse($hold->expires_at)
                    ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ) {
                return $base;
            }

            $stockEvidence[] = [
                (int) $hold->id,
                (int) $hold->variant_id,
                (int) $hold->quantity,
                (string) $hold->expires_at,
            ];
        }

        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new DomainException('Missing APP_KEY');
        }

        $payload = [
            'cod-warehouse-pack-v1',
            $orderId,
            (int) $dispatch['intent_id'],
            (int) $plan->id,
            (string) $plan->request_hmac,
            (int) $draft->id,
            (string) $draft->request_hmac,
            (int) $manifest->id,
            (string) $manifest->items_sha256,
            (int) $manifest->gross_grams,
            (int) $manifest->total_units,
            (int) $assessment->id,
            (string) $assessment->request_sha256,
            (int) $assessment->length_mm,
            (int) $assessment->width_mm,
            (int) $assessment->height_mm,
            $stockEvidence,
        ];

        return array_merge($base, [
            'manifest_id' => (int) $manifest->id,
            'assessment_id' => (int) $assessment->id,
            'source_ready' => true,
            'source_hmac' => hash_hmac(
                'sha256',
                json_encode($payload, JSON_THROW_ON_ERROR),
                $secret
            ),
            'items_sha256' => (string) $manifest->items_sha256,
            'total_units' => (int) $manifest->total_units,
            'gross_grams' => (int) $manifest->gross_grams,
            'length_mm' => (int) $assessment->length_mm,
            'width_mm' => (int) $assessment->width_mm,
            'height_mm' => (int) $assessment->height_mm,
            'items' => array_map(
                static fn (array $item): array => [
                    'variant_id' => $item['variant_id'],
                    'quantity' => $item['quantity'],
                ],
                $items
            ),
            'blockers' => [],
        ]);
    }
}
