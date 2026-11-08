<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class NovaPoshtaDeliveryQuoteService
{
    public function __construct(
        private readonly NovaPoshtaDestinationPreflightService $preflight,
        private readonly NovaPoshtaApiClient $client,
    ) {
    }

    /**
     * Advisory quote only. Values supplied by the browser are NOT authoritative
     * checkout financial data. Weight/value must be derived from order items later.
     *
     * @return array<string, mixed>
     */
    public function estimate(
        string $recipientCityRef,
        string $warehouseRef,
        string $type,
        int $weightGrams,
        int $declaredValueMinor,
        int $codAmountMinor = 0,
        int $seatsAmount = 1,
    ): array {
        $senderCityRef = config('services.nova_poshta.sender_city_ref');

        if (!is_string($senderCityRef) || !Str::isUuid($senderCityRef)) {
            throw new RuntimeException('Nova Poshta sender city is not configured');
        }

        if (
            $weightGrams < 1 || $weightGrams > 10000000
            || $declaredValueMinor < 100 || $declaredValueMinor > 100000000
            || $codAmountMinor < 0 || $codAmountMinor > $declaredValueMinor
            || $seatsAmount < 1 || $seatsAmount > 20
        ) {
            throw new InvalidArgumentException('Invalid parcel financial/physical values');
        }

        $verified = $this->preflight->inspect(
            $recipientCityRef,
            $warehouseRef,
            $type,
        );

        if (!$verified['destination_verified']) {
            throw new DomainException('Destination is not currently verified');
        }

        $properties = [
            'CitySender' => strtolower($senderCityRef),
            'CityRecipient' => strtolower($recipientCityRef),
            'Weight' => $this->minorToDecimal($weightGrams, 1000, 3),
            'ServiceType' => 'WarehouseWarehouse',
            'Cost' => $this->minorToDecimal($declaredValueMinor, 100, 2),
            'CargoType' => 'Parcel',
            'SeatsAmount' => (string) $seatsAmount,
        ];

        if ($codAmountMinor > 0) {
            $properties['RedeliveryCalculate'] = [
                'CargoType' => 'Money',
                'Amount' => $this->minorToDecimal($codAmountMinor, 100, 2),
            ];
        }

        $data = $this->client->call('InternetDocument', 'getDocumentPrice', $properties);

        if (count($data) !== 1 || !is_array($data[0])) {
            throw new RuntimeException('Invalid Nova Poshta price response');
        }

        $deliveryMinor = $this->moneyToMinor($data[0]['Cost'] ?? null);

        if ($deliveryMinor === null) {
            throw new RuntimeException('Invalid Nova Poshta delivery cost');
        }

        $codFeeMinor = $codAmountMinor > 0
            ? $this->moneyToMinor($data[0]['CostRedelivery'] ?? null)
            : null;

        return [
            'city_ref' => strtolower($recipientCityRef),
            'warehouse_ref' => strtolower($warehouseRef),
            'warehouse_type' => $type,
            'currency_code' => 'UAH',
            'delivery_cost_minor' => $deliveryMinor,
            'cod_transfer_fee_minor' => $codFeeMinor,
            'cod_fee_available' => $codAmountMinor === 0 || $codFeeMinor !== null,
            'estimated_only' => true,
            'cod_eligibility_confirmed' => false,
            'delivery_fee_finalized' => false,
            'ready_for_dispatch' => false,
        ];
    }

    private function minorToDecimal(int $value, int $scale, int $digits): string
    {
        return intdiv($value, $scale) . '.'
            . str_pad((string) ($value % $scale), $digits, '0', STR_PAD_LEFT);
    }

    private function moneyToMinor(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value) || $value < 0 || $value > 1000000) {
                return null;
            }

            // Provider may encode a UAH amount as a JSON number.
            // Normalize only if it has no significant digits beyond kopecks.
            if (abs($value - round($value, 2)) > 0.00000001) {
                return null;
            }

            $value = number_format($value, 2, '.', '');
        }

        if (!is_string($value) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $value)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);

        return $minor <= 100000000 ? $minor : null;
    }
}
