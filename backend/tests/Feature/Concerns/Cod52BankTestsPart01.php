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

trait Cod52BankTestsPart01
{
    public function test_bank_settlement_requires_existing_financial_case(): void
    {
        $this->actor(1901);
        $this->expectException(DomainException::class);
        app(CheckoutCodSettlementBankReceiptService::class)->record(
            (string) Str::uuid(), (string) Str::uuid(), 'BANKREF_NO_CASE', 680
        );
    }

    public function test_bank_settlement_receipt_is_only_claim_and_keeps_order_pending(): void
    {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case);
        $this->assertSame(680, $bank['claimed_net_minor']);
        $this->assertFalse($bank['funds_received_verified']);
        $this->assertFalse($bank['cod_paid']);
        $this->assertDatabaseCount('checkout_cod_settlement_bank_receipts', 1);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_bank_settlement_receipt_replay_is_idempotent(): void
    {
        $case = $this->settlementReadyCase();
        $this->actor(1901);
        $service = app(CheckoutCodSettlementBankReceiptService::class);
        $key = (string) Str::uuid();
        $a = $service->record($case['case_key'], $key, 'BANKREF_REPLAY_1001', 680);
        $this->assertSame($a, $service->record(
            $case['case_key'], $key, 'BANKREF_REPLAY_1001', 680
        ));
        $this->assertDatabaseCount('checkout_cod_settlement_bank_receipts', 1);
    }

    public function test_bank_settlement_global_reference_cannot_be_reused_under_different_kind(): void
    {
        $case = $this->settlementReadyCase();
        $this->actor(1901);
        $service = app(CheckoutCodSettlementBankReceiptService::class);
        $service->record($case['case_key'], (string) Str::uuid(),
            'BANKREF_DUPLICATE_1001', 680, 'bank_statement_claim');
        $this->expectException(DomainException::class);
        $service->record($case['case_key'], (string) Str::uuid(),
            'BANKREF_DUPLICATE_1001', 680, 'manual_bank_claim');
    }

