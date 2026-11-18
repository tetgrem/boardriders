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

trait Cod52CoreTestsPart03
{
    public function test_cod_analytics44_empty_period_has_zero_values_not_fake_percentages(): void
    {
        $today = CarbonImmutable::now('UTC')->toDateString();
        $r = $this->codAnalytics44Report($today, $today);
        $this->assertSame(0, $r['financial']['postings_count']);
        $this->assertSame(0, $r['cases']['created_count']);
        $this->assertNull($r['financial']['register_coverage_bp']);
        $this->assertNull($r['cases']['closed_sla_hit_rate_bp']);
        $this->assertSame(0, $r['notifications']['outbox_created_count']);
        $this->assertCount(1, $r['daily']);
        $this->assertFalse($r['cod_paid']);
    }

    public function test_cod_analytics44_bank_posting_counts_money_without_customer_paid(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $date = CarbonImmutable::now('UTC')->toDateString();
        $r = $this->codAnalytics44Report($date, $date);
        $this->assertSame(1, $r['financial']['postings_count']);
        $this->assertSame(680, $r['financial']['posted_bank_minor']);
        $this->assertSame(2000, $r['financial']['journal_gross_minor']);
        $this->assertSame(1320, $r['financial']['journal_fee_minor']);
        $this->assertSame(1, $r['financial']['missing_register_count']);
        $this->assertSame(0, $r['financial']['register_coverage_bp']);
        $this->assertNull($r['financial']['customer_paid_minor_verified']);
        $this->assertSame(1, $r['daily'][0]['postings_count']);
        $this->assertSame(680, $r['daily'][0]['posted_bank_minor']);
        $this->assertFalse($r['automatic_payment_posting_allowed']);
    }

    public function test_cod_analytics44_registry_is_a_claim_not_revenue(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();
        $this->novapayRegister39Capture($posting, $rows);
        $date = CarbonImmutable::now('UTC')->toDateString();
        $r = $this->codAnalytics44Report($date, $date);
        $this->assertSame(1, $r['financial']['register_claims_count']);
        $this->assertSame(0, $r['financial']['missing_register_count']);
        $this->assertSame(10000, $r['financial']['register_coverage_bp']);
        $this->assertSame(2000, $r['financial']['claimed_register_gross_minor']);
        $this->assertSame(1320, $r['financial']['claimed_register_fee_minor']);
        $this->assertSame(680, $r['financial']['claimed_register_net_minor']);
        $this->assertSame(0, $r['financial']['register_amount_mismatch_count']);
        $this->assertNull($r['financial']['revenue_minor_verified']);
    }

    public function test_cod_analytics44_case_cohort_counts_open_and_unassigned(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $case = $this->codCase42Open($posting);
        $date = CarbonImmutable::now('UTC')->toDateString();
        $r = $this->codAnalytics44Report($date, $date);
        $this->assertSame(1, $r['cases']['created_count']);
        $this->assertSame(1, $r['cases']['open_count']);
        $this->assertSame(0, $r['cases']['currently_assigned_count']);
        $this->assertSame([], $r['staff']['items']);
        $this->assertSame(1, $r['daily'][0]['cases_created_count']);
        $this->assertSame('open', $case['case_status']);
    }

    public function test_cod_analytics44_employee_load_is_based_on_current_assignee(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $case = $this->codCase42Open($posting);
        $this->codCase42Action($case, 'claim', 'start_work');
        $date = CarbonImmutable::now('UTC')->toDateString();
        $r = $this->codAnalytics44Report($date, $date);
        $this->assertSame(1, $r['cases']['in_progress_count']);
        $this->assertSame(1, $r['cases']['currently_assigned_count']);
        $this->assertSame(1200, $r['staff']['items'][0]['actor_id']);
        $this->assertSame(1, $r['staff']['items'][0]['active_cases_count']);
        $this->assertSame(0, $r['staff']['items'][0]['closed_cases_count']);
        $this->assertNull($r['staff']['items'][0]['closed_sla_hit_rate_bp']);
        $this->assertFalse($r['staff']['truncated']);
    }

