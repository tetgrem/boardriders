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

trait Cod52BankTestsPart04
{
    public function test_journal_draft34_reviewer_cannot_be_author(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        Gate::define(
            CheckoutCodJournalDraftService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3401
        );

        $this->actor(3401);

        $this->expectException(DomainException::class);

        app(CheckoutCodJournalDraftService::class)->review(
            $draft['operation_key'],
            (string) Str::uuid(),
            'retain_unposted'
        );
    }

    public function test_journal_draft34_reviewer_cannot_be_bank_importer(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        Gate::define(
            CheckoutCodJournalDraftService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3001
        );

        $this->actor(3001);

        $this->expectException(DomainException::class);

        app(CheckoutCodJournalDraftService::class)->review(
            $draft['operation_key'],
            (string) Str::uuid(),
            'retain_unposted'
        );
    }

    public function test_journal_draft34_prepare_replay_idempotent(): void
    {
        [, $packet] = $this->journal34Source();

        $key = (string) Str::uuid();

        $first = $this->journal34Prepare($packet, $key);
        $second = $this->journal34Prepare($packet, $key);

        $this->assertSame(
            $first['draft_id'],
            $second['draft_id']
        );

        $this->assertTrue($second['idempotent_replay']);

        $this->assertDatabaseCount(
            'checkout_cod_journal_drafts',
            1
        );
    }

    public function test_journal_draft34_second_draft_for_packet_rejected(): void
    {
        [, $packet] = $this->journal34Source();

        $this->journal34Prepare($packet);

        $this->expectException(DomainException::class);

        $this->journal34Prepare($packet);
    }

    public function test_journal_draft34_review_replay_idempotent(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        $key = (string) Str::uuid();

        $first = $this->journal34Review($draft, key: $key);
        $second = $this->journal34Review($draft, key: $key);

        $this->assertSame(
            $first['review_id'],
            $second['review_id']
        );

        $this->assertTrue($second['idempotent_replay']);
    }

    public function test_journal_draft34_second_bank_credit_invalidates_review(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        $this->privatStatement30Fixture['transactions'][0]['REFN']
            = 'BANK34_SECOND';

        $day = now('Europe/Kyiv')
            ->subDay()
            ->toDateString();

        $this->actor(3001);

        app(
            CheckoutCodPrivatStatementImportService::class
        )->import(
            (string) Str::uuid(),
            $day,
            $day
        );

        $this->actor(1200);

        $status = app(
            CheckoutCodJournalDraftStatusService::class
        )->inspect($packet['operation_key']);

        $this->assertSame(
            'journal_draft_stale',
            $status['status']
        );

        $this->actor(3402);

        $this->expectException(DomainException::class);

        app(CheckoutCodJournalDraftService::class)->review(
            $draft['operation_key'],
            (string) Str::uuid(),
            'retain_unposted'
        );
    }

