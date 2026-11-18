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

trait Cod52CoreTestsPart01
{
    public function test_order_without_intent_reports_no_case(): void
    {
        $result = $this->investigationStatus();
        $this->assertSame('no_intent', $result['status']);
        $this->assertFalse($result['ttn_submission_allowed']);
        Http::assertNothingSent();
    }

    public function test_intent_without_reconciliation_flag_cannot_open_case(): void
    {
        $this->stage();
        $this->assertSame('not_flagged', $this->investigationStatus()['status']);
        $this->expectException(DomainException::class);
        $this->openCase();
    }

    public function test_flagged_intent_can_open_single_case(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->assertSame($intent['id'], $case['intent_id']);
        $this->assertSame('awaiting_assignment', $this->investigationStatus()['status']);
        $this->assertFalse($case['reconciliation_hold_released']);
        $this->assertDatabaseCount('checkout_cod_ttn_investigation_cases', 1);
        Http::assertSentCount(3);
    }

    public function test_same_case_uuid_is_idempotent(): void
    {
        [, $case] = $this->beginCase();
        $this->assertSame($case, $this->openCase($case['case_key']));
        $this->assertDatabaseCount('checkout_cod_ttn_investigation_cases', 1);
    }

    public function test_changed_case_uuid_cannot_replace_intent_case(): void
    {
        $this->beginCase();
        $this->expectException(DomainException::class);
        $this->openCase();
    }

