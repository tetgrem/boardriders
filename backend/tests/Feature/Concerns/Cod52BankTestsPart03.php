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

trait Cod52BankTestsPart03
{
    public function test_statement_reconciliation31_new_bank_entry_invalidates_old_proposal(): void
    {
        [$bank, $entry] = $this->statement31Single();
        $p = $this->statement31Propose('single', $bank, $entry);
        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'SECOND_31';
        $date = now('Europe/Kyiv')->subDay()->toDateString();
        $this->actor(3001);
        app(CheckoutCodPrivatStatementImportService::class)->import(
            (string) Str::uuid(), $date, $date
        );
        $this->actor(1200);
        $state = app(CheckoutCodStatementReconciliationStatusService::class)
            ->inspect('single', $bank['operation_key']);
        $this->assertSame('stale_requires_new_investigation', $state['status']);
        $this->actor(3102);
        $this->expectException(DomainException::class);
        app(CheckoutCodStatementReconciliationService::class)->review(
            $p['operation_key'], (string) Str::uuid(), 'acknowledge_comparison'
        );
    }

    public function test_statement_reconciliation31_financial_hold_blocks_acknowledgment(): void
    {
        [$bank, $entry] = $this->statement31Single();
        $p = $this->statement31Propose('single', $bank, $entry);
        $this->financialExceptionReport('single', $bank, 'incorrect_amount');
        $this->actor(3102);
        $this->expectException(DomainException::class);
        app(CheckoutCodStatementReconciliationService::class)->review(
            $p['operation_key'], (string) Str::uuid(), 'acknowledge_comparison'
        );
    }

