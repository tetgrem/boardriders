<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Internal-only, append-only local assessment of OUTER packaging.
 * No carrier API calls, payment changes, or dispatch authorization.
 */
final class CheckoutCodPackageAssessmentService
{
    public function __construct(
        private readonly CheckoutCodParcelManifestService $manifests,
        private readonly CheckoutCodDeliverySelectionService $selections,
        private readonly NovaPoshtaPackageGeometryPolicy $geometry,
    ) {
    }

    /** @return array<string, mixed> */
    public function assess(
        string $orderKey,
        string $assessmentKey,
        int $lengthMm,
        int $widthMm,
        int $heightMm,
    ): array {
        if (!Str::isUuid($orderKey) || !Str::isUuid($assessmentKey)) {
            throw new InvalidArgumentException(
                'Invalid package assessment UUID'
            );
        }

        foreach ([$lengthMm, $widthMm, $heightMm] as $side) {
            if ($side < 1 || $side > 100000) {
                throw new InvalidArgumentException(
                    'Invalid package dimensions'
                );
            }
        }

        $orderKey = strtolower($orderKey);
        $assessmentKey = strtolower($assessmentKey);

        return DB::transaction(function () use (
            $orderKey,
            $assessmentKey,
            $lengthMm,
            $widthMm,
            $heightMm
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null || $order->payment_status !== 'pending') {
                throw new DomainException('COD order is not pending');
            }

            $orderId = (int) $order->id;

            $manifest = $this->manifests->findForOrder($orderId);
            $selection = $this->selections->findForOrder($orderId);

            if (
                $manifest === null
                || $selection === null
                || ($selection['carrier_code'] ?? null) !== 'nova_poshta'
                || ($selection['status'] ?? null)
                !== 'awaiting_carrier_verification'
                || !in_array(
                    $selection['delivery_type'] ?? null,
                    ['branch', 'parcel_locker'],
                    true
                )
                || CarbonImmutable::parse(
                    (string) $manifest['checkout_expires_at']
                )->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ) {
                throw new DomainException(
                    'Active COD parcel and selection required'
                );
            }

            $recipient = DB::table('checkout_cod_recipients')
                ->where('id', $manifest['recipient_id'])
                ->where('order_id', $orderId)
                ->first(['cod_delivery_selection_id']);

            if (
                $recipient === null
                || (int) $recipient->cod_delivery_selection_id
                !== (int) $selection['id']
            ) {
                throw new DomainException(
                    'Parcel recipient and destination mismatch'
                );
            }

            // A saved parcel is not proof of a currently active stock hold.
            $items = $manifest['items'];

            if (
                !is_array($items)
                || $items === []
                || count($items) > 200
            ) {
                throw new DomainException('Invalid stored parcel items');
            }

            $ids = [];

            foreach ($items as $item) {
                if (
                    !is_array($item)
                    || !is_int($item['inventory_reservation_id'] ?? null)
                    || ($item['inventory_reservation_id'] ?? 0) <= 0
                    || !is_int($item['quantity'] ?? null)
                    || ($item['quantity'] ?? 0) <= 0
                ) {
                    throw new DomainException(
                        'Invalid parcel reservation reference'
                    );
                }

                $ids[] = $item['inventory_reservation_id'];
            }

            if (count(array_unique($ids)) !== count($ids)) {
                throw new DomainException('Duplicated parcel reservation');
            }

            sort($ids, SORT_NUMERIC);

            $reservations = DB::table('inventory_reservations')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'order_id',
                    'quantity',
                    'shipped_qty',
                    'released_qty',
                    'expires_at',
                ])
                ->keyBy('id');

            if ($reservations->count() !== count($ids)) {
                throw new DomainException(
                    'Parcel stock reservation missing'
                );
            }

            foreach ($items as $item) {
                $reservation = $reservations->get(
                    $item['inventory_reservation_id']
                );

                if (
                    $reservation === null
                    || (int) $reservation->order_id !== $orderId
                    || (int) $reservation->quantity !== $item['quantity']
                    || (int) $reservation->shipped_qty !== 0
                    || (int) $reservation->released_qty !== 0
                    || $reservation->expires_at === null
                    || CarbonImmutable::parse(
                        (string) $reservation->expires_at
                    )->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                ) {
                    throw new DomainException(
                        'COD stock is no longer fully reserved'
                    );
                }
            }

