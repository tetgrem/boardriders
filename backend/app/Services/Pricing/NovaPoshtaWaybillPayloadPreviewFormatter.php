<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Pure formatting of safe, non-PII TTN fields.
 * Output is deliberately NOT InternetDocument/save methodProperties.
 */
final class NovaPoshtaWaybillPayloadPreviewFormatter
{
    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public function format(array $draft): array
    {
        $service = $draft['service_type'] ?? null;
        $payer = $draft['payer_type'] ?? null;
        $payment = $draft['payment_method'] ?? null;
        $weight = $draft['gross_grams'] ?? null;
        $cod = $draft['cod_amount_minor'] ?? null;
        $declared = $draft['declared_value_minor'] ?? null;

        if (!in_array($service, ['WarehouseWarehouse', 'WarehousePostomat'], true)
            || !in_array($payer, ['Sender', 'Recipient'], true)
            || !in_array($payment, ['Cash', 'NonCash'], true)
            || !is_int($weight) || $weight < 1 || $weight > 10000000
            || !is_int($cod) || $cod < 100 || $cod > 100000000
            || !is_int($declared) || $declared < 100 || $declared > 100000000
        ) {
            throw new InvalidArgumentException('Invalid internal waybill draft values');
        }

        return [
            'api_model_candidate' => 'InternetDocument',
            'api_method_candidate' => 'save',
            'is_api_request_payload' => false,
            'safe_fields' => [
                'ServiceType' => $service,
                'PayerType' => $payer,
                'PaymentMethod' => $payment,
                'CargoType' => 'Parcel',
                'Weight' => $this->decimal($weight, 1000, 3),
                'SeatsAmount' => '1',
                'Cost' => $this->decimal($declared, 100, 2),
                'Description' => '[stored merchant description]',
            ],
            'cod_amount_preview' => $this->decimal($cod, 100, 2),
            'declared_value_rerate_required' => $declared !== $cod,
            'contains_recipient_pii' => false,
            'carrier_submission_permitted' => false,
        ];
    }

    private function decimal(int $value, int $scale, int $digits): string
    {
        return intdiv($value, $scale) . '.'
            . str_pad((string) ($value % $scale), $digits, '0', STR_PAD_LEFT);
    }
}
