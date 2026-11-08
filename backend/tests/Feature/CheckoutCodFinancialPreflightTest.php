<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodCarrierRateReconciliationService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutCodFinancialPreflightService;
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
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class CheckoutCodFinancialPreflightTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;
    private string $orderKey;
    private string $cityRef;
    private string $destinationRef;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException('Requires PostgreSQL boardriders_test');
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
        $listId = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL', 'name' => 'Retail UA',
            'country_code' => 'UA', 'currency_code' => 'UAH',
            'type' => 'retail', 'is_default' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'COD-FIN-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId, 'price_list_id' => $listId,
            'price_minor' => 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'COD-FIN-WH-' . Str::uuid(),
            'name' => 'COD financial tests warehouse', 'country_code' => 'UA',
            'kind' => 'owned', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouseId, 'variant_id' => $this->variantId,
            'on_hand_qty' => 20, 'reserved_qty' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        app(ProductVariantShippingMetricsService::class)->change(
            $this->variantId, (string) Str::uuid(), 0, 300, 200, 100, 50
        );

        $this->cityRef = (string) Str::uuid();
        $this->destinationRef = (string) Str::uuid();
        $this->orderKey = $this->makeCodOrder();

        config()->set('services.nova_poshta.api_key', 'cod-financial-preflight-test');
        config()->set('services.nova_poshta.sender_city_ref', (string) Str::uuid());
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_records_explicit_declared_value_and_unchanged_cod_amount(): void
    {
        $this->prepareMeasuredRate();
        $saved = $this->record(declared: 4500);

        $this->assertSame(2000, $saved['cod_amount_minor']);
        $this->assertSame(4500, $saved['declared_value_minor']);
        $this->assertTrue($saved['declared_value_differs_from_cod']);
        $this->assertSame(39999900, $saved['payout_limit_minor']);
        $this->assertSame('within_published_limit', $saved['local_result']);
        $this->assertSame(9500, $saved['delivery_cost_minor']);
        $this->assertSame(1320, $saved['estimated_cod_fee_minor']);
        $this->assertFalse($saved['cod_eligibility_confirmed']);
        $this->assertFalse($saved['ready_for_dispatch']);
        $this->assertDatabaseCount('checkout_cod_financial_preflights', 1);
        Http::assertSentCount(1); // Rate was requested earlier, not during preflight.
    }

    public function test_same_operation_is_idempotent_without_http(): void
    {
        $this->prepareMeasuredRate();
        $key = (string) Str::uuid();
        $this->assertSame($this->record($key), $this->record($key));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('checkout_cod_financial_preflights', 1);
    }

    public function test_same_key_cannot_change_declared_value(): void
    {
        $this->prepareMeasuredRate();
        $key = (string) Str::uuid();
        $this->record($key, 3000);
        $this->expectException(DomainException::class);
        $this->record($key, 3500);
    }

    public function test_different_key_cannot_replace_existing_result(): void
    {
        $this->prepareMeasuredRate();
        $this->record();
        $this->expectException(DomainException::class);
        $this->record();
    }

    public function test_same_key_cannot_switch_payout_channel(): void
    {
        $this->prepareMeasuredRate();
        $key = (string) Str::uuid();
        $this->record($key);
        $this->expectException(DomainException::class);
        $this->record($key, channel: 'other_ua_card');
    }

    public function test_requires_existing_measured_rate(): void
    {
        $this->expectException(DomainException::class);
        $this->record();
    }

    public function test_expired_checkout_cannot_create_preflight(): void
    {
        $this->prepareMeasuredRate();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $this->expectException(DomainException::class);
        $this->record();
    }

    public function test_released_stock_cannot_create_preflight(): void
    {
        $this->prepareMeasuredRate();
        $reservationKey = (string) DB::table('inventory_reservations')
            ->where('order_id', $this->orderId())->value('reservation_key');
        app(InventoryService::class)->releaseOrderReservation(
            $reservationKey, 2, (string) Str::uuid()
        );
        $this->expectException(DomainException::class);
        $this->record();
    }

    public function test_missing_estimated_cod_fee_is_explicitly_recorded(): void
    {
        $this->prepareMeasuredRate(withFee: false);
        $saved = $this->record();
        $this->assertSame('missing_fee', $saved['local_result']);
        $this->assertNull($saved['estimated_cod_fee_minor']);
        $this->assertFalse($saved['fee_finalized']);
    }

    public function test_direct_database_update_is_blocked(): void
    {
        $this->prepareMeasuredRate();
        $saved = $this->record();
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_financial_preflights')
            ->where('id', $saved['id'])->update(['cod_amount_minor' => 1]);
    }

    public function test_direct_sql_cannot_bind_rate_to_unrelated_order(): void
    {
        $this->prepareMeasuredRate();
        $rate = DB::table('checkout_cod_measured_rates')->where('order_id', $this->orderId())->first();
        $assessment = DB::table('checkout_cod_package_assessments')
            ->where('id', $rate->assessment_id)->first();
        $snapshot = DB::table('order_financial_snapshots')
            ->where('order_id', $this->orderId())->first();
        $anotherOrder = $this->makeCodOrder();
        $otherOrderId = (int) DB::table('orders')
            ->where('order_key', $anotherOrder)->value('id');

        $this->expectException(QueryException::class);
        DB::table('checkout_cod_financial_preflights')->insert([
            'operation_key' => (string) Str::uuid(),
            'order_id' => $otherOrderId,
            'measured_rate_id' => $rate->id,
            'assessment_id' => $rate->assessment_id,
            'cod_delivery_selection_id' => $assessment->cod_delivery_selection_id,
            'financial_snapshot_id' => $snapshot->id,
            'policy_version' => 'np-cod-payout-limits-2026-11-v1',
            'payout_channel' => 'cash_branch',
            'payout_limit_minor' => 39999900,
            'cod_amount_minor' => 2000,
            'declared_value_minor' => 3000,
            'delivery_cost_minor' => 9500,
            'estimated_cod_fee_minor' => 1320,
            'local_result' => 'within_published_limit',
            'financial_snapshot_sha256' => $snapshot->snapshot_sha256,
            'request_sha256' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }

    public function test_readback_matches_saved_history(): void
    {
        $this->prepareMeasuredRate();
        $saved = $this->record();
        $read = app(CheckoutCodFinancialPreflightService::class)
            ->findForOrder($this->orderId());
        $this->assertSame($saved, $read);
    }

    public function test_invalid_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(CheckoutCodFinancialPreflightService::class)->record(
            'invalid', (string) Str::uuid(), 3000, 'cash_branch'
        );
    }

    public function test_operation_uuid_cannot_be_reused_for_another_order(): void
    {
        $this->prepareMeasuredRate();
        $key = (string) Str::uuid();
        $this->record($key);

        $otherKey = $this->makeCodOrder();
        $original = $this->orderKey;
        $this->orderKey = $otherKey;
        try {
            $this->prepareMeasuredRate();
            $this->expectException(DomainException::class);
            $this->record($key);
        } finally {
            $this->orderKey = $original;
        }
    }

    private function record(
        ?string $key = null,
        int $declared = 3000,
        string $channel = 'cash_branch',
    ): array {
        return app(CheckoutCodFinancialPreflightService::class)->record(
            $this->orderKey,
            $key ?? (string) Str::uuid(),
            $declared,
            $channel,
        );
    }

    private function prepareMeasuredRate(bool $withFee = true): void
    {
        app(CheckoutCodParcelManifestService::class)->capture(
            $this->orderKey, (string) Str::uuid(), 100
        );
        app(CheckoutCodPackageAssessmentService::class)->assess(
            $this->orderKey, (string) Str::uuid(), 300, 200, 100
        );

        Http::fake(static function (ProviderRequest $request) use ($withFee) {
            if (($request->data()['calledMethod'] ?? null) !== 'getDocumentPrice') {
                return Http::response(['success' => false, 'data' => []], 400);
            }
            $price = ['Cost' => '95.00'];
            if ($withFee) {
                $price['CostRedelivery'] = '13.20';
            }
            return Http::response(['success' => true, 'data' => [$price]]);
        });

        app(CheckoutCodCarrierRateReconciliationService::class)->reconcile(
            $this->orderKey, (string) Str::uuid()
        );
    }

    private function makeCodOrder(): string
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

    private function orderId(): int
    {
        return (int) DB::table('orders')->where('order_key', $this->orderKey)->value('id');
    }
}