    public function test_unprivileged_user_cannot_open_case(): void
    {
        $this->flagged();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodTtnInvestigationService::class)->open(
            $this->orderKey, (string) Str::uuid(), CarbonImmutable::now('UTC')->addDay()
        );
    }

    public function test_unprivileged_user_cannot_read_case(): void
    {
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodTtnInvestigationStatusService::class)->inspect($this->orderKey);
    }

    public function test_assignment_is_audited_and_reported(): void
    {
        [, $case] = $this->beginCase();
        $event = $this->caseEvent($case);
        $state = $this->investigationStatus();
        $this->assertSame('assign', $event['event_type']);
        $this->assertSame(1111, $state['assignee_id']);
        $this->assertSame('in_progress', $state['status']);
        $this->assertSame(1, $state['event_count']);
    }

    public function test_second_assignment_updates_projection_without_mutating_history(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->caseEvent($case, 'assign', 'reassignment', 2222);
        $state = $this->investigationStatus();
        $this->assertSame(2222, $state['assignee_id']);
        $this->assertSame(2, $state['event_count']);
        $this->assertDatabaseCount('checkout_cod_ttn_investigation_events', 2);
    }

    public function test_cannot_escalate_before_assignment_even_using_service(): void
    {
        [, $case] = $this->beginCase();
        $this->expectException(QueryException::class);
        $this->caseEvent($case, 'escalate', 'carrier_contact_needed');
    }

    public function test_deadline_expiry_marks_case_overdue(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(2));
        $this->assertTrue($this->investigationStatus()['overdue']);
    }

    public function test_reschedule_is_append_only_and_clears_overdue_flag(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->caseEvent($case, 'reschedule', 'carrier_response_pending', null,
            CarbonImmutable::now('UTC')->addDays(3));
        $state = $this->investigationStatus();
        $this->assertFalse($state['overdue']);
        $this->assertSame(2, $state['event_count']);
    }

    public function test_escalation_is_reported_without_ttn_permission(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->caseEvent($case, 'escalate', 'carrier_contact_needed');
        $state = $this->investigationStatus();
        $this->assertSame('escalated', $state['status']);
        $this->assertFalse($state['ttn_submission_allowed']);
        $this->assertFalse($state['automatic_retry_allowed']);
    }

    public function test_event_replay_with_original_key_is_idempotent(): void
    {
        [, $case] = $this->beginCase();
        $key = (string) Str::uuid();
        $this->assertSame($this->caseEvent($case, key: $key),
            $this->caseEvent($case, key: $key));
        $this->assertDatabaseCount('checkout_cod_ttn_investigation_events', 1);
    }

    public function test_same_event_uuid_with_changed_details_is_rejected(): void
    {
        [, $case] = $this->beginCase();
        $key = (string) Str::uuid();
        $this->caseEvent($case, key: $key);
        $this->expectException(DomainException::class);
        $this->caseEvent($case, 'assign', 'reassignment', 2222, key: $key);
    }

    public function test_archive_without_reviewed_scan_is_rejected(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->expectException(QueryException::class);
        $this->caseEvent($case, 'archive', 'inconclusive_reviewed');
    }

    public function test_archive_requires_comparison_when_reason_claims_comparison(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        $this->expectException(QueryException::class);
        $this->caseEvent($case, 'archive', 'comparison_documented');
    }

    public function test_reviewed_scan_can_be_administratively_archived_but_hold_remains(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        $this->caseEvent($case, 'archive', 'inconclusive_reviewed');
        $state = $this->investigationStatus();
        $this->assertSame('administratively_archived', $state['status']);
        $this->assertFalse($state['reconciliation_hold_released']);
        $this->assertFalse($state['automatic_retry_allowed']);
        $this->assertSame('reconciliation_required', $this->safetyStatus()['status']);
    }

    public function test_comparison_documented_can_be_archived_after_comparison(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        $this->compareDocument($scan);
        $this->caseEvent($case, 'archive', 'comparison_documented');
        $state = $this->investigationStatus();
        $this->assertSame('administratively_archived', $state['status']);
        $this->assertSame('snapshot_consistent', $state['latest_document_comparison']);
        $this->assertFalse($state['ttn_submission_allowed']);
        Http::assertNotSent(static fn (ProviderRequest $request): bool =>
            ($request->data()['calledMethod'] ?? null) === 'save');
    }

    public function test_case_opener_cannot_archive_even_with_permission(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        Gate::define(CheckoutCodTtnInvestigationService::ARCHIVE_ABILITY,
            static fn ($user): bool => in_array((int) $user->getAuthIdentifier(), [1012, 1014], true));
        $this->actor(1012);
        $this->expectException(QueryException::class);
        app(CheckoutCodTtnInvestigationService::class)->record(
            $case['case_key'], (string) Str::uuid(), 'archive', 'inconclusive_reviewed'
        );
    }

    public function test_archived_case_rejects_new_events(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        $this->caseEvent($case, 'archive', 'inconclusive_reviewed');
        $this->expectException(DomainException::class);
        $this->caseEvent($case, 'assign', 'reassignment', 2222);
    }

    public function test_database_cannot_rewrite_case(): void
    {
        [, $case] = $this->beginCase();
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_ttn_investigation_cases')->where('id', $case['id'])
            ->update(['opened_by' => 999]);
    }

    public function test_database_cannot_rewrite_event_history(): void
    {
        [, $case] = $this->beginCase();
        $event = $this->caseEvent($case);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_ttn_investigation_events')->where('id', $event['id'])
            ->update(['reason_code' => 'reassignment']);
    }

    public function test_status_redacts_recipient_and_candidate_number(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->scan($intent);
        $json = json_encode($this->investigationStatus(), JSON_THROW_ON_ERROR);
        foreach (['Петренко', '380671234567', self::RECIPIENT_CONTACT,
                     self::RECIPIENT_COUNTERPARTY, self::WAYBILL, 'ttn-gate-test-key'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_snapshot_cannot_be_reviewed_after_evidence_changes(): void
    {
        $intent = $this->flagged();
        $scan = $this->scan($intent);

        $this->actor(1301);

        $snapshot = app(CheckoutCodTtnResolutionService::class)
            ->capture(
                $this->orderKey,
                (string) Str::uuid()
            );

        $this->independentReview($scan);

        $this->actor(1302);

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnResolutionService::class)->review(
            $snapshot['operation_key'],
            (string) Str::uuid(),
            'retain_hold',
            'evidence_not_authoritative'
        );
    }

    public function test_conflicting_candidate_numbers_are_flagged(): void
    {
        $intent = $this->flagged();

        $this->scan($intent);
        $this->scan(
            $intent,
            number: self::OTHER_WAYBILL
        );

        $this->actor(1200);

        $status = app(CheckoutCodTtnResolutionStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'conflicting_evidence',
            $status['evidence_outcome']
        );

        $this->assertFalse($status['ttn_submission_allowed']);
    }

    public function test_uncertain_ttn_outcome_prevents_dispatch_preparation(): void
    {
        $this->flagged();
        $this->actor(1401);

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_independent_review_never_authorizes_live_ttn(): void
    {
        $this->stage();
        $this->actor(1401);

        $plan = app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->actor(1402);

        $review = app(CheckoutCodTtnDispatchPlanService::class)->review(
            $plan['operation_key'],
            (string) Str::uuid(),
            'reviewed_for_future_submission',
            'local_plan_checked'
        );

        $this->assertFalse($review['carrier_permission_proven']);

        $this->actor(1200);

        $status = app(CheckoutCodTtnDispatchStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'awaiting_trusted_carrier_authorization',
            $status['status']
        );

        $this->assertFalse($status['ttn_submission_allowed']);
        $this->assertFalse($status['ready_for_dispatch']);
    }

    public function test_new_safety_event_invalidates_pending_dispatch_review(): void
    {
        $intent = $this->stage();
        $this->actor(1401);

        $plan = app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->event(
            $intent,
            'reconciliation_required',
            'reported_timeout'
        );

        $this->actor(1402);

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnDispatchPlanService::class)->review(
            $plan['operation_key'],
            (string) Str::uuid(),
            'reviewed_for_future_submission',
            'local_plan_checked'
        );
    }

    public function test_rejected_dispatch_plan_stays_blocked(): void
    {
        $this->stage();
        $this->actor(1401);

        $plan = app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->actor(1402);

        app(CheckoutCodTtnDispatchPlanService::class)->review(
            $plan['operation_key'],
            (string) Str::uuid(),
            'rejected',
            'manual_risk_rejection'
        );

        $this->actor(1200);

        $status = app(CheckoutCodTtnDispatchStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame('rejected', $status['status']);
        $this->assertFalse($status['automatic_retry_allowed']);
    }

    public function test_accepted_pack_enables_internal_slip_not_carrier_label(): void
    {
        $this->warehouseReviewedDispatchPlan();
        $this->warehouseAcceptedPackCheck();

        $this->actor(1200);

        $status = app(CheckoutCodWarehousePackStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'internal_pack_verified',
            $status['status']
        );

        $this->assertTrue($status['internal_pick_slip_available']);
        $this->assertFalse($status['carrier_label_available']);
        $this->assertFalse($status['ttn_submission_allowed']);
    }

    public function test_rejected_pack_never_allows_internal_slip(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $check = $this->warehousePackCheck();

        $this->actor(1502);

        app(CheckoutCodWarehousePackService::class)->review(
            $check['operation_key'],
            (string) Str::uuid(),
            'rejected',
            'packaging_damaged'
        );

        $this->actor(1503);

        $this->expectException(DomainException::class);

        app(CheckoutCodInternalPickSlipService::class)->generate(
            $check['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_cod_operations41_bank_posting_without_register_is_in_queue(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->actor(1200);

        $service = app(CheckoutCodOperationsService::class);

        $state = $service->inspect($posting['operation_key']);

        $this->assertSame(
            'awaiting_register',
            $state['status']
        );

        $this->assertContains(
            'register_missing',
            $state['flags']
        );

        $this->assertFalse($state['cod_paid']);

        $this->assertFalse(
            $state['automatic_payment_posting_allowed']
        );

        $queue = $service->queue(
            null,
            10,
            'awaiting_register'
        );

        $this->assertCount(1, $queue['items']);

        $this->assertSame(
            $posting['operation_key'],
            $queue['items'][0]['posting_key']
        );
    }

    public function test_cod_operations41_register_matches_amounts_but_not_external_origin(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();

        $this->novapayRegister39Capture($posting, $rows);

        $this->actor(1200);

        $state = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key']);

        $this->assertSame(2000, $state['gross_minor']);
        $this->assertSame(1320, $state['fee_minor']);
        $this->assertSame(680, $state['bank_minor']);

        $this->assertTrue(
            $state['facts']['register_balanced']
        );

        $this->assertTrue(
            $state['facts']['bank_balanced']
        );

        $this->assertFalse(
            $state['novapay_origin_authenticated']
        );

        $this->assertFalse(
            $state['ttn_matches_register_verified']
        );

        $this->assertFalse($state['cod_paid']);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_cod_operations41_batch_is_reconciled_by_each_order(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source(true);

        $this->novapayRegister39Capture($posting, $rows);

        $this->actor(1200);

        $state = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key']);

        $this->assertCount(2, $state['lines']);

        $this->assertSame(4000, $state['gross_minor']);
        $this->assertSame(2640, $state['fee_minor']);
        $this->assertSame(1360, $state['bank_minor']);

        $this->assertTrue(
            $state['facts']['register_balanced']
        );

        foreach ($state['lines'] as $line) {
            $this->assertTrue(
                $line['register_amounts_match']
            );

            $this->assertFalse(
                $line['ttn_matches_register_verified']
            );
        }
    }

    public function test_cod_operations41_later_refetch_anomaly_escalates_current_case(): void
    {
        [$source, $posting, $rows] =
            $this->novapayRegister39Source();

        $this->novapayRegister39Capture($posting, $rows);

        $this->actor(1200);

        $service = app(CheckoutCodOperationsService::class);

        $before = $service->inspect($posting['operation_key']);

        $this->refetchRisk37Negative('single', $source);

// Возвращаем пользователя с правом просмотра COD.
        $this->actor(1200);

        $after = $service->inspect($posting['operation_key']);

        $this->assertSame(
            'investigation_required',
            $after['status']
        );

        $this->assertContains(
            'refetch_anomaly',
            $after['flags']
        );

        $this->assertNotSame(
            $before['snapshot_hmac'],
            $after['snapshot_hmac']
        );
    }

    public function test_cod_operations41_review_is_append_only_and_does_not_mark_paid(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();

        $this->novapayRegister39Capture($posting, $rows);

        $this->operations41Reviewer();

        $service = app(CheckoutCodOperationsService::class);

        $snapshot = $service
            ->inspect($posting['operation_key'])['snapshot_hmac'];

        $review = app(
            CheckoutCodOperationsReviewService::class
        )->record(
            $posting['operation_key'],
            (string) Str::uuid(),
            $snapshot,
            'request_evidence',
            'source_proof_needed'
        );

        $this->assertFalse($review['cod_paid']);

        $this->assertDatabaseCount(
            'checkout_cod_operations_reviews',
            1
        );

        $current = $service->inspect(
            $posting['operation_key']
        );

        $this->assertSame(
            'request_evidence',
            $current['latest_review']['action']
        );

        $this->assertTrue(
            $current['latest_review']['snapshot_current']
        );

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_cod_operations41_same_review_request_is_idempotent(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->operations41Reviewer();

        $snapshot = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key'])['snapshot_hmac'];

        $key = (string) Str::uuid();

        $service = app(
            CheckoutCodOperationsReviewService::class
        );

        $first = $service->record(
            $posting['operation_key'],
            $key,
            $snapshot,
            'request_evidence',
            'source_proof_needed'
        );

        $again = $service->record(
            $posting['operation_key'],
            $key,
            $snapshot,
            'request_evidence',
            'source_proof_needed'
        );

        $this->assertSame(
            $first['review_id'],
            $again['review_id']
        );

        $this->assertTrue($again['idempotent_replay']);

        $this->assertDatabaseCount(
            'checkout_cod_operations_reviews',
            1
        );
    }

    public function test_cod_operations41_changed_replay_is_rejected(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->operations41Reviewer();

        $snapshot = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key'])['snapshot_hmac'];

        $key = (string) Str::uuid();

        $service = app(
            CheckoutCodOperationsReviewService::class
        );

        $service->record(
            $posting['operation_key'],
            $key,
            $snapshot,
            'request_evidence',
            'source_proof_needed'
        );

        $this->expectException(DomainException::class);

        $service->record(
            $posting['operation_key'],
            $key,
            $snapshot,
            'request_evidence',
            'shipment_proof_needed'
        );
    }

    public function test_cod_operations41_stale_snapshot_cannot_be_reviewed(): void
    {
        [$source, $posting] = $this->novapayRegister39Source();

        $this->operations41Reviewer();

        $snapshot = app(CheckoutCodOperationsService::class)
            ->inspect($posting['operation_key'])['snapshot_hmac'];

        $this->refetchRisk37Negative('single', $source);


        $this->operations41Reviewer();

        $this->expectException(DomainException::class);

        app(CheckoutCodOperationsReviewService::class)->record(
            $posting['operation_key'],
            (string) Str::uuid(),
            $snapshot,
            'escalate_investigation',
            'manual_recheck'
        );
    }
}
