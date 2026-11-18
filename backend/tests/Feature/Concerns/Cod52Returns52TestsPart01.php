<?php

namespace Tests\Feature\Concerns;

use App\Services\Pricing\CheckoutOrderReturnService;
use App\Services\Pricing\CheckoutCodSettlementSealService;
use App\Services\Pricing\CheckoutCodTtnLifecycleService;
use App\Services\Pricing\CheckoutCodFinanceCenterService;
use App\Services\Pricing\CheckoutCodCorrectionSlaScanService;
use App\Services\Pricing\CheckoutCodCorrectionSlaInboxService;
use App\Services\Pricing\CheckoutCodDailyMonitorService;
use App\Services\Pricing\CheckoutCodDailyCorrectionService;
use App\Services\Pricing\CheckoutCodDailyReportService;
use App\Services\Pricing\CheckoutCodNovaPayRegisterService;
use App\Services\Pricing\CheckoutCodNovaPayRegisterStatusService;
use App\Services\Pricing\CheckoutCodPrivatRefetchService;
use App\Services\Pricing\CheckoutCodPrivatRefetchStatusService;
use App\Services\Pricing\CheckoutCodOperationsService;
use App\Services\Pricing\CheckoutCodOperationsReviewService;
use App\Services\Pricing\CheckoutCodNovaPayRegisterCsvParser;
use App\Services\Pricing\CheckoutCodNovaPayRegisterCsvImportService;
use Illuminate\Http\UploadedFile;
use App\Services\Pricing\CheckoutCodOperationsCaseService;
use App\Services\Pricing\CheckoutCodBankReceiptPostingService;
use App\Services\Pricing\CheckoutCodBankReceiptPostingEvaluator;
use App\Services\Pricing\CheckoutCodBankReceiptPostingStatusService;
use App\Jobs\DeliverCheckoutCodCaseAlert;
use App\Services\Pricing\CheckoutCodCaseAlertPolicy;
use App\Services\Pricing\CheckoutCodCaseAlertService;
use App\Services\Pricing\CheckoutCodCaseAlertInboxService;
use Illuminate\Support\Facades\Queue;
use App\Services\Pricing\CheckoutCodRefetchRiskStatusService;
use App\Services\Pricing\CheckoutCodAnalyticsService;
use App\Services\Pricing\CheckoutCodAdjustmentPlanService;

use App\Services\Pricing\CheckoutCodBankCoverageService;
use App\Services\Pricing\CheckoutCodBankCoverageStatusService;
use App\Services\Pricing\CheckoutCodBankBatchReviewService;

use App\Services\Pricing\CheckoutCodJournalDraftService;
use App\Services\Pricing\CheckoutCodJournalDraftStatusService;

use App\Services\Pricing\CheckoutCodBankEvidenceVerifier;
use App\Services\Pricing\CheckoutCodBankEvidenceStatusService;

use App\Services\Pricing\CheckoutCodPostingPreflightService;
use App\Services\Pricing\CheckoutCodPostingPreflightStatusService;

use App\Services\Pricing\CheckoutCodPrivatStatementImportService;

use App\Services\Pricing\CheckoutCodStatementReconciliationService;
use App\Services\Pricing\CheckoutCodStatementReconciliationStatusService;

use App\Services\Pricing\CheckoutCodFinancialResolutionService;
use App\Services\Pricing\CheckoutCodFinancialResolutionStatusService;

use App\Services\Pricing\CheckoutCodFinancialGatewaySignature;
use App\Services\Pricing\CheckoutCodFinancialGatewayStatusService;

use App\Services\Pricing\CheckoutCodBankBatchService;
use App\Services\Pricing\CheckoutCodBankBatchAllocationService;

use App\Services\Pricing\CheckoutCodBankBatchStatusService;
use App\Services\Pricing\CheckoutCodBankBatchQueueService;

use App\Services\Pricing\CheckoutCodFinancialExceptionService;
use App\Services\Pricing\CheckoutCodFinancialExceptionStatusService;
use App\Services\Pricing\CheckoutCodFinancialExceptionQueueService;

