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

trait Cod52Lifecycle51TestsPart01
{
    public function test_cod_life51_no_observations_is_awaiting_evidence(): void
    {
        $c = $this->codLife51Fixture();
        $this->actor(5101);
        $r = app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key']);
        $this->assertSame('awaiting_evidence', $r['observed_state']);
        $this->assertFalse($r['customer_delivery_confirmed']);
        $this->assertFalse($r['cod_paid']);
    }

    public function test_cod_life51_pickup_status_creates_contact_task_without_shipping_order(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '7');
        $result = $this->codLife51Sync($c['tracking_key']);
        $this->assertSame(1, $result['events_created']);
        $this->assertSame('awaiting_pickup', $result['observed_state']);
        $this->assertSame('contact_recipient', $result['action_code']);
        $this->assertSame('pending', DB::table('orders')->where('order_key', $this->orderKey)->value('payment_status'));
        $this->actor(5101);
        $this->assertCount(1, app(CheckoutCodTtnLifecycleService::class)->queue('pickup')['items']);
    }

    public function test_cod_life51_transit_arrival_received_sequence_remains_unpaid(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '5');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(1));
        $this->codLife51Poll($c['tracking_key'], '7');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(1));
        $this->codLife51Poll($c['tracking_key'], '9');
        $r = $this->codLife51Sync($c['tracking_key']);
        $this->assertSame(3, $r['events_created']);
        $this->assertSame('received', $r['observed_state']);
        $this->assertSame('verify_cod_collection', $r['action_code']);
        $this->assertFalse($r['cod_paid']);
        $this->assertSame('pending', DB::table('orders')->where('order_key', $this->orderKey)->value('payment_status'));
        $this->actor(5101);
        $this->assertFalse(app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key'])['customer_delivery_confirmed']);
    }

    public function test_cod_life51_refused_creates_return_followup_not_return_receipt(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '103');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $r = app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key']);
        $this->assertSame('refused', $r['observed_state']);
        $this->assertSame('follow_up_return', $r['action_code']);
        $this->assertFalse($r['return_received_by_sender_confirmed']);
    }

    public function test_cod_life51_unclaimed_creates_return_followup(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '105');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $items = app(CheckoutCodTtnLifecycleService::class)->queue('return')['items'];
        $this->assertCount(1, $items);
        $this->assertSame('unclaimed', $items[0]['observed_state']);
    }

    public function test_cod_life51_return_requested_does_not_mark_sender_receipt(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '102');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $r = app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key']);
        $this->assertSame('return_requested', $r['observed_state']);
        $this->assertFalse($r['return_received_by_sender_confirmed']);
        $this->assertFalse($r['inventory_restocked']);
    }

    public function test_cod_life51_sync_replay_creates_no_second_event(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '7');
        $this->assertSame(1, $this->codLife51Sync($c['tracking_key'])['events_created']);
        $this->assertSame(0, $this->codLife51Sync($c['tracking_key'])['events_created']);
        $this->assertDatabaseCount('checkout_cod_ttn_lifecycle_events', 1);
    }

    public function test_cod_life51_incremental_sync_keeps_append_only_chain(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '5');
        $this->codLife51Sync($c['tracking_key']);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(1));
        $this->codLife51Poll($c['tracking_key'], '7');
        $this->codLife51Sync($c['tracking_key']);
        $r = DB::table('checkout_cod_ttn_lifecycle_events')->orderBy('id')->get();
        $this->assertCount(2, $r);
        $this->assertSame($r[0]->event_hmac, $r[1]->previous_hmac);
        $this->actor(5101);
        $this->assertSame('awaiting_pickup', app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key'])['observed_state']);
    }

    public function test_cod_life51_failed_poll_keeps_last_good_state(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '7');
        $this->trackingShouldFail = true;
        $this->actor(1702);
        app(CheckoutCodTtnTrackingPollService::class)
            ->poll($c['tracking_key'], (string) Str::uuid());
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $history = app(CheckoutCodTtnLifecycleService::class)->history($c['tracking_key']);
        $this->assertSame('ignored_failure', $history['items'][0]['decision']);
        $this->assertSame('awaiting_pickup', $history['items'][0]['observed_state']);
    }

    public function test_cod_life51_unknown_provider_code_is_not_delivered(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '999');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $r = app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key']);
        $this->assertSame('awaiting_evidence', $r['observed_state']);
        $this->assertSame('ignored_unknown', $r['latest_decision']);
    }

    public function test_cod_life51_older_carrier_poll_does_not_regress(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '7');
        $this->codLife51Sync($c['tracking_key']);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->subMinutes(15));
        $this->codLife51Poll($c['tracking_key'], '9');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $r = app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key']);
        $this->assertSame('ignored_stale', $r['latest_decision']);
        $this->assertSame('awaiting_pickup', $r['observed_state']);
    }

    public function test_cod_life51_received_then_refusal_is_a_manual_conflict(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '9');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(1));
        $this->codLife51Poll($c['tracking_key'], '103');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $r = app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key']);
        $this->assertSame('conflict', $r['latest_decision']);
        $this->assertTrue($r['needs_review']);
        $this->assertSame('manual_carrier_review', $r['action_code']);
        $this->assertSame('received', $r['observed_state']);
    }

    public function test_cod_life51_return_then_received_is_a_manual_conflict(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '103');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(1));
        $this->codLife51Poll($c['tracking_key'], '9');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $r = app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key']);
        $this->assertSame('refused', $r['observed_state']);
        $this->assertTrue($r['needs_review']);
    }

    public function test_cod_life51_new_transit_after_pickup_is_regression(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '7');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(1));
        $this->codLife51Poll($c['tracking_key'], '5');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $r = app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key']);
        $this->assertSame('ignored_regression', $r['latest_decision']);
        $this->assertSame('awaiting_pickup', $r['observed_state']);
    }

    public function test_cod_life51_106_is_flagged_for_review(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '106');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $r = app(CheckoutCodTtnLifecycleService::class)->status($c['tracking_key']);
        $this->assertSame('received_reverse_created', $r['observed_state']);
        $this->assertTrue($r['needs_review']);
    }

    public function test_cod_life51_history_paginates_without_rewriting(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '5');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(1));
        $this->codLife51Poll($c['tracking_key'], '7');
        $this->codLife51Sync($c['tracking_key']);
        $this->actor(5101);
        $service = app(CheckoutCodTtnLifecycleService::class);
        $first = $service->history($c['tracking_key'], 1);
        $this->assertCount(1, $first['items']);
        $this->assertNotNull($first['next_before_id']);
        $second = $service->history($c['tracking_key'], 1, $first['next_before_id']);
        $this->assertCount(1, $second['items']);
        $this->assertNull($second['next_before_id']);
    }

    public function test_cod_life51_schedule_scans_stored_observations_once(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '103');
        $this->assertSame(1, app(CheckoutCodTtnLifecycleService::class)->scanDue()['events_created']);
        $this->assertSame(0, app(CheckoutCodTtnLifecycleService::class)->scanDue()['events_created']);
        $this->assertDatabaseCount('checkout_cod_ttn_lifecycle_events', 1);
    }

    public function test_cod_life51_no_permission_cannot_sync_or_read(): void
    {
        $c = $this->codLife51Fixture();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodTtnLifecycleService::class)->sync($c['tracking_key']);
    }

    public function test_cod_life51_postgresql_rejects_audit_mutation(): void
    {
        $c = $this->codLife51Fixture();
        $this->codLife51Poll($c['tracking_key'], '7');
        $this->codLife51Sync($c['tracking_key']);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_ttn_lifecycle_events')->delete();
    }

    public function test_cod_life51_routes_require_sanctum_and_are_registered(): void
    {
        foreach (['cod.lifecycle51.status', 'cod.lifecycle51.sync',
                     'cod.lifecycle51.history', 'cod.lifecycle51.queue'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
