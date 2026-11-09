<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodInternalPickSlipFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodInternalPickSlipFormatterTest extends TestCase
{
    private function payload(): array
    {
        return [
            'order_key' => '12345678-1234-4234-8234-123456789012',
            'container_type' => 'box',
            'total_units' => 3,
            'gross_grams' => 850,
            'length_mm' => 300,
            'width_mm' => 200,
            'height_mm' => 100,
            'items' => [
                ['variant_id' => 14, 'quantity' => 2],
                ['variant_id' => 21, 'quantity' => 1],
            ],
        ];
    }

    public function test_generates_printable_internal_document(): void
    {
        $text = (new CheckoutCodInternalPickSlipFormatter())
            ->render($this->payload());

        $this->assertStringContainsString(
            'INTERNAL PICK SLIP',
            $text
        );
        $this->assertStringContainsString(
            'NOT A CARRIER LABEL',
            $text
        );
        $this->assertStringContainsString('14 | 2', $text);
        $this->assertStringContainsString('TOTAL UNITS: 3', $text);
    }

    public function test_never_includes_unlisted_personal_fields(): void
    {
        $payload = $this->payload();
        $payload['recipient_phone'] = '+380671111111';
        $payload['carrier_api_key'] = 'secret_token';

        $text = (new CheckoutCodInternalPickSlipFormatter())
            ->render($payload);

        $this->assertStringNotContainsString(
            '+380671111111',
            $text
        );
        $this->assertStringNotContainsString(
            'secret_token',
            $text
        );
    }

    public function test_fails_on_missing_lines(): void
    {
        $payload = $this->payload();
        $payload['items'] = [];

        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodInternalPickSlipFormatter())
            ->render($payload);
    }

    public function test_fails_on_invalid_quantities(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['quantity'] = 0;

        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodInternalPickSlipFormatter())
            ->render($payload);
    }

    public function test_fails_when_expected_units_do_not_match(): void
    {
        $payload = $this->payload();
        $payload['total_units'] = 4;

        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodInternalPickSlipFormatter())
            ->render($payload);
    }

    public function test_fails_on_invalid_container(): void
    {
        $payload = $this->payload();
        $payload['container_type'] = 'carrier_label';

        $this->expectException(InvalidArgumentException::class);

        (new CheckoutCodInternalPickSlipFormatter())
            ->render($payload);
    }
}
