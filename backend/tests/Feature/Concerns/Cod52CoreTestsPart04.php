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

trait Cod52CoreTestsPart04
{
    public function test_cod_correction46_reviewer_cannot_publish_their_approval(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        $this->codCorrection46Action($c, 'approve');
        Gate::define(CheckoutCodDailyCorrectionService::PUBLISH_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1202);
        $this->actor(1202);
        $this->expectException(DomainException::class);
        app(CheckoutCodDailyCorrectionService::class)->act(
            $c['correction_key'], (string) Str::uuid(), 'publish', 'approved_appendix'
        );
    }

    public function test_cod_correction46_rejection_is_terminal(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        $r = $this->codCorrection46Action($c, 'reject');
        $this->assertSame('rejected', $r['status']);
        $this->expectException(DomainException::class);
        $this->codCorrection46Action($c, 'approve');
    }

    public function test_cod_correction46_late_gateway_reversal_detected(): void
    {
        $f = $this->codCorrection46Fixture(false);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        $this->codCorrection46AddGatewayReversal();
        $c = $this->codCorrection46Propose($f, null, 'late_financial_evidence');
        $this->assertSame(1,
            $c['observation']['late_signals']['late_gateway_reversal_reports_count']);
        $this->assertSame([], $c['observation']['delta']);
    }

    public function test_cod_correction46_new_evidence_invalidates_review(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        $this->codCorrection46AddGatewayReversal();
        $this->actor(1202);
        $this->expectException(DomainException::class);
        app(CheckoutCodDailyCorrectionService::class)->act(
            $c['correction_key'], (string) Str::uuid(), 'approve', 'evidence_reviewed'
        );
    }

    public function test_cod_correction46_stale_proposal_can_be_rejected(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        $this->codCorrection46AddGatewayReversal();
        $reject = $this->codCorrection46Action($c, 'reject');
        $this->assertSame('rejected', $reject['status']);
    }