use App\Services\Pricing\CheckoutCodSettlementCaseService;
use App\Services\Pricing\CheckoutCodSettlementObservationService;
use App\Services\Pricing\CheckoutCodSettlementReviewService;
use App\Services\Pricing\CheckoutCodSettlementStatusService;
use App\Services\Pricing\CheckoutCodSettlementQueueService;

use App\Services\Pricing\CheckoutCodSettlementBankReceiptService;
use App\Services\Pricing\CheckoutCodSettlementBankMatchService;
use App\Services\Pricing\CheckoutCodSettlementBankReviewService;
use App\Services\Pricing\CheckoutCodSettlementBankStatusService;
use App\Services\Pricing\CheckoutCodSettlementBankQueueService;

use App\Services\Pricing\CheckoutCodTtnTrackingCaseService;
use App\Services\Pricing\CheckoutCodTtnTrackingPollService;
use App\Services\Pricing\CheckoutCodTtnTrackingStatusService;
use App\Services\Pricing\CheckoutCodTtnTrackingDueService;
use Illuminate\Support\Facades\Artisan;
use App\Services\Pricing\CheckoutCodTtnDispatchPlanService;
use App\Services\Pricing\CheckoutCodWarehousePackReadinessService;
use App\Services\Pricing\CheckoutCodWarehousePackService;
use App\Services\Pricing\CheckoutCodWarehousePackStatusService;
use App\Services\Pricing\CheckoutCodWarehousePackQueueService;
use App\Services\Pricing\CheckoutCodInternalPickSlipService;
use App\Services\Pricing\CheckoutCodFulfillmentStagingService;
use App\Services\Pricing\CheckoutCodFulfillmentStatusService;
use App\Services\Pricing\CheckoutCodFulfillmentQueueService;
use App\Services\Pricing\CheckoutCodFulfillmentDispatchGuard;
use App\Services\Pricing\CheckoutCodTtnDispatchReadinessService;
use App\Services\Pricing\CheckoutCodTtnDispatchStatusService;
use App\Services\Pricing\CheckoutCodTtnDispatchQueueService;
use App\Services\Pricing\CheckoutCodTtnDispatchTransportGuard;
use App\Services\Pricing\CheckoutCodTtnEvidenceMatrixService;
use App\Services\Pricing\CheckoutCodTtnResolutionService;
use App\Services\Pricing\CheckoutCodTtnResolutionStatusService;
use App\Services\Pricing\CheckoutCodTtnRetryGuardService;
use App\Services\Pricing\CheckoutCodTtnInvestigationService;
use App\Services\Pricing\CheckoutCodTtnInvestigationStatusService;
use App\Services\Pricing\CheckoutCodTtnInvestigationQueueService;
use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodCarrierContactObservationService;
use App\Services\Pricing\CheckoutCodCarrierRateReconciliationService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutCodFinancialPreflightService;
use App\Services\Pricing\CheckoutCodPackageAssessmentService;
use App\Services\Pricing\CheckoutCodParcelManifestService;
use App\Services\Pricing\CheckoutCodRecipientCarrierBindingService;
use App\Services\Pricing\CheckoutCodRecipientService;
use App\Services\Pricing\CheckoutCodTtnSubmissionIntentService;
use App\Services\Pricing\CheckoutCodTtnSubmissionSafetyEventService;
use App\Services\Pricing\CheckoutCodTtnSubmissionSafetyStatusService;
use App\Services\Pricing\CheckoutCodWaybillDraftService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use App\Services\Pricing\NovaPoshtaCodContractEvidenceService;
use App\Services\Pricing\NovaPoshtaCodExternalAttestationService;
use App\Services\Pricing\NovaPoshtaSenderProfileService;
use App\Services\Pricing\ProductVariantShippingMetricsService;
use App\Services\Pricing\CheckoutCodTtnReconciliationScanService;
use App\Services\Pricing\CheckoutCodTtnReconciliationReviewService;
use App\Services\Pricing\CheckoutCodTtnReconciliationStatusService;
use App\Services\Pricing\CheckoutCodTtnDocumentComparisonService;
use App\Services\Pricing\CheckoutCodTtnDocumentComparisonStatusService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\GenericUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

