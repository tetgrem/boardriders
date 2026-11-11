<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodFinancialGatewaySignature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class CheckoutCodFinancialGatewaySignatureTest extends TestCase
{
    private CheckoutCodFinancialGatewaySignature $verifier;

    private string $secret =
        'testing-secret-of-at-least-thirty-two-characters';

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier =
            new CheckoutCodFinancialGatewaySignature();

        config()->set(
            'services.cod_financial_gateway.enabled',
            true
        );

        config()->set(
            'services.cod_financial_gateway.keys',
            ['primary' => $this->secret]
        );
    }

    private function raw(): string
    {
        return json_encode([
            'event_key' => (string) Str::uuid(),
            'event_kind' => 'credit_reported',
            'bank_reference' => 'BANKREF_TEST_456',
            'net_minor' => 680,
            'currency_code' => 'UAH',
        ], JSON_THROW_ON_ERROR);
    }

    public function test_cod_gateway_signing_roundtrip(): void
    {
        $raw = $this->raw();
        $nonce = (string) Str::uuid();
        $time = (string) now()->timestamp;

        $sig = $this->verifier->sign(
            'primary',
            $time,
            $nonce,
            $raw,
            $this->secret
        );

        $this->assertSame(
            (int) $time,
            $this->verifier->authenticate(
                $raw,
                'primary',
                $time,
                $nonce,
                $sig
            )
        );
    }

    public function test_cod_gateway_disabled_fails_closed(): void
    {
        config()->set(
            'services.cod_financial_gateway.enabled',
            false
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->verifier->authenticate(
            $this->raw(),
            'primary',
            (string) now()->timestamp,
            (string) Str::uuid(),
            str_repeat('a', 64)
        );
    }

    public function test_cod_gateway_tampered_body_fails(): void
    {
        $raw = $this->raw();
        $time = (string) now()->timestamp;
        $nonce = (string) Str::uuid();

        $sig = $this->verifier->sign(
            'primary',
            $time,
            $nonce,
            $raw,
            $this->secret
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->verifier->authenticate(
            $raw . ' ',
            'primary',
            $time,
            $nonce,
            $sig
        );
    }

    public function test_cod_gateway_expired_delivery_fails(): void
    {
        $time = (string) (now()->timestamp - 301);
        $nonce = (string) Str::uuid();
        $raw = $this->raw();

        $this->expectException(
            AuthorizationException::class
        );

        $this->verifier->authenticate(
            $raw,
            'primary',
            $time,
            $nonce,
            $this->verifier->sign(
                'primary',
                $time,
                $nonce,
                $raw,
                $this->secret
            )
        );
    }

    public function test_cod_gateway_unknown_key_fails(): void
    {
        $this->expectException(
            AuthorizationException::class
        );

        $this->verifier->authenticate(
            $this->raw(),
            'unknown',
            (string) now()->timestamp,
            (string) Str::uuid(),
            str_repeat('a', 64)
        );
    }

    public function test_cod_gateway_rejects_non_integer_amount(): void
    {
        $raw = str_replace(
            '"net_minor":680',
            '"net_minor":680.5',
            $this->raw()
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->verifier->payload($raw);
    }

    public function test_cod_gateway_rejects_unexpected_json_field(): void
    {
        $data = json_decode(
            $this->raw(),
            true,
            8,
            JSON_THROW_ON_ERROR
        );

        $data['paid'] = true;

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->verifier->payload(
            json_encode($data, JSON_THROW_ON_ERROR)
        );
    }

    public function test_cod_gateway_payload_has_no_payment_action(): void
    {
        $data = $this->verifier->payload(
            $this->raw()
        );

        $this->assertSame(
            'UAH',
            $data['currency_code']
        );

        $this->assertSame(
            'credit_reported',
            $data['event_kind']
        );

        $this->assertArrayNotHasKey(
            'payment_status',
            $data
        );

        $this->assertArrayNotHasKey(
            'refund_executed',
            $data
        );
    }
}
