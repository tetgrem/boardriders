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

trait Cod52SettlementTestsPart01
{
    public function test_settlement_case_requires_cod_financial_preflight(): void
    {
        $this->actor(1801);

        $this->expectException(DomainException::class);

        app(CheckoutCodSettlementCaseService::class)->open(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_settlement_case_pins_expected_cod_without_payment(): void
    {
        $case = $this->settlementReadyCase();

        $this->assertSame(2000, $case['expected_cod_minor']);
        $this->assertSame('UAH', $case['currency_code']);
        $this->assertFalse($case['cod_paid']);

        $this->assertDatabaseCount(
            'checkout_cod_settlement_cases',
            1
        );

        $orderId = DB::table('orders')
            ->where('order_key', $this->orderKey)
            ->value('id');

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('id', $orderId)
                ->value('payment_status')
        );
    }

    public function test_settlement_case_exact_retry_is_idempotent(): void
    {
        $case = $this->settlementReadyCase();

        $this->assertSame(
            $case,
            app(CheckoutCodSettlementCaseService::class)->open(
                $this->orderKey,
                $case['case_key']
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_settlement_cases',
            1
        );
    }

    public function test_settlement_case_cannot_be_replaced(): void
    {
        $this->settlementReadyCase();

        $this->expectException(DomainException::class);

        app(CheckoutCodSettlementCaseService::class)->open(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_settlement_matching_observation_is_not_payment_proof(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);

        $this->assertSame(
            'consistent_with_estimate',
            $claim['comparison_result']
        );

        $this->assertFalse($claim['funds_received_verified']);
        $this->assertFalse($claim['cod_paid']);

        $this->assertDatabaseCount(
            'checkout_cod_settlement_observations',
            1
        );
    }

    public function test_settlement_observation_replay_is_idempotent(): void
    {
        $case = $this->settlementReadyCase();

        $key = (string) Str::uuid();
        $reference = 'STATEMENT_REPLAY_1001';

        $this->assertSame(
            $this->settlementClaim(
                $case['case_key'],
                operationKey: $key,
                reference: $reference
            ),
            $this->settlementClaim(
                $case['case_key'],
                operationKey: $key,
                reference: $reference
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_settlement_observations',
            1
        );
    }

    public function test_settlement_duplicate_reference_with_new_key_is_blocked(): void
    {
        $case = $this->settlementReadyCase();

        $this->settlementClaim(
            $case['case_key'],
            reference: 'STATEMENT_DUPLICATE_1001'
        );

        $this->expectException(DomainException::class);

        $this->settlementClaim(
            $case['case_key'],
            reference: 'STATEMENT_DUPLICATE_1001'
        );
    }

    public function test_settlement_gross_mismatch_enters_discrepancy_queue(): void
    {
        $case = $this->settlementReadyCase();

        $claim = $this->settlementClaim(
            $case['case_key'],
            gross: 2500,
            fee: 1320,
            net: 1180
        );

        $this->assertSame(
            'gross_mismatch',
            $claim['comparison_result']
        );

        $this->actor(1200);

        $status = app(CheckoutCodSettlementStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'financial_discrepancy',
            $status['status']
        );

        $this->assertSame(1, $status['discrepancies_count']);
        $this->assertFalse($status['funds_received_verified']);
    }

    public function test_settlement_independent_review_keeps_claim_unverified(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);

        $this->actor(1803);

        $review = app(CheckoutCodSettlementReviewService::class)
            ->review(
                $claim['operation_key'],
                (string) Str::uuid(),
                'retain_unverified',
                'external_confirmation_required'
            );

        $this->assertFalse($review['funds_received_verified']);
        $this->assertFalse($review['cod_paid']);

        $this->actor(1200);

        $status = app(CheckoutCodSettlementStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'claims_reviewed_external_confirmation_required',
            $status['status']
        );
    }

    public function test_settlement_author_cannot_review_own_claim(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);

        Gate::define(
            CheckoutCodSettlementReviewService::REVIEW_ABILITY,
            static fn ($user): bool => in_array(
                (int) $user->getAuthIdentifier(),
                [1802, 1803],
                true
            )
        );

        $this->expectException(DomainException::class);

        app(CheckoutCodSettlementReviewService::class)->review(
            $claim['operation_key'],
            (string) Str::uuid(),
            'retain_unverified',
            'external_confirmation_required'
        );
    }

    public function test_settlement_direct_database_update_is_rejected(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_settlement_observations')
            ->where('id', $claim['id'])
            ->update([
                'claimed_gross_minor' => 999999,
            ]);
    }

    public function test_settlement_queue_has_no_sensitive_references(): void
    {
        $case = $this->settlementReadyCase();

        $this->settlementClaim(
            $case['case_key'],
            reference: 'STATEMENT_SUPERSECRET_2001'
        );

        $this->actor(1200);

        $queue = app(CheckoutCodSettlementQueueService::class)
            ->recent();

        $this->assertCount(1, $queue['items']);

        $json = json_encode($queue, JSON_THROW_ON_ERROR);

        foreach ([
                     'Петренко',
                     '380671234567',
                     'STATEMENT_SUPERSECRET_2001',
                     'ttn-gate-test-key',
                 ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $json
            );
        }

        $this->assertFalse($queue['cod_paid']);
    }

    public function test_settlement_unauthorized_observation_is_blocked(): void
    {
        $case = $this->settlementReadyCase();

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodSettlementObservationService::class)->record(
            $case['case_key'],
            (string) Str::uuid(),
            'manual_claim',
            'STATEMENT_FORBIDDEN_3001',
            2000,
            1320,
            680
        );
    }
}