trait Cod52Returns52TestsPart01
{
    public function test_cod_return52_single_unit_from_immutable_snapshot(): void
    {
        $r = $this->codReturn52Open();
        $this->assertSame('awaiting_inspection', $r['workflow']);
        $this->assertSame(1, $r['requested_qty']);
        $this->assertSame(1000, $r['units'][0]['cash_minor']);
        $this->assertSame(1, $r['units'][0]['unit_index']);
        $this->assertFalse($r['money_refunded']);
        $this->assertDatabaseCount('checkout_order_return_units', 1);
    }

    public function test_cod_return52_full_two_unit_return_claim(): void
    {
        $r = $this->codReturn52Open(2);
        $this->assertCount(2, $r['units']);
        $this->assertSame([1, 2], array_column($r['units'], 'unit_index'));
        $this->assertDatabaseCount('checkout_order_return_units', 2);
    }

    public function test_cod_return52_two_partial_requests_use_distinct_unit_indexes(): void
    {
        $first = $this->codReturn52Open();
        $second = $this->codReturn52Open();
        $this->assertSame(1, $first['units'][0]['unit_index']);
        $this->assertSame(2, $second['units'][0]['unit_index']);
        $this->assertDatabaseCount('checkout_order_return_units', 2);
    }

    public function test_cod_return52_overreturn_rejected(): void
    {
        $this->codReturn52Open(2);
        $this->expectException(DomainException::class);
        $this->codReturn52Open();
    }

