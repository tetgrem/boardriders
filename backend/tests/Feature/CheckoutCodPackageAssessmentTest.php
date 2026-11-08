<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutCodPackageAssessmentService;
use App\Services\Pricing\CheckoutCodParcelManifestService;
use App\Services\Pricing\CheckoutCodRecipientService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use App\Services\Pricing\ProductVariantShippingMetricsService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CheckoutCodPackageAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;
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
            throw new \RuntimeException(
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

        $listId = (int) DB::table('price_lists')->insertGetId([
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

        $this->variantId = (int) DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'ASSESS-' . Str::uuid(),
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

        $warehouseId = (int) DB::table('warehouses')
            ->insertGetId([
                'code' => 'ASSESS-WH-' . Str::uuid(),
                'name' => 'Packaging assessment warehouse',
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
        $this->orderKey = $this->makeCodOrder();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_saves_auditable_package_assessment_without_carrier_approval(): void
    {
        $this->makeManifest();
        $saved = $this->assess();

        $this->assertSame(700, $saved['estimated_gross_grams']);
        $this->assertSame(1500, $saved['volume_weight_grams']);
        $this->assertSame(1500, $saved['chargeable_weight_grams']);
        $this->assertSame('within_standard_limits', $saved['local_result']);

        $this->assertFalse($saved['carrier_acceptance_confirmed']);
        $this->assertFalse($saved['cod_eligibility_confirmed']);
        $this->assertFalse($saved['ready_for_dispatch']);

        $this->assertDatabaseCount(
            'checkout_cod_package_assessments',
            1
        );

        Http::assertNothingSent();
    }

    public function test_identical_key_and_measurements_are_idempotent(): void
    {
        $this->makeManifest();
        $key = (string) Str::uuid();

        $this->assertSame(
            $this->assess($key),
            $this->assess($key)
        );

        $this->assertDatabaseCount(
            'checkout_cod_package_assessments',
            1
        );
    }

    public function test_same_key_cannot_change_box_dimensions(): void
    {
        $this->makeManifest();
        $key = (string) Str::uuid();
        $this->assess($key);

        $this->expectException(DomainException::class);
        $this->assess($key, length: 350);
    }

    public function test_different_key_cannot_replace_existing_assessment(): void
    {
        $this->makeManifest();
        $this->assess();

        $this->expectException(DomainException::class);
        $this->assess();
    }

    public function test_cannot_assess_without_parcel_manifest(): void
    {
        $this->expectException(DomainException::class);
        $this->assess();
    }

    public function test_cannot_assess_expired_checkout(): void
    {
        $this->makeManifest();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addHour()
        );

        $this->expectException(DomainException::class);
        $this->assess();
    }

    public function test_cannot_assess_released_stock(): void
    {
        $this->makeManifest();

        $reservationKey = (string) DB::table('inventory_reservations')
            ->where('order_id', $this->orderId())
            ->value('reservation_key');

        app(InventoryService::class)->releaseOrderReservation(
            $reservationKey,
            2,
            (string) Str::uuid()
        );

        $this->expectException(DomainException::class);
        $this->assess();
    }

    public function test_locker_oversize_is_recorded_as_blocked(): void
    {
        $this->orderKey = $this->makeCodOrder('parcel_locker');
        $this->makeManifest();

        $saved = $this->assess(
            length: 601,
            width: 400,
            height: 300
        );

        $this->assertSame('blocked', $saved['local_result']);
        $this->assertContains(
            'parcel_locker_size_limit',
            $saved['reasons']
        );
    }

    public function test_branch_over_standard_dimensions_is_manual_review(): void
    {
        $this->makeManifest();

        $saved = $this->assess(
            length: 1201,
            width: 700,
            height: 700
        );

        $this->assertSame('manual_review', $saved['local_result']);
        $this->assertContains(
            'standard_branch_size_limit',
            $saved['reasons']
        );
    }

    public function test_catalog_edit_does_not_mutate_recorded_assessment(): void
    {
        $this->makeManifest();
        $key = (string) Str::uuid();

        $old = $this->assess($key);

        app(ProductVariantShippingMetricsService::class)->change(
            $this->variantId,
            (string) Str::uuid(),
            1,
            900,
            250,
            150,
            80
        );

        $this->assertSame($old, $this->assess($key));
        $this->assertSame(700, $old['estimated_gross_grams']);
    }

    public function test_direct_database_update_is_blocked(): void
    {
        $this->makeManifest();
        $saved = $this->assess();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_package_assessments')
            ->where('id', $saved['id'])
            ->update([
                'local_result' => 'blocked',
            ]);
    }

    public function test_direct_sql_cannot_bind_assessment_to_another_order(): void
    {
        $manifest = $this->makeManifest();
        $other = $this->makeCodOrder();

        $otherId = (int) DB::table('orders')
            ->where('order_key', $other)
            ->value('id');

        $manifestRow = DB::table('checkout_cod_parcel_manifests')
            ->where('id', $manifest['id'])
            ->first();

        $selectionId = (int) DB::table('checkout_cod_recipients')
            ->where('order_id', $this->orderId())
            ->value('cod_delivery_selection_id');

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_package_assessments')->insert([
            'assessment_key' => (string) Str::uuid(),
            'order_id' => $otherId,
            'manifest_id' => $manifest['id'],
            'cod_delivery_selection_id' => $selectionId,
            'ruleset' => 'boardriders-ua-standard-parcel-v1',
            'destination_type' => 'branch',
            'length_mm' => 300,
            'width_mm' => 200,
            'height_mm' => 100,
            'estimated_gross_grams' => 700,
            'volume_weight_grams' => 1500,
            'chargeable_weight_grams' => 1500,
            'local_result' => 'within_standard_limits',
            'reasons_json' => '[]',
            'manifest_items_sha256' => $manifestRow->items_sha256,
            'request_sha256' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }

    private function assess(
        ?string $key = null,
        int $length = 300,
        int $width = 200,
        int $height = 100,
    ): array {
        return app(CheckoutCodPackageAssessmentService::class)->assess(
            $this->orderKey,
            $key ?? (string) Str::uuid(),
            $length,
            $width,
            $height
        );
    }

    private function makeManifest(): array
    {
        return app(CheckoutCodParcelManifestService::class)->capture(
            $this->orderKey,
            (string) Str::uuid(),
            100
        );
    }

    private function orderId(): int
    {
        return (int) DB::table('orders')
            ->where('order_key', $this->orderKey)
            ->value('id');
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
}
