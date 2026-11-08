<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only Nova Poshta directory inspection for an already selected COD point.
 * No shipment, waybill, reservation extension, or payment settlement.
 * Only call from trusted server code; never return raw provider responses.
 */
final class NovaPoshtaWarehouseInspectionService
{
    private const ENDPOINT = 'https://api.novaposhta.ua/v2.0/json/';

    /** @return array<string,mixed> */
    public function inspect(int $orderId): array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('checkout_cod_delivery_selections as d')
            ->join('orders as o', 'o.id', '=', 'd.order_id')
            ->join('checkout_cod_requests as r', 'r.id', '=', 'd.cod_request_id')
            ->join('checkout_payment_choices as c', 'c.id', '=', 'r.payment_choice_id')
            ->join('checkout_order_preparations as p', 'p.order_id', '=', 'o.id')
            ->where('d.order_id', $orderId)
            ->first([
                'o.payment_status',
                'r.order_id as request_order_id',
                'r.checkout_expires_at as request_expires_at',
                'c.order_id as choice_order_id',
                'c.payment_method',
                'c.provider_code',
                'p.expires_at as preparation_expires_at',
                'd.id as selection_id',
                'd.order_id',
                'd.carrier_code',
                'd.delivery_type',
                'd.destination_ref',
                'd.checkout_expires_at',
            ]);

        if (
            $row === null
            || $row->payment_status !== 'pending'
            || (int) $row->request_order_id !== $orderId
            || (int) $row->choice_order_id !== $orderId
            || $row->payment_method !== 'cash_on_delivery'
            || $row->provider_code !== null
            || $row->carrier_code !== 'nova_poshta'
            || !in_array($row->delivery_type, ['branch', 'parcel_locker'], true)
            || !CarbonImmutable::parse($row->checkout_expires_at)
                ->equalTo(CarbonImmutable::parse($row->request_expires_at))
            || !CarbonImmutable::parse($row->checkout_expires_at)
                ->equalTo(CarbonImmutable::parse($row->preparation_expires_at))
            || CarbonImmutable::parse($row->checkout_expires_at)
                ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
        ) {
            throw new DomainException('No live COD delivery selection for this order');
        }

        $apiKey = config('services.nova_poshta.api_key');

        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('Nova Poshta API key is not configured');
        }

        // The directory API may ignore unsupported filters. Never trust the
        // filter alone: require exactly the selected Ref in the response.
        $response = Http::acceptJson()
            ->timeout(6)
            ->connectTimeout(3)
            ->post(self::ENDPOINT, [
                'apiKey' => $apiKey,
                'modelName' => 'AddressGeneral',
                'calledMethod' => 'getWarehouses',
                'methodProperties' => [
                    'Ref' => (string) $row->destination_ref,
                    'Limit' => 1,
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Nova Poshta warehouse directory unavailable');
        }

        $body = $response->json();

        if (
            !is_array($body)
            || ($body['success'] ?? null) !== true
            || !is_array($body['data'] ?? null)
            || !array_is_list($body['data'])
            || count($body['data']) !== 1
            || !is_array($body['data'][0])
        ) {
            throw new DomainException('No unambiguous Nova Poshta warehouse response');
        }

        $warehouse = $body['data'][0];

        $ref = $warehouse['Ref'] ?? null;
        $category = $warehouse['CategoryOfWarehouse'] ?? null;
        $description = $warehouse['Description'] ?? null;

        if (
            !is_string($ref)
            || strtolower($ref) !== strtolower((string) $row->destination_ref)
            || !is_string($category)
            || !in_array($category, ['Branch', 'Postomat'], true)
            || !is_string($description)
            || trim($description) === ''
            || mb_strlen($description) > 255
        ) {
            throw new DomainException('Nova Poshta warehouse identity or metadata mismatch');
        }

        $actualType = $category === 'Postomat'
            ? 'parcel_locker'
            : 'branch';

        if ($actualType !== $row->delivery_type) {
            throw new DomainException('Selected COD destination type does not match carrier data');
        }

        return [
            'order_id' => $orderId,
            'selection_id' => (int) $row->selection_id,
            'carrier_code' => 'nova_poshta',
            'destination_ref' => (string) $row->destination_ref,
            'delivery_type' => $actualType,
            'warehouse_description' => trim($description),
            'directory_match' => true,

            // A warehouse listing alone does NOT prove cash-on-delivery eligibility.
            'cod_eligibility_confirmed' => false,
            'delivery_fee_finalized' => false,
            'ready_for_dispatch' => false,

            'checked_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }
}
