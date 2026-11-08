<?php

namespace Tests\Feature;

use App\Services\Pricing\NovaPoshtaDirectoryService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NovaPoshtaDirectoryApiTest extends TestCase
{
    private const KEY = 'np-directory-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate directory cache from Redis and other test processes.
        config()->set('cache.default', 'array');
        Cache::clear();

        config()->set('services.nova_poshta.api_key', self::KEY);

        // Any unexpected external HTTP request must fail.
        Http::preventStrayRequests();
    }

    public function test_searches_settlements_and_normalizes_results(): void
    {
        $ref = (string) Str::uuid();

        $this->fakeSettlements([
            [
                'Ref' => $ref,
                'Description' => 'Київ',
                'AreaDescription' => 'Київська',
            ],
        ]);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q='
            . urlencode('Київ')
        )
            ->assertOk()
            ->assertJsonPath('data.0.ref', $ref)
            ->assertJsonPath('data.0.name', 'Київ')
            ->assertJsonPath('data.0.region', 'Київська')
            ->assertJsonPath('meta.count', 1);
    }

    public function test_settlement_search_is_cached(): void
    {
        $this->fakeSettlements([
            [
                'Ref' => (string) Str::uuid(),
                'Description' => 'Львів',
            ],
        ]);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q='
            . urlencode('Львів')
        )->assertOk();

        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q='
            . urlencode('Львів')
        )->assertOk();

        Http::assertSentCount(1);
    }

    public function test_settlement_search_uses_correct_provider_method(): void
    {
        $this->fakeSettlements([]);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q='
            . urlencode('Одеса')
        )->assertOk();

        Http::assertSent(static function (HttpRequest $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.novaposhta.ua/v2.0/json/'
                && ($data['apiKey'] ?? null) === self::KEY
                && ($data['modelName'] ?? null) === 'Address'
                && ($data['calledMethod'] ?? null) === 'getCities'
                && ($data['methodProperties']['FindByString'] ?? null) === 'Одеса';
        });
    }

    public function test_short_settlement_query_is_rejected_without_http(): void
    {
        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q=А'
        )->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_invalid_settlement_limit_is_rejected(): void
    {
        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q=Київ&limit=999'
        )->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_warehouse_endpoint_returns_both_supported_types(): void
    {
        $cityRef = (string) Str::uuid();
        $branchRef = (string) Str::uuid();
        $lockerRef = (string) Str::uuid();

        $this->fakeWarehouses([
            [
                'Ref' => $branchRef,
                'Description' => 'Відділення №1',
                'CategoryOfWarehouse' => 'Branch',
                'Number' => '1',
            ],
            [
                'Ref' => $lockerRef,
                'Description' => 'Поштомат №2',
                'CategoryOfWarehouse' => 'Postomat',
                'Number' => '2',
            ],
        ]);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/warehouses?city_ref=' . $cityRef
        )
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.ref', $branchRef)
            ->assertJsonPath('data.0.type', 'branch')
            ->assertJsonPath('data.1.ref', $lockerRef)
            ->assertJsonPath('data.1.type', 'parcel_locker')
            ->assertJsonPath('data.1.cod_eligibility_confirmed', false)
            ->assertJsonPath('meta.cod_eligibility_confirmed', false);
    }

    public function test_filters_warehouses_by_type(): void
    {
        $cityRef = (string) Str::uuid();

        $this->fakeWarehouses([
            [
                'Ref' => (string) Str::uuid(),
                'Description' => 'Відділення №1',
                'CategoryOfWarehouse' => 'Branch',
            ],
            [
                'Ref' => (string) Str::uuid(),
                'Description' => 'Поштомат №2',
                'CategoryOfWarehouse' => 'Postomat',
            ],
        ]);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/warehouses?city_ref='
            . $cityRef . '&type=parcel_locker'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'parcel_locker');
    }

    public function test_warehouse_request_uses_city_ref_and_pagination(): void
    {
        $cityRef = (string) Str::uuid();

        $this->fakeWarehouses([]);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/warehouses?city_ref='
            . $cityRef . '&page=2&limit=10'
        )->assertOk();

        Http::assertSent(static function (HttpRequest $request) use ($cityRef): bool {
            $data = $request->data();

            return ($data['calledMethod'] ?? null) === 'getWarehouses'
                && ($data['methodProperties']['CityRef'] ?? null) === $cityRef
                && ($data['methodProperties']['Page'] ?? null) === 2
                && ($data['methodProperties']['Limit'] ?? null) === 10;
        });
    }

    public function test_warehouse_cache_is_shared_between_type_filters(): void
    {
        $cityRef = (string) Str::uuid();

        $this->fakeWarehouses([
            [
                'Ref' => (string) Str::uuid(),
                'Description' => 'Відділення №1',
                'CategoryOfWarehouse' => 'Branch',
            ],
            [
                'Ref' => (string) Str::uuid(),
                'Description' => 'Поштомат №2',
                'CategoryOfWarehouse' => 'Postomat',
            ],
        ]);

        $base = '/api/v1/delivery/nova-poshta/warehouses?city_ref=' . $cityRef;

        $this->getJson($base . '&type=branch')->assertOk();
        $this->getJson($base . '&type=parcel_locker')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_invalid_city_ref_is_rejected_without_http(): void
    {
        $this->getJson(
            '/api/v1/delivery/nova-poshta/warehouses?city_ref=invalid'
        )->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_invalid_warehouse_type_is_rejected(): void
    {
        $this->getJson(
            '/api/v1/delivery/nova-poshta/warehouses?city_ref='
            . Str::uuid() . '&type=courier'
        )->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_invalid_pagination_is_rejected(): void
    {
        $this->getJson(
            '/api/v1/delivery/nova-poshta/warehouses?city_ref='
            . Str::uuid() . '&page=0'
        )->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_provider_http_failure_returns_safe_503(): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([], 503),
        ]);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q=Київ'
        )
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'Delivery directory temporarily unavailable'
            );
    }

    public function test_provider_business_failure_returns_safe_503(): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => false,
                'data' => [],
                'errors' => ['Private provider diagnostic'],
            ]),
        ]);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q=Київ'
        )
            ->assertStatus(503)
            ->assertDontSee('Private provider diagnostic');
    }

    public function test_missing_api_key_does_not_make_http_request(): void
    {
        config()->set('services.nova_poshta.api_key', null);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q=Київ'
        )->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_invalid_directory_rows_are_not_exposed(): void
    {
        $validRef = (string) Str::uuid();

        $this->fakeSettlements([
            [
                'Ref' => 'not-a-uuid',
                'Description' => 'Invalid',
            ],
            [
                'Ref' => $validRef,
                'Description' => 'Черкаси',
            ],
            [
                'Ref' => $validRef,
                'Description' => 'Duplicate',
            ],
        ]);

        $this->getJson(
            '/api/v1/delivery/nova-poshta/settlements?q=Черкаси'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Черкаси');
    }

    public function test_service_rejects_control_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(NovaPoshtaDirectoryService::class)
            ->settlements("Київ\n");
    }

    private function fakeSettlements(array $data): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => true,
                'data' => $data,
            ], 200),
        ]);
    }

    private function fakeWarehouses(array $data): void
    {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => true,
                'data' => $data,
            ], 200),
        ]);
    }
}