    public function test_cod_correction46_wrong_role_cannot_approve(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        $this->actor(1204);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodDailyCorrectionService::class)->act(
            $c['correction_key'], (string) Str::uuid(), 'approve', 'evidence_reviewed'
        );
    }

    public function test_cod_correction46_approval_uuid_replays_without_new_event(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        $this->actor(1202);
        $op = (string) Str::uuid();
        $svc = app(CheckoutCodDailyCorrectionService::class);
        $a = $svc->act($c['correction_key'], $op, 'approve', 'evidence_reviewed');
        $b = $svc->act($c['correction_key'], $op, 'approve', 'evidence_reviewed');
        $this->assertFalse($a['idempotent_replay']);
        $this->assertTrue($b['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_daily_correction_events', 2);
    }

    public function test_cod_correction46_published_csv_audited_and_idempotent(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        $this->codCorrection46Action($c, 'approve');
        $this->codCorrection46Action($c, 'publish');
        $this->actor(1204);
        $op = (string) Str::uuid();
        $svc = app(CheckoutCodDailyCorrectionService::class);
        $a = $svc->exportCsv($c['correction_key'], $op);
        $b = $svc->exportCsv($c['correction_key'], $op);
        $this->assertFalse($a['idempotent_replay']);
        $this->assertTrue($b['idempotent_replay']);
        $this->assertSame($a['csv'], $b['csv']);
        $this->assertStringContainsString('financial,register_claims_count,0,1,1,count', $a['csv']);
        $this->assertStringNotContainsString('Петренко', $a['csv']);
        $this->assertDatabaseCount('checkout_cod_daily_correction_events', 4);
        $this->assertTrue($svc->verify($c['correction_key'])['integrity']['valid']);
    }

    public function test_cod_correction46_unpublished_csv_cannot_be_exported(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        $this->actor(1204);
        $this->expectException(DomainException::class);
        app(CheckoutCodDailyCorrectionService::class)->exportCsv(
            $c['correction_key'], (string) Str::uuid()
        );
    }

    public function test_cod_correction46_event_rows_cannot_be_mutated(): void
    {
        $f = $this->codCorrection46Fixture();
        $this->codCorrection46Propose($f);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_daily_correction_events')->update([
            'actor_id' => 999,
        ]);
    }

    public function test_cod_correction46_history_and_listing_show_appendix(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        $this->codCorrection46Action($c, 'approve');
        $this->codCorrection46Action($c, 'publish');
        $this->actor(1204);
        $svc = app(CheckoutCodDailyCorrectionService::class);
        $history = $svc->history($c['correction_key'], null, 2);
        $this->assertCount(2, $history['items']);
        $this->assertNotNull($history['next_before_id']);
        $older = $svc->history($c['correction_key'], $history['next_before_id'], 2);
        $this->assertSame('proposed', $older['items'][0]['event_kind']);
        $list = $svc->listing($f['report']['report_key']);
        $this->assertSame($c['correction_key'], $list['items'][0]['correction_key']);
    }

    public function test_cod_correction46_routes_registered(): void
    {
        foreach ([
                     'cod.correction46.index', 'cod.correction46.propose',
                     'cod.correction46.show', 'cod.correction46.verify',
                     'cod.correction46.act', 'cod.correction46.events',
                     'cod.correction46.csv',
                 ] as $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name));
        }
    }

    public function test_cod_monitor47_clean_report_is_archived_as_no_attention(): void
    {
        $f = $this->codMonitor47Fixture(false);
        $result = $this->codMonitor47Scan();

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['signals_created']);

        $signal = $this->codMonitor47Latest();

        $this->assertSame('clean', $signal['coverage_status']);
        $this->assertSame('none', $signal['severity']);
        $this->assertSame($f['report']['report_key'], $signal['report_key']);
        $this->assertFalse($signal['customer_payment_confirmed']);
    }

    public function test_cod_monitor47_late_register_flags_correction_required(): void
    {
        $this->codMonitor47Fixture();
        $this->codMonitor47Scan();

        $signal = $this->codMonitor47Latest();

        $this->assertSame('correction_required', $signal['coverage_status']);
        $this->assertSame('critical', $signal['severity']);
        $this->assertSame('propose', $signal['next_action']);

        $this->assertCount(
            1,
            app(CheckoutCodDailyMonitorService::class)
                ->listing('unacknowledged')['items']
        );
    }

    public function test_cod_monitor47_periodic_rescan_is_deduplicated(): void
    {
        $this->codMonitor47Fixture();

        $this->assertSame(1, $this->codMonitor47Scan()['signals_created']);
        $this->assertSame(0, $this->codMonitor47Scan()['signals_created']);
        $this->assertSame(0, $this->codMonitor47Scan()['signals_created']);

        $this->assertDatabaseCount('checkout_cod_daily_monitor_signals', 1);
    }

    public function test_cod_monitor47_proposed_appendix_waits_for_independent_review(): void
    {
        $f = $this->codMonitor47Fixture();

        $this->codMonitor47Scan();

        $this->actor(1201);
        $this->codCorrection46Propose($f);

        $this->codMonitor47Scan();

        $this->assertSame(
            'awaiting_review',
            $this->codMonitor47Latest()['coverage_status']
        );
        $this->assertDatabaseCount('checkout_cod_daily_monitor_signals', 2);
    }

    public function test_cod_monitor47_approved_appendix_waits_for_publication(): void
    {
        $f = $this->codMonitor47Fixture();

        $this->actor(1201);
        $c = $this->codCorrection46Propose($f);

        $this->codCorrection46Action($c, 'approve');

        $this->codMonitor47Scan();

        $this->assertSame(
            'awaiting_publication',
            $this->codMonitor47Latest()['coverage_status']
        );
    }

    public function test_cod_monitor47_published_matching_appendix_covers_evidence(): void
    {
        $f = $this->codMonitor47Fixture();

        $this->actor(1201);
        $c = $this->codCorrection46Propose($f);

        $this->codCorrection46Action($c, 'approve');
        $this->codCorrection46Action($c, 'publish');

        $this->codMonitor47Scan();

        $signal = $this->codMonitor47Latest();

        $this->assertSame('covered', $signal['coverage_status']);

        $this->assertSame(
            [],
            app(CheckoutCodDailyMonitorService::class)
                ->listing('attention')['items']
        );
    }

    public function test_cod_monitor47_rejected_appendix_remains_attention_required(): void
    {
        $f = $this->codMonitor47Fixture();

        $this->actor(1201);
        $c = $this->codCorrection46Propose($f);

        $this->codCorrection46Action($c, 'reject');
        $this->codMonitor47Scan();

        $signal = $this->codMonitor47Latest();

        $this->assertSame('rejected_requires_action', $signal['coverage_status']);
        $this->assertSame('investigate', $signal['next_action']);
    }

    public function test_cod_monitor47_new_evidence_after_publication_reopens_attention(): void
    {
        $f = $this->codMonitor47Fixture();

        $this->actor(1201);
        $c = $this->codCorrection46Propose($f);

        $this->codCorrection46Action($c, 'approve');
        $this->codCorrection46Action($c, 'publish');
        $this->codMonitor47Scan();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addMinute()
        );
        $this->codCorrection46AddGatewayReversal();

        $this->codMonitor47Scan();

        $signal = $this->codMonitor47Latest();

        $this->assertSame('changed_after_publication', $signal['coverage_status']);
        $this->assertSame('propose_updated', $signal['next_action']);
    }

    public function test_cod_monitor47_attention_receipt_is_audited_not_payment(): void
    {
        $this->codMonitor47Fixture();
        $this->codMonitor47Scan();

        $signal = $this->codMonitor47Latest();

        $r = app(CheckoutCodDailyMonitorService::class)
            ->acknowledge($signal['signal_id'], (string) Str::uuid());

        $this->assertSame(1204, $r['acknowledged_by']);
        $this->assertFalse($r['financial_evidence_approved']);
        $this->assertFalse($r['orders_payment_status_changed']);

        $this->assertDatabaseCount('checkout_cod_daily_monitor_receipts', 1);

        $this->assertSame(
            [],
            app(CheckoutCodDailyMonitorService::class)
                ->listing('unacknowledged')['items']
        );

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_cod_monitor47_receipt_operation_uuid_is_idempotent(): void
    {
        $this->codMonitor47Fixture();
        $this->codMonitor47Scan();

        $signal = $this->codMonitor47Latest();
        $svc = app(CheckoutCodDailyMonitorService::class);
        $op = (string) Str::uuid();

        $first = $svc->acknowledge($signal['signal_id'], $op);
        $again = $svc->acknowledge($signal['signal_id'], $op);

        $this->assertFalse($first['idempotent_replay']);
        $this->assertTrue($again['idempotent_replay']);

        $this->assertDatabaseCount('checkout_cod_daily_monitor_receipts', 1);
    }

    public function test_cod_monitor47_wrong_employee_cannot_acknowledge(): void
    {
        $this->codMonitor47Fixture();
        $this->codMonitor47Scan();

        $signal = $this->codMonitor47Latest();
        $this->actor(1201);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodDailyMonitorService::class)->acknowledge(
            $signal['signal_id'], (string) Str::uuid()
        );
    }

    public function test_cod_monitor47_clean_signal_cannot_be_acknowledged(): void
    {
        $this->codMonitor47Fixture(false);
        $this->codMonitor47Scan();

        $signal = $this->codMonitor47Latest();

        $this->expectException(DomainException::class);

        app(CheckoutCodDailyMonitorService::class)->acknowledge(
            $signal['signal_id'], (string) Str::uuid()
        );
    }

    public function test_cod_monitor47_superseded_signal_requires_new_receipt(): void
    {
        $f = $this->codMonitor47Fixture();

        $this->codMonitor47Scan();
        $signal = $this->codMonitor47Latest();

        $this->actor(1201);
        $this->codCorrection46Propose($f);

        $this->codMonitor47Scan();

        $this->actor(1204);
        $this->expectException(DomainException::class);

        app(CheckoutCodDailyMonitorService::class)->acknowledge(
            $signal['signal_id'], (string) Str::uuid()
        );
    }

    public function test_cod_monitor47_single_receipt_per_signal(): void
    {
        $this->codMonitor47Fixture();
        $this->codMonitor47Scan();

        $signal = $this->codMonitor47Latest();
        $svc = app(CheckoutCodDailyMonitorService::class);

        $svc->acknowledge($signal['signal_id'], (string) Str::uuid());

        $this->expectException(DomainException::class);

        $svc->acknowledge($signal['signal_id'], (string) Str::uuid());
    }

    public function test_cod_monitor47_monitor_history_shows_state_transitions(): void
    {
        $f = $this->codMonitor47Fixture();

        $this->codMonitor47Scan();

        $this->actor(1201);
        $c = $this->codCorrection46Propose($f);

        $this->codMonitor47Scan();
        $this->codCorrection46Action($c, 'approve');
        $this->codMonitor47Scan();

        $this->actor(1204);
        $svc = app(CheckoutCodDailyMonitorService::class);

        $first = $svc->history($f['report']['report_key'], null, 2);

        $this->assertCount(2, $first['items']);
        $this->assertSame('awaiting_publication', $first['items'][0]['coverage_status']);
        $this->assertNotNull($first['next_before_id']);

        $next = $svc->history(
            $f['report']['report_key'],
            $first['next_before_id'],
            2
        );

        $this->assertCount(1, $next['items']);
        $this->assertSame('correction_required', $next['items'][0]['coverage_status']);
    }

    public function test_cod_monitor47_postgresql_prevents_signal_mutation(): void
    {
        $this->codMonitor47Fixture();
        $this->codMonitor47Scan();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_daily_monitor_signals')
            ->update(['severity' => 'none']);
    }

    public function test_cod_monitor47_artisan_command_checks_sealed_reports(): void
    {
        $this->codMonitor47Fixture();

        $this->assertSame(
            0,
            Artisan::call('cod:daily-monitor', ['--limit' => 50])
        );

        $this->assertDatabaseCount('checkout_cod_daily_monitor_signals', 1);
    }

    public function test_cod_monitor47_scan_cursor_advances_across_report_pages(): void
    {
        $f = $this->codMonitor47Fixture(false);

        $this->actor(1200);

        $previousDay = CarbonImmutable::parse(
            $f['report']['report_date']
        )->subDay()->toDateString();

        $this->codDaily45Seal($previousDay);
        $this->codMonitor47Gate();

        $this->assertSame(1, $this->codMonitor47Scan(1)['scanned']);
        $this->assertSame(1, $this->codMonitor47Scan(1)['scanned']);

        $this->assertDatabaseCount('checkout_cod_daily_monitor_signals', 2);
    }

    public function test_cod_monitor47_invalid_scan_limit_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CheckoutCodDailyMonitorService::class)->scan(0);
    }

    public function test_cod_monitor47_routes_exist(): void
    {
        foreach ([
                     'cod.monitor47.index',
                     'cod.monitor47.history',
                     'cod.monitor47.acknowledge',
                 ] as $name) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($name)
            );
        }
    }

    public function test_cod_sla48_no_warning_before_review_window(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 175);
        $this->assertSame(0, $this->codSla48Scan()['new_alerts']);
        $this->assertDatabaseCount('checkout_cod_correction_sla_alerts', 0);
    }

    public function test_cod_sla48_review_warning_targets_review_team_only(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 180);
        $this->assertSame(1, $this->codSla48Scan()['new_alerts']);
        $alert = $this->codSla48Inbox()[0];
        $this->assertSame('due_soon', $alert['stage']);
        $this->assertSame('review', $alert['phase']);
        $this->assertSame('finance_review', $alert['target_role']);
        $this->assertSame([], $this->codSla48Inbox('all', 1205));
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_cod_sla48_rescan_does_not_duplicate_stage(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 185);
        $this->assertSame(1, $this->codSla48Scan()['new_alerts']);
        $this->assertSame(0, $this->codSla48Scan()['new_alerts']);
        $this->assertDatabaseCount('checkout_cod_correction_sla_alerts', 1);
    }

    public function test_cod_sla48_escalates_at_deadline_four_and_twenty_four_hours(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 240);
        $this->codSla48Scan();
        $this->assertSame('overdue', $this->codSla48Inbox()[0]['stage']);
        $this->codSla48Advance($c, 'proposed', 480);
        $this->codSla48Scan();
        $this->assertSame('overdue_4h', $this->codSla48Inbox('all', 1206)[0]['stage']);
        $this->codSla48Advance($c, 'proposed', 1680);
        $this->codSla48Scan();
        $this->assertSame('overdue_24h', $this->codSla48Inbox('all', 1206)[0]['stage']);
        $this->assertDatabaseCount('checkout_cod_correction_sla_alerts', 3);
    }

    public function test_cod_sla48_management_escalation_replaces_team_current_item(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 240);
        $this->codSla48Scan();
        $this->codSla48Advance($c, 'proposed', 480);
        $this->codSla48Scan();
        $this->assertSame([], $this->codSla48Inbox('all', 1204));
        $this->actor(1206);
        $summary = app(CheckoutCodCorrectionSlaInboxService::class)->summary();
        $this->assertSame(1, $summary['active_alerts']);
        $this->assertSame(1, $summary['critical']);
        $this->assertSame(1, $summary['management_escalations']);
    }

    public function test_cod_sla48_approval_starts_new_publication_deadline(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 190);
        $this->codSla48Scan();
        $this->codCorrection46Action($c, 'approve');
        $this->codSla48Advance($c, 'approved', 65);
        $this->codSla48Scan();
        $this->assertSame([], $this->codSla48Inbox('all', 1204));
        $alert = $this->codSla48Inbox('all', 1205)[0];
        $this->assertSame('publication', $alert['phase']);
        $this->assertSame('due_soon', $alert['stage']);
        $this->assertDatabaseCount('checkout_cod_correction_sla_alerts', 2);
    }

    public function test_cod_sla48_published_correction_hides_active_alert_but_keeps_history(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codCorrection46Action($c, 'approve');
        $this->codSla48Advance($c, 'approved', 70);
        $this->codSla48Scan();
        $this->assertCount(1, $this->codSla48Inbox('all', 1205));
        $this->codCorrection46Action($c, 'publish');
        $this->assertSame([], $this->codSla48Inbox('all', 1205));
        $this->actor(1205);
        $history = app(CheckoutCodCorrectionSlaInboxService::class)
            ->history($c['correction_key']);
        $this->assertCount(1, $history['items']);
    }

    public function test_cod_sla48_rejected_correction_escalates_to_management_immediately(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codCorrection46Action($c, 'reject');
        $this->codSla48Scan();
        $alert = $this->codSla48Inbox('all', 1206)[0];
        $this->assertSame('rejection', $alert['phase']);
        $this->assertSame('rejected_action', $alert['stage']);
        $this->assertSame('critical', $alert['severity']);
        $this->assertSame([], $this->codSla48Inbox('all', 1204));
    }
}
