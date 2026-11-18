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

trait Cod52CoreTestsPart05
{
    public function test_cod_sla48_rejected_followup_becomes_overdue_after_one_day(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codCorrection46Action($c, 'reject');
        $this->codSla48Scan();
        $this->codSla48Advance($c, 'rejected', 1440);
        $this->codSla48Scan();
        $this->assertSame('overdue', $this->codSla48Inbox('all', 1206)[0]['stage']);
        $this->assertDatabaseCount('checkout_cod_correction_sla_alerts', 2);
    }

    public function test_cod_sla48_acknowledgement_is_idempotent_not_financial_approval(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 180);
        $this->codSla48Scan();
        $alert = $this->codSla48Inbox()[0];
        $service = app(CheckoutCodCorrectionSlaInboxService::class);
        $op = (string) Str::uuid();
        $first = $service->acknowledge($alert['alert_id'], $op);
        $replay = $service->acknowledge($alert['alert_id'], $op);
        $this->assertFalse($first['idempotent_replay']);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertFalse($replay['financial_approval_performed']);
        $this->assertSame([], $service->listing('unacknowledged')['items']);
        $this->assertDatabaseCount('checkout_cod_correction_sla_receipts', 1);
    }

    public function test_cod_sla48_wrong_team_cannot_acknowledge_an_alert(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 180);
        $this->codSla48Scan();
        $alertId = (int) DB::table('checkout_cod_correction_sla_alerts')->value('id');
        $this->actor(1205);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodCorrectionSlaInboxService::class)
            ->acknowledge($alertId, (string) Str::uuid());
    }

    public function test_cod_sla48_phase_transition_rejects_stale_acknowledgement(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 185);
        $this->codSla48Scan();
        $alertId = (int) DB::table('checkout_cod_correction_sla_alerts')->value('id');
        $this->codCorrection46Action($c, 'approve');
        $this->actor(1204);
        $this->expectException(DomainException::class);
        app(CheckoutCodCorrectionSlaInboxService::class)
            ->acknowledge($alertId, (string) Str::uuid());
    }

    public function test_cod_sla48_new_escalation_supersedes_older_unread_alert(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 180);
        $this->codSla48Scan();
        $oldId = (int) DB::table('checkout_cod_correction_sla_alerts')->value('id');
        $this->codSla48Advance($c, 'proposed', 480);
        $this->codSla48Scan();
        $this->actor(1204);
        $this->expectException(DomainException::class);
        app(CheckoutCodCorrectionSlaInboxService::class)
            ->acknowledge($oldId, (string) Str::uuid());
    }

    public function test_cod_sla48_postgresql_prevents_receipt_mutation(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 185);
        $this->codSla48Scan();
        $alert = $this->codSla48Inbox()[0];
        app(CheckoutCodCorrectionSlaInboxService::class)
            ->acknowledge($alert['alert_id'], (string) Str::uuid());
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_correction_sla_receipts')->update(['actor_id' => 999]);
    }

    public function test_cod_sla48_history_retains_all_escalation_stages(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 180);
        $this->codSla48Scan();
        $this->codSla48Advance($c, 'proposed', 240);
        $this->codSla48Scan();
        $this->actor(1204);
        $history = app(CheckoutCodCorrectionSlaInboxService::class)
            ->history($c['correction_key']);
        $this->assertCount(2, $history['items']);
        $this->assertSame('overdue', $history['items'][0]['stage']);
        $this->assertSame('due_soon', $history['items'][1]['stage']);
    }

    public function test_cod_sla48_artisan_command_and_api_routes_registered(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 180);
        $this->assertSame(0, Artisan::call('cod:correction-sla', ['--limit' => 50]));
        $this->assertDatabaseCount('checkout_cod_correction_sla_alerts', 1);
        foreach (['cod.sla48.index', 'cod.sla48.summary', 'cod.sla48.history', 'cod.sla48.acknowledge'] as $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name));
        }
    }

    public function test_cod_sla48_cursor_wraps_and_deduplicates(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->codSla48Advance($c, 'proposed', 180);
        $this->assertSame(1, $this->codSla48Scan(1)['new_alerts']);
        $this->assertSame(0, $this->codSla48Scan(1)['scanned']);
        $this->assertSame(0, $this->codSla48Scan(1)['new_alerts']);
        $this->assertDatabaseCount('checkout_cod_correction_sla_alerts', 1);
    }

    public function test_cod_sla48_unprivileged_user_cannot_read_crm(): void
    {
        [, $c] = $this->codSla48Fixture();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodCorrectionSlaInboxService::class)->listing();
    }

    public function test_cod_sla48_invalid_batch_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(CheckoutCodCorrectionSlaScanService::class)->scan(0);
    }

    public function test_cod_finance49_unified_queue_contains_monitor_and_management_sla(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1206);

        $data = app(CheckoutCodFinanceCenterService::class)
            ->queue();

        $sources = array_column(
            $data['items'],
            'source'
        );

        $this->assertContains('monitor', $sources);
        $this->assertContains('sla', $sources);

        $this->assertTrue(
            $data['actions_must_use_source_workflow']
        );

        $this->assertTrue(
            $data['snapshot_is_live']
        );
    }

    public function test_cod_finance49_review_team_does_not_see_management_sla(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1204);

        $sources = array_column(
            app(CheckoutCodFinanceCenterService::class)
                ->queue()['items'],
            'source'
        );

        $this->assertContains('monitor', $sources);
        $this->assertNotContains('sla', $sources);
    }

    public function test_cod_finance49_publication_team_does_not_see_review_or_management(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1205);

        $this->assertSame(
            [],
            app(CheckoutCodFinanceCenterService::class)
                ->queue()['items']
        );
    }

    public function test_cod_finance49_source_filter_keeps_only_sla(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1206);

        $items = app(CheckoutCodFinanceCenterService::class)
            ->queue('sla')['items'];

        $this->assertCount(1, $items);
        $this->assertSame('sla', $items[0]['source']);
        $this->assertSame('overdue_4h', $items[0]['code']);
        $this->assertSame(
            'finance_management',
            $items[0]['target_role']
        );
    }

    public function test_cod_finance49_severity_filter_separates_sla_and_monitor(): void
    {
        $this->codFinance49Fixture(480);

        $this->actor(1206);

        $critical = app(CheckoutCodFinanceCenterService::class)
            ->queue('all', 'critical');

        $this->assertSame(
            'sla',
            $critical['items'][0]['source']
        );

        $this->assertNotContains(
            'monitor',
            array_column($critical['items'], 'source')
        );

        $warning = app(CheckoutCodFinanceCenterService::class)
            ->queue('all', 'warning');

        $this->assertSame(
            'monitor',
            $warning['items'][0]['source']
        );
    }

    public function test_cod_finance49_global_priority_orders_critical_before_warning(): void
    {
        $this->codFinance49Fixture(480);

        $this->actor(1206);

        $items = app(CheckoutCodFinanceCenterService::class)
            ->queue()['items'];

        $this->assertGreaterThanOrEqual(
            2,
            count($items)
        );

        $this->assertSame(
            'critical',
            $items[0]['severity']
        );

        $this->assertSame(
            'warning',
            $items[count($items) - 1]['severity']
        );
    }

    public function test_cod_finance49_cursor_paginates_distinct_sources(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1206);

        $service = app(CheckoutCodFinanceCenterService::class);

        $first = $service->queue(
            'all',
            'all',
            false,
            1
        );

        $this->assertCount(
            1,
            $first['items']
        );

        $this->assertNotNull(
            $first['next_cursor']
        );

        $second = $service->queue(
            'all',
            'all',
            false,
            1,
            $first['next_cursor']
        );

        $this->assertCount(
            1,
            $second['items']
        );

        $this->assertNotSame(
            $first['items'][0]['source']
            . ':'
            . $first['items'][0]['source_id'],
            $second['items'][0]['source']
            . ':'
            . $second['items'][0]['source_id']
        );
    }

    public function test_cod_finance49_cursor_rejects_changed_filter(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1206);

        $service = app(CheckoutCodFinanceCenterService::class);

        $first = $service->queue(
            'all',
            'all',
            false,
            1
        );

        $this->assertNotNull(
            $first['next_cursor']
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $service->queue(
            'sla',
            'all',
            false,
            1,
            $first['next_cursor']
        );
    }

    public function test_cod_finance49_summary_uses_only_visible_sources(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1206);

        $manager = app(CheckoutCodFinanceCenterService::class)
            ->summary();

        $this->assertSame(
            1,
            $manager['by_source']['monitor']
        );

        $this->assertSame(
            1,
            $manager['by_source']['sla']
        );

        $this->assertSame(
            1,
            $manager['by_severity']['critical']
        );

        $this->assertSame(
            1,
            $manager['by_severity']['warning']
        );

        $this->actor(1204);

        $reviewer = app(CheckoutCodFinanceCenterService::class)
            ->summary();

        $this->assertSame(
            0,
            $reviewer['by_source']['sla']
        );
    }

    public function test_cod_finance49_management_overview_reuses_block44_analytics(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1206);

        $result = app(CheckoutCodFinanceCenterService::class)
            ->overview(2);

        $this->assertArrayHasKey(
            'financial',
            $result
        );

        $this->assertArrayHasKey(
            'staff',
            $result
        );

        $this->assertArrayHasKey(
            'notifications',
            $result
        );

        $this->assertArrayHasKey(
            'risk_summary',
            $result
        );

        $this->assertCount(
            2,
            $result['top_risks']
        );

        $this->assertTrue(
            $result['financial_data_are_not_proof_of_customer_payment']
        );
    }

    public function test_cod_finance49_review_team_cannot_see_management_financial_overview(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1204);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutCodFinanceCenterService::class)
            ->overview();
    }

    public function test_cod_finance49_unprivileged_user_cannot_access_queue(): void
    {
        $this->codFinance49Fixture();

        $this->actor(999);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutCodFinanceCenterService::class)
            ->queue();
    }

    public function test_cod_finance49_malformed_cursor_is_rejected(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1206);

        $this->expectException(
            InvalidArgumentException::class
        );

        app(CheckoutCodFinanceCenterService::class)
            ->queue(
                'all',
                'all',
                false,
                20,
                'invalid'
            );
    }

    public function test_cod_finance49_invalid_paging_limit_is_rejected(): void
    {
        $this->codFinance49Gate();

        $this->actor(1206);

        $this->expectException(
            InvalidArgumentException::class
        );

        app(CheckoutCodFinanceCenterService::class)
            ->queue(
                'all',
                'all',
                false,
                101
            );
    }

    public function test_cod_finance49_clean_monitor_signals_not_in_risk_queue(): void
    {
        $this->codMonitor47Fixture(false);

        $this->codFinance49Gate();

        $this->codMonitor47Scan();

        $this->actor(1206);

        $queue = app(CheckoutCodFinanceCenterService::class)
            ->queue('monitor');

        $this->assertSame(
            [],
            $queue['items']
        );
    }

    public function test_cod_finance49_dashboard_does_not_mutate_sources(): void
    {
        $this->codFinance49Fixture();

        $this->actor(1206);

        $before = [
            DB::table('checkout_cod_daily_reports')->count(),
            DB::table('checkout_cod_daily_corrections')->count(),
            DB::table('checkout_cod_daily_monitor_signals')->count(),
            DB::table('checkout_cod_correction_sla_alerts')->count(),
            DB::table('checkout_cod_correction_sla_receipts')->count(),
        ];

        $center = app(CheckoutCodFinanceCenterService::class);

        $center->queue();
        $center->summary();
        $center->overview();

        $after = [
            DB::table('checkout_cod_daily_reports')->count(),
            DB::table('checkout_cod_daily_corrections')->count(),
            DB::table('checkout_cod_daily_monitor_signals')->count(),
            DB::table('checkout_cod_correction_sla_alerts')->count(),
            DB::table('checkout_cod_correction_sla_receipts')->count(),
        ];

        $this->assertSame(
            $before,
            $after
        );
    }

    public function test_cod_finance49_routes_registered(): void
    {
        foreach ([
                     'cod.finance49.queue',
                     'cod.finance49.summary',
                     'cod.finance49.overview',
                 ] as $name) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($name)
            );
        }

    }
}