            $requestSha = hash('sha256', json_encode([
                'cod-package-assessment-v1',
                $orderKey,
                $manifest['manifest_key'],
                $lengthMm,
                $widthMm,
                $heightMm,
            ], JSON_THROW_ON_ERROR));

            $existing = DB::table('checkout_cod_package_assessments')
                ->where('order_id', $orderId)
                ->first();

            if ($existing !== null) {
                if (
                    (string) $existing->assessment_key !== $assessmentKey
                    || (int) $existing->manifest_id
                    !== (int) $manifest['id']
                    || (int) $existing->cod_delivery_selection_id
                    !== (int) $selection['id']
                    || !hash_equals(
                        (string) $existing->request_sha256,
                        $requestSha
                    )
                ) {
                    throw new DomainException(
                        'COD package assessment is immutable'
                    );
                }

                return $this->present($existing);
            }

            if (
                DB::table('checkout_cod_package_assessments')
                    ->where('assessment_key', $assessmentKey)
                    ->exists()
            ) {
                throw new DomainException(
                    'Package assessment key already used'
                );
            }

            $result = $this->geometry->evaluate(
                (int) $manifest['gross_grams'],
                $lengthMm,
                $widthMm,
                $heightMm,
                $selection['delivery_type'],
                $items
            );

            $manifestRow = DB::table('checkout_cod_parcel_manifests')
                ->where('id', $manifest['id'])
                ->first(['items_sha256']);

            if ($manifestRow === null) {
                throw new RuntimeException(
                    'Stored parcel manifest disappeared'
                );
            }

            $id = DB::table('checkout_cod_package_assessments')
                ->insertGetId([
                    'assessment_key' => $assessmentKey,
                    'order_id' => $orderId,
                    'manifest_id' => $manifest['id'],
                    'cod_delivery_selection_id' => $selection['id'],
                    'ruleset' => $result['ruleset'],
                    'destination_type' => $result['destination_type'],
                    'length_mm' => $lengthMm,
                    'width_mm' => $widthMm,
                    'height_mm' => $heightMm,
                    'estimated_gross_grams' =>
                        $result['estimated_gross_grams'],
                    'volume_weight_grams' =>
                        $result['volume_weight_grams'],
                    'chargeable_weight_grams' =>
                        $result['chargeable_weight_grams'],
                    'local_result' => $result['local_result'],
                    'reasons_json' => json_encode(
                        $result['reasons'],
                        JSON_THROW_ON_ERROR
                    ),
                    'manifest_items_sha256' =>
                        $manifestRow->items_sha256,
                    'request_sha256' => $requestSha,
                    'created_at' => now(),
                ]);

            return $this->present(
                DB::table('checkout_cod_package_assessments')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('checkout_cod_package_assessments')
            ->where('order_id', $orderId)
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $reasons = json_decode(
            (string) $row->reasons_json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($reasons) || !array_is_list($reasons)) {
            throw new RuntimeException(
                'Invalid stored package assessment reasons'
            );
        }

        return [
            'id' => (int) $row->id,
            'assessment_key' => (string) $row->assessment_key,
            'order_id' => (int) $row->order_id,
            'manifest_id' => (int) $row->manifest_id,
            'cod_delivery_selection_id' =>
                (int) $row->cod_delivery_selection_id,
            'ruleset' => (string) $row->ruleset,
            'destination_type' => (string) $row->destination_type,
            'length_mm' => (int) $row->length_mm,
            'width_mm' => (int) $row->width_mm,
            'height_mm' => (int) $row->height_mm,
            'estimated_gross_grams' =>
                (int) $row->estimated_gross_grams,
            'volume_weight_grams' =>
                (int) $row->volume_weight_grams,
            'chargeable_weight_grams' =>
                (int) $row->chargeable_weight_grams,
            'local_result' => (string) $row->local_result,
            'reasons' => $reasons,
            'item_fit_check_only' => true,
            'declared_value_checked' => false,
            'carrier_acceptance_confirmed' => false,
            'cod_eligibility_confirmed' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