    public function test_cod_analytics44_open_overdue_is_evaluated_at_report_time(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $case = $this->codCase42Open($posting);
        $originalDay = CarbonImmutable::now('UTC')->toDateString();
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse($case['sla_due_at'])->addMinute()
        );
        $r = $this->codAnalytics44Report($originalDay, $originalDay);
        $this->assertSame(1, $r['cases']['active_overdue_count']);
        $this->assertSame(0, $r['cases']['closed_internal_review_count']);
        $this->assertFalse($r['cases']['live_evidence_freshness_checked']);
    }

    public function test_cod_analytics44_daily_series_fills_empty_days(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $today = CarbonImmutable::now('UTC');
        $r = $this->codAnalytics44Report(
            $today->subDay()->toDateString(),
            $today->addDay()->toDateString()
        );
        $this->assertCount(3, $r['daily']);
        $this->assertSame(0, $r['daily'][0]['postings_count']);
        $this->assertSame(1, $r['daily'][1]['postings_count']);
        $this->assertSame(0, $r['daily'][2]['postings_count']);
    }

    public function test_cod_analytics44_alert_outbox_shows_pending_status(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $case = $this->codCase42Open($posting);
        $from = CarbonImmutable::now('UTC')->toDateString();
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse($case['sla_due_at'])->addMinutes(30)
        );
        app(CheckoutCodCaseAlertService::class)->scan();
        $to = CarbonImmutable::now('UTC')->toDateString();
        $r = $this->codAnalytics44Report($from, $to);
        $this->assertSame(2, $r['notifications']['outbox_created_count']);
        $this->assertSame(2, $r['notifications']['outbox_pending_count']);
        $this->assertSame(1, $r['notifications']['by_kind']['unassigned_30m']);
        $this->assertSame(1, $r['notifications']['by_kind']['overdue']);
        $this->assertSame(0, $r['notifications']['crm_delivery_rate_bp']);
    }

    public function test_cod_analytics44_crm_delivery_and_read_receipts_are_separate(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $case = $this->codCase42Open($posting);
        $from = CarbonImmutable::now('UTC')->toDateString();
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse($case['sla_due_at'])->addMinutes(30)
        );
        app(CheckoutCodCaseAlertService::class)->scan();
        $outboxId = (int) DB::table('checkout_cod_case_alert_outbox')
            ->where('alert_kind', 'overdue')->value('id');
        (new DeliverCheckoutCodCaseAlert($outboxId))->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );
        $this->codAlert43Gate();
        $this->actor(1201);
        $noticeId = (int) DB::table('checkout_cod_case_alert_inbox')->value('id');
        app(CheckoutCodCaseAlertInboxService::class)->acknowledge($noticeId);
        $to = CarbonImmutable::now('UTC')->toDateString();
        $r = $this->codAnalytics44Report($from, $to);
        $this->assertSame(1, $r['notifications']['outbox_delivered_count']);
        $this->assertSame(1, $r['notifications']['outbox_pending_count']);
        $this->assertSame(5000, $r['notifications']['crm_delivery_rate_bp']);
        $this->assertSame(1, $r['notifications']['crm_inbox_items_created_count']);
        $this->assertSame(1, $r['notifications']['crm_inbox_read_count']);
        $this->assertFalse($r['notifications']['external_channels_delivered_verified']);
    }

    public function test_cod_analytics44_outside_requested_period_is_excluded(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $far = CarbonImmutable::now('UTC')->addDays(5)->toDateString();
        $r = $this->codAnalytics44Report($far, $far);
        $this->assertSame(0, $r['financial']['postings_count']);
        $this->assertSame(0, $r['daily'][0]['posted_bank_minor']);
    }

    public function test_cod_analytics44_requires_extra_finance_analytics_permission(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $this->actor(1200); // COD viewer, but no special analytics right.
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodAnalyticsService::class)->report();
    }

    public function test_cod_analytics44_rejects_reversed_dates(): void
    {
        $this->codAnalytics44Gate();
        $this->expectException(InvalidArgumentException::class);
        app(CheckoutCodAnalyticsService::class)->report('2026-11-14', '2026-11-13');
    }

    public function test_cod_analytics44_is_read_only_for_payment_and_case_tables(): void
    {
        [, $posting] = $this->novapayRegister39Source();
        $case = $this->codCase42Open($posting);
        $before = (int) DB::table('checkout_cod_operations_case_events')->count();
        $day = CarbonImmutable::now('UTC')->toDateString();
        $r = $this->codAnalytics44Report($day, $day);
        $this->assertSame($before,
            (int) DB::table('checkout_cod_operations_case_events')->count());
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
        $this->assertFalse($r['orders_payment_status_changed']);
        $this->assertSame($case['case_id'],
            (int) DB::table('checkout_cod_operations_cases')->value('id'));
    }

    public function test_cod_analytics44_named_api_route_is_registered(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('cod.analytics.dashboard')
        );
    }

    public function test_cod_daily45_seals_previous_day_with_precise_bank_sums(): void
    {
        $f = $this->codDaily45Fixture();
        $r = $this->codDaily45Seal($f['day']);

        $this->assertSame($f['day'], $r['report_date']);
        $this->assertSame(1, $r['schema_version']);
        $this->assertSame(
            680,
            $r['payload']['financial']['posted_bank_minor']
        );
        $this->assertSame(
            2000,
            $r['payload']['financial']['journal_gross_minor']
        );
        $this->assertSame(
            1320,
            $r['payload']['financial']['journal_fee_minor']
        );
        $this->assertSame(
            'awaiting_register_claims',
            $r['reconciliation']['status']
        );
        $this->assertFalse($r['customer_payment_confirmed']);

        $this->assertDatabaseCount('checkout_cod_daily_reports', 1);
        $this->assertDatabaseCount('checkout_cod_daily_report_events', 1);
    }

    public function test_cod_daily45_valid_register_still_not_a_verified_customer_payment(): void
    {
        $f = $this->codDaily45Fixture(false, true);
        $r = $this->codDaily45Seal($f['day']);

        $this->assertSame(
            'internally_consistent_unverified',
            $r['reconciliation']['status']
        );
        $this->assertSame(
            1,
            $r['payload']['financial']['register_claims_count']
        );
        $this->assertFalse($r['cod_paid']);
    }

    public function test_cod_daily45_same_seal_uuid_replays_original_snapshot(): void
    {
        $f = $this->codDaily45Fixture();
        $op = (string) Str::uuid();

        $first = $this->codDaily45Seal($f['day'], $op);
        $replay = $this->codDaily45Seal($f['day'], $op);

        $this->assertSame(
            $first['report_key'],
            $replay['report_key']
        );
        $this->assertSame(
            $first['payload_hmac'],
            $replay['payload_hmac']
        );
        $this->assertFalse($first['idempotent_replay']);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_daily_report_events', 1);
    }

    public function test_cod_daily45_different_uuid_cannot_overwrite_sealed_day(): void
    {
        $f = $this->codDaily45Fixture();
        $this->codDaily45Seal($f['day']);

        $this->expectException(DomainException::class);

        $this->codDaily45Seal($f['day']);
    }

    public function test_cod_daily45_seal_uuid_is_bound_to_day(): void
    {
        $f = $this->codDaily45Fixture();
        $op = (string) Str::uuid();

        $this->codDaily45Seal($f['day'], $op);

        $otherDay = CarbonImmutable::parse($f['day'])
            ->subDay()
            ->toDateString();

        $this->expectException(DomainException::class);

        $this->codDaily45Seal($otherDay, $op);
    }

    public function test_cod_daily45_cannot_seal_unfinished_utc_day(): void
    {
        $this->codDaily45Fixture();
        $today = CarbonImmutable::now('UTC')->toDateString();

        $this->expectException(DomainException::class);

        $this->codDaily45Seal($today);
    }

    public function test_cod_daily45_past_empty_day_is_explicitly_empty(): void
    {
        $f = $this->codDaily45Fixture();

        $emptyDay = CarbonImmutable::parse($f['day'])
            ->subDay()
            ->toDateString();

        $r = $this->codDaily45Seal($emptyDay);

        $this->assertSame(
            0,
            $r['payload']['financial']['postings_count']
        );
        $this->assertSame(
            'no_bank_postings',
            $r['reconciliation']['status']
        );
    }

    public function test_cod_daily45_seal_permission_is_separate_from_view(): void
    {
        $f = $this->codDaily45Fixture();

        Gate::define(
            CheckoutCodDailyReportService::SEAL_ABILITY,
            static fn ($u): bool => false
        );

        $this->expectException(AuthorizationException::class);

        $this->codDaily45Seal($f['day']);
    }

    public function test_cod_daily45_show_requires_daily_view_permission(): void
    {
        $f = $this->codDaily45Fixture();
        $r = $this->codDaily45Seal($f['day']);

        Gate::define(
            CheckoutCodDailyReportService::VIEW_ABILITY,
            static fn ($u): bool => false
        );

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodDailyReportService::class)
            ->show($r['report_key']);
    }

    public function test_cod_daily45_verify_integrity_and_unchanged_live_projection(): void
    {
        $f = $this->codDaily45Fixture();
        $r = $this->codDaily45Seal($f['day']);

        $check = app(CheckoutCodDailyReportService::class)
            ->verify($r['report_key'], true);

        $this->assertTrue($check['integrity']['valid']);
        $this->assertTrue($check['integrity']['report_hash_valid']);
        $this->assertTrue($check['integrity']['seal_event_valid']);
        $this->assertSame(
            1,
            $check['integrity']['events_checked']
        );
        $this->assertFalse($check['live_projection_differs']);
    }

    public function test_cod_daily45_live_case_change_is_not_archive_tampering(): void
    {
        $f = $this->codDaily45Fixture(true);
        $r = $this->codDaily45Seal($f['day']);

        $this->codCase42Action(
            $f['case'],
            'claim',
            'start_work'
        );

        $this->codDaily45Gate();

        $check = app(CheckoutCodDailyReportService::class)
            ->verify($r['report_key'], true);

        $this->assertTrue($check['integrity']['valid']);
        $this->assertTrue($check['live_projection_differs']);
        $this->assertTrue(
            $check['live_difference_is_not_archive_tampering']
        );
    }

    public function test_cod_daily45_csv_is_from_signed_snapshot_and_audited(): void
    {
        $f = $this->codDaily45Fixture();
        $r = $this->codDaily45Seal($f['day']);
        $op = (string) Str::uuid();

        $service = app(CheckoutCodDailyReportService::class);

        $first = $service->exportCsv($r['report_key'], $op);
        $again = $service->exportCsv($r['report_key'], $op);

        $this->assertFalse($first['idempotent_replay']);
        $this->assertTrue($again['idempotent_replay']);
        $this->assertSame($first['csv'], $again['csv']);

        $this->assertStringContainsString(
            'financial,posted_bank_minor,680,UAH_minor',
            $first['csv']
        );

        $this->assertStringNotContainsString(
            'Петренко',
            $first['csv']
        );

        $this->assertDatabaseCount(
            'checkout_cod_daily_report_events',
            2
        );
    }

    public function test_cod_daily45_export_permission_is_independent_of_view(): void
    {
        $f = $this->codDaily45Fixture();
        $r = $this->codDaily45Seal($f['day']);

        Gate::define(
            CheckoutCodDailyReportService::EXPORT_ABILITY,
            static fn ($u): bool => false
        );

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodDailyReportService::class)->exportCsv(
            $r['report_key'],
            (string) Str::uuid()
        );
    }

    public function test_cod_daily45_export_uuid_cannot_be_used_for_different_intent(): void
    {
        $f = $this->codDaily45Fixture();
        $r = $this->codDaily45Seal($f['day']);
        $op = (string) Str::uuid();

        app(CheckoutCodDailyReportService::class)->exportCsv(
            $r['report_key'],
            $op
        );

        $this->actor(1201);

        Gate::define(
            CheckoutCodDailyReportService::EXPORT_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [1200, 1201],
                true
            )
        );

        $this->expectException(DomainException::class);

        app(CheckoutCodDailyReportService::class)->exportCsv(
            $r['report_key'],
            $op
        );
    }

    public function test_cod_daily45_report_and_event_history_are_paginated(): void
    {
        $f = $this->codDaily45Fixture();
        $r = $this->codDaily45Seal($f['day']);

        $service = app(CheckoutCodDailyReportService::class);

        $service->exportCsv($r['report_key'], (string) Str::uuid());
        $service->exportCsv($r['report_key'], (string) Str::uuid());

        $page = $service->history($r['report_key'], null, 2);

        $this->assertCount(2, $page['items']);
        $this->assertNotNull($page['next_before_id']);

        $older = $service->history(
            $r['report_key'],
            $page['next_before_id'],
            2
        );

        $this->assertCount(1, $older['items']);
        $this->assertSame(
            'sealed',
            $older['items'][0]['event_kind']
        );

        $list = $service->listing();

        $this->assertSame(
            $r['report_key'],
            $list['items'][0]['report_key']
        );
    }

    public function test_cod_daily45_database_rejects_snapshot_update(): void
    {
        $f = $this->codDaily45Fixture();
        $r = $this->codDaily45Seal($f['day']);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_daily_reports')
            ->where('report_key', $r['report_key'])
            ->update([
                'payload_hmac' => str_repeat('f', 64),
            ]);
    }

    public function test_cod_daily45_database_rejects_audit_delete(): void
    {
        $f = $this->codDaily45Fixture();
        $this->codDaily45Seal($f['day']);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_daily_report_events')->delete();
    }

    public function test_cod_daily45_does_not_change_order_payment_status(): void
    {
        $f = $this->codDaily45Fixture();
        $r = $this->codDaily45Seal($f['day']);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );

        $this->assertFalse($r['orders_payment_status_changed']);
        $this->assertFalse(
            $r['automatic_payment_posting_allowed']
        );
    }

    public function test_cod_daily45_routes_are_registered(): void
    {
        foreach ([
                     'cod.daily45.index',
                     'cod.daily45.seal',
                     'cod.daily45.show',
                     'cod.daily45.verify',
                     'cod.daily45.events',
                     'cod.daily45.csv',
                 ] as $name) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($name)
            );
        }
    }

    public function test_cod_correction46_no_late_change_cannot_create_appendix(): void
    {
        $f = $this->codCorrection46Fixture(false);
        $this->expectException(DomainException::class);
        $this->codCorrection46Propose($f);
    }

    public function test_cod_correction46_late_register_detected_and_signed(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        $this->assertSame('proposed', $c['status']);
        $this->assertTrue($c['observation']['has_changes']);
        $this->assertSame(1, $c['observation']['late_signals']['late_register_claims_count']);
        $this->assertSame(1, $c['observation']['delta']['register_claims_count']['difference']);
        $this->assertSame(680, $c['observation']['baseline']['posted_bank_minor']);
        $this->assertFalse($c['cod_paid']);
        $this->assertDatabaseCount('checkout_cod_daily_corrections', 1);
        $this->assertDatabaseCount('checkout_cod_daily_correction_events', 1);
    }

    public function test_cod_correction46_incorrect_reason_is_rejected(): void
    {
        $f = $this->codCorrection46Fixture();
        $this->expectException(DomainException::class);
        $this->codCorrection46Propose($f, null, 'late_bank_booking');
    }

    public function test_cod_correction46_sealer_cannot_propose_even_with_permission(): void
    {
        $f = $this->codCorrection46Fixture();
        Gate::define(CheckoutCodDailyCorrectionService::PROPOSE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1200);
        $this->actor(1200);
        $this->expectException(DomainException::class);
        $this->codCorrection46Propose($f);
    }

    public function test_cod_correction46_proposal_uuid_replay_and_observation_deduplication(): void
    {
        $f = $this->codCorrection46Fixture();
        $op = (string) Str::uuid();
        $a = $this->codCorrection46Propose($f, $op);
        $b = $this->codCorrection46Propose($f, $op);
        $this->assertSame($a['correction_key'], $b['correction_key']);
        $this->assertFalse($a['idempotent_replay']);
        $this->assertTrue($b['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_daily_corrections', 1);
        $this->expectException(DomainException::class);
        $this->codCorrection46Propose($f);
    }

    public function test_cod_correction46_independent_approval_and_publication(): void
    {
        $f = $this->codCorrection46Fixture();
        $p = $this->codCorrection46Propose($f);
        $a = $this->codCorrection46Action($p, 'approve');
        $this->assertSame('approved', $a['status']);
        $b = $this->codCorrection46Action($p, 'publish');
        $this->assertSame('published', $b['status']);
        $this->actor(1204);
        $check = app(CheckoutCodDailyCorrectionService::class)
            ->verify($p['correction_key'], true);
        $this->assertTrue($check['integrity']['valid']);
        $this->assertTrue($check['base_report_integrity']['valid']);
        $this->assertTrue($check['source_evidence_still_current']);
        $this->assertSame(3, $check['integrity']['events_checked']);
        $this->assertDatabaseCount('checkout_cod_daily_reports', 1);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_cod_correction46_author_cannot_review_own_proposal(): void
    {
        $f = $this->codCorrection46Fixture();
        $c = $this->codCorrection46Propose($f);
        Gate::define(CheckoutCodDailyCorrectionService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1201);
        $this->actor(1201);
        $this->expectException(DomainException::class);
        app(CheckoutCodDailyCorrectionService::class)->act(
            $c['correction_key'], (string) Str::uuid(), 'approve', 'evidence_reviewed'
        );
    }
}
