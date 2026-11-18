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

trait Cod52CoreTestsPart02
{
    public function test_cod_operations41_unverified_comparison_cannot_be_acknowledged_as_clean(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->operations41Reviewer();

        $snapshot = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key'])['snapshot_hmac'];

        $this->expectException(DomainException::class);

        app(CheckoutCodOperationsReviewService::class)->record(
            $posting['operation_key'],
            (string) Str::uuid(),
            $snapshot,
            'acknowledge_comparison',
            'allocation_checked'
        );
    }

    public function test_cod_operations41_poster_cannot_review_even_with_permission(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        Gate::define(
            CheckoutCodOperationsReviewService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3801
        );

        Gate::define(
            CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3801
        );

        $this->actor(3801);

        $snapshot = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key'])['snapshot_hmac'];

        $this->expectException(DomainException::class);

        app(CheckoutCodOperationsReviewService::class)->record(
            $posting['operation_key'],
            (string) Str::uuid(),
            $snapshot,
            'request_evidence',
            'manual_recheck'
        );
    }

    public function test_cod_operations41_register_capturer_cannot_review(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();

        $this->novapayRegister39Capture($posting, $rows);

        Gate::define(
            CheckoutCodOperationsReviewService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3901
        );

        Gate::define(
            CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3901
        );

        $this->actor(3901);

        $snapshot = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key'])['snapshot_hmac'];

        $this->expectException(DomainException::class);

        app(CheckoutCodOperationsReviewService::class)->record(
            $posting['operation_key'],
            (string) Str::uuid(),
            $snapshot,
            'request_evidence',
            'manual_recheck'
        );
    }

