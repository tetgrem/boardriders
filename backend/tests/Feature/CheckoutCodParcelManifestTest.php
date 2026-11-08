<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutCodMeasuredQuoteService;
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
use InvalidArgumentException;
use Tests\TestCase;

final class CheckoutCodParcelManifestTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;
    private int $warehouseId;
    private string $orderKey;
    private string $cityRef;
    private string $destinationRef;

    protected function setUp(): void
    {
        parent::setUp();
        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new \RuntimeException('Tests require PostgreSQL boardriders_test');
        }

        $now = now();
        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH', 'name' => 'Ukrainian hryvnia', 'symbol' => '₴',
            'minor_units' => 2, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('markets')->insert([
            'code' => 'UA', 'name' => 'Ukraine', 'country_code' => 'UA',
            'currency_code' => 'UAH', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $priceListId = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL', 'name' => 'Retail UA',
            'country_code' => 'UA', 'currency_code' => 'UAH',
            'type' => 'retail', 'is_default' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'COD-MANIFEST-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId, 'price_list_id' => $priceListId,
            'price_minor' => 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'COD-MANIFEST-WH-' . Str::uuid(),
            'name' => 'COD manifest test warehouse', 'country_code' => 'UA',
            'kind' => 'owned', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $this->warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 20, 'reserved_qty' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->cityRef = (string) Str::uuid();
        $this->destinationRef = (string) Str::uuid();
        $this->orderKey = $this->createCodOrder();
        config()->set('services.nova_poshta.api_key', 'manifest-test-key');
        config()->set('services.nova_poshta.sender_city_ref', (string) Str::uuid());
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_manifest_calculates_weight_from_reserved_quantity(): void
    {
        $this->measure();
        $saved = $this->manifest(packaging: 100);
        $this->assertSame(600, $saved['goods_grams']);
        $this->assertSame(700, $saved['gross_grams']);
        $this->assertSame(2, $saved['total_units']);
        $this->assertSame(1, $saved['line_count']);
        $this->assertSame(300, $saved['items'][0]['unit_weight_grams']);
        $this->assertSame(600, $saved['items'][0]['line_weight_grams']);
        $this->assertFalse($saved['ready_for_dispatch']);
        $this->assertDatabaseCount('checkout_cod_parcel_manifests', 1);
    }

    public function test_unmeasured_sku_cannot_create_manifest(): void
    {
        $this->expectException(DomainException::class);
        $this->manifest();
    }

    public function test_same_manifest_key_and_packaging_are_idempotent(): void
    {
        $this->measure();
        $key = (string) Str::uuid();
        $this->assertSame($this->manifest($key), $this->manifest($key));
        $this->assertDatabaseCount('checkout_cod_parcel_manifests', 1);
    }

    public function test_changing_packaging_or_key_is_rejected(): void
    {
        $this->measure();
        $key = (string) Str::uuid();
        $this->manifest($key);
        $this->expectException(DomainException::class);
        $this->manifest($key, 150);
    }

    public function test_second_key_for_same_order_is_rejected(): void
    {
        $this->measure();
        $this->manifest();
        $this->expectException(DomainException::class);
        $this->manifest();
    }

    public function test_manifest_preserves_old_metrics_after_catalog_edit(): void
    {
        $this->measure();
        $key = (string) Str::uuid();
        $old = $this->manifest($key);
        app(ProductVariantShippingMetricsService::class)->change(
            $this->variantId, (string) Str::uuid(), 1, 900, 240, 110, 75
        );
        $this->assertSame($old, $this->manifest($key));
        $this->assertSame(300, $old['items'][0]['unit_weight_grams']);
        $this->assertSame(900, (int) DB::table('product_variants')
            ->where('id', $this->variantId)->value('weight_grams'));
    }

    public function test_expired_preparation_cannot_create_manifest(): void
    {
        $this->measure();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $this->expectException(DomainException::class);
        $this->manifest();
    }

    public function test_released_reservation_cannot_create_manifest(): void
    {
        $this->measure();
        $reservationKey = (string) DB::table('inventory_reservations')
            ->where('order_id', $this->orderId())->value('reservation_key');
        app(InventoryService::class)->releaseOrderReservation(
            $reservationKey, 2, (string) Str::uuid()
        );
        $this->expectException(DomainException::class);
        $this->manifest();
    }

    public function test_excessive_packaging_is_rejected(): void
    {
        $this->measure();
        $this->expectException(InvalidArgumentException::class);
        $this->manifest(packaging: 20001);
    }

    public function test_database_blocks_manifest_modification(): void
    {
        $this->measure();
        $saved = $this->manifest();
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_parcel_manifests')
            ->where('id', $saved['id'])->update(['gross_grams' => 1]);
    }

    public function test_existing_legacy_quote_cannot_gain_a_retroactive_manifest(): void
    {
        $this->measure();
        $this->fakeProvider();
        app(CheckoutCodShippingQuoteService::class)->quote(
            $this->orderKey, (string) Str::uuid(), 700
        );
        $this->expectException(DomainException::class);
        $this->manifest();
    }

    public function test_measured_quote_uses_exact_saved_gross_weight(): void
    {
        $this->measure();
        $this->fakeProvider();
        $result = app(CheckoutCodMeasuredQuoteService::class)->estimate(
            $this->orderKey, (string) Str::uuid(), (string) Str::uuid(), 100
        );
        $this->assertSame(700, $result['manifest']['gross_grams']);
        $this->assertSame(700, $result['quote']['weight_grams']);
        $this->assertSame(2000, $result['quote']['goods_due_minor']);
        $this->assertFalse($result['ready_for_dispatch']);
        Http::assertSentCount(2);
        Http::assertSent(static function (ProviderRequest $request): bool {
            $data = $request->data();
            return ($data['calledMethod'] ?? null) === 'getDocumentPrice'
                && ($data['methodProperties']['Weight'] ?? null) === '0.700'
                && ($data['methodProperties']['Cost'] ?? null) === '20.00';
        });
    }

    public function test_measured_quote_is_idempotent(): void
    {
        $this->measure();
        $this->fakeProvider();
        $manifestKey = (string) Str::uuid();
        $quoteKey = (string) Str::uuid();
        $service = app(CheckoutCodMeasuredQuoteService::class);
        $first = $service->estimate($this->orderKey, $manifestKey, $quoteKey, 100);
        $second = $service->estimate($this->orderKey, $manifestKey, $quoteKey, 100);
        $this->assertSame($first, $second);
        Http::assertSentCount(2);
    }

    private function measure(int $weight = 300): void
    {
        app(ProductVariantShippingMetricsService::class)->change(
            $this->variantId, (string) Str::uuid(), 0, $weight, 200, 100, 50
        );
    }

    private function manifest(?string $key = null, int $packaging = 100): array
    {
        return app(CheckoutCodParcelManifestService::class)->capture(
            $this->orderKey, $key ?? (string) Str::uuid(), $packaging
        );
    }

    private function orderId(): int
    {
        return (int) DB::table('orders')->where('order_key', $this->orderKey)->value('id');
    }

    private function createCodOrder(): string
    {
        $key = (string) Str::uuid();
        app(CheckoutOrderPreparationService::class)->prepare(
            $key, [['variant_id' => $this->variantId, 'quantity' => 2]],
            'UA', 'UA_RETAIL', CarbonImmutable::now('UTC')->addMinutes(30)
        );
        app(CheckoutPaymentChoiceService::class)->choose($key, 'cash_on_delivery');
        app(CheckoutCashOnDeliveryRequestService::class)->request(
            $key, (string) Str::uuid()
        );
        app(CheckoutCodDeliverySelectionService::class)->select(
            $key, (string) Str::uuid(), 'branch', $this->destinationRef
        );
        app(CheckoutCodRecipientService::class)->save(
            $key, (string) Str::uuid(), $this->cityRef,
            'Іван', 'Петренко', '+380671234567'
        );
        return $key;
    }

    private function fakeProvider(): void
    {
        $city = $this->cityRef;
        $warehouse = $this->destinationRef;
        Http::fake(static function (ProviderRequest $request) use ($city, $warehouse) {
            return match ($request->data()['calledMethod'] ?? null) {
                'getWarehouses' => Http::response(['success' => true, 'data' => [[
                    'Ref' => $warehouse, 'CityRef' => $city,
                    'CategoryOfWarehouse' => 'Branch',
                    'WarehouseStatus' => 'Working',
                    'Description' => 'Відділення №1',
                ]]]),
                'getDocumentPrice' => Http::response(['success' => true, 'data' => [[
                    'Cost' => '85.50', 'CostRedelivery' => '12.30',
                ]]]),
                default => Http::response(['success' => false, 'data' => []], 400),
            };
        });
    }
}
