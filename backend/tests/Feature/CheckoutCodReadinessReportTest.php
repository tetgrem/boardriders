<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodCarrierRateReconciliationService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutCodFinancialPreflightService;
use App\Services\Pricing\CheckoutCodPackageAssessmentService;
use App\Services\Pricing\CheckoutCodParcelManifestService;
use App\Services\Pricing\CheckoutCodReadinessReportService;
use App\Services\Pricing\CheckoutCodRecipientService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use App\Services\Pricing\ProductVariantShippingMetricsService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class CheckoutCodReadinessReportTest extends TestCase
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
                'sku' => 'READINESS-' . Str::uuid(),
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
                'code' => 'READINESS-WH-' . Str::uuid(),
                'name' => 'Readiness warehouse',
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

        config()->set(
            'services.nova_poshta.api_key',
            'readiness-test-key'
        );

        config()->set(
            'services.nova_poshta.sender_city_ref',
            (string) Str::uuid()
        );

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_initial_checkout_is_not_claimed_to_be_ready(): void
    {
        $report = $this->report();

        $this->assertTrue($report['checks']['order_pending']);
        $this->assertTrue($report['checks']['cod_selection_valid']);
        $this->assertFalse($report['checks']['manifest_valid']);
        $this->assertSame(
            'awaiting_local_completion',
            $report['phase']
        );
        $this->assertFalse($report['ready_for_dispatch']);

        Http::assertNothingSent();
    }

    public function test_unknown_order_reports_missing_checks_without_side_effects(): void
    {
        $report = app(CheckoutCodReadinessReportService::class)
            ->inspect((string) Str::uuid());

        $this->assertFalse($report['order_found']);
        $this->assertNull($report['order_id']);
        $this->assertFalse($report['local_checks_complete']);

        Http::assertNothingSent();
    }

    public function test_invalid_order_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CheckoutCodReadinessReportService::class)
            ->inspect('bad');
    }

    public function test_manifest_without_assessment_is_reported(): void
    {
        $this->createManifest();
        $report = $this->report();

        $this->assertTrue($report['checks']['manifest_valid']);
        $this->assertFalse(
            $report['checks']['package_within_limits']
        );
    }

    public function test_assessment_without_rate_is_reported(): void
    {
        $this->createAssessment();
        $report = $this->report();

        $this->assertTrue(
            $report['checks']['package_within_limits']
        );
        $this->assertFalse(
            $report['checks']['measured_rate_present']
        );
    }

    public function test_rate_without_financial_preflight_is_reported(): void
    {
        $this->createRate();
        $report = $this->report();

        $this->assertTrue(
            $report['checks']['measured_rate_present']
        );
        $this->assertFalse(
            $report['checks']['financial_preflight_within_limit']
        );
    }

    public function test_complete_local_pipeline_is_still_blocked_externally(): void
    {
        $this->completeLocals();
        $report = $this->report();

        $this->assertTrue($report['local_checks_complete']);
        $this->assertSame(
            'awaiting_external_evidence',
            $report['phase']
        );
        $this->assertCount(4, $report['blockers']);
        $this->assertContains(
            'cod_merchant_agreement_not_verified',
            $report['blockers']
        );
        $this->assertFalse($report['cod_eligibility_confirmed']);
        $this->assertFalse($report['ready_for_dispatch']);

        Http::assertSentCount(1);
    }

    public function test_missing_provider_cod_fee_is_not_accepted_as_complete(): void
    {
        $this->completeLocals(withFee: false);
        $report = $this->report();

        $this->assertFalse(
            $report['checks']['financial_preflight_within_limit']
        );
        $this->assertContains(
            'financial_preflight_within_limit',
            $report['blockers']
        );
    }

    public function test_expired_reservation_invalidates_a_previously_complete_report(): void
    {
        $this->completeLocals();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addHour()
        );

        $report = $this->report();

        $this->assertFalse($report['checks']['checkout_active']);
        $this->assertFalse($report['checks']['stock_active']);
        $this->assertSame(
            'awaiting_local_completion',
            $report['phase']
        );
    }

    public function test_released_stock_invalidates_a_previously_complete_report(): void
    {
        $this->completeLocals();

        $key = (string) DB::table('inventory_reservations')
            ->where('order_id', $this->orderId())
            ->value('reservation_key');

        app(InventoryService::class)->releaseOrderReservation(
            $key,
            2,
            (string) Str::uuid()
        );

        $report = $this->report();

        $this->assertFalse($report['checks']['stock_active']);
        $this->assertFalse($report['local_checks_complete']);
    }

    public function test_report_does_not_expose_recipient_pii(): void
    {
        $this->completeLocals();

        $json = json_encode(
            $this->report(),
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString(
            'Петренко',
            $json
        );
        $this->assertStringNotContainsString(
            '380671234567',
            $json
        );
        $this->assertStringNotContainsString(
            'readiness-test-key',
            $json
        );
    }

    public function test_repeated_report_reads_have_no_database_or_http_side_effects(): void
    {
        $this->completeLocals();

        $service = app(CheckoutCodReadinessReportService::class);

        $first = $service->inspect($this->orderKey);
        $second = $service->inspect($this->orderKey);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount(
            'checkout_cod_financial_preflights',
            1
        );
        $this->assertDatabaseCount(
            'checkout_cod_measured_rates',
            1
        );

        Http::assertSentCount(1);
    }

    private function report(): array
    {
        return app(CheckoutCodReadinessReportService::class)
            ->inspect($this->orderKey);
    }

    private function createManifest(): void
    {
        app(CheckoutCodParcelManifestService::class)->capture(
            $this->orderKey,
            (string) Str::uuid(),
            100
        );
    }

    private function createAssessment(): void
    {
        $this->createManifest();

        app(CheckoutCodPackageAssessmentService::class)->assess(
            $this->orderKey,
            (string) Str::uuid(),
            300,
            200,
            100
        );
    }

    private function createRate(bool $withFee = true): void
    {
        $this->createAssessment();

        Http::fake(
            static function (
                ProviderRequest $request
            ) use ($withFee) {
                if (
                    ($request->data()['calledMethod'] ?? null)
                    !== 'getDocumentPrice'
                ) {
                    return Http::response([
                        'success' => false,
                        'data' => [],
                    ], 400);
                }

                $row = ['Cost' => '95.00'];

                if ($withFee) {
                    $row['CostRedelivery'] = '13.20';
                }

                return Http::response([
                    'success' => true,
                    'data' => [$row],
                ]);
            }
        );

        app(CheckoutCodCarrierRateReconciliationService::class)
            ->reconcile(
                $this->orderKey,
                (string) Str::uuid()
            );
    }

    private function completeLocals(bool $withFee = true): void
    {
        $this->createRate($withFee);

        app(CheckoutCodFinancialPreflightService::class)->record(
            $this->orderKey,
            (string) Str::uuid(),
            3000,
            'cash_branch'
        );
    }

    private function makeCodOrder(): string
    {
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
            'branch',
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
}
