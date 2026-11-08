<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Internal operation: capture one parcel's measured goods and packaging.
 * Does not confirm carrier constraints, COD eligibility or dispatch.
 */
final class CheckoutCodParcelManifestService
{
    public function __construct(
        private readonly CheckoutCodDeliverySelectionService $selections,
    ) {
    }

    /** @return array<string, mixed> */
    public function capture(
        string $orderKey,
        string $manifestKey,
        int $packagingGrams
    ): array {
        if (!Str::isUuid($orderKey) || !Str::isUuid($manifestKey)) {
            throw new InvalidArgumentException('Invalid COD manifest UUID');
        }

        if ($packagingGrams < 0 || $packagingGrams > 20000) {
            throw new InvalidArgumentException('Invalid packaging weight');
        }

        $orderKey = strtolower($orderKey);
        $manifestKey = strtolower($manifestKey);

        $requestSha = hash('sha256', json_encode([
            'cod-parcel-v1',
            $orderKey,
            $packagingGrams,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $orderKey,
            $manifestKey,
            $packagingGrams,
            $requestSha
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null || $order->payment_status !== 'pending') {
                throw new DomainException('COD order is not pending');
            }

            $orderId = (int) $order->id;

            $preparation = DB::table('checkout_order_preparations')
                ->where('order_id', $orderId)
                ->first(['id', 'expires_at']);

            if (
                $preparation === null
                || CarbonImmutable::parse($preparation->expires_at)
                    ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ) {
                throw new DomainException('COD checkout has expired');
            }

            $recipient = DB::table('checkout_cod_recipients')
                ->where('order_id', $orderId)
                ->first(['id', 'cod_delivery_selection_id']);

            if ($recipient === null) {
                throw new DomainException('COD recipient is required');
            }

            /*
             * IMPORTANT:
             * checkout_cod_delivery_selections has no physical "status"
             * column. Status is derived by CheckoutCodDeliverySelectionService.
             */
            $selection = $this->selections->findForOrder($orderId);

            if (
                $selection === null
                || (int) $selection['id']
                !== (int) $recipient->cod_delivery_selection_id
                || ($selection['carrier_code'] ?? null) !== 'nova_poshta'
                || ($selection['status'] ?? null)
                !== 'awaiting_carrier_verification'
            ) {
                throw new DomainException(
                    'COD delivery selection is unavailable'
                );
            }

            $existing = DB::table('checkout_cod_parcel_manifests')
                ->where('order_id', $orderId)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->manifest_key !== $manifestKey
                    || !hash_equals(
                        (string) $existing->request_sha256,
                        $requestSha
                    )
                    || (int) $existing->recipient_id !== (int) $recipient->id
                ) {
                    throw new DomainException(
                        'COD parcel manifest is immutable'
                    );
                }

                return $this->present($existing, $orderKey);
            }

            if (
                DB::table('checkout_cod_parcel_manifests')
                    ->where('manifest_key', $manifestKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'COD parcel manifest key already used'
                );
            }

            if (
                DB::table('checkout_cod_shipping_quotes')
                    ->where('order_id', $orderId)
                    ->exists()
            ) {
                throw new DomainException(
                    'Existing quote cannot be retroactively measured'
                );
            }

            $reservations = DB::table(
                'checkout_order_stock_reservations as s'
            )
                ->join(
                    'inventory_reservations as r',
                    'r.id',
                    '=',
                    's.inventory_reservation_id'
                )
                ->where('s.checkout_preparation_id', $preparation->id)
                ->orderBy('s.variant_id')
                ->get([
                    's.variant_id',
                    's.warehouse_id',
                    's.inventory_reservation_id',
                    's.quantity',
                    'r.quantity as reserved_quantity',
                    'r.order_id as reservation_order_id',
                    'r.shipped_qty',
                    'r.released_qty',
                    'r.expires_at',
                ]);

            if ($reservations->isEmpty() || $reservations->count() > 200) {
                throw new DomainException('Invalid COD parcel lines');
            }

            $variantIds = $reservations
                ->pluck('variant_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $variants = DB::table('product_variants')
                ->whereIn('id', $variantIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'weight_grams',
                    'shipping_length_mm',
                    'shipping_width_mm',
                    'shipping_height_mm',
                    'shipping_measurement_revision',
                ])
                ->keyBy('id');

            $items = [];
            $goodsGrams = 0;
            $units = 0;
            $warehouseId = null;

            foreach ($reservations as $reservation) {
                $quantity = (int) $reservation->quantity;

                if (
                    $quantity < 1
                    || $quantity > 99
                    || (int) $reservation->reserved_quantity !== $quantity
                    || (int) $reservation->reservation_order_id !== $orderId
                    || (int) $reservation->shipped_qty !== 0
                    || (int) $reservation->released_qty !== 0
                    || $reservation->expires_at === null
                    || CarbonImmutable::parse($reservation->expires_at)
                        ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                ) {
                    throw new DomainException(
                        'COD stock reservations are not active'
                    );
                }

                $currentWarehouseId = (int) $reservation->warehouse_id;
                $warehouseId ??= $currentWarehouseId;

                if ($currentWarehouseId !== $warehouseId) {
                    throw new DomainException(
                        'Multiple warehouses require split-shipment support'
                    );
                }

                $variant = $variants->get($reservation->variant_id);

                if ($variant === null) {
                    throw new DomainException('Parcel SKU not found');
                }

                $weight = $variant->weight_grams === null
                    ? 0
                    : (int) $variant->weight_grams;

                $length = $variant->shipping_length_mm === null
                    ? 0
                    : (int) $variant->shipping_length_mm;

                $width = $variant->shipping_width_mm === null
                    ? 0
                    : (int) $variant->shipping_width_mm;

                $height = $variant->shipping_height_mm === null
                    ? 0
                    : (int) $variant->shipping_height_mm;

                if (
                    $weight < 1
                    || $weight > 10000000
                    || min($length, $width, $height) < 1
                    || max($length, $width, $height) > 100000
                ) {
                    throw new DomainException(
                        'Missing or invalid SKU shipping measurements'
                    );
                }

                $lineGrams = $weight * $quantity;
                $goodsGrams += $lineGrams;
                $units += $quantity;

                if ($goodsGrams + $packagingGrams > 10000000) {
                    throw new DomainException('Parcel exceeds weight limit');
                }

                $items[] = [
                    'variant_id' => (int) $reservation->variant_id,
                    'warehouse_id' => $currentWarehouseId,
                    'inventory_reservation_id' =>
                        (int) $reservation->inventory_reservation_id,
                    'quantity' => $quantity,
                    'shipping_revision' =>
                        (int) $variant->shipping_measurement_revision,
                    'unit_weight_grams' => $weight,
                    'length_mm' => $length,
                    'width_mm' => $width,
                    'height_mm' => $height,
                    'line_weight_grams' => $lineGrams,
                ];
            }

            $warehouse = DB::table('warehouses')
                ->where('id', $warehouseId)
                ->first(['kind', 'is_active']);

            if (
                $warehouse === null
                || $warehouse->kind !== 'owned'
                || !(bool) $warehouse->is_active
            ) {
                throw new DomainException(
                    'Fulfillment warehouse is unavailable'
                );
            }

            $itemsJson = json_encode(
                $items,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );

            $id = DB::table('checkout_cod_parcel_manifests')
                ->insertGetId([
                    'manifest_key' => $manifestKey,
                    'order_id' => $orderId,
                    'recipient_id' => (int) $recipient->id,
                    'warehouse_id' => $warehouseId,
                    'packaging_grams' => $packagingGrams,
                    'goods_grams' => $goodsGrams,
                    'gross_grams' => $goodsGrams + $packagingGrams,
                    'total_units' => $units,
                    'line_count' => count($items),
                    'items_json' => $itemsJson,
                    'items_sha256' => hash('sha256', $itemsJson),
                    'request_sha256' => $requestSha,
                    'checkout_expires_at' => $preparation->expires_at,
                    'created_at' => now(),
                ]);

            return $this->present(
                DB::table('checkout_cod_parcel_manifests')
                    ->where('id', $id)
                    ->first(),
                $orderKey
            );
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('checkout_cod_parcel_manifests as m')
            ->join('orders as o', 'o.id', '=', 'm.order_id')
            ->where('m.order_id', $orderId)
            ->first(['m.*', 'o.order_key']);

        return $row === null
            ? null
            : $this->present($row, (string) $row->order_key);
    }

    /** @return array<string, mixed> */
    private function present(object $row, string $orderKey): array
    {
        $json = (string) $row->items_json;

        if (
            !hash_equals(
                (string) $row->items_sha256,
                hash('sha256', $json)
            )
        ) {
            throw new RuntimeException(
                'Stored parcel items checksum mismatch'
            );
        }

        $items = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($items)
            || count($items) !== (int) $row->line_count
        ) {
            throw new RuntimeException(
                'Stored parcel item count mismatch'
            );
        }

        return [
            'id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'order_key' => $orderKey,
            'manifest_key' => (string) $row->manifest_key,
            'recipient_id' => (int) $row->recipient_id,
            'warehouse_id' => (int) $row->warehouse_id,
            'packaging_grams' => (int) $row->packaging_grams,
            'goods_grams' => (int) $row->goods_grams,
            'gross_grams' => (int) $row->gross_grams,
            'total_units' => (int) $row->total_units,
            'line_count' => (int) $row->line_count,
            'items' => $items,
            'checkout_expires_at' =>
                (string) $row->checkout_expires_at,
            'advisory_only' => true,
            'dimensions_verified_by_carrier' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
