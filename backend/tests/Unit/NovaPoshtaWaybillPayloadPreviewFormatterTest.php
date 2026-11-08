<?php

namespace Tests\Unit;

use App\Services\Pricing\NovaPoshtaWaybillPayloadPreviewFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NovaPoshtaWaybillPayloadPreviewFormatterTest extends TestCase
{
    private function format(array $overrides = []): array
    {
        $draft = array_merge([
            'service_type' => 'WarehouseWarehouse',
            'payer_type' => 'Recipient',
            'payment_method' => 'Cash',
            'gross_grams' => 700,
            'cod_amount_minor' => 2000,
            'declared_value_minor' => 3000,
        ], $overrides);

        return (new NovaPoshtaWaybillPayloadPreviewFormatter())->format($draft);
    }

    public function test_formats_money_and_real_weight_without_floats(): void
    {
        $preview = $this->format();
        $this->assertSame('0.700', $preview['safe_fields']['Weight']);
        $this->assertSame('30.00', $preview['safe_fields']['Cost']);
        $this->assertSame('20.00', $preview['cod_amount_preview']);
    }

    public function test_stays_a_non_submittable_candidate(): void
    {
        $preview = $this->format();
        $this->assertSame('InternetDocument', $preview['api_model_candidate']);
        $this->assertSame('save', $preview['api_method_candidate']);
        $this->assertFalse($preview['is_api_request_payload']);
        $this->assertFalse($preview['carrier_submission_permitted']);
    }

    public function test_supports_postomat_service_type(): void
    {
        $this->assertSame('WarehousePostomat',
            $this->format(['service_type' => 'WarehousePostomat'])['safe_fields']['ServiceType']);
    }

    public function test_gram_precision_is_not_rounded_to_kilograms(): void
    {
        $this->assertSame('0.001', $this->format(['gross_grams' => 1])['safe_fields']['Weight']);
    }

    public function test_separate_declared_value_is_flagged_for_rerating(): void
    {
        $this->assertTrue($this->format()['declared_value_rerate_required']);
        $this->assertFalse($this->format(['declared_value_minor' => 2000])['declared_value_rerate_required']);
    }

    public function test_invalid_service_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->format(['service_type' => 'DoorsDoors']);
    }

    public function test_invalid_delivery_payer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->format(['payer_type' => 'Buyer']);
    }

    public function test_invalid_payment_method_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->format(['payment_method' => 'Crypto']);
    }

    public function test_non_integer_money_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->format(['declared_value_minor' => 30.5]);
    }

    public function test_safe_preview_never_echoes_sensitive_or_arbitrary_input_fields(): void
    {
        $preview = $this->format([
            'sender_phone' => '380671234567',
            'recipient_name' => 'Петренко',
            'counterparty_ref' => 'confidential-ref',
        ]);
        $json = json_encode($preview, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('380671234567', $json);
        $this->assertStringNotContainsString('Петренко', $json);
        $this->assertStringNotContainsString('confidential-ref', $json);
        $this->assertFalse($preview['contains_recipient_pii']);
    }
}