    public function test_statement_reconciliation31_database_history_immutable(): void
    {
        [$bank, $entry] = $this->statement31Single();
        $this->statement31Propose('single', $bank, $entry);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_statement_reconciliations')->update([
            'claimed_net_minor' => 1,
        ]);
    }

    public function test_statement_reconciliation31_queue_redacted_and_read_permission(): void
    {
        [$bank, $entry] = $this->statement31Single(reference: 'PRIVATE_STMT31_REF');
        $this->statement31Propose('single', $bank, $entry);
        $this->actor(1200);
        $queue = app(CheckoutCodStatementReconciliationStatusService::class)->recent();
        $this->assertCount(1, $queue['items']);
        $json = json_encode($queue, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE_STMT31_REF', $json);
        $this->assertStringNotContainsString('UA111111111111111111111111111', $json);
        $this->assertFalse($queue['automatic_payment_posting_allowed']);
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodStatementReconciliationStatusService::class)->recent();
    }

    public function test_statement_reconciliation31_new_evidence_creates_append_only_revision(): void
    {
        [$bank, $entry] = $this->statement31Single(reference: 'STMT31_REVISE_REF');
        $first = $this->statement31Propose('single', $bank, $entry);
        $this->statement31Review($first);

        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'NEW_VERSION_31';
        $day = now('Europe/Kyiv')->subDay()->toDateString();
        $this->actor(3001);
        app(CheckoutCodPrivatStatementImportService::class)->import(
            (string) Str::uuid(), $day, $day
        );

        $second = $this->statement31Propose('single', $bank, $entry);
        $this->assertSame('evidence_conflict', $second['result_code']);
        $this->assertNotSame($first['reconciliation_id'], $second['reconciliation_id']);
        $this->assertDatabaseCount('checkout_cod_statement_reconciliations', 2);
        $this->assertDatabaseCount('checkout_cod_statement_reconciliation_reviews', 1);

        $this->actor(1200);
        $state = app(CheckoutCodStatementReconciliationStatusService::class)
            ->inspect('single', $bank['operation_key']);
        $this->assertSame($second['reconciliation_id'], $state['reconciliation_id']);
        $this->assertSame('discrepancy_awaiting_review', $state['status']);
    }

    public function test_statement_reconciliation31_import_author_cannot_review(): void
    {
        [$bank, $entry] = $this->statement31Single();
        $p = $this->statement31Propose('single', $bank, $entry);
        Gate::define(CheckoutCodStatementReconciliationService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3001);
        $this->actor(3001);
        $this->expectException(DomainException::class);
        app(CheckoutCodStatementReconciliationService::class)->review(
            $p['operation_key'], (string) Str::uuid(), 'acknowledge_comparison'
        );
    }

    public function test_posting_preflight32_single_order_balanced(): void
    {
        [, $proposal] = $this->posting32Source();

        $preflight = $this->posting32Prepare($proposal);

        $this->assertSame(2000, $preflight['cod_gross_minor']);
        $this->assertSame(1320, $preflight['fee_minor']);
        $this->assertSame(
            680,
            $preflight['bank_allocation_minor']
        );
        $this->assertFalse($preflight['payment_posting_allowed']);

        $review = $this->posting32Review($preflight);

        $this->assertSame(
            'retain_unverified',
            $review['decision']
        );
        $this->assertFalse($review['cod_paid']);

        $this->actor(1200);

        $status = app(
            CheckoutCodPostingPreflightStatusService::class
        )->inspect($this->orderKey);

        $this->assertSame(
            'reviewed_external_payment_evidence_required',
            $status['status']
        );
        $this->assertTrue($status['snapshot_current']);
        $this->assertFalse($status['payment_posting_allowed']);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_posting_preflight32_batch_order_balanced(): void
    {
        [, $proposal] = $this->posting32Source('batch');

        $preflight = $this->posting32Prepare($proposal);

        $this->assertSame(
            'batch',
            $preflight['source_kind']
        );
        $this->assertSame(2000, $preflight['cod_gross_minor']);
        $this->assertSame(680, $preflight['bank_allocation_minor']);

        $this->posting32Review($preflight);

        $this->actor(1200);

        $status = app(
            CheckoutCodPostingPreflightStatusService::class
        )->inspect($this->orderKey);

        $this->assertSame(
            'reviewed_external_payment_evidence_required',
            $status['status']
        );
    }

    public function test_posting_preflight32_requires_reviewed_reconciliation(): void
    {
        [$bank, $entry] = $this->statement31Single();

        $proposal = $this->statement31Propose(
            'single',
            $bank,
            $entry
        );

        $this->posting32Gate();

        $this->expectException(DomainException::class);

        $this->posting32Prepare($proposal);
    }

    public function test_posting_preflight32_cannot_use_another_order(): void
    {
        [, $proposal] = $this->posting32Source();

        $otherOrder = $this->makeCodOrder();

        $this->expectException(DomainException::class);

        $this->posting32Prepare(
            $proposal,
            $otherOrder
        );
    }

    public function test_posting_preflight32_prepare_requires_permission(): void
    {
        [, $proposal] = $this->posting32Source();

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(
            CheckoutCodPostingPreflightService::class
        )->prepare(
            $proposal['operation_key'],
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_posting_preflight32_author_must_be_independent(): void
    {
        [, $proposal] = $this->posting32Source();

        Gate::define(
            CheckoutCodPostingPreflightService::PREPARE_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 1901
        );

        $this->actor(1901);

        $this->expectException(DomainException::class);

        app(
            CheckoutCodPostingPreflightService::class
        )->prepare(
            $proposal['operation_key'],
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_posting_preflight32_reviewer_must_be_independent(): void
    {
        [, $proposal] = $this->posting32Source();

        $preflight = $this->posting32Prepare($proposal);

        Gate::define(
            CheckoutCodPostingPreflightService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3201
        );

        $this->actor(3201);

        $this->expectException(DomainException::class);

        app(
            CheckoutCodPostingPreflightService::class
        )->review(
            $preflight['operation_key'],
            (string) Str::uuid(),
            'retain_unverified'
        );
    }

    public function test_posting_preflight32_prepare_replay_is_idempotent(): void
    {
        [, $proposal] = $this->posting32Source();

        $operationKey = (string) Str::uuid();

        $first = $this->posting32Prepare(
            $proposal,
            operationKey: $operationKey
        );

        $second = $this->posting32Prepare(
            $proposal,
            operationKey: $operationKey
        );

        $this->assertSame(
            $first['preflight_id'],
            $second['preflight_id']
        );

        $this->assertTrue($second['idempotent_replay']);

        $this->assertDatabaseCount(
            'checkout_cod_posting_preflights',
            1
        );
    }

    public function test_posting_preflight32_duplicate_order_revision_rejected(): void
    {
        [, $proposal] = $this->posting32Source();

        $this->posting32Prepare($proposal);

        $this->expectException(DomainException::class);

        $this->posting32Prepare($proposal);
    }

    public function test_posting_preflight32_review_replay_is_idempotent(): void
    {
        [, $proposal] = $this->posting32Source();

        $preflight = $this->posting32Prepare($proposal);

        $key = (string) Str::uuid();

        $first = $this->posting32Review(
            $preflight,
            reviewKey: $key
        );

        $second = $this->posting32Review(
            $preflight,
            reviewKey: $key
        );

        $this->assertSame(
            $first['review_id'],
            $second['review_id']
        );

        $this->assertTrue($second['idempotent_replay']);
    }

    public function test_posting_preflight32_new_bank_entry_invalidates_review(): void
    {
        [, $proposal] = $this->posting32Source();

        $preflight = $this->posting32Prepare($proposal);

        $this->privatStatement30Fixture['transactions'][0]['REFN']
            = 'SECOND_POST32';

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
            CheckoutCodPostingPreflightStatusService::class
        )->inspect($this->orderKey);

        $this->assertSame(
            'posting_preflight_stale',
            $status['status']
        );

        $this->actor(3202);

        $this->expectException(DomainException::class);

        app(
            CheckoutCodPostingPreflightService::class
        )->review(
            $preflight['operation_key'],
            (string) Str::uuid(),
            'retain_unverified'
        );
    }

    public function test_posting_preflight32_financial_hold_invalidates_review(): void
    {
        [$bank, $proposal] = $this->posting32Source();

        $preflight = $this->posting32Prepare($proposal);

        $this->financialExceptionReport(
            'single',
            $bank,
            'incorrect_amount'
        );

        $this->actor(3202);

        $this->expectException(DomainException::class);

        app(
            CheckoutCodPostingPreflightService::class
        )->review(
            $preflight['operation_key'],
            (string) Str::uuid(),
            'retain_unverified'
        );
    }

    public function test_posting_preflight32_database_history_is_immutable(): void
    {
        [, $proposal] = $this->posting32Source();

        $this->posting32Prepare($proposal);

        $this->expectException(QueryException::class);

        DB::table(
            'checkout_cod_posting_preflights'
        )->update([
            'cod_gross_minor' => 1,
        ]);
    }

    public function test_posting_preflight32_queue_redacted_and_permissioned(): void
    {
        [, $proposal] = $this->posting32Source();

        $this->posting32Prepare($proposal);

        $this->actor(1200);

        $queue = app(
            CheckoutCodPostingPreflightStatusService::class
        )->recent();

        $this->assertCount(1, $queue['items']);

        $json = json_encode(
            $queue,
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString(
            'UA111111111111111111111111111',
            $json
        );

        $this->assertFalse(
            $queue['automatic_payment_posting_allowed']
        );

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(
            CheckoutCodPostingPreflightStatusService::class
        )->recent();
    }

    public function test_bank_coverage33_single_packet_balances_and_keeps_order_pending(): void
    {
        [$source] = $this->coverage33Source();
        $packet = $this->coverage33Compile('single', $source);
        $this->assertSame(1, $packet['expected_orders_count']);
        $this->assertSame(2000, $packet['gross_minor']);
        $this->assertSame(1320, $packet['fee_minor']);
        $this->assertSame(680, $packet['allocated_minor']);
        $this->assertFalse($packet['payment_posting_allowed']);
        $review = $this->coverage33Review($packet);
        $this->assertSame('retain_unverified', $review['decision']);
        $this->assertFalse($review['cod_paid']);
        $this->assertDatabaseCount('checkout_cod_bank_coverage_lines', 1);
        $this->actor(1200);
        $s = app(CheckoutCodBankCoverageStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('coverage_reviewed_external_confirmation_required', $s['status']);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_bank_coverage33_single_order_batch_balances(): void
    {
        [$source] = $this->coverage33Source('batch');
        $packet = $this->coverage33Compile('batch', $source);
        $this->assertSame(680, $packet['bank_net_minor']);
        $this->coverage33Review($packet);
        $this->assertDatabaseCount('checkout_cod_bank_coverage_lines', 1);
    }

    public function test_bank_coverage33_multiorder_batch_requires_all_preflights(): void
    {
        [$batch] = $this->coverage33TwoOrderBatch(false);
        $this->actor(1200);
        $status = app(CheckoutCodBankCoverageStatusService::class)
            ->inspect('batch', $batch['operation_key']);
        $this->assertSame(2, $status['expected_orders_count']);
        $this->assertSame(1, $status['ready_orders_count']);
        $this->assertSame('preflight_coverage_incomplete', $status['result_code']);
        $this->expectException(DomainException::class);
        $this->coverage33Compile('batch', $batch);
    }

    public function test_bank_coverage33_multiorder_batch_compiles_two_lines(): void
    {
        [$batch] = $this->coverage33TwoOrderBatch(true);
        $packet = $this->coverage33Compile('batch', $batch);
        $this->assertSame(2, $packet['expected_orders_count']);
        $this->assertSame(4000, $packet['gross_minor']);
        $this->assertSame(2640, $packet['fee_minor']);
        $this->assertSame(1360, $packet['allocated_minor']);
        $this->assertDatabaseCount('checkout_cod_bank_coverage_lines', 2);
        $this->coverage33Review($packet);
        $this->actor(1200);
        $status = app(CheckoutCodBankCoverageStatusService::class)
            ->inspect('batch', $batch['operation_key']);
        $this->assertSame(2, $status['ready_orders_count']);
        $this->assertSame('coverage_reviewed_external_confirmation_required', $status['status']);
    }

    public function test_bank_coverage33_compile_requires_permission(): void
    {
        [$source] = $this->coverage33Source();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodBankCoverageService::class)->compile(
            'single', $source['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_coverage33_compiler_cannot_be_preflight_author(): void
    {
        [$source] = $this->coverage33Source();
        Gate::define(CheckoutCodBankCoverageService::COMPILE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3201);
        $this->actor(3201);
        $this->expectException(DomainException::class);
        app(CheckoutCodBankCoverageService::class)->compile(
            'single', $source['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_coverage33_reviewer_cannot_be_compiler(): void
    {
        [$source] = $this->coverage33Source();
        $packet = $this->coverage33Compile('single', $source);
        Gate::define(CheckoutCodBankCoverageService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3301);
        $this->actor(3301);
        $this->expectException(DomainException::class);
        app(CheckoutCodBankCoverageService::class)->review(
            $packet['operation_key'], (string) Str::uuid(), 'retain_unverified'
        );
    }

    public function test_bank_coverage33_reviewer_cannot_be_bank_importer(): void
    {
        [$source] = $this->coverage33Source();
        $packet = $this->coverage33Compile('single', $source);
        Gate::define(CheckoutCodBankCoverageService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3001);
        $this->actor(3001);
        $this->expectException(DomainException::class);
        app(CheckoutCodBankCoverageService::class)->review(
            $packet['operation_key'], (string) Str::uuid(), 'retain_unverified'
        );
    }

    public function test_bank_coverage33_compile_replay_idempotent(): void
    {
        [$source] = $this->coverage33Source();
        $key = (string) Str::uuid();
        $a = $this->coverage33Compile('single', $source, $key);
        $b = $this->coverage33Compile('single', $source, $key);
        $this->assertSame($a['packet_id'], $b['packet_id']);
        $this->assertTrue($b['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_bank_coverage_packets', 1);
    }

    public function test_bank_coverage33_second_packet_same_snapshot_rejected(): void
    {
        [$source] = $this->coverage33Source();
        $this->coverage33Compile('single', $source);
        $this->expectException(DomainException::class);
        $this->coverage33Compile('single', $source);
    }

    public function test_bank_coverage33_review_replay_idempotent(): void
    {
        [$source] = $this->coverage33Source();
        $packet = $this->coverage33Compile('single', $source);
        $key = (string) Str::uuid();
        $a = $this->coverage33Review($packet, $key);
        $b = $this->coverage33Review($packet, $key);
        $this->assertSame($a['review_id'], $b['review_id']);
        $this->assertTrue($b['idempotent_replay']);
    }

    public function test_bank_coverage33_new_bank_credit_invalidates_packet(): void
    {
        [$source] = $this->coverage33Source();
        $packet = $this->coverage33Compile('single', $source);
        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'BANK33_SECOND';
        $day = now('Europe/Kyiv')->subDay()->toDateString();
        $this->actor(3001);
        app(CheckoutCodPrivatStatementImportService::class)->import(
            (string) Str::uuid(), $day, $day
        );
        $this->actor(1200);
        $status = app(CheckoutCodBankCoverageStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('coverage_stale_requires_recheck', $status['status']);
        $this->actor(3302);
        $this->expectException(DomainException::class);
        app(CheckoutCodBankCoverageService::class)->review(
            $packet['operation_key'], (string) Str::uuid(), 'retain_unverified'
        );
    }

    public function test_bank_coverage33_financial_hold_blocks_new_packet(): void
    {
        [$source] = $this->coverage33Source();
        $this->financialExceptionReport('single', $source, 'incorrect_amount');
        $this->expectException(DomainException::class);
        $this->coverage33Compile('single', $source);
    }

    public function test_bank_coverage33_audit_lines_immutable(): void
    {
        [$source] = $this->coverage33Source();
        $this->coverage33Compile('single', $source);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_bank_coverage_lines')->update(['allocated_minor' => 1]);
    }

    public function test_bank_coverage33_queue_redacted_and_permissioned(): void
    {
        [$source] = $this->coverage33Source();
        $this->coverage33Compile('single', $source);
        $this->actor(1200);
        $queue = app(CheckoutCodBankCoverageStatusService::class)->recent();
        $this->assertCount(1, $queue['items']);
        $encoded = json_encode($queue, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('STMT31_SINGLE_REF', $encoded);
        $this->assertStringNotContainsString('UA111111111111111111111111111', $encoded);
        $this->assertFalse($queue['automatic_payment_posting_allowed']);
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodBankCoverageStatusService::class)->recent();
    }

    public function test_journal_draft34_single_order_three_balanced_entries(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        $this->assertSame(1, $draft['orders_count']);
        $this->assertSame(3, $draft['entry_count']);
        $this->assertSame(2000, $draft['gross_minor']);
        $this->assertSame(1320, $draft['fee_minor']);
        $this->assertSame(680, $draft['bank_minor']);

        $this->assertDatabaseCount(
            'checkout_cod_journal_draft_entries',
            3
        );

        $review = $this->journal34Review($draft);

        $this->assertSame('retain_unposted', $review['decision']);
        $this->assertFalse($review['payment_posting_allowed']);

        $this->actor(1200);

        $status = app(
            CheckoutCodJournalDraftStatusService::class
        )->inspect($packet['operation_key']);

        $this->assertSame(
            'reviewed_draft_external_evidence_required',
            $status['status']
        );

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_journal_draft34_single_order_bank_batch(): void
    {
        [, $packet] = $this->journal34Source('batch');

        $draft = $this->journal34Prepare($packet);

        $this->assertSame('batch', $draft['source_kind']);
        $this->assertSame(3, $draft['entry_count']);

        $this->journal34Review($draft);
    }

    public function test_journal_draft34_two_order_batch_has_six_entries(): void
    {
        [, $packet] = $this->journal34MultiOrderSource();

        $draft = $this->journal34Prepare($packet);

        $this->assertSame(2, $draft['orders_count']);
        $this->assertSame(6, $draft['entry_count']);
        $this->assertSame(4000, $draft['gross_minor']);
        $this->assertSame(2640, $draft['fee_minor']);
        $this->assertSame(1360, $draft['bank_minor']);

        $this->assertDatabaseCount(
            'checkout_cod_journal_draft_entries',
            6
        );

        $this->journal34Review($draft);
    }

    public function test_journal_draft34_unreviewed_coverage_cannot_be_prepared(): void
    {
        [$source] = $this->coverage33Source();

        $packet = $this->coverage33Compile('single', $source);

        $this->journal34Gate();

        $this->expectException(DomainException::class);

        $this->journal34Prepare($packet);
    }

    public function test_journal_draft34_compiler_cannot_prepare(): void
    {
        [, $packet] = $this->journal34Source();

        Gate::define(
            CheckoutCodJournalDraftService::PREPARE_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3301
        );

        $this->actor(3301);

        $this->expectException(DomainException::class);

        app(CheckoutCodJournalDraftService::class)->prepare(
            $packet['operation_key'],
            (string) Str::uuid()
        );
    }
}
