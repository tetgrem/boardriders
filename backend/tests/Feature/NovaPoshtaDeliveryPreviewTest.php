<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NovaPoshtaDeliveryPreviewTest extends TestCase
{
    private string $city;
    private string $warehouse;
    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = (string) Str::uuid();
        $this->warehouse = (string) Str::uuid();
        $this->sender = (string) Str::uuid();

        config()->set('services.nova_poshta.api_key', 'test-provider-key');
        config()->set('services.nova_poshta.sender_city_ref', $this->sender);
        Http::preventStrayRequests();
    }

    public function test_verified_working_branch_is_recognized_without_confirming_cod(): void
    {
        $this->fakeWarehouseRows([$this->warehouseRow()]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertOk()
            ->assertJsonPath('data.directory_match', true)
            ->assertJsonPath('data.type_match', true)
            ->assertJsonPath('data.operational', true)
            ->assertJsonPath('data.destination_verified', true)
            ->assertJsonPath('data.cod_eligibility_confirmed', false)
            ->assertJsonPath('data.ready_for_dispatch', false);
    }

    public function test_matching_postomat_is_recognized(): void
    {
        $this->fakeWarehouseRows([$this->warehouseRow('Postomat')]);

        $this->postJson($this->verifyUrl(), $this->destination('parcel_locker'))
            ->assertOk()
            ->assertJsonPath('data.destination_verified', true);
    }

    public function test_wrong_warehouse_ref_does_not_pass(): void
    {
        $this->fakeWarehouseRows([
            array_replace($this->warehouseRow(), ['Ref' => (string) Str::uuid()]),
        ]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertOk()
            ->assertJsonPath('data.directory_match', false)
            ->assertJsonPath('data.destination_verified', false);
    }

    public function test_warehouse_from_another_city_does_not_pass(): void
    {
        $this->fakeWarehouseRows([
            array_replace($this->warehouseRow(), ['CityRef' => (string) Str::uuid()]),
        ]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertOk()
            ->assertJsonPath('data.directory_match', false);
    }

    public function test_missing_city_ref_in_provider_row_fails_closed(): void
    {
        $row = $this->warehouseRow();
        unset($row['CityRef']);
        $this->fakeWarehouseRows([$row]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertOk()
            ->assertJsonPath('data.destination_verified', false);
    }

    public function test_incorrect_category_fails_type_check(): void
    {
        $this->fakeWarehouseRows([$this->warehouseRow('Postomat')]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertOk()
            ->assertJsonPath('data.directory_match', true)
            ->assertJsonPath('data.type_match', false)
            ->assertJsonPath('data.destination_verified', false);
    }

    public function test_closed_warehouse_does_not_pass(): void
    {
        $this->fakeWarehouseRows([
            array_replace($this->warehouseRow(), ['WarehouseStatus' => 'Closed']),
        ]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertOk()
            ->assertJsonPath('data.operational', false)
            ->assertJsonPath('data.destination_verified', false);
    }

    public function test_missing_status_fails_closed(): void
    {
        $row = $this->warehouseRow();
        unset($row['WarehouseStatus']);
        $this->fakeWarehouseRows([$row]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertOk()
            ->assertJsonPath('data.destination_verified', false);
    }

    public function test_unrecognized_category_fails_closed(): void
    {
        $this->fakeWarehouseRows([$this->warehouseRow('Unknown')]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertOk()
            ->assertJsonPath('data.destination_verified', false);
    }

    public function test_invalid_destination_is_rejected_without_http(): void
    {
        $this->postJson($this->verifyUrl(), [
            'city_ref' => 'bad',
            'warehouse_ref' => $this->warehouse,
            'type' => 'branch',
        ])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_pagination_scans_second_page_for_exact_ref(): void
    {
        $firstPage = [];
        for ($i = 0; $i < 100; $i++) {
            $firstPage[] = array_replace($this->warehouseRow(), [
                'Ref' => (string) Str::uuid(),
            ]);
        }

        Http::fakeSequence()
            ->push(['success' => true, 'data' => $firstPage])
            ->push(['success' => true, 'data' => [$this->warehouseRow()]]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertOk()
            ->assertJsonPath('data.destination_verified', true);

        Http::assertSentCount(2);
        Http::assertSent(static fn (ProviderRequest $request): bool =>
            ($request->data()['methodProperties']['Page'] ?? null) === 2
        );
    }

    public function test_bad_provider_response_returns_safe_503(): void
    {
        Http::fake(['api.novaposhta.ua/*' => Http::response([
            'success' => false,
            'errors' => ['Sensitive provider information'],
            'data' => [],
        ])]);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertStatus(503)
            ->assertDontSee('Sensitive provider information');
    }

    public function test_missing_provider_key_is_safe(): void
    {
        config()->set('services.nova_poshta.api_key', null);

        $this->postJson($this->verifyUrl(), $this->destination())
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_quote_uses_ordered_provider_calls_and_exact_minor_units(): void
    {
        $this->fakeQuote(87.5, '15.75');

        $this->postJson($this->quoteUrl(), $this->quoteData())
            ->assertOk()
            ->assertJsonPath('data.delivery_cost_minor', 8750)
            ->assertJsonPath('data.cod_transfer_fee_minor', 1575)
            ->assertJsonPath('data.cod_fee_available', true)
            ->assertJsonPath('data.currency_code', 'UAH')
            ->assertJsonPath('data.estimated_only', true)
            ->assertJsonPath('data.delivery_fee_finalized', false)
            ->assertJsonPath('data.cod_eligibility_confirmed', false);

        Http::assertSentCount(2);
        Http::assertSent(function (ProviderRequest $request): bool {
            $data = $request->data();
            $props = $data['methodProperties'] ?? [];

            return ($data['modelName'] ?? null) === 'InternetDocument'
                && ($data['calledMethod'] ?? null) === 'getDocumentPrice'
                && ($props['CitySender'] ?? null) === $this->sender
                && ($props['CityRecipient'] ?? null) === $this->city
                && ($props['Weight'] ?? null) === '1.250'
                && ($props['Cost'] ?? null) === '500.00'
                && ($props['SeatsAmount'] ?? null) === '1'
                && ($props['RedeliveryCalculate']['Amount'] ?? null) === '200.00';
        });
    }

    public function test_quote_without_cod_does_not_request_redelivery(): void
    {
        $this->fakeQuote('50.00', null);
        $payload = $this->quoteData();
        $payload['cod_amount_minor'] = 0;

        $this->postJson($this->quoteUrl(), $payload)
            ->assertOk()
            ->assertJsonPath('data.delivery_cost_minor', 5000)
            ->assertJsonPath('data.cod_transfer_fee_minor', null);

        Http::assertSent(function (ProviderRequest $request): bool {
            $data = $request->data();

            return ($data['calledMethod'] ?? null) === 'getDocumentPrice'
                && !array_key_exists('RedeliveryCalculate', $data['methodProperties'] ?? []);
        });
    }

    public function test_quote_never_falsely_confirms_missing_cod_fee(): void
    {
        $this->fakeQuote('70.00', null);

        $this->postJson($this->quoteUrl(), $this->quoteData())
            ->assertOk()
            ->assertJsonPath('data.cod_fee_available', false)
            ->assertJsonPath('data.cod_transfer_fee_minor', null)
            ->assertJsonPath('data.cod_eligibility_confirmed', false);
    }

    public function test_unverified_destination_does_not_request_price(): void
    {
        $this->fakeWarehouseRows([]);

        $this->postJson($this->quoteUrl(), $this->quoteData())
            ->assertUnprocessable();

        Http::assertSentCount(1);
    }

    public function test_missing_sender_city_config_fails_before_http(): void
    {
        config()->set('services.nova_poshta.sender_city_ref', null);

        $this->postJson($this->quoteUrl(), $this->quoteData())
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_cod_amount_above_declared_value_is_rejected(): void
    {
        $data = $this->quoteData();
        $data['cod_amount_minor'] = 60000;

        $this->postJson($this->quoteUrl(), $data)
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_zero_weight_is_rejected(): void
    {
        $data = $this->quoteData();
        $data['weight_grams'] = 0;

        $this->postJson($this->quoteUrl(), $data)
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_invalid_price_data_is_rejected_safely(): void
    {
        $this->fakeQuote('85.999', '12.00');

        $this->postJson($this->quoteUrl(), $this->quoteData())
            ->assertStatus(503);
    }

    public function test_multiple_price_rows_are_rejected(): void
    {
        $this->fakeProvider([$this->warehouseRow()], [
            ['Cost' => '50.00'],
            ['Cost' => '60.00'],
        ]);

        $this->postJson($this->quoteUrl(), $this->quoteData())
            ->assertStatus(503);
    }

    public function test_unexpected_provider_calls_are_not_allowed(): void
    {
        $this->fakeQuote('65', '10');

        $this->postJson($this->quoteUrl(), $this->quoteData())->assertOk();

        Http::assertSent(function (ProviderRequest $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.novaposhta.ua/v2.0/json/'
                && ($data['apiKey'] ?? null) === 'test-provider-key';
        });
    }

    private function destination(string $type = 'branch'): array
    {
        return [
            'city_ref' => $this->city,
            'warehouse_ref' => $this->warehouse,
            'type' => $type,
        ];
    }

    private function quoteData(): array
    {
        return array_merge($this->destination(), [
            'weight_grams' => 1250,
            'declared_value_minor' => 50000,
            'cod_amount_minor' => 20000,
            'seats_amount' => 1,
        ]);
    }

    private function warehouseRow(string $category = 'Branch'): array
    {
        return [
            'Ref' => $this->warehouse,
            'CityRef' => $this->city,
            'CategoryOfWarehouse' => $category,
            'WarehouseStatus' => 'Working',
            'Description' => 'Відділення №1',
        ];
    }

    private function fakeWarehouseRows(array $rows): void
    {
        Http::fake(['api.novaposhta.ua/*' => Http::response([
            'success' => true,
            'data' => $rows,
        ])]);
    }

    private function fakeQuote(int|float|string $cost, int|float|string|null $codFee): void
    {
        $price = ['Cost' => $cost];
        if ($codFee !== null) {
            $price['CostRedelivery'] = $codFee;
        }

        $this->fakeProvider([$this->warehouseRow()], [$price]);
    }

    private function fakeProvider(array $warehouses, array $priceRows): void
    {
        Http::fake(function (ProviderRequest $request) use ($warehouses, $priceRows) {
            $method = $request->data()['calledMethod'] ?? null;

            return match ($method) {
                'getWarehouses' => Http::response(['success' => true, 'data' => $warehouses]),
                'getDocumentPrice' => Http::response(['success' => true, 'data' => $priceRows]),
                default => Http::response(['success' => false, 'data' => []], 400),
            };
        });
    }

    private function verifyUrl(): string
    {
        return '/api/v1/delivery/nova-poshta/verify';
    }

    private function quoteUrl(): string
    {
        return '/api/v1/delivery/nova-poshta/quote';
    }
}
