<?php

namespace Tests\Feature;

use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use App\Services\Pricing\NovaPoshtaWarehouseInspectionService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** No external HTTP requests. PostgreSQL boardriders_test only. */
final class NovaPoshtaWarehouseInspectionTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'test-nova-poshta-key-do-not-use-in-production';

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException('Only PostgreSQL boardriders_test is allowed');
        }

        Http::preventStrayRequests();
        config()->set('services.nova_poshta.api_key', self::API_KEY);

        $now = now();

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Ukrainian hryvnia',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('markets')->insert([
            'code' => 'UA',
            'name' => 'Ukraine',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $listId = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL',
            'name' => 'UA retail',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'type' => 'retail',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'sku' => 'NP-LOOKUP-' . Str::uuid(),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $listId,
            'price_minor' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'NP-LOOKUP-WH-' . Str::uuid(),
            'name' => 'Nova Poshta lookup test warehouse',
            'country_code' => 'UA',
            'kind' => 'owned',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 20,
            'reserved_qty' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_valid_branch_reference_is_directory_matched_only(): void
    {
        $subject = $this->selectedCod();

        $this->fakeDirectory(
            $subject['selection']['destination_ref'],
            'Branch'
        );

        $result = $this->service()->inspect(
            $subject['order']['order_id']
        );

        $this->assertTrue($result['directory_match']);
        $this->assertSame('branch', $result['delivery_type']);
        $this->assertSame(
            'Відділення №1',
            $result['warehouse_description']
        );

        $this->assertFalse($result['cod_eligibility_confirmed']);
        $this->assertFalse($result['delivery_fee_finalized']);
        $this->assertFalse($result['ready_for_dispatch']);

        $this->assertDatabaseHas('orders', [
            'id' => $subject['order']['order_id'],
            'payment_status' => 'pending',
        ]);

        $this->assertDatabaseCount(
            'checkout_cod_delivery_selections',
            1
        );

        $this->assertDatabaseCount(
            'checkout_payment_capture_receipts',
            0
        );
    }

    public function test_valid_parcel_locker_is_not_automatically_cod_eligible(): void
    {
        $subject = $this->selectedCod('parcel_locker');

        $this->fakeDirectory(
            $subject['selection']['destination_ref'],
            'Postomat'
        );

        $result = $this->service()->inspect(
            $subject['order']['order_id']
        );

        $this->assertSame(
            'parcel_locker',
            $result['delivery_type']
        );

        $this->assertFalse(
            $result['cod_eligibility_confirmed']
        );

        $this->assertFalse($result['ready_for_dispatch']);
    }

    public function test_request_targets_fixed_api_and_uses_server_side_key(): void
    {
        $subject = $this->selectedCod();
        $ref = $subject['selection']['destination_ref'];

        $this->fakeDirectory($ref, 'Branch');

        $this->service()->inspect(
            $subject['order']['order_id']
        );

        Http::assertSentCount(1);

        Http::assertSent(
            static function (HttpRequest $request) use ($ref): bool {
                $payload = $request->data();

                return $request->method() === 'POST'
                    && $request->url() === 'https://api.novaposhta.ua/v2.0/json/'
                    && ($payload['apiKey'] ?? null) === self::API_KEY
                    && ($payload['modelName'] ?? null) === 'AddressGeneral'
                    && ($payload['calledMethod'] ?? null) === 'getWarehouses'
                    && ($payload['methodProperties']['Ref'] ?? null) === $ref
                    && ($payload['methodProperties']['Limit'] ?? null) === 1;
            }
        );
    }

    public function test_missing_api_key_blocks_http_request(): void
    {
        $subject = $this->selectedCod();

        config()->set('services.nova_poshta.api_key', null);

        try {
            $this->service()->inspect(
                $subject['order']['order_id']
            );

            $this->fail(
                'Expected missing API key to reject inspection'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Nova Poshta API key is not configured',
                $exception->getMessage()
            );
        }

        Http::assertNothingSent();
    }

    public function test_unknown_order_is_rejected_before_http(): void
    {
        $this->expectException(DomainException::class);

        try {
            $this->service()->inspect(99999999);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_order_without_selected_destination_is_rejected(): void
    {
        $order = $this->prepared();

        $this->expectException(DomainException::class);

        try {
            $this->service()->inspect(
                $order['order_id']
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_expired_selection_is_rejected_without_api_call(): void
    {
        $expires = CarbonImmutable::now('UTC')->addMinute();

        $subject = $this->selectedCod('branch', $expires);

        CarbonImmutable::setTestNow(
            $expires->addSecond()
        );

        $this->expectException(DomainException::class);

        try {
            $this->service()->inspect(
                $subject['order']['order_id']
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_canceled_order_is_rejected_without_api_call(): void
    {
        $subject = $this->selectedCod();

        DB::table('orders')
            ->where('id', $subject['order']['order_id'])
            ->update(['payment_status' => 'canceled']);

        $this->expectException(DomainException::class);

        try {
            $this->service()->inspect(
                $subject['order']['order_id']
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_http_error_does_not_verify_warehouse(): void
    {
        $subject = $this->selectedCod();

        Http::fake([
            'api.novaposhta.ua/*' => Http::response([], 503),
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->inspect(
            $subject['order']['order_id']
        );
    }

    public function test_carrier_reported_failure_is_rejected(): void
    {
        $subject = $this->selectedCod();

        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => false,
                'data' => [],
                'errors' => ['Unavailable'],
            ]),
        ]);

        $this->expectException(DomainException::class);

        $this->service()->inspect(
            $subject['order']['order_id']
        );
    }

    public function test_wrong_returned_ref_cannot_validate_selected_warehouse(): void
    {
        $subject = $this->selectedCod();

        $this->fakeDirectory(
            (string) Str::uuid(),
            'Branch'
        );

        $this->expectException(DomainException::class);

        $this->service()->inspect(
            $subject['order']['order_id']
        );
    }

    public function test_empty_directory_result_is_rejected(): void
    {
        $subject = $this->selectedCod();

        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => true,
                'data' => [],
            ]),
        ]);

        $this->expectException(DomainException::class);

        $this->service()->inspect(
            $subject['order']['order_id']
        );
    }

    public function test_multiple_directory_matches_are_rejected_as_ambiguous(): void
    {
        $subject = $this->selectedCod();

        $ref = $subject['selection']['destination_ref'];

        $record = [
            'Ref' => $ref,
            'CategoryOfWarehouse' => 'Branch',
            'Description' => 'Відділення №1',
        ];

        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => true,
                'data' => [$record, $record],
            ]),
        ]);

        $this->expectException(DomainException::class);

        $this->service()->inspect(
            $subject['order']['order_id']
        );
    }

    public function test_wrong_warehouse_type_cannot_satisfy_branch_selection(): void
    {
        $subject = $this->selectedCod('branch');

        $this->fakeDirectory(
            $subject['selection']['destination_ref'],
            'Postomat'
        );

        $this->expectException(DomainException::class);

        $this->service()->inspect(
            $subject['order']['order_id']
        );
    }

    public function test_unknown_category_is_rejected_not_guessed(): void
    {
        $subject = $this->selectedCod();

        $this->fakeDirectory(
            $subject['selection']['destination_ref'],
            'Unknown'
        );

        $this->expectException(DomainException::class);

        $this->service()->inspect(
            $subject['order']['order_id']
        );
    }

    public function test_non_json_response_cannot_verify_warehouse(): void
    {
        $subject = $this->selectedCod();

        Http::fake([
            'api.novaposhta.ua/*' => Http::response(
                'not json',
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);

        $this->expectException(DomainException::class);

        $this->service()->inspect(
            $subject['order']['order_id']
        );
    }

    // ========================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // ========================================

    /** @return array<string,mixed> */
    private function prepared(
        ?CarbonImmutable $expiresAt = null
    ): array {
        return app(CheckoutOrderPreparationService::class)
            ->prepare(
                (string) Str::uuid(),
                [
                    [
                        'variant_id' => $this->variantId,
                        'quantity' => 2,
                    ],
                ],
                'UA',
                'UA_RETAIL',
                $expiresAt ?? CarbonImmutable::now('UTC')
                ->addMinutes(30)
            );
    }

    /**
     * @return array{
     *     order:array<string,mixed>,
     *     selection:array<string,mixed>
     * }
     */
    private function selectedCod(
        string $deliveryType = 'branch',
        ?CarbonImmutable $expiresAt = null
    ): array {
        $order = $this->prepared($expiresAt);

        app(CheckoutPaymentChoiceService::class)
            ->choose(
                $order['order_key'],
                'cash_on_delivery'
            );

        app(CheckoutCashOnDeliveryRequestService::class)
            ->request(
                $order['order_key'],
                (string) Str::uuid()
            );

        $selection = app(
            CheckoutCodDeliverySelectionService::class
        )->select(
            $order['order_key'],
            (string) Str::uuid(),
            $deliveryType,
            (string) Str::uuid()
        );

        return [
            'order' => $order,
            'selection' => $selection,
        ];
    }

    private function fakeDirectory(
        string $ref,
        string $category
    ): void {
        Http::fake([
            'api.novaposhta.ua/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'Ref' => $ref,
                        'CategoryOfWarehouse' => $category,
                        'Description' => 'Відділення №1',
                    ],
                ],
            ], 200),
        ]);
    }

    private function service(): NovaPoshtaWarehouseInspectionService
    {
        return app(NovaPoshtaWarehouseInspectionService::class);
    }
}
