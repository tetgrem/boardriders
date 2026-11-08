<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaCounterpartyContactProbe;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class NovaPoshtaCounterpartyContactProbeTest extends TestCase
{
    private const COUNTERPARTY = '11111111-1111-4111-8111-111111111111';
    private const CONTACT = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nova_poshta.api_key', 'contact-probe-test-key');
        Http::preventStrayRequests();
    }

    private function probe(): array
    {
        return app(NovaPoshtaCounterpartyContactProbe::class)
            ->inspect(self::COUNTERPARTY, self::CONTACT);
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

    public function test_exact_contact_match_is_observed_but_not_identity_proof(): void
    {
        $this->fake([['Ref' => self::CONTACT]]);
        $result = $this->probe();

        $this->assertSame('observed_once', $result['result']);
        $this->assertTrue($result['contact_seen']);
        $this->assertCount(2, $result);
        Http::assertSent(static function (ProviderRequest $request): bool {
            $data = $request->data();
            return $request->method() === 'POST'
                && ($data['modelName'] ?? null) === 'Counterparty'
                && ($data['calledMethod'] ?? null) === 'getCounterpartyContactPersons'
                && ($data['methodProperties']['Ref'] ?? null) === self::COUNTERPARTY;
        });
    }

    public function test_no_contact_in_response_does_not_count_as_observed(): void
    {
        $this->fake([['Ref' => '33333333-3333-4333-8333-333333333333']]);
        $result = $this->probe();
        $this->assertSame('not_observed_in_response', $result['result']);
        $this->assertFalse($result['contact_seen']);
    }

    public function test_empty_response_is_only_a_single_response_negative(): void
    {
        $this->fake([]);
        $this->assertSame('not_observed_in_response', $this->probe()['result']);
    }

    public function test_duplicate_contact_reference_fails_closed(): void
    {
        $this->fake([['Ref' => self::CONTACT], ['Ref' => self::CONTACT]]);
        $this->assertSame('duplicate_reference', $this->probe()['result']);
        $this->assertFalse($this->probe()['contact_seen']);
    }

    public function test_malformed_row_makes_response_inconclusive(): void
    {
        $this->fake([['Other' => 'value']]);
        $result = $this->probe();
        $this->assertSame('response_inconclusive', $result['result']);
        $this->assertFalse($result['contact_seen']);
    }

    public function test_match_with_malformed_row_does_not_claim_clean_observation(): void
    {
        $this->fake([['Ref' => self::CONTACT], ['Ref' => 'bad']]);
        $this->assertSame('response_inconclusive', $this->probe()['result']);
    }

    public function test_case_insensitive_uuid_match(): void
    {
        $this->fake([['Ref' => strtoupper(self::CONTACT)]]);
        $this->assertTrue($this->probe()['contact_seen']);
    }

    public function test_invalid_uuid_makes_no_http_request(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(NovaPoshtaCounterpartyContactProbe::class)
            ->inspect('not-a-uuid', self::CONTACT);
    }

    public function test_provider_failure_is_not_accepted(): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => false,
                'data' => [],
                'errors' => ['private provider failure'],
            ]),
        ]);
        $this->expectException(RuntimeException::class);
        $this->probe();
    }

    public function test_oversized_response_is_rejected(): void
    {
        $this->fake(array_fill(0, 501, ['Ref' => self::CONTACT]));
        $this->expectException(RuntimeException::class);
        $this->probe();
    }
}
