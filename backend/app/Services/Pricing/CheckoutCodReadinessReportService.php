<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Internal-only advisory report. No external HTTP, DB writes, or dispatch permission.
 * Caller must enforce authorization before exposing even the order existence.
 */
final class CheckoutCodReadinessReportService
{
    public function __construct(
        private readonly CheckoutCodReadinessRules $rules,
        private readonly CheckoutCodDeliverySelectionService $selections,
        private readonly OrderFinancialSnapshotService $snapshots,
        private readonly CheckoutCodParcelManifestService $manifests,
        private readonly CheckoutCodPackageAssessmentService $assessments,
        private readonly CheckoutCodCarrierRateReconciliationService $rates,
        private readonly CheckoutCodFinancialPreflightService $preflights,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(string $orderKey): array
    {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid COD order reference');
        }

        $orderKey = strtolower($orderKey);

        return DB::transaction(function () use ($orderKey): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->first(['id', 'payment_status']);

            $checks = array_fill_keys(
                CheckoutCodReadinessRules::INTERNAL_CHECKS,
                false
            );

            if ($order === null) {
                return $this->format($orderKey, null, $checks);
            }

            $orderId = (int) $order->id;
            $now = CarbonImmutable::now('UTC');

            $checks['order_pending'] = $order->payment_status === 'pending';

            $preparation = DB::table('checkout_order_preparations')
                ->where('order_id', $orderId)
                ->first(['id', 'expires_at', 'bonus_units_reserved']);

            $checks['checkout_active'] = $preparation !== null
                && (int) $preparation->bonus_units_reserved === 0
                && $this->isFuture($preparation->expires_at, $now);

            $snapshot = $this->snapshots->findForOrder($orderId);
            $selection = $this->selections->findForOrder($orderId);

            $recipient = DB::table('checkout_cod_recipients')
                ->where('order_id', $orderId)
                ->first(['id', 'cod_delivery_selection_id', 'city_ref']);

            $manifest = $this->manifests->findForOrder($orderId);
            $assessment = $this->assessments->findForOrder($orderId);
            $rate = $this->rates->findForOrder($orderId);
            $preflight = $this->preflights->findForOrder($orderId);

            $checks['financial_snapshot_valid'] = $snapshot !== null
                && ($snapshot['currency_code'] ?? null) === 'UAH'
                && ($snapshot['goods_payable_minor'] ?? 0) >= 100;

            $checks['cod_selection_valid'] = $selection !== null
                && ($selection['carrier_code'] ?? null) === 'nova_poshta'
                && ($selection['status'] ?? null)
                === 'awaiting_carrier_verification'
                && in_array(
                    $selection['delivery_type'] ?? null,
                    ['branch', 'parcel_locker'],
                    true
                )
                && $checks['financial_snapshot_valid']
                && ($selection['goods_due_minor'] ?? null)
                === $snapshot['goods_payable_minor']
                && ($selection['snapshot_sha256'] ?? null)
                === $snapshot['sha256'];

            $checks['recipient_present'] = $recipient !== null
                && $checks['cod_selection_valid']
                && (int) $recipient->cod_delivery_selection_id
                === (int) $selection['id']
                && Str::isUuid((string) $recipient->city_ref);

            $checks['manifest_valid'] = $manifest !== null
                && $checks['recipient_present']
                && (int) $manifest['order_id'] === $orderId
                && (int) $manifest['recipient_id'] === (int) $recipient->id
                && $this->isFuture(
                    $manifest['checkout_expires_at'],
                    $now
                )
                && ($manifest['gross_grams'] ?? 0) >= 1
                && ($manifest['line_count'] ?? 0) >= 1;

            $checks['package_within_limits'] = $assessment !== null
                && $checks['manifest_valid']
                && (int) $assessment['order_id'] === $orderId
                && (int) $assessment['manifest_id'] === (int) $manifest['id']
                && (int) $assessment['cod_delivery_selection_id']
                === (int) $selection['id']
                && ($assessment['destination_type'] ?? null)
                === $selection['delivery_type']
                && ($assessment['local_result'] ?? null)
                === 'within_standard_limits'
                && (int) $assessment['estimated_gross_grams']
                === (int) $manifest['gross_grams'];

            $rateRow = DB::table('checkout_cod_measured_rates')
                ->where('order_id', $orderId)
                ->first([
                    'id',
                    'assessment_id',
                    'manifest_id',
                    'snapshot_sha256',
                ]);

            $checks['measured_rate_present'] = $rate !== null
                && $rateRow !== null
                && $checks['package_within_limits']
                && (int) $rate['assessment_id']
                === (int) $assessment['id']
                && (int) $rate['manifest_id']
                === (int) $manifest['id']
                && (int) $rate['goods_due_minor']
                === (int) $snapshot['goods_payable_minor']
                && (string) $rateRow->snapshot_sha256
                === (string) $snapshot['sha256']
                && (int) $rate['gross_grams']
                === (int) $manifest['gross_grams']
                && (int) $rate['chargeable_grams']
                === (int) $assessment['chargeable_weight_grams']
                && ($rate['service_type'] ?? null) === (
                $selection['delivery_type'] === 'branch'
                    ? 'WarehouseWarehouse'
                    : 'WarehousePostomat'
                );

            $checks['financial_preflight_within_limit'] =
                $preflight !== null
                && $checks['measured_rate_present']
                && (int) $preflight['order_id'] === $orderId
                && (int) $preflight['measured_rate_id']
                === (int) $rate['id']
                && (int) $preflight['assessment_id']
                === (int) $assessment['id']
                && (int) $preflight['cod_delivery_selection_id']
                === (int) $selection['id']
                && (int) $preflight['financial_snapshot_id']
                === (int) $snapshot['id']
                && ($preflight['financial_snapshot_sha256'] ?? null)
                === $snapshot['sha256']
                && (int) $preflight['cod_amount_minor']
                === (int) $snapshot['goods_payable_minor']
                && (int) $preflight['delivery_cost_minor']
                === (int) $rate['delivery_cost_minor']
                && ($preflight['local_result'] ?? null)
                === 'within_published_limit'
                && is_int($preflight['estimated_cod_fee_minor'] ?? null);

            if ($preparation !== null) {
                $reservations = DB::table(
                    'checkout_order_stock_reservations as s'
                )
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
                        's.warehouse_id',
                        's.inventory_reservation_id',
                        's.quantity as line_quantity',
                        'r.order_id',
                        'r.quantity as reserved_quantity',
                        'r.shipped_qty',
                        'r.released_qty',
                        'r.expires_at',
                    ]);

                $valid = $checks['checkout_active']
                    && $checks['manifest_valid']
                    && $reservations->isNotEmpty()
                    && $reservations->count() === $manifest['line_count'];

                if ($valid) {
                    foreach ($reservations as $index => $reservation) {
                        $item = $manifest['items'][$index] ?? null;

                        if (
                            !is_array($item)
                            || (int) $reservation->variant_id
                            !== ($item['variant_id'] ?? null)
                            || (int) $reservation->warehouse_id
                            !== ($item['warehouse_id'] ?? null)
                            || (int) $reservation->inventory_reservation_id
                            !== ($item['inventory_reservation_id'] ?? null)
                            || (int) $reservation->line_quantity
                            !== ($item['quantity'] ?? null)
                            || (int) $reservation->line_quantity
                            !== (int) $reservation->reserved_quantity
                            || (int) $reservation->order_id !== $orderId
                            || (int) $reservation->shipped_qty !== 0
                            || (int) $reservation->released_qty !== 0
                            || !$this->isFuture(
                                $reservation->expires_at,
                                $now
                            )
                        ) {
                            $valid = false;
                            break;
                        }
                    }
                }

                $checks['stock_active'] = $valid;
            }

            return $this->format($orderKey, $orderId, $checks);
        }, 3);
    }

    private function isFuture(
        mixed $time,
        CarbonImmutable $now
    ): bool {
        return $time !== null
            && CarbonImmutable::parse((string) $time)
                ->greaterThan($now);
    }

    /**
     * @param array<string, bool> $checks
     * @return array<string, mixed>
     */
    private function format(
        string $orderKey,
        ?int $orderId,
        array $checks
    ): array {
        return array_merge([
            'order_key' => $orderKey,
            'order_id' => $orderId,
            'order_found' => $orderId !== null,
        ], $this->rules->evaluate($checks));
    }
}