    public function test_bank_settlement_bank_receipt_needs_permission(): void
    {
        $case = $this->settlementReadyCase();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodSettlementBankReceiptService::class)->record(
            $case['case_key'], (string) Str::uuid(), 'BANKREF_FORBIDDEN', 680
        );
    }

    public function test_bank_settlement_pair_requires_reviewed_novapay_claim(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);
        $bank = $this->bankSettlementClaim($case);
        $this->actor(1902);
        $this->expectException(DomainException::class);
        app(CheckoutCodSettlementBankMatchService::class)->propose(
            $claim['operation_key'], $bank['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_settlement_exact_pair_never_confirms_funds(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case);
        $match = $this->bankSettlementMatch($claim, $bank);
        $this->assertSame('amounts_match_unverified', $match['match_result']);
        $this->assertSame(0, $match['difference_minor']);
        $this->assertFalse($match['funds_received_verified']);
        $this->assertFalse($match['cod_paid']);
        $this->assertDatabaseCount('checkout_cod_settlement_bank_matches', 1);
        Http::assertNotSent(static fn (ProviderRequest $r): bool =>
            ($r->data()['calledMethod'] ?? null) === 'save');
    }

    public function test_bank_settlement_net_mismatch_is_identified(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch(
            $claim, $this->bankSettlementClaim($case, 650)
        );
        $this->assertSame('bank_net_mismatch', $match['match_result']);
        $this->assertSame(-30, $match['difference_minor']);
    }

    public function test_bank_settlement_novapay_discrepancy_takes_priority(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim(2500, 1320, 1180);
        $match = $this->bankSettlementMatch(
            $claim, $this->bankSettlementClaim($case, 1180)
        );
        $this->assertSame('source_claim_discrepancy', $match['match_result']);
        $this->assertSame(0, $match['difference_minor']);
    }

    public function test_bank_settlement_pair_uuid_replay_is_idempotent(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case);
        $key = (string) Str::uuid();
        $match = $this->bankSettlementMatch($claim, $bank, $key);
        $this->assertSame($match, $this->bankSettlementMatch($claim, $bank, $key));
        $this->assertDatabaseCount('checkout_cod_settlement_bank_matches', 1);
    }

    public function test_bank_settlement_same_claim_cannot_be_paired_twice(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case);
        $this->bankSettlementMatch($claim, $bank);
        $this->expectException(DomainException::class);
        $this->bankSettlementMatch($claim, $bank);
    }

    public function test_bank_settlement_novapay_claim_author_cannot_propose_pair(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case);
        Gate::define(CheckoutCodSettlementBankMatchService::PROPOSE_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [1802, 1902], true));
        $this->actor(1802);
        $this->expectException(DomainException::class);
        app(CheckoutCodSettlementBankMatchService::class)->propose(
            $claim['operation_key'], $bank['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_settlement_independent_review_keeps_external_confirmation_pending(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case));
        $this->actor(1903);
        $review = app(CheckoutCodSettlementBankReviewService::class)->review(
            $match['operation_key'], (string) Str::uuid(),
            'retain_external_confirmation', 'bank_and_novapay_unverified'
        );
        $this->assertFalse($review['funds_received_verified']);
        $this->actor(1200);
        $status = app(CheckoutCodSettlementBankStatusService::class)
            ->inspect($this->orderKey);
        $this->assertSame('claims_correlated_external_confirmation_required', $status['status']);
        $this->assertSame(1, $status['match_reviews_count']);
        $this->assertFalse($status['cod_paid_by_reconciliation']);
    }

    public function test_bank_settlement_matching_review_replays_idempotently(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case));
        $this->actor(1903);
        $service = app(CheckoutCodSettlementBankReviewService::class);
        $key = (string) Str::uuid();
        $a = $service->review($match['operation_key'], $key,
            'retain_external_confirmation', 'bank_and_novapay_unverified');
        $this->assertSame($a, $service->review($match['operation_key'], $key,
            'retain_external_confirmation', 'bank_and_novapay_unverified'));
        $this->assertDatabaseCount('checkout_cod_settlement_bank_reviews', 1);
    }

    public function test_bank_settlement_mismatch_requires_escalation(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case, 650));
        $this->actor(1903);
        $review = app(CheckoutCodSettlementBankReviewService::class)->review(
            $match['operation_key'], (string) Str::uuid(),
            'escalate_difference', 'bank_net_mismatch'
        );
        $this->assertSame('escalate_difference', $review['decision']);
        $this->actor(1200);
        $this->assertSame('bank_discrepancy_requires_investigation',
            app(CheckoutCodSettlementBankStatusService::class)
                ->inspect($this->orderKey)['status']);
    }

    public function test_bank_settlement_reviewer_cannot_review_own_pair(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case));
        Gate::define(CheckoutCodSettlementBankReviewService::REVIEW_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [1902, 1903], true));
        $this->actor(1902);
        $this->expectException(DomainException::class);
        app(CheckoutCodSettlementBankReviewService::class)->review(
            $match['operation_key'], (string) Str::uuid(),
            'retain_external_confirmation', 'bank_and_novapay_unverified'
        );
    }

    public function test_bank_settlement_invalid_review_decision_is_rejected(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case));
        $this->actor(1903);
        $this->expectException(InvalidArgumentException::class);
        app(CheckoutCodSettlementBankReviewService::class)->review(
            $match['operation_key'], (string) Str::uuid(),
            'escalate_difference', 'bank_net_mismatch'
        );
    }

    public function test_bank_settlement_receipt_is_database_immutable(): void
    {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_settlement_bank_receipts')
            ->where('id', $bank['id'])->update(['claimed_net_minor' => 1]);
    }

    public function test_bank_settlement_status_without_case_is_safe(): void
    {
        $this->actor(1200);
        $report = app(CheckoutCodSettlementBankStatusService::class)
            ->inspect($this->orderKey);
        $this->assertSame('no_settlement_case', $report['status']);
        $this->assertFalse($report['funds_received_verified']);
    }

    public function test_bank_settlement_queue_is_pii_free(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $this->actor(1901);
        $bank = app(CheckoutCodSettlementBankReceiptService::class)->record(
            $case['case_key'], (string) Str::uuid(),
            'BANKREF_PRIVATE_2001', 680
        );
        $this->bankSettlementMatch($claim, $bank);
        $this->actor(1200);
        $queue = app(CheckoutCodSettlementBankQueueService::class)->recent();
        $this->assertCount(1, $queue['items']);
        $json = json_encode($queue, JSON_THROW_ON_ERROR);
        foreach (['BANKREF_PRIVATE_2001', 'Петренко', '380671234567', 'ttn-gate-test-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertFalse($queue['cod_paid']);
    }

    public function test_bank_settlement_queue_requires_reader_permission(): void
    {
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodSettlementBankQueueService::class)->recent();
    }

    public function test_bank_batch_record_requires_permission(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodBankBatchService::class)->record(
            (string) Str::uuid(),
            'BATCHREF_DENIED',
            680
        );
    }

    public function test_bank_batch_record_is_unverified_and_has_global_reference(): void
    {
        $batch = $this->bankBatchCreate();

        $this->assertSame(680, $batch['claimed_net_minor']);
        $this->assertFalse($batch['funds_received_verified']);
        $this->assertFalse($batch['cod_paid']);

        $this->assertDatabaseCount('checkout_cod_bank_batches', 1);
        $this->assertDatabaseCount('checkout_cod_bank_reference_claims', 1);
    }

    public function test_bank_batch_record_exact_replay_is_idempotent(): void
    {
        $key = (string) Str::uuid();

        $batch = $this->bankBatchCreate(
            680,
            'BATCHREF_REPLAY_1001',
            $key
        );

        $this->assertSame(
            $batch,
            $this->bankBatchCreate(
                680,
                'BATCHREF_REPLAY_1001',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_bank_reference_claims',
            1
        );
    }

    public function test_bank_batch_duplicate_external_reference_is_blocked(): void
    {
        $this->bankBatchCreate(
            680,
            'BATCHREF_DUPLICATE_1001'
        );

        $this->expectException(DomainException::class);

        $this->bankBatchCreate(
            680,
            'BATCHREF_DUPLICATE_1001'
        );
    }

    public function test_bank_batch_existing_single_bank_reference_cannot_be_reclaimed(): void
    {
        $case = $this->settlementReadyCase();

        $this->actor(1901);

        app(CheckoutCodSettlementBankReceiptService::class)->record(
            $case['case_key'],
            (string) Str::uuid(),
            'BATCHREF_CROSS_PATH_1001',
            680
        );

        $this->expectException(DomainException::class);

        $this->bankBatchCreate(
            680,
            'BATCHREF_CROSS_PATH_1001'
        );
    }

    public function test_bank_batch_reference_cannot_be_reused_as_single_receipt(): void
    {
        $case = $this->settlementReadyCase();

        $this->bankBatchCreate(
            680,
            'BATCHREF_CROSS_PATH_1002'
        );

        $this->actor(1901);

        $this->expectException(QueryException::class);

        app(CheckoutCodSettlementBankReceiptService::class)->record(
            $case['case_key'],
            (string) Str::uuid(),
            'BATCHREF_CROSS_PATH_1002',
            680
        );
    }

    public function test_bank_batch_allocation_requires_reviewed_novapay_claim(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);
        $batch = $this->bankBatchCreate();

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch, $claim);
    }

    public function test_bank_batch_partial_allocation_does_not_settle_cod(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate(1000);
        $allocation = $this->bankBatchAllocate(
            $batch,
            $claim,
            500
        );

        $this->assertSame(500, $allocation['amount_minor']);

        $this->actor(1200);

        $status = app(CheckoutCodBankBatchStatusService::class)
            ->inspect($batch['operation_key']);

        $this->assertSame(500, $status['remaining_minor']);
        $this->assertSame(
            'awaiting_independent_review',
            $status['status']
        );
        $this->assertFalse($status['cod_paid']);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_bank_batch_allocation_exact_replay_is_idempotent(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate();
        $key = (string) Str::uuid();

        $first = $this->bankBatchAllocate(
            $batch,
            $claim,
            680,
            $key
        );

        $this->assertSame(
            $first,
            $this->bankBatchAllocate(
                $batch,
                $claim,
                680,
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_bank_batch_allocations',
            1
        );
    }

    public function test_bank_batch_same_claim_cannot_be_allocated_twice_to_one_batch(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(1000);

        $this->bankBatchAllocate($batch, $claim, 300);

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch, $claim, 300);
    }

    public function test_bank_batch_novapay_claim_cannot_be_overallocated_across_batches(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch1 = $this->bankBatchCreate(500);
        $this->bankBatchAllocate($batch1, $claim, 500);

        $batch2 = $this->bankBatchCreate(500);

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch2, $claim, 200);
    }

    public function test_bank_batch_one_transfer_can_cover_two_cod_orders(): void
    {
        [$case1, $claim1] = $this->bankBatchSource();
        [$case2, $claim2] = $this->bankBatchSecondCase();

        $this->assertNotSame(
            $case1['id'],
            $case2['id']
        );

        $batch = $this->bankBatchCreate(1360);

        $this->bankBatchAllocate($batch, $claim1, 680);
        $this->bankBatchAllocate($batch, $claim2, 680);

        $this->actor(1200);

        $status = app(CheckoutCodBankBatchStatusService::class)
            ->inspect($batch['operation_key']);

        $this->assertSame(2, $status['allocation_count']);
        $this->assertSame(0, $status['remaining_minor']);
        $this->assertFalse($status['funds_received_verified']);
    }

    public function test_bank_batch_cannot_allocate_more_than_batch_amount(): void
    {
        [$case1, $claim1] = $this->bankBatchSource();
        [$case2, $claim2] = $this->bankBatchSecondCase();

        $batch = $this->bankBatchCreate(1000);

        $this->bankBatchAllocate($batch, $claim1, 680);

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch, $claim2, 680);
    }

    public function test_bank_batch_independent_review_seals_fully_allocated_batch(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate();
        $this->bankBatchAllocate($batch, $claim);

        $this->actor(2003);

        $review = app(CheckoutCodBankBatchReviewService::class)
            ->review(
                $batch['operation_key'],
                (string) Str::uuid()
            );

        $this->assertSame(
            'allocation_balanced_unverified',
            $review['decision']
        );
        $this->assertFalse($review['cod_paid']);

        $this->actor(1200);

        $this->assertTrue(
            app(CheckoutCodBankBatchStatusService::class)
                ->inspect($batch['operation_key'])['sealed']
        );
    }

    public function test_bank_batch_partial_review_preserves_unallocated_amount(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(1000);

        $this->bankBatchAllocate($batch, $claim, 500);

        $this->actor(2003);

        $review = app(CheckoutCodBankBatchReviewService::class)
            ->review(
                $batch['operation_key'],
                (string) Str::uuid()
            );

        $this->assertSame(
            'allocation_partial_unverified',
            $review['decision']
        );
        $this->assertSame(
            'unallocated_balance',
            $review['reason_code']
        );
    }

    public function test_bank_batch_review_exact_replay_is_idempotent(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate();

        $this->bankBatchAllocate($batch, $claim);

        $this->actor(2003);

        $key = (string) Str::uuid();
        $svc = app(CheckoutCodBankBatchReviewService::class);

        $first = $svc->review(
            $batch['operation_key'],
            $key
        );

        $this->assertSame(
            $first,
            $svc->review($batch['operation_key'], $key)
        );

        $this->assertDatabaseCount(
            'checkout_cod_bank_batch_reviews',
            1
        );
    }

    public function test_bank_batch_creator_cannot_review_even_with_gate_permission(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate();

        $this->bankBatchAllocate($batch, $claim);

        Gate::define(
            CheckoutCodBankBatchReviewService::REVIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2001, 2003],
                true
            )
        );

        $this->actor(2001);

        $this->expectException(DomainException::class);

        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_bank_batch_review_prevents_later_allocations(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate(1000);

        $this->bankBatchAllocate($batch, $claim, 300);

        $this->actor(2003);

        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'],
            (string) Str::uuid()
        );

        $secondClaim = $this->settlementClaim($case['case_key']);

        $this->actor(1803);

        app(CheckoutCodSettlementReviewService::class)->review(
            $secondClaim['operation_key'],
            (string) Str::uuid(),
            'retain_unverified',
            'external_confirmation_required'
        );

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate(
            $batch,
            $secondClaim,
            100
        );
    }

    public function test_bank_batch_cannot_use_claim_already_matched_in_block24(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $this->actor(1901);

        $bank = app(CheckoutCodSettlementBankReceiptService::class)
            ->record(
                $case['case_key'],
                (string) Str::uuid(),
                'BANKREF_PATH24_1001',
                680
            );

        $this->actor(1902);

        app(CheckoutCodSettlementBankMatchService::class)->propose(
            $claim['operation_key'],
            $bank['operation_key'],
            (string) Str::uuid()
        );

        $batch = $this->bankBatchCreate();

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch, $claim);
    }
}
