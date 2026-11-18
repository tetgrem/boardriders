<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutMonoGateway53;
use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class CheckoutMonoGateway53Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.mono_acquiring.token',
            'fake-unit-test-merchant-token'
        );
        config()->set(
            'services.mono_acquiring.webhook_url',
            'https://shop.example.test/api/v1/payments/mono/webhook'
        );
        config()->set(
            'services.mono_acquiring.redirect_url',
            'https://shop.example.test/checkout/result'
        );
    }

    public function test_cod_mono53_accepts_original_ecdsa_signed_webhook(): void
    {
        $private = openssl_pkey_new([
            'ec' => [
                'curve_name' => 'prime256v1',
            ],
        ]);
        if ($private === false) {
            $errors = [];

            while (($error = openssl_error_string()) !== false) {
                $errors[] = $error;
            }

            self::fail(
                'OpenSSL EC key generation failed: '
                . implode(' | ', $errors)
            );
        }
        self::assertNotFalse($private);

        $details = openssl_pkey_get_details($private);

        config()->set(
            'services.mono_acquiring.public_key_base64',
            base64_encode($details['key'])
        );

        $body = '{"invoiceId":"p2_test","status":"success"}';

        openssl_sign(
            $body,
            $signature,
            $private,
            OPENSSL_ALGO_SHA256
        );

        self::assertTrue(
            app(CheckoutMonoGateway53::class)->verifyWebhook(
                $body,
                base64_encode($signature)
            )
        );
    }

    public function test_cod_mono53_rejects_changed_signed_bytes(): void
    {
        $private = openssl_pkey_new([
            'ec' => [
                'curve_name' => 'prime256v1',
            ],
        ]);

        $details = openssl_pkey_get_details($private);

        config()->set(
            'services.mono_acquiring.public_key_base64',
            base64_encode($details['key'])
        );

        openssl_sign(
            'original',
            $signature,
            $private,
            OPENSSL_ALGO_SHA256
        );

        self::assertFalse(
            app(CheckoutMonoGateway53::class)->verifyWebhook(
                'modified',
                base64_encode($signature)
            )
        );
    }

    public function test_cod_mono53_rejects_missing_signature(): void
    {
        self::assertFalse(
            app(CheckoutMonoGateway53::class)
                ->verifyWebhook('{}', null)
        );
    }

    public function test_cod_mono53_invoice_uses_exact_integer_minor_amount(): void
    {
        Http::fake([
            'https://api.monobank.ua/*' => Http::response([
                'invoiceId' => 'p2_test',
                'pageUrl' => 'https://pay.mbnk.biz/p2_test',
            ], 200),
        ]);

        $result = app(CheckoutMonoGateway53::class)->create(
            2001,
            'order-123',
            600
        );

        self::assertSame('p2_test', $result['invoice_id']);

        Http::assertSent(
            static fn ($request): bool =>
                $request->url()
                === 'https://api.monobank.ua/api/merchant/invoice/create'
                && $request['amount'] === 2001
                && $request['ccy'] === 980
                && $request['merchantPaymInfo']['reference']
                === 'order-123'
                && $request['paymentType'] === 'debit'
                && $request->hasHeader(
                    'X-Token',
                    'fake-unit-test-merchant-token'
                )
        );

        Http::assertSentCount(1);
    }

    public function test_cod_mono53_rejects_untrusted_provider_payment_url(): void
    {
        Http::fake([
            'https://api.monobank.ua/*' => Http::response([
                'invoiceId' => 'p2_test',
                'pageUrl' => 'https://evil.example/pay',
            ]),
        ]);

        $this->expectException(RuntimeException::class);

        app(CheckoutMonoGateway53::class)->create(
            1000,
            'test',
            600
        );
    }

    public function test_cod_mono53_rejects_insecure_callback_url(): void
    {
        config()->set(
            'services.mono_acquiring.webhook_url',
            'http://unsafe.example/webhook'
        );

        Http::fake();

        $this->expectException(DomainException::class);

        app(CheckoutMonoGateway53::class)->create(
            1000,
            'test',
            600
        );
    }

    public function test_cod_mono53_fetches_provider_status_server_side(): void
    {
        Http::fake([
            'https://api.monobank.ua/*' => Http::response([
                'invoiceId' => 'p2_test',
                'status' => 'created',
            ]),
        ]);

        $result = app(CheckoutMonoGateway53::class)
            ->status('p2_test');

        self::assertSame('created', $result['status']);

        Http::assertSent(
            static fn ($request): bool =>
            str_contains(
                $request->url(),
                '/api/merchant/invoice/status?invoiceId=p2_test'
            )
        );
    }

    public function test_cod_mono53_http_failure_is_not_retried_automatically(): void
    {
        Http::fake([
            'https://api.monobank.ua/*' =>
                Http::response(['error' => 'busy'], 500),
        ]);

        try {
            app(CheckoutMonoGateway53::class)->create(
                1000,
                'order-123',
                600
            );

            self::fail('Mono failure must fail closed');
        } catch (RuntimeException) {
            Http::assertSentCount(1);
        }
    }

    public function test_cod_mono53_rejects_zero_payment(): void
    {
        $this->expectException(DomainException::class);

        app(CheckoutMonoGateway53::class)->create(
            0,
            'order',
            600
        );
    }
}
