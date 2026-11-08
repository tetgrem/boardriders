<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaMeasuredTariffService;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class NovaPoshtaMeasuredTariffTest extends TestCase
{
    private string $sender;
    private string $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = (string) Str::uuid();
        $this->recipient = (string) Str::uuid();

        config()->set(
            'services.nova_poshta.api_key',
            'measured-tariff-test-key'
        );

        Http::preventStrayRequests();
    }

    public function test_branch_request_contains_actual_weight_and_outer_dimensions(): void
    {
        $this->fake(['Cost' => '95.50', 'CostRedelivery' => '13.20']);

        $result = $this->estimate();

        $this->assertSame(9550, $result['delivery_cost_minor']);
        $this->assertSame(1320, $result['cod_fee_minor']);
        $this->assertSame('WarehouseWarehouse', $result['service_type']);
        $this->assertFalse($result['provider_dimensions_confirmed']);
        $this->assertFalse($result['cod_eligibility_confirmed']);
        $this->assertFalse($result['ready_for_dispatch']);

        Http::assertSent(function (ProviderRequest $request): bool {
            $data = $request->data();
            $properties = $data['methodProperties'] ?? [];
            $seat = $properties['OptionsSeat'][0] ?? [];

            return $request->url() === 'https://api.novaposhta.ua/v2.0/json/'
                && ($data['calledMethod'] ?? null) === 'getDocumentPrice'
                && ($data['modelName'] ?? null) === 'InternetDocument'
                && ($data['apiKey'] ?? null) === 'measured-tariff-test-key'
                && ($properties['CitySender'] ?? null) === $this->sender
                && ($properties['CityRecipient'] ?? null) === $this->recipient
                && ($properties['Weight'] ?? null) === '0.700'
                && ($properties['Cost'] ?? null) === '20.00'
                && ($properties['SeatsAmount'] ?? null) === '1'
                && ($seat['weight'] ?? null) === '0.700'
                && ($seat['volumetricLength'] ?? null) === '30.0'
                && ($seat['volumetricWidth'] ?? null) === '20.0'
                && ($seat['volumetricHeight'] ?? null) === '10.0'
                && ($properties['RedeliveryCalculate']['Amount'] ?? null)
                === '20.00';
        });
    }

    public function test_postomat_uses_different_service_type(): void
    {
        $this->fake(['Cost' => 80, 'CostRedelivery' => 20]);

        $result = $this->estimate(type: 'parcel_locker');

        $this->assertSame('WarehousePostomat', $result['service_type']);

        Http::assertSent(
            static fn (ProviderRequest $request): bool =>
                ($request->data()['methodProperties']['ServiceType'] ?? null)
                === 'WarehousePostomat'
        );
    }

    public function test_no_cod_does_not_send_redelivery(): void
    {
        $this->fake(['Cost' => '70.00']);

        $result = $this->estimate(cod: 0);

        $this->assertNull($result['cod_fee_minor']);
        $this->assertTrue($result['cod_fee_available']);

        Http::assertSent(
            static fn (ProviderRequest $request): bool =>
            !array_key_exists(
                'RedeliveryCalculate',
                $request->data()['methodProperties'] ?? []
            )
        );
    }

    public function test_missing_cod_fee_remains_unconfirmed(): void
    {
        $this->fake(['Cost' => '70.00']);

        $result = $this->estimate();

        $this->assertNull($result['cod_fee_minor']);
        $this->assertFalse($result['cod_fee_available']);
        $this->assertFalse($result['cod_eligibility_confirmed']);
    }

    public function test_zero_cod_fee_is_not_mistaken_for_missing(): void
    {
        $this->fake(['Cost' => 70, 'CostRedelivery' => 0]);

        $result = $this->estimate();

        $this->assertSame(0, $result['cod_fee_minor']);
        $this->assertTrue($result['cod_fee_available']);
    }

    public function test_malformed_cost_is_rejected(): void
    {
        $this->fake(['Cost' => '95.999']);

        $this->expectException(RuntimeException::class);

        $this->estimate();
    }

    public function test_more_than_one_price_row_is_rejected(): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => true,
                'data' => [
                    ['Cost' => 80],
                    ['Cost' => 90],
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->estimate();
    }

    public function test_provider_business_failure_is_rejected(): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => false,
                'data' => [],
                'errors' => ['provider diagnostic'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->estimate();
    }

    public function test_invalid_city_uuid_does_not_send_http(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->estimate(sender: 'not-a-uuid');
    }

    public function test_invalid_dimensions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->estimate(length: 0);
    }

    public function test_cod_more_than_declared_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->estimate(cod: 2001);
    }

    public function test_request_hash_is_stable_for_same_parameters(): void
    {
        $this->fake(['Cost' => 80, 'CostRedelivery' => 10]);

        $first = $this->estimate();
        $second = $this->estimate();

        $this->assertSame(
            $first['provider_request_sha256'],
            $second['provider_request_sha256']
        );

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $first['provider_request_sha256']
        );

        Http::assertSentCount(2);
    }

    private function estimate(
        ?string $sender = null,
        string $type = 'branch',
        int $length = 300,
        int $cod = 2000,
    ): array {
        return app(NovaPoshtaMeasuredTariffService::class)->estimate(
            $sender ?? $this->sender,
            $this->recipient,
            $type,
            700,
            $length,
            200,
            100,
            2000,
            $cod,
        );
    }

    private function fake(array $row): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => true,
                'data' => [$row],
            ]),
        ]);
    }
}