    public function test_cod_return52_open_uuid_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $first = $this->codReturn52Open(1, $key);
        $again = $this->codReturn52Open(1, $key);
        $this->assertTrue($again['idempotent_replay']);
        $this->assertSame($first['return_key'], $again['return_key']);
        $this->assertDatabaseCount('checkout_order_return_cases', 1);
    }

    public function test_cod_return52_reused_uuid_with_different_qty_rejected(): void
    {
        $key = (string) Str::uuid();
        $this->codReturn52Open(1, $key);
        $this->expectException(DomainException::class);
        $this->codReturn52Open(2, $key);
    }

    public function test_cod_return52_carrier_refusal_requires_matching_lifecycle_evidence(): void
    {
        $this->codReturn52Gate();
        $this->actor(5201);
        $this->expectException(DomainException::class);
        app(CheckoutOrderReturnService::class)->open(
            $this->orderKey, (string) Str::uuid(), 'carrier_refusal',
            [['variant_id' => $this->variantId, 'quantity' => 1]]
        );
    }

    public function test_cod_return52_unprivileged_user_cannot_open(): void
    {
        $this->codReturn52Gate();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutOrderReturnService::class)->open(
            $this->orderKey, (string) Str::uuid(), 'customer_return',
            [['variant_id' => $this->variantId, 'quantity' => 1]]
        );
    }

    public function test_cod_return52_warehouse_inspection_requires_independent_actor(): void
    {
        $r = $this->codReturn52Open();
        Gate::define(CheckoutOrderReturnService::INSPECT_ABILITY,
            static fn ($u): bool => true);
        $this->actor(5201);
        $this->expectException(DomainException::class);
        app(CheckoutOrderReturnService::class)->inspect(
            $r['return_key'], (string) Str::uuid(), $this->codReturn52WarehouseId(), 'ACT-52',
            $this->codReturn52Conditions($r)
        );
    }

    public function test_cod_return52_inspection_classifies_all_units(): void
    {
        $r = $this->codReturn52Open(2);
        $conds = $this->codReturn52Conditions($r);
        $conds[1]['condition'] = 'not_received';
        $state = $this->codReturn52Inspect($r, $conds);
        $this->assertSame('awaiting_review', $state['workflow']);
        $this->assertSame(1, $state['inspection']['assessed_totals']['not_received_units']);
        $this->assertSame(1000, $state['inspection']['assessed_totals']['cash_minor']);
        $this->assertFalse($state['inventory_restocked']);
    }

    public function test_cod_return52_inspection_requires_every_claimed_unit(): void
    {
        $r = $this->codReturn52Open(2);
        $this->expectException(InvalidArgumentException::class);
        $this->codReturn52Inspect($r, [$this->codReturn52Conditions($r)[0]]);
    }

    public function test_cod_return52_inspection_uuid_replay(): void
    {
        $r = $this->codReturn52Open();
        $key = (string) Str::uuid();
        $first = $this->codReturn52Inspect($r, $this->codReturn52Conditions($r), $key);
        $again = $this->codReturn52Inspect($r, $this->codReturn52Conditions($r), $key);
        $this->assertSame($first['return_key'], $again['return_key']);
        $this->assertTrue($again['idempotent_replay']);
        $this->assertDatabaseCount('checkout_order_return_inspections', 1);
    }

    public function test_cod_return52_approval_requires_inspection(): void
    {
        $r = $this->codReturn52Open();
        $this->actor(5203);
        $this->expectException(DomainException::class);
        app(CheckoutOrderReturnService::class)->decide(
            $r['return_key'], (string) Str::uuid(), 'approved'
        );
    }

    public function test_cod_return52_approval_proposes_exact_cash_without_refunding(): void
    {
        $r = $this->codReturn52Open();
        $this->codReturn52Inspect($r, $this->codReturn52Conditions($r));
        $stockBefore = (int) DB::table('inventory_stocks')->sum('on_hand_qty');
        $loyaltyBefore = DB::table('loyalty_ledger_entries')->count();
        $this->actor(5203);
        $review = app(CheckoutOrderReturnService::class)->decide(
            $r['return_key'], (string) Str::uuid(), 'approved'
        );
        $this->assertSame('awaiting_separate_execution', $review['workflow']);
        $this->assertSame(1000, $review['decision']['cash_candidate_minor']);
        $this->assertSame(1, $review['decision']['accepted_qty']);
        $this->assertFalse($review['money_refunded']);
        $this->assertFalse($review['loyalty_refunded']);
        $this->assertSame($stockBefore, (int) DB::table('inventory_stocks')->sum('on_hand_qty'));
        $this->assertSame($loyaltyBefore, DB::table('loyalty_ledger_entries')->count());
        $this->assertSame('pending', DB::table('orders')->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_cod_return52_rejection_has_no_refund_amounts(): void
    {
        $r = $this->codReturn52Open();
        $this->codReturn52Inspect($r, $this->codReturn52Conditions($r, 'damaged'));
        $this->actor(5203);
        $out = app(CheckoutOrderReturnService::class)->decide(
            $r['return_key'], (string) Str::uuid(), 'rejected'
        );
        $this->assertSame('closed_rejected', $out['workflow']);
        $this->assertSame(0, $out['decision']['cash_candidate_minor']);
        $this->assertSame(0, $out['decision']['accepted_qty']);
    }

    public function test_cod_return52_reviewer_cannot_be_warehouse_inspector(): void
    {
        $r = $this->codReturn52Open();
        $this->codReturn52Inspect($r, $this->codReturn52Conditions($r));
        Gate::define(CheckoutOrderReturnService::DECIDE_ABILITY,
            static fn ($u): bool => true);
        $this->actor(5202);
        $this->expectException(DomainException::class);
        app(CheckoutOrderReturnService::class)->decide(
            $r['return_key'], (string) Str::uuid(), 'approved'
        );
    }

    public function test_cod_return52_review_uuid_replay_and_double_review_block(): void
    {
        $r = $this->codReturn52Open();
        $this->codReturn52Inspect($r, $this->codReturn52Conditions($r));
        $this->actor(5203);
        $svc = app(CheckoutOrderReturnService::class);
        $key = (string) Str::uuid();
        $first = $svc->decide($r['return_key'], $key, 'approved');
        $again = $svc->decide($r['return_key'], $key, 'approved');
        $this->assertTrue($again['idempotent_replay']);
        $this->assertSame($first['return_key'], $again['return_key']);
        $this->assertDatabaseCount('checkout_order_return_decisions', 1);
        $this->expectException(DomainException::class);
        $svc->decide($r['return_key'], (string) Str::uuid(), 'rejected');
    }

    public function test_cod_return52_queue_filters_stages(): void
    {
        $r = $this->codReturn52Open();
        $this->actor(5203);
        $svc = app(CheckoutOrderReturnService::class);
        $this->assertCount(1, $svc->queue('inspection')['items']);
        $this->codReturn52Inspect($r, $this->codReturn52Conditions($r));
        $this->actor(5203);
        $this->assertCount(1, $svc->queue('review')['items']);
        $svc->decide($r['return_key'], (string) Str::uuid(), 'approved');
        $this->assertCount(1, $svc->queue('execution')['items']);
    }

    public function test_cod_return52_database_rejects_mutating_request_units(): void
    {
        $this->codReturn52Open();
        $this->expectException(QueryException::class);
        DB::table('checkout_order_return_units')->delete();
    }

    public function test_cod_return52_legacy_pre_dispatch_cancellation_releases_inventory(): void
    {
        $this->codReturn52Gate();
        $this->actor(5204);
        $r = app(CheckoutOrderReturnService::class)->cancelBeforeDispatch(
            $this->orderKey, (string) Str::uuid(), 'customer_cancel'
        );
        $this->assertSame('canceled', $r['payment_status']);
        $this->assertSame(2, $r['released_stock_qty']);
        $this->assertDatabaseCount('checkout_order_cancellations', 1);
        $this->assertDatabaseCount('checkout_order_return_cases', 0);
    }

    public function test_cod_return52_canceled_order_cannot_become_return_case(): void
    {
        $this->codReturn52Gate();
        $this->actor(5204);
        app(CheckoutOrderReturnService::class)->cancelBeforeDispatch(
            $this->orderKey, (string) Str::uuid(), 'customer_cancel'
        );
        $this->actor(5201);
        $this->expectException(DomainException::class);
        app(CheckoutOrderReturnService::class)->open(
            $this->orderKey, (string) Str::uuid(), 'customer_return',
            [['variant_id' => $this->variantId, 'quantity' => 1]]
        );
    }

    public function test_cod_return52_api_requires_auth_and_has_named_routes(): void
    {
        foreach (['cod.return52.cancel', 'cod.return52.open', 'cod.return52.inspect',
                     'cod.return52.decide', 'cod.return52.show', 'cod.return52.queue'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }

    public function test_cod_return52_http_open_return_case(): void
    {
        $this->codReturn52Gate();
        $this->actingAs(new GenericUser(['id' => 5201]), 'sanctum');
        $this->postJson('/api/v1/admin/orders/returns/cases', [
            'order_key' => $this->orderKey,
            'operation_key' => (string) Str::uuid(),
            'reason_code' => 'customer_return',
            'items' => [['variant_id' => $this->variantId, 'quantity' => 1]],
        ])->assertStatus(201)
            ->assertJsonPath('requested_qty', 1)
            ->assertJsonPath('money_refunded', false);
    }

    public function test_cod_return52_http_invalid_return_quantity_is_422(): void
    {
        $this->codReturn52Gate();
        $this->actingAs(new GenericUser(['id' => 5201]), 'sanctum');
        $this->postJson('/api/v1/admin/orders/returns/cases', [
            'order_key' => $this->orderKey,
            'operation_key' => (string) Str::uuid(),
            'reason_code' => 'customer_return',
            'items' => [['variant_id' => $this->variantId, 'quantity' => 0]],
        ])->assertStatus(422);
        $this->assertDatabaseCount('checkout_order_return_cases', 0);
    }
}
