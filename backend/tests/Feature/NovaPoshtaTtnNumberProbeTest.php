<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaTtnNumberProbe;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class NovaPoshtaTtnNumberProbeTest extends TestCase
{
    private const NUMBER = '20450000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nova_poshta.api_key', 'reconciliation-test-key');
        Http::preventStrayRequests();
    }

    private function inspect(): array
    {
        return app(NovaPoshtaTtnNumberProbe::class)->inspect(self::NUMBER);
    }

    private function fake(array $rows): void
    {
        Http::fake(['api.novaposhta.ua/*' => Http::response([
            'success' => true, 'data' => $rows,
        ])]);
    }

    public function test_read_only_method_and_exact_number_filter(): void
    {
        $this->fake([['IntDocNumber' => self::NUMBER]]);
        $this->assertSame('number_observed_once', $this->inspect()['result']);
        Http::assertSentCount(1);
        Http::assertSent(static function (ProviderRequest $request): bool {
            $p = $request->data();
            return ($p['modelName'] ?? null) === 'InternetDocument'
                && ($p['calledMethod'] ?? null) === 'getDocumentList'
                && ($p['methodProperties']['IntDocNumber'] ?? null) === self::NUMBER
                && ($p['methodProperties']['Page'] ?? null) === 1
                && ($p['methodProperties']['Limit'] ?? null) === 100;
        });
    }

    public function test_page_without_number_is_not_presence_proof(): void
    {
        $this->fake([]);
        $this->assertSame('number_absent_in_page', $this->inspect()['result']);
    }

    public function test_malformed_response_is_inconclusive(): void
    {
        $this->fake([['Ref' => 'x']]);
        $this->assertSame('response_inconclusive', $this->inspect()['result']);
    }

    public function test_duplicates_are_not_unique_confirmation(): void
    {
        $this->fake([['IntDocNumber' => self::NUMBER], ['IntDocNumber' => self::NUMBER]]);
        $this->assertSame('duplicate_number', $this->inspect()['result']);
    }

    public function test_invalid_number_makes_no_provider_request(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(NovaPoshtaTtnNumberProbe::class)->inspect('wrong');
    }

    public function test_provider_failure_is_not_converted_to_absence(): void
    {
        Http::fake(['api.novaposhta.ua/*' => Http::response([
            'success' => false, 'data' => [], 'errors' => ['fake'],
        ])]);
        $this->expectException(RuntimeException::class);
        $this->inspect();
    }

    public function test_no_save_request_is_sent(): void
    {
        $this->fake([['IntDocNumber' => self::NUMBER]]);
        $this->inspect();
        Http::assertNotSent(static fn (ProviderRequest $request): bool =>
            ($request->data()['calledMethod'] ?? null) === 'save');
    }
}
