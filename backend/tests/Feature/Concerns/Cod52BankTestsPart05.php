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

trait Cod52BankTestsPart05
{
    public function test_bank_refetch36_queue_is_redacted_and_permissioned(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->bankRefetch36Run('single', $source);
        $this->actor(1200);
        $queue = app(CheckoutCodPrivatRefetchStatusService::class)->recent();
        $this->assertCount(1, $queue['items']);
        $json = json_encode($queue, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('STMT31_SINGLE_REF', $json);
        $this->assertStringNotContainsString('UA111111111111111111111111111', $json);
        $this->assertFalse($queue['automatic_payment_posting_allowed']);
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodPrivatRefetchStatusService::class)->recent();
    }

    public function test_refetch_risk37_without_recheck_has_no_invented_hold(): void
    {
        [$source] = $this->statement31Single();
        $this->actor(1200);
        $r = app(CheckoutCodRefetchRiskStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('no_api_recheck_yet', $r['status']);
        $this->assertFalse($r['blocks_financial_approval']);
        $this->assertFalse($r['funds_received_verified']);
        $this->assertArrayNotHasKey('snapshot_items', $r);
    }

    public function test_refetch_risk37_clean_recheck_does_not_stale_balanced_comparison(): void
    {
        [$source] = $this->posting32Source();
        $this->bankRefetch36Gate();
        $this->bankRefetch36Run('single', $source);
        $this->actor(1200);
        $state = app(CheckoutCodStatementReconciliationStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('comparison_reviewed_payment_unverified', $state['status']);
        $this->assertTrue($state['snapshot_current']);
        $this->assertFalse($state['cod_paid']);
    }

    public function test_refetch_risk37_negative_recheck_stales_prior_comparison(): void
    {
        [$source] = $this->posting32Source();
        $this->refetchRisk37Negative('single', $source);
        $this->actor(1200);
        $state = app(CheckoutCodStatementReconciliationStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('stale_requires_new_investigation', $state['status']);
        $this->assertSame('evidence_conflict', $state['current_result_code']);
        $risk = app(CheckoutCodRefetchRiskStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertTrue($risk['blocks_financial_approval']);
        $this->assertSame(1, $risk['negative_observations_count']);
    }

    public function test_refetch_risk37_later_consistent_read_does_not_erase_negative_history(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->refetchRisk37Negative('single', $source);
        $this->privatStatement30Fixture['transactions'][0]['SUM'] = '6.80';
        $this->bankRefetch36Run('single', $source);
        $this->actor(1200);
        $risk = app(CheckoutCodRefetchRiskStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('bank_refetch_anomaly_unresolved', $risk['status']);
        $this->assertSame('consistent_unverified', $risk['latest_result_code']);
        $this->assertTrue($risk['blocks_financial_approval']);
    }

    public function test_refetch_risk37_database_blocks_approval_of_posting_preflight(): void
    {
        [$source, $proposal] = $this->posting32Source();
        $preflight = $this->posting32Prepare($proposal);
        $this->refetchRisk37Negative('single', $source);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_posting_preflight_reviews')->insert([
            'review_key' => (string) Str::uuid(),
            'preflight_id' => $preflight['preflight_id'],
            'decision' => 'retain_unverified',
            'reason_code' => 'external_payment_evidence_required',
            'reviewed_by' => 3202,
            'request_hmac' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }

    public function test_refetch_risk37_database_blocks_coverage_review(): void
    {
        [$source] = $this->coverage33Source();
        $packet = $this->coverage33Compile('single', $source);
        $this->refetchRisk37Negative('single', $source);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_bank_coverage_reviews')->insert([
            'review_key' => (string) Str::uuid(),
            'packet_id' => $packet['packet_id'],
            'decision' => 'retain_unverified',
            'reason_code' => 'external_bank_confirmation_required',
            'reviewed_by' => 3302,
            'request_hmac' => str_repeat('b', 64),
            'created_at' => now(),
        ]);
    }

    public function test_refetch_risk37_database_blocks_journal_draft_review(): void
    {
        [$source, $packet] = $this->journal34Source();
        $draft = $this->journal34Prepare($packet);
        $this->refetchRisk37Negative('single', $source);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_journal_draft_reviews')->insert([
            'review_key' => (string) Str::uuid(),
            'draft_id' => $draft['draft_id'],
            'decision' => 'retain_unposted',
            'reason_code' => 'authenticated_payment_evidence_required',
            'reviewed_by' => 3402,
            'request_hmac' => str_repeat('c', 64),
            'created_at' => now(),
        ]);
    }

    public function test_refetch_risk37_database_allows_escalation_after_negative_recheck(): void
    {
        [$source, $entry] = $this->statement31Single();
        $proposal = $this->statement31Propose('single', $source, $entry);
        $this->refetchRisk37Negative('single', $source);
        $review = $this->statement31Review($proposal, 'escalate_investigation');
        $this->assertSame('escalate_investigation', $review['decision']);
        $this->assertFalse($review['cod_paid']);
    }

    public function test_refetch_risk37_database_blocks_direct_acknowledgment(): void
    {
        [$source, $entry] = $this->statement31Single();
        $proposal = $this->statement31Propose('single', $source, $entry);
        $this->refetchRisk37Negative('single', $source);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_statement_reconciliation_reviews')->insert([
            'review_key' => (string) Str::uuid(),
            'reconciliation_id' => $proposal['reconciliation_id'],
            'decision' => 'acknowledge_comparison',
            'reason_code' => 'external_payment_verification_required',
            'reviewed_by' => 3102,
            'request_hmac' => str_repeat('d', 64),
            'created_at' => now(),
        ]);
    }

    public function test_refetch_risk37_reader_requires_permission(): void
    {
        [$source] = $this->statement31Single();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodRefetchRiskStatusService::class)
            ->inspect('single', $source['operation_key']);
    }

    public function test_bank_receipt38_single_credit_posts_two_ledger_lines_without_paid(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $post = $this->bankReceipt38Post($draft, $refetch);
        $this->assertSame(680, $post['amount_minor']);
        $this->assertSame(2, $post['ledger_lines_count']);
        $this->assertTrue($post['bank_credit_booked_in_internal_ledger']);
        $this->assertFalse($post['cod_paid']);
        $lines = DB::table('checkout_cod_bank_receipt_ledger_lines')
            ->where('posting_id', $post['posting_id'])->orderBy('side')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(1360, (int) $lines->sum('amount_minor'));
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
        $this->actor(1200);
        $status = app(CheckoutCodBankReceiptPostingStatusService::class)
            ->inspect($draft['operation_key']);
        $this->assertSame('bank_credit_posted', $status['status']);
    }

    public function test_bank_receipt38_batch_credit_posted_once(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source('batch');
        $post = $this->bankReceipt38Post($draft, $refetch);
        $this->assertSame('batch', $post['source_kind']);
        $this->assertSame(680, $post['amount_minor']);
        $this->assertDatabaseCount('checkout_cod_bank_receipt_postings', 1);
    }

    public function test_bank_receipt38_multi_order_batch_one_bank_entry(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source('batch', true);
        $post = $this->bankReceipt38Post($draft, $refetch);
        $this->assertSame(1360, $post['amount_minor']);
        $this->assertDatabaseCount('checkout_cod_bank_receipt_postings', 1);
        $this->assertDatabaseCount('checkout_cod_bank_receipt_ledger_lines', 2);
    }

    public function test_bank_receipt38_exact_request_replay(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $key = (string) Str::uuid();
        $a = $this->bankReceipt38Post($draft, $refetch, $key);
        $b = $this->bankReceipt38Post($draft, $refetch, $key);
        $this->assertSame($a['posting_id'], $b['posting_id']);
        $this->assertTrue($b['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_bank_receipt_ledger_lines', 2);
    }

    public function test_bank_receipt38_new_uuid_cannot_post_same_source_twice(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $this->bankReceipt38Post($draft, $refetch);
        $this->expectException(DomainException::class);
        $this->bankReceipt38Post($draft, $refetch);
    }

    public function test_bank_receipt38_negative_refetch_blocks_posting(): void
    {
        [$source, $draft, $refetch] = $this->bankReceipt38Source();
        $this->refetchRisk37Negative('single', $source);
        $this->expectException(DomainException::class);
        try {
            $this->bankReceipt38Post($draft, $refetch);
        } finally {
            $this->assertDatabaseCount('checkout_cod_bank_receipt_postings', 0);
        }
    }

    public function test_bank_receipt38_remote_changed_amount_blocks_posting(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $this->privatStatement30Fixture['transactions'][0]['SUM'] = '7.00';
        $this->expectException(DomainException::class);
        try {
            $this->bankReceipt38Post($draft, $refetch);
        } finally {
            $this->assertDatabaseCount('checkout_cod_bank_receipt_postings', 0);
        }
    }

    public function test_bank_receipt38_remote_transaction_identity_changed_blocks(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'NEW38';
        $this->expectException(DomainException::class);
        $this->bankReceipt38Post($draft, $refetch);
    }

    public function test_bank_receipt38_remote_reference_missing_blocks(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $this->privatStatement30Fixture['transactions'][0]['REF'] = 'OTHER_BANK38';
        $this->expectException(DomainException::class);
        $this->bankReceipt38Post($draft, $refetch);
    }

    public function test_bank_receipt38_unreviewed_journal_cannot_post(): void
    {
        [$source, $packet] = $this->journal34Source();
        $draft = $this->journal34Prepare($packet);
        $this->bankRefetch36Gate();
        $refetch = $this->bankRefetch36Run('single', $source);
        $this->bankReceipt38Gate();
        $this->expectException(DomainException::class);
        $this->bankReceipt38Post($draft, $refetch);
    }

    public function test_bank_receipt38_fails_without_bank_refetch(): void
    {
        [, $packet] = $this->journal34Source();
        $draft = $this->journal34Prepare($packet);
        $this->journal34Review($draft);
        $this->bankReceipt38Gate();
        $this->expectException(DomainException::class);
        $this->bankReceipt38Post($draft, ['operation_key' => (string) Str::uuid()]);
    }

    public function test_bank_receipt38_requires_permission(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodBankReceiptPostingService::class)->post(
            $draft['operation_key'], $refetch['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_receipt38_importer_cannot_be_posting_operator(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        Gate::define(CheckoutCodBankReceiptPostingService::POST_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3001);
        $this->actor(3001);
        $this->expectException(DomainException::class);
        app(CheckoutCodBankReceiptPostingService::class)->post(
            $draft['operation_key'], $refetch['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_receipt38_refetcher_cannot_be_posting_operator(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        Gate::define(CheckoutCodBankReceiptPostingService::POST_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3601);
        $this->actor(3601);
        $this->expectException(DomainException::class);
        app(CheckoutCodBankReceiptPostingService::class)->post(
            $draft['operation_key'], $refetch['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_receipt38_later_clean_refetch_preserves_posting_status(): void
    {
        [$source, $draft, $refetch] = $this->bankReceipt38Source();
        $this->bankReceipt38Post($draft, $refetch);
        $this->bankRefetch36Run('single', $source);
        $this->actor(1200);
        $status = app(CheckoutCodBankReceiptPostingStatusService::class)
            ->inspect($draft['operation_key']);
        $this->assertSame('bank_credit_posted', $status['status']);
    }

    public function test_bank_receipt38_connector_disabled_blocks_new_posting(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        config()->set('services.privatbank_autoclient.enabled', false);
        $this->expectException(DomainException::class);
        $this->bankReceipt38Post($draft, $refetch);
    }

    public function test_bank_receipt38_posting_requires_both_ledger_sides_at_commit(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $view = app(CheckoutCodBankReceiptPostingEvaluator::class)
            ->inspect($draft['operation_key'], $refetch['operation_key']);
        try {
            DB::transaction(static function () use ($view): void {
                DB::table('checkout_cod_bank_receipt_postings')->insert([
                    'operation_key' => (string) Str::uuid(),
                    'journal_draft_id' => $view['journal_draft_id'],
                    'refetch_id' => $view['refetch_id'],
                    'statement_entry_id' => $view['statement_entry_id'],
                    'source_kind' => $view['source_kind'],
                    'source_id' => $view['source_id'],
                    'reference_hmac' => $view['reference_hmac'],
                    'transaction_hmac' => $view['transaction_hmac'],
                    'row_hmac' => $view['row_hmac'],
                    'account_alias' => $view['account_alias'],
                    'booked_on' => $view['booked_on'],
                    'amount_minor' => $view['amount_minor'],
                    'api_result_sha256' => str_repeat('a', 64),
                    'snapshot_hmac' => $view['snapshot_hmac'],
                    'posted_by' => 3801,
                    'request_hmac' => str_repeat('b', 64),
                    'created_at' => now(),
                ]);
                DB::statement('SET CONSTRAINTS cod_brp_balance_at_commit IMMEDIATE');
            });
            $this->fail('Posting without balanced ledger lines was accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ledger must balance', $e->getMessage());
        }
        $this->assertDatabaseCount('checkout_cod_bank_receipt_postings', 0);
    }

    public function test_bank_receipt38_ledger_line_is_immutable(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $this->bankReceipt38Post($draft, $refetch);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_bank_receipt_ledger_lines')->update(['amount_minor' => 1]);
    }

    public function test_bank_receipt38_posted_receipt_remains_audited_after_new_anomaly(): void
    {
        [$source, $draft, $refetch] = $this->bankReceipt38Source();
        $this->bankReceipt38Post($draft, $refetch);
        $this->refetchRisk37Negative('single', $source);
        $this->actor(1200);
        $state = app(CheckoutCodBankReceiptPostingStatusService::class)
            ->inspect($draft['operation_key']);
        $this->assertSame('posted_bank_credit_requires_investigation', $state['status']);
        $this->assertTrue($state['bank_credit_booked_in_internal_ledger']);
        $this->assertFalse($state['cod_paid']);
    }

    public function test_bank_receipt38_status_reader_permission_required(): void
    {
        [, $draft, $refetch] = $this->bankReceipt38Source();
        $this->bankReceipt38Post($draft, $refetch);
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodBankReceiptPostingStatusService::class)
            ->inspect($draft['operation_key']);
    }
}
