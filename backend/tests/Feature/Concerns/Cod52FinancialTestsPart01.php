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

trait Cod52FinancialTestsPart01
{
    public function test_financial_exception_source_must_exist(): void
    {
        $this->actor(2101);

        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialExceptionService::class)->report(
            'single',
            (string) Str::uuid(),
            (string) Str::uuid(),
            'incorrect_amount'
        );
    }

    public function test_financial_exception_report_requires_permission(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialExceptionService::class)->report(
            'batch',
            (string) Str::uuid(),
            (string) Str::uuid(),
            'incorrect_amount'
        );
    }

    public function test_financial_exception_creates_immediate_hold_on_bank_claim(): void
    {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case);

        $incident = $this->financialExceptionReport(
            'single',
            $bank
        );

        $this->assertSame(
            'pending_review_hold',
            $incident['status_at_creation']
        );

        $this->assertFalse($incident['refund_executed']);

        $this->actor(1200);

        $status = app(CheckoutCodFinancialExceptionStatusService::class)
            ->inspect('single', $bank['operation_key']);

        $this->assertTrue($status['financial_hold']);
        $this->assertSame(
            'pending_review_hold',
            $status['status']
        );

        $this->assertSame(
            'financial_exception_hold',
            app(CheckoutCodSettlementBankStatusService::class)
                ->inspect($this->orderKey)['status']
        );
    }

    public function test_financial_exception_exact_report_replay_is_idempotent(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $key = (string) Str::uuid();

        $first = $this->financialExceptionReport(
            'single',
            $bank,
            'incorrect_amount',
            $key
        );

        $this->assertSame(
            $first,
            $this->financialExceptionReport(
                'single',
                $bank,
                'incorrect_amount',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_financial_exceptions',
            1
        );
    }

    public function test_financial_exception_reused_report_uuid_with_new_reason_is_rejected(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $key = (string) Str::uuid();

        $this->financialExceptionReport(
            'single',
            $bank,
            'incorrect_amount',
            $key
        );

        $this->expectException(DomainException::class);

        $this->financialExceptionReport(
            'single',
            $bank,
            'wrong_source_reference',
            $key
        );
    }

    public function test_financial_exception_second_open_hold_is_rejected(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $this->financialExceptionReport('single', $bank);

        $this->expectException(DomainException::class);

        $this->financialExceptionReport('single', $bank);
    }

    public function test_financial_exception_unprivileged_reviewer_is_rejected(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        $this->actor(999);
        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialExceptionService::class)->review(
            $incident['exception_key'],
            (string) Str::uuid(),
            'hold_for_investigation',
            'await_external_evidence'
        );
    }

    public function test_financial_exception_reporter_cannot_self_review(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        Gate::define(
            CheckoutCodFinancialExceptionService::REVIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2101, 2102],
                true
            )
        );

        $this->actor(2101);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialExceptionService::class)->review(
            $incident['exception_key'],
            (string) Str::uuid(),
            'hold_for_investigation',
            'await_external_evidence'
        );
    }

    public function test_financial_exception_bank_claim_author_cannot_review(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        Gate::define(
            CheckoutCodFinancialExceptionService::REVIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [1901, 2102],
                true
            )
        );

        $this->actor(1901);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialExceptionService::class)->review(
            $incident['exception_key'],
            (string) Str::uuid(),
            'hold_for_investigation',
            'await_external_evidence'
        );
    }

    public function test_financial_exception_independent_review_keeps_hold(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);
        $review = $this->financialExceptionReview($incident);

        $this->assertSame(
            'hold_for_investigation',
            $review['decision']
        );

        $this->assertFalse($review['refund_executed']);

        $this->actor(1200);

        $this->assertSame(
            'investigation_hold',
            app(CheckoutCodFinancialExceptionStatusService::class)
                ->inspect('single', $bank['operation_key'])['status']
        );
    }

    public function test_financial_exception_dismissal_releases_hold_and_allows_new_report(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        $this->financialExceptionReview(
            $incident,
            'dismiss_report'
        );

        $this->actor(1200);

        $status = app(CheckoutCodFinancialExceptionStatusService::class)
            ->inspect('single', $bank['operation_key']);

        $this->assertFalse($status['financial_hold']);
        $this->assertSame('dismissed', $status['status']);

        $new = $this->financialExceptionReport(
            'single',
            $bank,
            'bank_reversal_reported'
        );

        $this->assertNotSame(
            $incident['exception_key'],
            $new['exception_key']
        );

        $this->assertDatabaseCount(
            'checkout_cod_financial_exceptions',
            2
        );
    }

    public function test_financial_exception_review_exact_replay_is_idempotent(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);
        $key = (string) Str::uuid();

        $first = $this->financialExceptionReview(
            $incident,
            'dismiss_report',
            $key
        );

        $this->assertSame(
            $first,
            $this->financialExceptionReview(
                $incident,
                'dismiss_report',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_financial_exception_reviews',
            1
        );
    }

    public function test_financial_exception_conflicting_second_review_is_rejected(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        $this->financialExceptionReview($incident);

        $this->expectException(DomainException::class);

        $this->financialExceptionReview(
            $incident,
            'dismiss_report'
        );
    }

    public function test_financial_exception_single_match_blocked_by_bank_hold(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();

        $bank = $this->bankSettlementClaim($case);

        $this->financialExceptionReport('single', $bank);

        $this->expectException(QueryException::class);

        $this->bankSettlementMatch($claim, $bank);
    }

    public function test_financial_exception_single_match_allowed_after_dismissal(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();

        $bank = $this->bankSettlementClaim($case);

        $incident = $this->financialExceptionReport('single', $bank);

        $this->financialExceptionReview(
            $incident,
            'dismiss_report'
        );

        $match = $this->bankSettlementMatch($claim, $bank);

        $this->assertSame(
            'amounts_match_unverified',
            $match['match_result']
        );
    }

    public function test_financial_exception_novapay_hold_blocks_source_review(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);

        $this->financialExceptionReport(
            'novapay',
            $claim,
            'customer_return_reported'
        );

        $this->actor(1803);
        $this->expectException(QueryException::class);

        app(CheckoutCodSettlementReviewService::class)->review(
            $claim['operation_key'],
            (string) Str::uuid(),
            'retain_unverified',
            'external_confirmation_required'
        );
    }

    public function test_financial_exception_novapay_hold_blocks_batch_allocation(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(680);

        $this->financialExceptionReport(
            'novapay',
            $claim,
            'order_cancellation_reported'
        );

        $this->expectException(QueryException::class);

        $this->bankBatchAllocate($batch, $claim);
    }

    public function test_financial_exception_batch_hold_blocks_new_allocation(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(680);

        $this->financialExceptionReport(
            'batch',
            $batch,
            'bank_reversal_reported'
        );

        $this->expectException(QueryException::class);

        $this->bankBatchAllocate($batch, $claim);
    }

    public function test_financial_exception_batch_reviewed_before_hold_shows_dispute(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate(680);
        $this->bankBatchAllocate($batch, $claim);

        $this->actor(2003);

        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'],
            (string) Str::uuid()
        );

        $this->financialExceptionReport(
            'batch',
            $batch,
            'bank_reversal_reported'
        );

        $this->actor(1200);

        $status = app(CheckoutCodBankBatchStatusService::class)
            ->inspect($batch['operation_key']);

        $this->assertSame(
            'financial_exception_hold',
            $status['status']
        );

        $this->assertTrue(
            $status['financial_exception_hold']
        );

        $this->assertFalse(
            $status['funds_received_verified']
        );

        $this->assertSame(
            'financial_exception_hold',
            app(CheckoutCodSettlementBankStatusService::class)
                ->inspect($this->orderKey)['status']
        );
    }

    public function test_financial_exception_batch_cannot_be_reviewed_during_hold(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate();
        $this->bankBatchAllocate($batch, $claim);

        $this->financialExceptionReport(
            'batch',
            $batch,
            'wrong_source_reference'
        );

        $this->actor(2003);
        $this->expectException(QueryException::class);

        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_financial_exception_history_cannot_be_modified(): void
    {
        $batch = $this->bankBatchCreate();

        $incident = $this->financialExceptionReport(
            'batch',
            $batch
        );

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_financial_exceptions')
            ->where('id', $incident['id'])
            ->update([
                'reason_code' => 'wrong_source_reference',
            ]);
    }

    public function test_financial_exception_queue_redacts_references(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase(),
            680,
            'EXCEPTION_PRIVATE_REF_1'
        );

        $this->financialExceptionReport('single', $bank);

        $this->actor(1200);

        $queue = app(CheckoutCodFinancialExceptionQueueService::class)
            ->recent();

        $this->assertCount(1, $queue['items']);

        $json = json_encode($queue, JSON_THROW_ON_ERROR);

        foreach ([
                     'EXCEPTION_PRIVATE_REF_1',
                     'Петренко',
                     '380671234567',
                 ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $json
            );
        }

        $this->assertFalse(
            $queue['automatic_settlement_allowed']
        );
    }

    public function test_financial_exception_status_and_queue_require_read_permission(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialExceptionQueueService::class)
            ->recent();
    }

    public function test_financial_exception_does_not_touch_payment_status_or_refund(): void
    {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case);

        $incident = $this->financialExceptionReport(
            'single',
            $bank,
            'customer_return_reported'
        );

        $review = $this->financialExceptionReview($incident);

        $this->assertFalse($review['cod_paid']);
        $this->assertFalse($review['refund_executed']);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_financial_resolution_evidence_requires_investigation_hold(): void
    {
        [, , $incident] = $this->resolutionCase(false);

        $this->actor(2201);
        $this->expectException(QueryException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->addEvidence(
                $incident['exception_key'],
                (string) Str::uuid(),
                'bank_notice_claim',
                str_repeat('a', 64)
            );
    }

    public function test_financial_resolution_two_evidence_records_are_append_only_claims(): void
    {
        [, , $incident] = $this->resolutionCase();
        $this->resolutionTwoSources($incident);

        $this->assertDatabaseCount(
            'checkout_cod_exception_evidence',
            2
        );

        $this->actor(1200);

        $status = app(
            CheckoutCodFinancialResolutionStatusService::class
        )->inspect($incident['exception_key']);

        $this->assertSame(
            'evidence_ready_for_proposal',
            $status['status']
        );
        $this->assertTrue($status['financial_hold']);
        $this->assertFalse(
            $status['bank_source_authenticated']
        );
    }

    public function test_financial_resolution_evidence_uuid_replay_is_idempotent(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->actor(2201);
        $key = (string) Str::uuid();

        $svc = app(CheckoutCodFinancialResolutionService::class);
        $digest = str_repeat('a', 64);

        $first = $svc->addEvidence(
            $incident['exception_key'],
            $key,
            'bank_notice_claim',
            $digest
        );

        $this->assertSame(
            $first,
            $svc->addEvidence(
                $incident['exception_key'],
                $key,
                'bank_notice_claim',
                $digest
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_exception_evidence',
            1
        );
    }

    public function test_financial_resolution_reused_evidence_digest_is_rejected(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionEvidence(
            $incident,
            2201,
            'bank_notice_claim',
            'same'
        );

        $this->expectException(DomainException::class);

        $this->resolutionEvidence(
            $incident,
            2202,
            'internal_reconciliation',
            'same'
        );
    }

    public function test_financial_resolution_evidence_needs_permission(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->actor(999);
        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->addEvidence(
                $incident['exception_key'],
                (string) Str::uuid(),
                'bank_notice_claim',
                str_repeat('b', 64)
            );
    }

    public function test_financial_resolution_reporter_cannot_submit_evidence_even_with_gate(): void
    {
        [, , $incident] = $this->resolutionCase();

        Gate::define(
            CheckoutCodFinancialResolutionService::EVIDENCE_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2101, 2201, 2202],
                true
            )
        );

        $this->actor(2101);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->addEvidence(
                $incident['exception_key'],
                (string) Str::uuid(),
                'bank_notice_claim',
                str_repeat('b', 64)
            );
    }

    public function test_financial_resolution_proposal_requires_two_evidence_authors(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionEvidence(
            $incident,
            2201,
            'bank_notice_claim',
            'one'
        );

        $this->resolutionEvidence(
            $incident,
            2201,
            'internal_reconciliation',
            'two'
        );

        $this->expectException(DomainException::class);

        $this->resolutionProposal($incident);
    }

    public function test_financial_resolution_proposal_requires_distinct_evidence_kinds(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionEvidence(
            $incident,
            2201,
            'bank_notice_claim',
            'one'
        );

        $this->resolutionEvidence(
            $incident,
            2202,
            'bank_notice_claim',
            'two'
        );

        $this->expectException(DomainException::class);

        $this->resolutionProposal($incident);
    }

    public function test_financial_resolution_correction_is_signed_kopiyka_advice_only(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);

        $proposal = $this->resolutionProposal($incident);

        $this->assertSame(
            680,
            $proposal['source_net_minor']
        );

        $this->assertSame(
            600,
            $proposal['recommended_net_minor']
        );

        $this->assertSame(
            -80,
            $proposal['delta_minor']
        );

        $this->assertFalse($proposal['refund_executed']);

        $this->assertDatabaseCount(
            'checkout_cod_exception_proposals',
            1
        );
    }

    public function test_financial_resolution_proposal_replay_is_idempotent(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);

        $key = (string) Str::uuid();

        $first = $this->resolutionProposal(
            $incident,
            600,
            'amount_correction',
            $key
        );

        $this->assertSame(
            $first,
            $this->resolutionProposal(
                $incident,
                600,
                'amount_correction',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_exception_proposals',
            1
        );
    }

    public function test_financial_resolution_second_active_proposal_is_rejected(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $this->resolutionProposal($incident);

        $this->expectException(DomainException::class);

        $this->resolutionProposal($incident, 500);
    }

    public function test_financial_resolution_evidence_freezes_during_proposal(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $this->resolutionProposal($incident);

        $this->expectException(DomainException::class);

        $this->resolutionEvidence(
            $incident,
            2201,
            'novapay_notice_claim',
            'late'
        );
    }

    public function test_financial_resolution_proposer_cannot_be_evidence_author(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);

        Gate::define(
            CheckoutCodFinancialResolutionService::PROPOSE_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2201, 2203],
                true
            )
        );

        $this->actor(2201);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->propose(
                $incident['exception_key'],
                (string) Str::uuid(),
                'amount_correction',
                600
            );
    }

    public function test_financial_resolution_reviewer_requires_permission(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $proposal = $this->resolutionProposal($incident);

        $this->actor(999);
        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->review(
                $proposal['operation_key'],
                (string) Str::uuid(),
                'ready_for_external_verification',
                'recommendation_documented'
            );
    }

    public function test_financial_resolution_proposer_cannot_review_own_proposal(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $proposal = $this->resolutionProposal($incident);

        Gate::define(
            CheckoutCodFinancialResolutionService::REVIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2203, 2204],
                true
            )
        );

        $this->actor(2203);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->review(
                $proposal['operation_key'],
                (string) Str::uuid(),
                'ready_for_external_verification',
                'recommendation_documented'
            );
    }

    public function test_financial_resolution_review_is_not_funds_or_refund(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $proposal = $this->resolutionProposal($incident);

        $decision = $this->resolutionReview($proposal);

        $this->assertSame(
            'ready_for_external_verification',
            $decision['decision']
        );

        $this->assertFalse(
            $decision['financial_hold_released']
        );
        $this->assertFalse($decision['refund_executed']);

        $this->actor(1200);

        $status = app(
            CheckoutCodFinancialResolutionStatusService::class
        )->inspect($incident['exception_key']);

        $this->assertSame(
            'recommendation_awaiting_external_verification',
            $status['status']
        );

        $this->assertTrue($status['financial_hold']);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }
}
