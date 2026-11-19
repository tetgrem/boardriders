<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutMonoRefund54Evidence;
use App\Services\Pricing\CheckoutMonoRefund54Gateway;
use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class CheckoutMonoRefund54UnitTest extends TestCase
{
    private function invoice(array $cancels = []): array
    {
        return [
            'invoiceId' => 'p2_test54',
            'reference' => 'order-ref',
            'amount' => 2000,
            'ccy' => 980,
            'status' => 'success',
            'cancelList' => $cancels,
        ];
    }

    private function inspect(
        array $invoice,
        array $known = [
            '0123456789abcdef0123456789abcdef' => 1000,
        ]
    ): array {
        return app(CheckoutMonoRefund54Evidence::class)->inspect(
            $invoice,
            'p2_test54',
            'order-ref',
            2000,
            '0123456789abcdef0123456789abcdef',
            1000,
            $known
        );
    }

    public function test_cod_mono54_no_refund_evidence_is_absent(): void
    {
        $this->assertSame(
            'absent',
            $this->inspect($this->invoice())['state']
        );
    }

    public function test_cod_mono54_processing_is_not_a_refund(): void
    {
        $c = [
            'extRef' => '0123456789abcdef0123456789abcdef',
            'status' => 'processing',
            'amount' => 1000,
            'ccy' => 980,
        ];

        $out = $this->inspect($this->invoice([$c]));

        $this->assertSame('processing', $out['state']);
        $this->assertNull($out['matched_amount_minor']);
    }

    public function test_cod_mono54_success_requires_exact_reference_amount_and_currency(): void
    {
        $c = [
            'extRef' => '0123456789abcdef0123456789abcdef',
            'status' => 'success',
            'amount' => 1000,
            'ccy' => 980,
        ];

        $out = $this->inspect($this->invoice([$c]));

        $this->assertSame('success', $out['state']);
        $this->assertSame(
            1000,
            $out['matched_amount_minor']
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $out['evidence_sha256']
        );
    }

    public function test_cod_mono54_unknown_external_refund_blocks_evidence(): void
    {
        $c = [
            'extRef' => 'outside',
            'status' => 'success',
            'amount' => 1000,
            'ccy' => 980,
        ];

        $this->expectException(DomainException::class);

        $this->inspect($this->invoice([$c]));
    }

    public function test_cod_mono54_other_known_refund_must_have_correct_amount(): void
    {
        $other = 'ffffffffffffffffffffffffffffffff';

        $c = [
            'extRef' => $other,
            'status' => 'success',
            'amount' => 500,
            'ccy' => 980,
        ];

        $this->expectException(DomainException::class);

        $this->inspect(
            $this->invoice([$c]),
            [
                '0123456789abcdef0123456789abcdef' => 1000,
                $other => 250,
            ]
        );
    }

    public function test_cod_mono54_repeated_refund_reference_is_ambiguous(): void
    {
        $c = [
            'extRef' => '0123456789abcdef0123456789abcdef',
            'status' => 'success',
            'amount' => 1000,
            'ccy' => 980,
        ];

        $this->expectException(DomainException::class);

        $this->inspect($this->invoice([$c, $c]));
    }

    public function test_cod_mono54_invoice_amount_must_match_original_capture(): void
    {
        $status = $this->invoice();
        $status['amount'] = 1999;

        $this->expectException(DomainException::class);

        $this->inspect($status);
    }

    public function test_cod_mono54_reversed_invoice_can_confirm_completed_refund(): void
    {
        $status = $this->invoice([[
            'extRef' => '0123456789abcdef0123456789abcdef',
            'status' => 'success',
            'amount' => 1000,
            'ccy' => 980,
        ]]);

        $status['status'] = 'reversed';

        $this->assertSame(
            'success',
            $this->inspect($status)['state']
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.mono_acquiring.token',
            'fake-token'
        );
    }

    public function test_cod_mono54_partial_cancel_uses_exact_amount_and_stable_extref(): void
    {
        Http::fake([
            'https://api.monobank.ua/*' =>
                Http::response(['status' => 'processing']),
        ]);

        $out = app(
            CheckoutMonoRefund54Gateway::class
        )->cancelOnce(
            'p2_test54',
            '0123456789abcdef0123456789abcdef',
            1000,
            2000
        );

        $this->assertSame('processing', $out);

        Http::assertSent(
            fn ($r): bool =>
                $r->url()
                === 'https://api.monobank.ua/api/merchant/invoice/cancel'
                && $r['amount'] === 1000
                && $r['extRef']
                === '0123456789abcdef0123456789abcdef'
                && $r['invoiceId'] === 'p2_test54'
        );

        Http::assertSentCount(1);
    }

    public function test_cod_mono54_full_cancel_omits_partial_amount(): void
    {
        Http::fake([
            'https://api.monobank.ua/*' =>
                Http::response(['status' => 'processing']),
        ]);

        app(CheckoutMonoRefund54Gateway::class)->cancelOnce(
            'p2_test54',
            '0123456789abcdef0123456789abcdef',
            2000,
            2000
        );

        Http::assertSent(
            fn ($r): bool => !isset($r['amount'])
        );
    }

    public function test_cod_mono54_http_failure_is_not_retried(): void
    {
        Http::fake([
            'https://api.monobank.ua/*' =>
                Http::response([], 500),
        ]);

        try {
            app(CheckoutMonoRefund54Gateway::class)->cancelOnce(
                'p2_test54',
                '0123456789abcdef0123456789abcdef',
                1000,
                2000
            );

            $this->fail('Uncertain request must throw');
        } catch (RuntimeException) {
            Http::assertSentCount(1);
        }
    }

    public function test_cod_mono54_invalid_extref_never_sends(): void
    {
        Http::fake();

        try {
            app(CheckoutMonoRefund54Gateway::class)->cancelOnce(
                'p2_test54',
                'bad',
                1000,
                2000
            );

            $this->fail(
                'Expected strict reference validation'
            );
        } catch (DomainException) {
            Http::assertNothingSent();
        }
    }
}   
