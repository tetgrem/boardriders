<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaTtnDocumentProbe;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class NovaPoshtaTtnDocumentProbeTest extends TestCase
{
    private const NUMBER = '20450000000001';
    private const REF = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.nova_poshta.api_key', 'document-compare-test-key');
        Http::preventStrayRequests();
    }

    private function runProbe(): array
    {
        return app(NovaPoshtaTtnDocumentProbe::class)->inspect(self::NUMBER, [
            'IntDocNumber' => self::NUMBER, 'Sender' => self::REF,
            'Recipient' => self::REF, 'CitySender' => self::REF,
            'CityRecipient' => self::REF, 'RecipientAddress' => self::REF,
            'ServiceType' => 'WarehouseWarehouse', 'Weight' => 700, 'Cost' => 2000,
        ]);
    }

    private function fake(array $listing, array $document = []): void
    {
        Http::fake(static function (ProviderRequest $request) use ($listing, $document) {
            $method = $request->data()['calledMethod'] ?? null;
            return Http::response(['success' => true, 'data' => match ($method) {
                'getDocumentList' => $listing,
                'getDocument' => $document,
                default => [],
            }]);
        });
    }

    public function test_ref_lookup_then_document_lookup_are_read_only(): void
    {
        $row = [
            'Ref' => self::REF, 'IntDocNumber' => self::NUMBER,
            'Sender' => self::REF, 'Recipient' => self::REF,
            'CitySender' => self::REF, 'CityRecipient' => self::REF,
            'RecipientAddress' => self::REF, 'ServiceType' => 'WarehouseWarehouse',
            'Weight' => '0.700', 'Cost' => '20.00',
        ];
        $this->fake([$row], [$row]);
        $this->assertSame('snapshot_consistent', $this->runProbe()['summary']);
        Http::assertSentCount(2);
        Http::assertSent(static fn (ProviderRequest $r): bool =>
            ($r->data()['calledMethod'] ?? null) === 'getDocument'
            && ($r->data()['methodProperties']['Ref'] ?? null) === self::REF);
        Http::assertNotSent(static fn (ProviderRequest $r): bool =>
            ($r->data()['calledMethod'] ?? null) === 'save');
    }

    public function test_missing_number_in_list_never_requests_document(): void
    {
        $this->fake([]);
        $this->assertSame('inconclusive_list', $this->runProbe()['summary']);
        Http::assertSentCount(1);
    }

    public function test_duplicate_number_never_requests_document(): void
    {
        $row = ['Ref' => self::REF, 'IntDocNumber' => self::NUMBER];
        $this->fake([$row, $row]);
        $this->assertSame('inconclusive_list', $this->runProbe()['summary']);
        Http::assertSentCount(1);
    }

    public function test_list_without_valid_ref_is_inconclusive(): void
    {
        $this->fake([['Ref' => 'bad', 'IntDocNumber' => self::NUMBER]]);
        $this->assertSame('inconclusive_list', $this->runProbe()['summary']);
    }

    public function test_empty_document_response_is_inconclusive(): void
    {
        $this->fake([['Ref' => self::REF, 'IntDocNumber' => self::NUMBER]], []);
        $this->assertSame('inconclusive_document', $this->runProbe()['summary']);
        Http::assertSentCount(2);
    }

    public function test_invalid_number_makes_no_http_request(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(NovaPoshtaTtnDocumentProbe::class)->inspect('bad', []);
    }

    public function test_provider_error_never_looks_like_missing_document(): void
    {
        Http::fake(['api.novaposhta.ua/*' => Http::response([
            'success' => false, 'data' => [], 'errors' => ['test'],
        ])]);
        $this->expectException(RuntimeException::class);
        $this->runProbe();
    }
}