    public function test_journal_draft34_financial_hold_invalidates_review(): void
    {
        [$source, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        $this->financialExceptionReport(
            'single',
            $source,
            'incorrect_amount'
        );

        $this->expectException(DomainException::class);

        $this->journal34Review($draft);
    }

    public function test_journal_draft34_entries_are_immutable(): void
    {
        [, $packet] = $this->journal34Source();

        $this->journal34Prepare($packet);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_journal_draft_entries')
            ->update(['amount_minor' => 1]);
    }

    public function test_journal_draft34_database_rejects_forged_extra_entry(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        $first = DB::table('checkout_cod_journal_draft_entries')
            ->where('draft_id', $draft['draft_id'])
            ->first();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_journal_draft_entries')->insert([
            'draft_id' => $draft['draft_id'],
            'coverage_line_id' => $first->coverage_line_id,
            'order_id' => $first->order_id,
            'entry_kind' => 'carrier_collection',
            'debit_account' => 'draft_carrier_clearing',
            'credit_account' => 'draft_cod_receivable',
            'amount_minor' => 1,
            'created_at' => now(),
        ]);
    }

    public function test_journal_draft34_queue_redacted_permissioned(): void
    {
        [, $packet] = $this->journal34Source();

        $this->journal34Prepare($packet);

        $this->actor(1200);

        $queue = app(
            CheckoutCodJournalDraftStatusService::class
        )->recent();

        $this->assertCount(1, $queue['items']);
        $this->assertFalse(
            $queue['automatic_payment_posting_allowed']
        );

        $encoded = json_encode(
            $queue,
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString(
            'UA111111111111111111111111111',
            $encoded
        );

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(
            CheckoutCodJournalDraftStatusService::class
        )->recent();
    }

    public function test_adjustment_plan35_return_exposure_remains_unexecuted(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        $this->assertSame('return_case_followup', $plan['plan_kind']);
        $this->assertSame(1000, $plan['requested_refund_minor']);
        $this->assertSame(2000, $plan['original_gross_minor']);
        $this->assertSame(1320, $plan['original_fee_minor']);
        $this->assertSame(680, $plan['original_order_net_minor']);
        $this->assertFalse($plan['customer_refund_executed']);
        $review = $this->adjustment35Review($plan);
        $this->assertSame('retain_unexecuted', $review['decision']);
        $this->actor(1200);
        $status = app(CheckoutCodAdjustmentPlanService::class)->status($plan['operation_key']);
        $this->assertSame('adjustment_reviewed_not_executed', $status['status']);
        $this->assertTrue($status['snapshot_current']);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_adjustment_plan35_full_return_is_claim_only(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal, 2000);
        $this->assertSame(2000, $plan['requested_refund_minor']);
        $this->assertFalse($plan['financial_ledger_changed']);
    }

    public function test_adjustment_plan35_amount_correction_signed_difference(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source(
            reason: 'incorrect_amount', recommendation: 'amount_correction', recommended: 600
        );
        $plan = $this->adjustment35Prepare($draft, $proposal, 0);
        $this->assertSame('amount_correction', $plan['plan_kind']);
        $this->assertSame(-80, $plan['source_net_difference_minor']);
        $this->assertSame(0, $plan['requested_refund_minor']);
        $this->adjustment35Review($plan);
    }

    public function test_adjustment_plan35_bank_reversal_is_not_customer_refund(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source(
            reason: 'bank_reversal_reported', recommendation: 'reported_reversal', recommended: 0
        );
        $plan = $this->adjustment35Prepare($draft, $proposal, 0);
        $this->assertSame('reported_reversal', $plan['plan_kind']);
        $this->assertSame(-680, $plan['source_net_difference_minor']);
        $this->assertSame(0, $plan['requested_refund_minor']);
    }

    public function test_adjustment_plan35_batch_return_allocated_to_one_order(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source('batch');
        $plan = $this->adjustment35Prepare($draft, $proposal, 1000);
        $this->assertSame('batch', DB::table('checkout_cod_adjustment_plans')
            ->where('id', $plan['plan_id'])->value('source_kind'));
        $this->assertSame(680, $plan['original_order_net_minor']);
        $this->assertSame(1000, $plan['requested_refund_minor']);
    }

    public function test_adjustment_plan35_zero_refund_rejected(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $this->expectException(InvalidArgumentException::class);
        $this->adjustment35Prepare($draft, $proposal, 0);
    }

    public function test_adjustment_plan35_over_refund_rejected(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $this->expectException(InvalidArgumentException::class);
        $this->adjustment35Prepare($draft, $proposal, 2001);
    }

    public function test_adjustment_plan35_planner_independent_of_resolution_author(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        Gate::define(CheckoutCodAdjustmentPlanService::PREPARE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 2203);
        $this->actor(2203);
        $this->expectException(DomainException::class);
        app(CheckoutCodAdjustmentPlanService::class)->prepare(
            $proposal['operation_key'], $draft['operation_key'],
            $this->orderKey, 1000, (string) Str::uuid()
        );
    }

    public function test_adjustment_plan35_reviewer_independent_of_planner(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        Gate::define(CheckoutCodAdjustmentPlanService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3501);
        $this->actor(3501);
        $this->expectException(DomainException::class);
        app(CheckoutCodAdjustmentPlanService::class)->review(
            $plan['operation_key'], (string) Str::uuid(), 'retain_unexecuted'
        );
    }

    public function test_adjustment_plan35_prepare_idempotent_replay(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $key = (string) Str::uuid();
        $a = $this->adjustment35Prepare($draft, $proposal, 1000, $key);
        $b = $this->adjustment35Prepare($draft, $proposal, 1000, $key);
        $this->assertSame($a['plan_id'], $b['plan_id']);
        $this->assertTrue($b['idempotent_replay']);
    }

    public function test_adjustment_plan35_duplicate_source_order_rejected(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $this->adjustment35Prepare($draft, $proposal);
        $this->expectException(DomainException::class);
        $this->adjustment35Prepare($draft, $proposal);
    }

    public function test_adjustment_plan35_review_idempotent_replay(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        $key = (string) Str::uuid();
        $a = $this->adjustment35Review($plan, key: $key);
        $b = $this->adjustment35Review($plan, key: $key);
        $this->assertSame($a['review_id'], $b['review_id']);
        $this->assertTrue($b['idempotent_replay']);
    }

    public function test_adjustment_plan35_new_bank_evidence_invalidates_review(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'BANK35_NEW';
        $day = now('Europe/Kyiv')->subDay()->toDateString();
        $this->actor(3001);
        app(CheckoutCodPrivatStatementImportService::class)->import(
            (string) Str::uuid(), $day, $day
        );
        $this->actor(1200);
        $status = app(CheckoutCodAdjustmentPlanService::class)->status($plan['operation_key']);
        $this->assertSame('adjustment_plan_stale', $status['status']);
        $this->actor(3502);
        $this->expectException(DomainException::class);
        app(CheckoutCodAdjustmentPlanService::class)->review(
            $plan['operation_key'], (string) Str::uuid(), 'retain_unexecuted'
        );
    }

    public function test_adjustment_plan35_database_history_immutable(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $this->adjustment35Prepare($draft, $proposal);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_adjustment_plans')->update(['requested_refund_minor' => 1]);
    }

    public function test_adjustment_plan35_view_requires_permission(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodAdjustmentPlanService::class)->status($plan['operation_key']);
    }

    public function test_bank_refetch36_single_exact_remote_credit(): void
    {
        [$source] = $this->bankRefetch36Source();
        $result = $this->bankRefetch36Run('single', $source);
        $this->assertSame('consistent_unverified', $result['result_code']);
        $this->assertSame(1, $result['reference_rows']);
        $this->assertSame(1, $result['exact_rows']);
        $this->assertFalse($result['bank_native_signature_verified']);
        $this->assertFalse($result['funds_received_verified']);
        $this->assertFalse($result['cod_paid']);
        $this->actor(1200);
        $status = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertTrue($status['fresh_consistent_api_read']);
        $this->assertFalse($status['payment_posting_allowed']);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_bank_refetch36_batch_credit_is_separate_source(): void
    {
        [$source] = $this->bankRefetch36Source('batch');
        $result = $this->bankRefetch36Run('batch', $source);
        $this->assertSame('batch', $result['source_kind']);
        $this->assertSame('consistent_unverified', $result['result_code']);
        $this->actor(1200);
        $s = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('batch', $source['operation_key']);
        $this->assertTrue($s['fresh_consistent_api_read']);
    }

    public function test_bank_refetch36_changed_remote_amount_detected(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->privatStatement30Fixture['transactions'][0]['SUM'] = '7.00';
        $r = $this->bankRefetch36Run('single', $source);
        $this->assertSame('transaction_changed', $r['result_code']);
        $this->assertSame(0, $r['exact_rows']);
        $this->actor(1200);
        $s = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertFalse($s['fresh_consistent_api_read']);
    }

    public function test_bank_refetch36_changed_remote_identity_detected(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'CHANGED36';
        $r = $this->bankRefetch36Run('single', $source);
        $this->assertSame('transaction_changed', $r['result_code']);
        $this->assertSame(0, $r['identity_rows']);
    }

    public function test_bank_refetch36_missing_remote_reference_detected(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->privatStatement30Fixture['transactions'][0]['REF'] = 'ANOTHER_REF36';
        $r = $this->bankRefetch36Run('single', $source);
        $this->assertSame('reference_not_found', $r['result_code']);
        $this->assertSame(0, $r['reference_rows']);
    }

    public function test_bank_refetch36_ambiguous_reference_detected(): void
    {
        [$source] = $this->bankRefetch36Source();
        $other = $this->privatStatement30Fixture['transactions'][0];
        $other['REFN'] = 'ANOTHER36';
        $this->privatStatement30Fixture['transactions'][] = $other;
        $r = $this->bankRefetch36Run('single', $source);
        $this->assertSame('ambiguous_reference', $r['result_code']);
        $this->assertSame(2, $r['reference_rows']);
        $this->assertFalse($r['cod_paid']);
    }

    public function test_bank_refetch36_same_uuid_exact_replay(): void
    {
        [$source] = $this->bankRefetch36Source();
        $key = (string) Str::uuid();
        $first = $this->bankRefetch36Run('single', $source, $key);
        $second = $this->bankRefetch36Run('single', $source, $key);
        $this->assertSame($first['refetch_id'], $second['refetch_id']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_privat_refetches', 1);
    }

    public function test_bank_refetch36_new_uuid_creates_new_audit_snapshot(): void
    {
        [$source] = $this->bankRefetch36Source();
        $a = $this->bankRefetch36Run('single', $source);
        $b = $this->bankRefetch36Run('single', $source);
        $this->assertNotSame($a['refetch_id'], $b['refetch_id']);
        $this->assertDatabaseCount('checkout_cod_privat_refetches', 2);
    }

    public function test_bank_refetch36_importer_cannot_recheck(): void
    {
        [$source] = $this->bankRefetch36Source();
        Gate::define(CheckoutCodPrivatRefetchService::RECHECK_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3001);
        $this->actor(3001);
        $this->expectException(DomainException::class);
        app(CheckoutCodPrivatRefetchService::class)->recheck(
            'single', $source['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_refetch36_bank_claim_author_cannot_recheck(): void
    {
        [$source] = $this->bankRefetch36Source();
        Gate::define(CheckoutCodPrivatRefetchService::RECHECK_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1901);
        $this->actor(1901);
        $this->expectException(DomainException::class);
        app(CheckoutCodPrivatRefetchService::class)->recheck(
            'single', $source['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_refetch36_operator_permission_required(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodPrivatRefetchService::class)->recheck(
            'single', $source['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_refetch36_financial_hold_disables_current_status(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->bankRefetch36Run('single', $source);
        $this->financialExceptionReport('single', $source, 'incorrect_amount');
        $this->actor(1200);
        $state = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('financial_investigation_hold', $state['status']);
        $this->assertFalse($state['fresh_consistent_api_read']);
    }

    public function test_bank_refetch36_local_new_transaction_invalidates_old_read(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->bankRefetch36Run('single', $source);
        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'LATER36';
        $day = now('Europe/Kyiv')->subDay()->toDateString();
        $this->actor(3001);
        app(CheckoutCodPrivatStatementImportService::class)
            ->import((string) Str::uuid(), $day, $day);
        $this->actor(1200);
        $status = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('local_statement_changed', $status['status']);
        $this->assertFalse($status['fresh_consistent_api_read']);
    }

    public function test_bank_refetch36_connector_failure_records_nothing(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->privatStatement30Fixture['status'] = 'FAILED';
        $this->expectException(DomainException::class);
        try {
            $this->bankRefetch36Run('single', $source);
        } finally {
            $this->assertDatabaseCount('checkout_cod_privat_refetches', 0);
        }
    }

    public function test_bank_refetch36_audit_is_immutable(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->bankRefetch36Run('single', $source);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_privat_refetches')->update(['result_code' => 'reference_not_found']);
    }
}