    public function test_cod_operations41_review_history_cannot_be_updated(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->operations41Reviewer();

        $snapshot = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key'])['snapshot_hmac'];

        app(CheckoutCodOperationsReviewService::class)->record(
            $posting['operation_key'],
            (string) Str::uuid(),
            $snapshot,
            'request_evidence',
            'manual_recheck'
        );

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_operations_reviews')->update([
            'reason_code' => 'source_proof_needed',
        ]);
    }

    public function test_cod_operations41_reader_requires_permission(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->actor(999);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key']);
    }

    public function test_cod_operations41_reviewer_requires_separate_permission(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->actor(1200);

        $snapshot = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key'])['snapshot_hmac'];

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutCodOperationsReviewService::class)->record(
            $posting['operation_key'],
            (string) Str::uuid(),
            $snapshot,
            'request_evidence',
            'manual_recheck'
        );
    }

    public function test_cod_case42_open_creates_single_audited_case(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);

        $this->assertSame('open', $case['case_status']);
        $this->assertSame(1, $case['revision']);
        $this->assertNull($case['assigned_to']);
        $this->assertSame(
            'awaiting_register',
            $case['current_financial_status']
        );
        $this->assertFalse($case['cod_paid']);

        $this->assertDatabaseCount(
            'checkout_cod_operations_cases',
            1
        );
        $this->assertDatabaseCount(
            'checkout_cod_operations_case_events',
            1
        );
    }

    public function test_cod_case42_open_replay_is_idempotent(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->codCase42Gate();
        $this->actor(1200);

        $service = app(CheckoutCodOperationsCaseService::class);
        $key = (string) Str::uuid();

        $first = $service->open($posting['operation_key'], $key);
        $again = $service->open($posting['operation_key'], $key);

        $this->assertSame(
            $first['case_id'],
            $again['case_id']
        );
        $this->assertSame(
            $first['event_id'],
            $again['event_id']
        );
        $this->assertTrue($again['idempotent_replay']);

        $this->assertDatabaseCount(
            'checkout_cod_operations_case_events',
            1
        );
    }

    public function test_cod_case42_second_case_for_same_posting_is_rejected(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->codCase42Open($posting);

        $this->expectException(DomainException::class);

        app(CheckoutCodOperationsCaseService::class)->open(
            $posting['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_cod_case42_claim_wait_resume_and_release(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);

        $case = $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        $this->assertSame('in_progress', $case['case_status']);
        $this->assertSame(1200, $case['assigned_to']);

        $case = $this->codCase42Action(
            $case,
            'request_evidence',
            'source_proof_needed'
        );

        $this->assertSame(
            'waiting_external',
            $case['case_status']
        );

        $case = $this->codCase42Action(
            $case,
            'resume',
            'evidence_received'
        );

        $this->assertSame('in_progress', $case['case_status']);

        $case = $this->codCase42Action(
            $case,
            'release',
            'reassign_required'
        );

        $this->assertSame('open', $case['case_status']);
        $this->assertNull($case['assigned_to']);
        $this->assertSame(5, $case['revision']);

        $this->assertDatabaseCount(
            'checkout_cod_operations_case_events',
            5
        );
    }

    public function test_cod_case42_other_agent_cannot_release_someones_case(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);
        $case = $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        $this->actor(1201);

        $this->expectException(DomainException::class);

        $this->codCase42Action(
            $case,
            'release',
            'reassign_required'
        );
    }

    public function test_cod_case42_action_replay_does_not_repeat_event(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);
        $key = (string) Str::uuid();

        $first = $this->codCase42Action(
            $case,
            'claim',
            'start_work',
            $key
        );

        $again = $this->codCase42Action(
            $case,
            'claim',
            'start_work',
            $key
        );

        $this->assertSame(
            $first['event_id'],
            $again['event_id']
        );

        $this->assertTrue($again['idempotent_replay']);
        $this->assertSame(2, $again['revision']);

        $this->assertDatabaseCount(
            'checkout_cod_operations_case_events',
            2
        );
    }

    public function test_cod_case42_changed_replay_is_rejected(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);
        $key = (string) Str::uuid();

        $this->codCase42Action(
            $case,
            'claim',
            'start_work',
            $key
        );

        $this->expectException(DomainException::class);

        $this->codCase42Action(
            $case,
            'release',
            'reassign_required',
            $key
        );
    }

    public function test_cod_case42_old_revision_cannot_override_new_assignment(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);

        $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        $this->expectException(DomainException::class);

        $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );
    }

    public function test_cod_case42_later_refetch_is_visible_as_stale(): void
    {
        [$source, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);

        $this->refetchRisk37Negative('single', $source);

        // Restore permissions after the negative refetch fixture.
        $this->codCase42Gate();
        $this->actor(1200);

        $changed = app(CheckoutCodOperationsCaseService::class)
            ->inspect($case['case_key']);

        $this->assertTrue($changed['evidence_changed']);
        $this->assertSame(
            'critical',
            $changed['current_priority']
        );

        $this->expectException(DomainException::class);

        $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );
    }

    public function test_cod_case42_refresh_reopens_and_updates_snapshot(): void
    {
        [$source, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);

        $case = $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        $this->refetchRisk37Negative('single', $source);

        $this->codCase42Gate();
        $this->actor(1200);

        $refreshed = $this->codCase42Action(
            $case,
            'refresh',
            'evidence_changed'
        );

        $this->assertSame('open', $refreshed['case_status']);
        $this->assertSame('critical', $refreshed['priority']);
        $this->assertNull($refreshed['assigned_to']);
        $this->assertFalse($refreshed['evidence_changed']);
        $this->assertSame(3, $refreshed['revision']);
        $this->assertFalse($refreshed['cod_paid']);
    }

    public function test_cod_case42_refresh_without_change_is_rejected(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);

        $this->expectException(DomainException::class);

        $this->codCase42Action(
            $case,
            'refresh',
            'evidence_changed'
        );
    }

    public function test_cod_case42_unverified_case_cannot_be_closed(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);

        $case = $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        $this->expectException(DomainException::class);

        $this->codCase42Action(
            $case,
            'close',
            'comparison_only'
        );
    }

    public function test_cod_case42_queue_and_history_are_paginated(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);

        $case = $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        $service = app(CheckoutCodOperationsCaseService::class);

        $mine = $service->queue(null, 20, 'mine');

        $this->assertCount(1, $mine['items']);
        $this->assertSame(
            $case['case_key'],
            $mine['items'][0]['case_key']
        );

        $history = $service->history(
            $case['case_key'],
            null,
            1
        );

        $this->assertCount(1, $history['items']);
        $this->assertSame(
            'claim',
            $history['items'][0]['action']
        );
        $this->assertNotNull($history['next_before_id']);

        $older = $service->history(
            $case['case_key'],
            $history['next_before_id'],
            1
        );

        $this->assertSame(
            'open',
            $older['items'][0]['action']
        );
    }

    public function test_cod_case42_immutable_event_cannot_be_updated(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->codCase42Open($posting);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_operations_case_events')->update([
            'reason_code' => 'tampered',
        ]);
    }

    public function test_cod_case42_direct_case_revision_bypass_is_rejected(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $case = $this->codCase42Open($posting);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_operations_cases')
            ->where('case_key', $case['case_key'])
            ->update([
                'case_status' => 'in_progress',
                'assigned_to' => 1200,
            ]);
    }

    public function test_cod_case42_workflow_requires_permission(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->codCase42Gate();
        $this->actor(999);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutCodOperationsCaseService::class)->open(
            $posting['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_cod_case42_original_bank_poster_cannot_manage_case(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        Gate::define(
            CheckoutCodOperationsCaseService::MANAGE_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3801
        );

        Gate::define(
            CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3801
        );

        $this->actor(3801);

        $this->expectException(DomainException::class);

        app(CheckoutCodOperationsCaseService::class)->open(
            $posting['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_cod_alert43_new_case_does_not_alert_early(): void
    {
        $this->codAlert43Open();

        $result = app(
            CheckoutCodCaseAlertService::class
        )->scan();

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(0, $result['created']);

        $this->assertDatabaseCount(
            'checkout_cod_case_alert_outbox',
            0
        );
    }

    public function test_cod_alert43_open_unassigned_case_generates_one_alert(): void
    {
        $case = $this->codAlert43Open();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse($case['sla_due_at'])
                ->subHours(23)
        );

        $service = app(
            CheckoutCodCaseAlertService::class
        );

        $first = $service->scan();
        $again = $service->scan();

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $again['created']);

        $this->assertDatabaseHas(
            'checkout_cod_case_alert_outbox',
            [
                'case_id' => $case['case_id'],
                'case_revision' => 1,
                'alert_kind' => 'unassigned_30m',
                'recipient_id' => null,
            ]
        );
    }

    public function test_cod_alert43_due_soon_alert_is_separate_and_deduplicated(): void
    {
        $case = $this->codAlert43Open();

        $this->codAlert43Time($case, -30);

        $service = app(
            CheckoutCodCaseAlertService::class
        );

        $r = $service->scan();

        $this->assertSame(2, $r['created']);

        $this->assertDatabaseHas(
            'checkout_cod_case_alert_outbox',
            [
                'alert_kind' => 'due_soon',
                'case_id' => $case['case_id'],
            ]
        );

        $service->scan();

        $this->assertDatabaseCount(
            'checkout_cod_case_alert_outbox',
            2
        );
    }

    public function test_cod_alert43_overdue_escalates_to_team_in_stages(): void
    {
        $case = $this->codAlert43Open();

        $this->codAlert43Time(
            $case,
            60 * 25
        );

        $r = app(
            CheckoutCodCaseAlertService::class
        )->scan();

        $this->assertSame(4, $r['created']);

        foreach ([
                     'unassigned_30m',
                     'overdue',
                     'overdue_4h',
                     'overdue_24h',
                 ] as $kind) {
            $this->assertDatabaseHas(
                'checkout_cod_case_alert_outbox',
                [
                    'case_id' => $case['case_id'],
                    'alert_kind' => $kind,
                ]
            );
        }
    }

    public function test_cod_alert43_enqueue_is_throttled_and_retryable(): void
    {
        $case = $this->codAlert43Open();

        $this->codAlert43Time($case, 30);

        app(CheckoutCodCaseAlertService::class)->scan();

        Queue::fake();

        $service = app(
            CheckoutCodCaseAlertService::class
        );

        $first = $service->enqueue(10);
        $second = $service->enqueue(10);

        $this->assertSame(2, $first['enqueued']);
        $this->assertSame(0, $second['enqueued']);

        Queue::assertPushed(
            DeliverCheckoutCodCaseAlert::class,
            2
        );

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addMinutes(6)
        );

        $retry = $service->enqueue(10);

        $this->assertSame(2, $retry['enqueued']);
    }

    public function test_cod_alert43_job_delivers_to_team_inbox_and_is_idempotent(): void
    {
        $case = $this->codAlert43Open();

        $this->codAlert43Time($case, 30);

        app(CheckoutCodCaseAlertService::class)->scan();

        $id = (int) DB::table(
            'checkout_cod_case_alert_outbox'
        )
            ->where('alert_kind', 'overdue')
            ->value('id');

        $job = new DeliverCheckoutCodCaseAlert($id);

        $job->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $job->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $this->assertDatabaseCount(
            'checkout_cod_case_alert_inbox',
            1
        );

        $this->assertNotNull(
            DB::table('checkout_cod_case_alert_outbox')
                ->where('id', $id)
                ->value('delivered_at')
        );

        $this->actor(1201);

        $inbox = app(
            CheckoutCodCaseAlertInboxService::class
        )->listing('team');

        $this->assertCount(1, $inbox['items']);

        $this->assertSame(
            'overdue',
            $inbox['items'][0]['kind']
        );

        $this->assertFalse($inbox['cod_paid']);
    }

    public function test_cod_alert43_old_alert_is_suppressed_after_case_revision_change(): void
    {
        $case = $this->codAlert43Open();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse($case['sla_due_at'])
                ->subHours(23)
        );

        app(CheckoutCodCaseAlertService::class)->scan();

        $id = (int) DB::table(
            'checkout_cod_case_alert_outbox'
        )->value('id');

        $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        (new DeliverCheckoutCodCaseAlert($id))->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $this->assertDatabaseCount(
            'checkout_cod_case_alert_inbox',
            0
        );

        $this->assertNotNull(
            DB::table('checkout_cod_case_alert_outbox')
                ->where('id', $id)
                ->value('suppressed_at')
        );
    }

    public function test_cod_alert43_job_suppresses_expired_due_soon_event(): void
    {
        $case = $this->codAlert43Open();

        $this->codAlert43Time($case, -30);

        app(CheckoutCodCaseAlertService::class)->scan();

        $id = (int) DB::table(
            'checkout_cod_case_alert_outbox'
        )
            ->where('alert_kind', 'due_soon')
            ->value('id');

        $this->codAlert43Time($case, 20);

        (new DeliverCheckoutCodCaseAlert($id))->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $this->assertNotNull(
            DB::table('checkout_cod_case_alert_outbox')
                ->where('id', $id)
                ->value('suppressed_at')
        );
    }

    public function test_cod_alert43_assigned_overdue_goes_to_owner_inbox(): void
    {
        $case = $this->codAlert43Open();

        $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        $this->codAlert43Time($case, 30);

        app(CheckoutCodCaseAlertService::class)->scan();

        $id = (int) DB::table(
            'checkout_cod_case_alert_outbox'
        )
            ->where('alert_kind', 'overdue')
            ->value('id');

        (new DeliverCheckoutCodCaseAlert($id))->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $this->actor(1200);

        $list = app(
            CheckoutCodCaseAlertInboxService::class
        )->listing('mine');

        $this->assertCount(1, $list['items']);

        $this->assertSame(
            1200,
            $list['items'][0]['recipient_id']
        );
    }

    public function test_cod_alert43_acknowledgement_is_idempotent_and_audited(): void
    {
        $case = $this->codAlert43Open();

        $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        $this->codAlert43Time($case, 30);

        app(CheckoutCodCaseAlertService::class)->scan();

        $id = (int) DB::table(
            'checkout_cod_case_alert_outbox'
        )
            ->where('alert_kind', 'overdue')
            ->value('id');

        (new DeliverCheckoutCodCaseAlert($id))->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $notificationId = (int) DB::table(
            'checkout_cod_case_alert_inbox'
        )->value('id');

        $inbox = app(
            CheckoutCodCaseAlertInboxService::class
        );

        $a = $inbox->acknowledge($notificationId);
        $b = $inbox->acknowledge($notificationId);

        $this->assertSame(1200, $a['read_by']);
        $this->assertFalse($a['already_acknowledged']);
        $this->assertTrue($b['already_acknowledged']);
        $this->assertFalse($b['payment_status_changed']);
    }

    public function test_cod_alert43_team_ack_requires_triage_permission(): void
    {
        $case = $this->codAlert43Open();

        $this->codAlert43Time($case, 30);

        app(CheckoutCodCaseAlertService::class)->scan();

        $id = (int) DB::table(
            'checkout_cod_case_alert_outbox'
        )
            ->where('alert_kind', 'overdue')
            ->value('id');

        (new DeliverCheckoutCodCaseAlert($id))->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $notificationId = (int) DB::table(
            'checkout_cod_case_alert_inbox'
        )->value('id');

        $this->actor(1200);

        $this->expectException(
            AuthorizationException::class
        );

        app(
            CheckoutCodCaseAlertInboxService::class
        )->acknowledge($notificationId);
    }

    public function test_cod_alert43_team_triager_can_acknowledge_team_alert(): void
    {
        $case = $this->codAlert43Open();

        $this->codAlert43Time($case, 30);

        app(CheckoutCodCaseAlertService::class)->scan();

        $id = (int) DB::table(
            'checkout_cod_case_alert_outbox'
        )
            ->where('alert_kind', 'overdue')
            ->value('id');

        (new DeliverCheckoutCodCaseAlert($id))->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $notificationId = (int) DB::table(
            'checkout_cod_case_alert_inbox'
        )->value('id');

        $this->actor(1201);

        $r = app(
            CheckoutCodCaseAlertInboxService::class
        )->acknowledge($notificationId);

        $this->assertSame(1201, $r['read_by']);
    }

    public function test_cod_alert43_another_agent_cannot_read_mine_or_ack(): void
    {
        $case = $this->codAlert43Open();

        $this->codCase42Action(
            $case,
            'claim',
            'start_work'
        );

        $this->codAlert43Time($case, 30);

        app(CheckoutCodCaseAlertService::class)->scan();

        $id = (int) DB::table(
            'checkout_cod_case_alert_outbox'
        )
            ->where('alert_kind', 'overdue')
            ->value('id');

        (new DeliverCheckoutCodCaseAlert($id))->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $notificationId = (int) DB::table(
            'checkout_cod_case_alert_inbox'
        )->value('id');

        $this->actor(1201);

        $this->assertSame(
            [],
            app(CheckoutCodCaseAlertInboxService::class)
                ->listing('mine')['items']
        );

        $this->expectException(
            AuthorizationException::class
        );

        app(
            CheckoutCodCaseAlertInboxService::class
        )->acknowledge($notificationId);
    }

    public function test_cod_alert43_inbox_event_cannot_be_rewritten_after_receipt(): void
    {
        $case = $this->codAlert43Open();

        $this->codAlert43Time($case, 30);

        app(CheckoutCodCaseAlertService::class)->scan();

        $id = (int) DB::table(
            'checkout_cod_case_alert_outbox'
        )
            ->where('alert_kind', 'overdue')
            ->value('id');

        (new DeliverCheckoutCodCaseAlert($id))->handle(
            app(CheckoutCodCaseAlertPolicy::class)
        );

        $this->actor(1201);

        $notificationId = (int) DB::table(
            'checkout_cod_case_alert_inbox'
        )->value('id');

        app(
            CheckoutCodCaseAlertInboxService::class
        )->acknowledge($notificationId);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_case_alert_inbox')
            ->where('id', $notificationId)
            ->update([
                'read_by' => 1200,
            ]);
    }

    public function test_cod_alert43_artisan_command_scans_and_queues_jobs(): void
    {
        $case = $this->codAlert43Open();

        $this->codAlert43Time($case, 30);

        Queue::fake();

        $exit = Artisan::call(
            'cod:case-alerts',
            [
                '--limit' => 50,
                '--enqueue-limit' => 50,
            ]
        );

        $this->assertSame(0, $exit);

        Queue::assertPushed(
            DeliverCheckoutCodCaseAlert::class,
            2
        );
    }

    public function test_cod_alert43_invalid_batch_limits_fail_closed(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        app(
            CheckoutCodCaseAlertService::class
        )->scan(0);
    }

    public function test_cod_alert43_inbox_requires_cod_view_permission(): void
    {
        $this->codAlert43Gate();
        $this->actor(999);

        $this->expectException(
            AuthorizationException::class
        );

        app(
            CheckoutCodCaseAlertInboxService::class
        )->listing('mine');
    }
}
