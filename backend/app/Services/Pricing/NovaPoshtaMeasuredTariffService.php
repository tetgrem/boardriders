<?php

namespace App\Services\Pricing;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Read-only tariff probe. A successful price is NOT COD or shipment approval. */
final class NovaPoshtaMeasuredTariffService
{
    public function __construct(private readonly NovaPoshtaApiClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function estimate(
        string $senderCityRef,
        string $recipientCityRef,
        string $deliveryType,
        int $grossGrams,
        int $lengthMm,
        int $widthMm,
        int $heightMm,
        int $declaredValueMinor,
        int $codAmountMinor,
    ): array {
        if (!Str::isUuid($senderCityRef) || !Str::isUuid($recipientCityRef)) {
            throw new InvalidArgumentException('Invalid carrier city reference');
        }
        if (!in_array($deliveryType, ['branch', 'parcel_locker'], true)) {
            throw new InvalidArgumentException('Invalid carrier destination type');
        }
        if ($grossGrams < 1 || $grossGrams > 10000000
            || min($lengthMm, $widthMm, $heightMm) < 1
            || max($lengthMm, $widthMm, $heightMm) > 100000
            || $declaredValueMinor < 100 || $declaredValueMinor > 100000000
            || $codAmountMinor < 0 || $codAmountMinor > $declaredValueMinor
        ) {
            throw new InvalidArgumentException('Invalid measured parcel or money');
        }

        $serviceType = $deliveryType === 'branch'
            ? 'WarehouseWarehouse' : 'WarehousePostomat';
        $weight = $this->decimal($grossGrams, 1000, 3);

        $properties = [
            'CitySender' => strtolower($senderCityRef),
            'CityRecipient' => strtolower($recipientCityRef),
            'ServiceType' => $serviceType,
            'CargoType' => 'Parcel',
            'SeatsAmount' => '1',

            // Actual weight must not be substituted with volumetric weight.
            'Weight' => $weight,
            'Cost' => $this->decimal($declaredValueMinor, 100, 2),

            'OptionsSeat' => [[
                'weight' => $weight,
                'volumetricLength' => $this->decimal($lengthMm, 10, 1),
                'volumetricWidth' => $this->decimal($widthMm, 10, 1),
                'volumetricHeight' => $this->decimal($heightMm, 10, 1),
            ]],
        ];

        if ($codAmountMinor > 0) {
            $properties['RedeliveryCalculate'] = [
                'CargoType' => 'Money',
                'Amount' => $this->decimal($codAmountMinor, 100, 2),
            ];
        }

        $data = $this->client->call('InternetDocument', 'getDocumentPrice', $properties);
        if (count($data) !== 1 || !is_array($data[0])) {
            throw new RuntimeException('Invalid carrier tariff response');
        }

        $cost = $this->moneyToMinor($data[0]['Cost'] ?? null);
        if ($cost === null) {
            throw new RuntimeException('Invalid carrier delivery cost');
        }

        $codFee = $codAmountMinor > 0
            ? $this->moneyToMinor($data[0]['CostRedelivery'] ?? null)
            : null;

        // Missing COD fee remains unconfirmed, never interpreted as zero.
        return [
            'sender_city_ref' => strtolower($senderCityRef),
            'recipient_city_ref' => strtolower($recipientCityRef),
            'service_type' => $serviceType,
            'actual_weight_grams' => $grossGrams,
            'length_mm' => $lengthMm,
            'width_mm' => $widthMm,
            'height_mm' => $heightMm,
            'declared_value_minor' => $declaredValueMinor,
            'cod_amount_minor' => $codAmountMinor,
            'currency_code' => 'UAH',
            'delivery_cost_minor' => $cost,
            'cod_fee_minor' => $codFee,
            'cod_fee_available' => $codAmountMinor === 0 || $codFee !== null,
            'provider_request_sha256' => hash('sha256', json_encode(
                ['InternetDocument', 'getDocumentPrice', $properties],
                JSON_THROW_ON_ERROR
            )),
            'estimated_only' => true,
            'provider_dimensions_confirmed' => false,
            'declared_value_policy_confirmed' => false,
            'cod_eligibility_confirmed' => false,
            'ready_for_dispatch' => false,
        ];
    }

    private function decimal(int $value, int $scale, int $digits): string
    {
        return intdiv($value, $scale) . '.'
            . str_pad((string) ($value % $scale), $digits, '0', STR_PAD_LEFT);
    }

    private function moneyToMinor(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value) || $value < 0 || $value > 1000000
                || abs($value - round($value, 2)) > 0.00000001
            ) {
                return null;
            }
            $value = number_format($value, 2, '.', '');
        }

        if (!is_string($value)
            || !preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/D', $value)
        ) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        $minor = ((int) $whole * 100)
            + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);

        return $minor <= 100000000 ? $minor : null;
    }
}
