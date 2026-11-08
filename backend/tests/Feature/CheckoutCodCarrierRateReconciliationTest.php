<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutCodCarrierRateReconciliationService;
use App\Services\Pricing\CheckoutCodPackageAssessmentService;
use App\Services\Pricing\CheckoutCodParcelManifestService;
use App\Services\Pricing\CheckoutCodRecipientService;
use App\Services\Pricing\CheckoutCodShippingQuoteService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use App\Services\Pricing\ProductVariantShippingMetricsService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class CheckoutCodCarrierRateReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;
    private string $orderKey;
    private string $cityRef;
    private string $destinationRef;
    private string $senderRef;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Requires PostgreSQL boardriders_test'
            );
        }

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

        $priceListId = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL',
            'name' => 'Retail UA',
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
            'sku' => 'RATE-SKU-' . Str::uuid(),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $priceListId,
            'price_minor' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'RATE-WH-' . Str::uuid(),
            'name' => 'Rate test warehouse',
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

        app(ProductVariantShippingMetricsService::class)->change(
            $this->variantId,
            (string) Str::uuid(),
            0,
            300,
            200,
            100,
            50
        );

        $this->cityRef = (string) Str::uuid();
        $this->destinationRef = (string) Str::uuid();
        $this->senderRef = (string) Str::uuid();

        config()->set(
            'services.nova_poshta.api_key',
            'measured-rate-test-key'
        );

        config()->set(
            'services.nova_poshta.sender_city_ref',
            $this->senderRef
        );

        $this->orderKey = $this->makeCodOrder();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_rates_real_order_against_measured_box_without_authorizing_cod(): void
    {
        $this->preparePackage();
        $this->fakeCarrier();

        $rate = $this->reconcile();

        $this->assertSame(700, $rate['gross_grams']);
        $this->assertSame(1500, $rate['chargeable_grams']);
        $this->assertSame(9500, $rate['delivery_cost_minor']);
        $this->assertSame(1320, $rate['cod_fee_minor']);
        $this->assertSame(2000, $rate['goods_due_minor']);
        $this->assertSame('WarehouseWarehouse', $rate['service_type']);

        $this->assertFalse($rate['provider_dimensions_confirmed']);
        $this->assertFalse($rate['cod_eligibility_confirmed']);
        $this->assertFalse($rate['ready_for_dispatch']);

        $this->assertDatabaseCount('checkout_cod_measured_rates', 1);

        Http::assertSentCount(1);

        Http::assertSent(static function (ProviderRequest $request): bool {
            $properties = $request->data()['methodProperties'] ?? [];

            return ($properties['Weight'] ?? null) === '0.700'
                && ($properties['OptionsSeat'][0]['volumetricLength'] ?? null)
                === '30.0'
                && ($properties['Cost'] ?? null) === '20.00'
                && ($properties['RedeliveryCalculate']['Amount'] ?? null)
                === '20.00';
        });
    }

    public function test_repeat_with_same_key_is_idempotent_without_http(): void
    {
        $this->preparePackage();
        $this->fakeCarrier();

        $key = (string) Str::uuid();

        $this->assertSame(
            $this->reconcile($key),
            $this->reconcile($key)
        );

        Http::assertSentCount(1);
    }

    public function test_different_key_cannot_replace_a_saved_rate(): void
    {
        $this->preparePackage();
        $this->fakeCarrier();

        $this->reconcile();

        $this->expectException(DomainException::class);
        $this->reconcile();
    }

    public function test_cannot_rate_without_package_assessment(): void
    {
        app(CheckoutCodParcelManifestService::class)->capture(
            $this->orderKey,
            (string) Str::uuid(),
            100
        );

        $this->expectException(DomainException::class);
        $this->reconcile();
    }

    public function test_manual_review_packaging_stops_before_provider(): void
    {
        $this->preparePackage(
            length: 1201,
            width: 700,
            height: 700
        );

        try {
            $this->reconcile();
            $this->fail('Manual review package must not be rated');
        } catch (DomainException) {
            Http::assertNothingSent();
        }
    }

    public function test_oversize_postomat_stops_before_provider(): void
    {
        $this->orderKey = $this->makeCodOrder('parcel_locker');

        $this->preparePackage(
            length: 601,
            width: 400,
            height: 300
        );

        try {
            $this->reconcile();
            $this->fail('Oversize locker package must not be rated');
        } catch (DomainException) {
            Http::assertNothingSent();
        }
    }

    public function test_valid_postomat_rate_uses_postomat_service_type(): void
    {
        $this->orderKey = $this->makeCodOrder('parcel_locker');

        $this->preparePackage();
        $this->fakeCarrier();

        $result = $this->reconcile();

        $this->assertSame('WarehousePostomat', $result['service_type']);

        Http::assertSent(
            static fn (ProviderRequest $request): bool =>
                ($request->data()['methodProperties']['ServiceType'] ?? null)
                === 'WarehousePostomat'
        );
    }

    public function test_expired_checkout_stops_before_provider(): void
    {
        $this->preparePackage();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addHour()
        );

        $this->expectException(DomainException::class);
        $this->reconcile();
    }

    public function test_released_stock_stops_before_provider(): void
    {
        $this->preparePackage();

        $key = (string) DB::table('inventory_reservations')
            ->where('order_id', $this->orderId())
            ->value('reservation_key');

        app(InventoryService::class)->releaseOrderReservation(
            $key,
            2,
            (string) Str::uuid()
        );

        $this->expectException(DomainException::class);
        $this->reconcile();
    }

    public function test_provider_failure_does_not_persist_any_rate(): void
    {
        $this->preparePackage();
        $this->fakeCarrier(malformed: true);

        try {
            $this->reconcile();
            $this->fail('Malformed provider price must fail');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('checkout_cod_measured_rates', 0);
        }
    }

    public function test_missing_cod_fee_is_preserved_as_unknown(): void
    {
        $this->preparePackage();
        $this->fakeCarrier(codFee: null);

        $saved = $this->reconcile();

        $this->assertNull($saved['cod_fee_minor']);
        $this->assertSame('cod_fee_missing', $saved['status']);
        $this->assertFalse($saved['cod_eligibility_confirmed']);
    }

    public function test_reconciliation_records_difference_from_older_estimate(): void
    {
        $this->preparePackage();
        $this->fakeCarrier();

        $legacy = app(CheckoutCodShippingQuoteService::class)->quote(
            $this->orderKey,
            (string) Str::uuid(),
            700
        );

        $this->assertSame(6000, $legacy['delivery_cost_minor']);

        $saved = $this->reconcile();

        $this->assertSame(6000, $saved['legacy_cost_minor']);
        $this->assertSame(3500, $saved['delta_from_legacy_minor']);
        $this->assertNotNull($saved['legacy_quote_id']);

        Http::assertSentCount(3);
    }

    public function test_database_blocks_changes_to_recorded_rate(): void
    {
        $this->preparePackage();
        $this->fakeCarrier();

        $saved = $this->reconcile();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_measured_rates')
            ->where('id', $saved['id'])
            ->update(['delivery_cost_minor' => 1]);
    }

    public function test_database_rejects_link_to_different_order(): void
    {
        $this->preparePackage();

        $assessment = DB::table('checkout_cod_package_assessments')
            ->where('order_id', $this->orderId())
            ->first();

        $other = $this->makeCodOrder();

        $otherId = (int) DB::table('orders')
            ->where('order_key', $other)
            ->value('id');

        $snapshot = DB::table('order_financial_snapshots')
            ->where('order_id', $this->orderId())
            ->first();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_measured_rates')->insert([
            'operation_key' => (string) Str::uuid(),
            'order_id' => $otherId,
            'assessment_id' => $assessment->id,
            'manifest_id' => $assessment->manifest_id,
            'sender_city_ref' => $this->senderRef,
            'recipient_city_ref' => $this->cityRef,
            'service_type' => 'WarehouseWarehouse',
            'goods_due_minor' => 2000,
            'snapshot_sha256' => $snapshot->snapshot_sha256,
            'gross_grams' => 700,
            'chargeable_grams' => 1500,
            'length_mm' => 300,
            'width_mm' => 200,
            'height_mm' => 100,
            'delivery_cost_minor' => 9500,
            'cod_fee_minor' => 1320,
            'request_sha256' => str_repeat('a', 64),
            'provider_request_sha256' => str_repeat('b', 64),
            'created_at' => now(),
        ]);
    }

    private function preparePackage(
        int $length = 300,
        int $width = 200,
        int $height = 100
    ): void {
        app(CheckoutCodParcelManifestService::class)->capture(
            $this->orderKey,
            (string) Str::uuid(),
            100
        );

        app(CheckoutCodPackageAssessmentService::class)->assess(
            $this->orderKey,
            (string) Str::uuid(),
            $length,
            $width,
            $height
        );
    }

    private function reconcile(?string $key = null): array
    {
        return app(
            CheckoutCodCarrierRateReconciliationService::class
        )->reconcile(
            $this->orderKey,
            $key ?? (string) Str::uuid()
        );
    }

    private function makeCodOrder(
        string $deliveryType = 'branch'
    ): string {
        $key = (string) Str::uuid();

        app(CheckoutOrderPreparationService::class)->prepare(
            $key,
            [
                [
                    'variant_id' => $this->variantId,
                    'quantity' => 2,
                ],
            ],
            'UA',
            'UA_RETAIL',
            CarbonImmutable::now('UTC')->addMinutes(30)
        );

        app(CheckoutPaymentChoiceService::class)->choose(
            $key,
            'cash_on_delivery'
        );

        app(CheckoutCashOnDeliveryRequestService::class)->request(
            $key,
            (string) Str::uuid()
        );

        app(CheckoutCodDeliverySelectionService::class)->select(
            $key,
            (string) Str::uuid(),
            $deliveryType,
            $this->destinationRef
        );

        app(CheckoutCodRecipientService::class)->save(
            $key,
            (string) Str::uuid(),
            $this->cityRef,
            'Іван',
            'Петренко',
            '+380671234567'
        );

        return $key;
    }

    private function orderId(): int
    {
        return (int) DB::table('orders')
            ->where('order_key', $this->orderKey)
            ->value('id');
    }

    private function fakeCarrier(
        bool $malformed = false,
        ?string $codFee = '13.20'
    ): void {
        $city = $this->cityRef;
        $destination = $this->destinationRef;

        Http::fake(
            static function (ProviderRequest $request) use (
                $city,
                $destination,
                $malformed,
                $codFee
            ) {
                $method = $request->data()['calledMethod'] ?? null;

                if ($method === 'getWarehouses') {
                    return Http::response([
                        'success' => true,
                        'data' => [
                            [
                                'Ref' => $destination,
                                'CityRef' => $city,
                                'CategoryOfWarehouse' => 'Branch',
                                'WarehouseStatus' => 'Working',
                                'Description' => 'Відділення №1',
                            ],
                        ],
                    ]);
                }

                if ($method === 'getDocumentPrice') {
                    $properties = $request->data()['methodProperties'] ?? [];
                    $new = array_key_exists('OptionsSeat', $properties);

                    $row = [
                        'Cost' => $malformed
                            ? 'invalid'
                            : ($new ? '95.00' : '60.00'),
                    ];

                    if ($codFee !== null) {
                        $row['CostRedelivery'] = $codFee;
                    }

                    return Http::response([
                        'success' => true,
                        'data' => [$row],
                    ]);
                }

                return Http::response([
                    'success' => false,
                    'data' => [],
                ], 400);
            }
        );
    }
}
