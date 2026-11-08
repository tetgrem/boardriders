<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaSenderAccountProbe;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class NovaPoshtaSenderAccountProbeTest extends TestCase
{
    private const SENDER_REF =
        '00000000-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.nova_poshta.api_key',
            'sender-probe-test-key'
        );

        Http::preventStrayRequests();
    }

    private function inspect(): array
    {
        return app(NovaPoshtaSenderAccountProbe::class)
            ->inspect(self::SENDER_REF);
    }

    private function fake(array $rows): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => true,
                'data' => $rows,
            ]),
        ]);
    }

    public function test_exact_sender_ref_and_reported_role_are_diagnostic_only(): void
    {
        $this->fake([
            [
                'Ref' => self::SENDER_REF,
                'CounterpartyProperty' => 'Sender',
            ],
        ]);

        $result = $this->inspect();

        $this->assertSame(
            'sender_role_reported',
            $result['result']
        );
        $this->assertTrue($result['sender_ref_seen_once']);
        $this->assertTrue($result['sender_role_reported']);
        $this->assertFalse(
            $result['merchant_cod_agreement_verified']
        );
        $this->assertFalse($result['ready_for_dispatch']);

        Http::assertSent(static function (
            ProviderRequest $request
        ): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url()
                === 'https://api.novaposhta.ua/v2.0/json/'
                && ($data['modelName'] ?? null) === 'Counterparty'
                && ($data['calledMethod'] ?? null)
                === 'getCounterparties'
                && (
                    $data['methodProperties']['CounterpartyProperty']
                    ?? null
                ) === 'Sender';
        });
    }

    public function test_ref_without_role_cannot_confirm_sender_role(): void
    {
        $this->fake([
            ['Ref' => self::SENDER_REF],
        ]);

        $result = $this->inspect();

        $this->assertSame(
            'sender_ref_seen_role_unconfirmed',
            $result['result']
        );
        $this->assertFalse($result['sender_role_reported']);
    }

    public function test_ref_marked_as_recipient_cannot_confirm_sender_role(): void
    {
        $this->fake([
            [
                'Ref' => self::SENDER_REF,
                'CounterpartyProperty' => 'Recipient',
            ],
        ]);

        $this->assertFalse(
            $this->inspect()['sender_role_reported']
        );
    }

    public function test_duplicate_ref_is_ambiguous(): void
    {
        $row = [
            'Ref' => self::SENDER_REF,
            'CounterpartyProperty' => 'Sender',
        ];

        $this->fake([$row, $row]);

        $result = $this->inspect();

        $this->assertSame(
            'ambiguous_sender_reference',
            $result['result']
        );
        $this->assertFalse($result['sender_ref_seen_once']);
    }

    public function test_unseen_ref_is_not_claimed_to_be_missing_from_carrier(): void
    {
        $this->fake([
            ['Ref' => 'different'],
        ]);

        $result = $this->inspect();

        $this->assertSame(
            'sender_ref_not_seen',
            $result['result']
        );
        $this->assertFalse($result['sender_ref_seen_once']);
        $this->assertTrue($result['scan_complete']);
    }

    public function test_page_two_can_contain_sender(): void
    {
        $first = array_fill(
            0,
            100,
            ['Ref' => 'unrelated']
        );

        Http::fakeSequence()
            ->push([
                'success' => true,
                'data' => $first,
            ])
            ->push([
                'success' => true,
                'data' => [
                    [
                        'Ref' => self::SENDER_REF,
                        'CounterpartyProperty' => 'Sender',
                    ],
                ],
            ]);

        $result = $this->inspect();

        $this->assertTrue($result['sender_role_reported']);
        $this->assertSame(2, $result['pages_inspected']);

        Http::assertSentCount(2);

        Http::assertSent(
            static fn (ProviderRequest $request): bool =>
                ($request->data()['methodProperties']['Page'] ?? null)
                === 2
        );
    }

    public function test_three_full_pages_are_reported_as_incomplete(): void
    {
        $first = array_fill(
            0,
            100,
            ['Ref' => 'unrelated']
        );

        $this->fake($first);

        $result = $this->inspect();

        $this->assertSame('scan_incomplete', $result['result']);
        $this->assertFalse($result['scan_complete']);
        Http::assertSentCount(3);
    }

    public function test_invalid_sender_ref_makes_no_request(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(NovaPoshtaSenderAccountProbe::class)
            ->inspect('bad ref !');
    }

    public function test_missing_api_key_makes_no_request(): void
    {
        config()->set('services.nova_poshta.api_key', null);

        try {
            $this->inspect();
            $this->fail('Missing key must be rejected');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }
    }

    public function test_provider_failure_is_not_converted_to_positive_result(): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => false,
                'data' => [],
                'errors' => ['private diagnostic'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->inspect();
    }
}
