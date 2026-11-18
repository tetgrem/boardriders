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

trait Cod52FinancialTestsPart02
{
    public function test_financial_resolution_review_uuid_replay_is_idempotent(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $proposal = $this->resolutionProposal($incident);

        $key = (string) Str::uuid();

        $first = $this->resolutionReview(
            $proposal,
            'ready_for_external_verification',
            $key
        );

        $this->assertSame(
            $first,
            $this->resolutionReview(
                $proposal,
                'ready_for_external_verification',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_exception_proposal_reviews',
            1
        );
    }

    public function test_financial_resolution_return_for_rework_allows_revised_proposal(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);

        $first = $this->resolutionProposal($incident);

        $this->resolutionReview(
            $first,
            'return_for_rework'
        );

        $this->resolutionEvidence(
            $incident,
            2201,
            'novapay_notice_claim',
            'new-evidence'
        );

        $second = $this->resolutionProposal(
            $incident,
            500
        );

        $this->assertNotSame(
            $first['operation_key'],
            $second['operation_key']
        );

        $this->assertSame(
            3,
            $second['evidence_count']
        );

        $this->assertDatabaseCount(
            'checkout_cod_exception_proposals',
            2
        );

        $this->actor(1200);

        $this->assertSame(
            'proposal_awaiting_independent_review',
            app(CheckoutCodFinancialResolutionStatusService::class)
                ->inspect($incident['exception_key'])['status']
        );
    }

    public function test_financial_resolution_database_evidence_is_immutable(): void
    {
        [, , $incident] = $this->resolutionCase();

        $evidence = $this->resolutionEvidence(
            $incident,
            2201,
            'bank_notice_claim',
            'immutable'
        );

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_exception_evidence')
            ->where('id', $evidence['id'])
            ->update([
                'evidence_kind' => 'internal_reconciliation',
            ]);
    }

    public function test_financial_resolution_queue_is_redacted_and_paginated(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);

        $this->actor(1200);

        $service = app(
            CheckoutCodFinancialResolutionStatusService::class
        );

        $queue = $service->recent(1);

        $this->assertCount(1, $queue['items']);
        $this->assertFalse(
            $queue['funds_received_verified']
        );

        $this->assertSame(
            'evidence_ready_for_proposal',
            $queue['items'][0]['status']
        );

        $json = json_encode(
            $queue,
            JSON_THROW_ON_ERROR
        );

        foreach ([
                     'Петренко',
                     '380671234567',
                     'ttn-gate-test-key',
                 ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $json
            );
        }
    }

    public function test_financial_resolution_read_permission_is_required(): void
    {
        $this->actor(999);

        $this->expectException(
            AuthorizationException::class
        );

        app(CheckoutCodFinancialResolutionStatusService::class)
            ->recent();
    }

    public function test_financial_resolution_reversal_advice_cannot_execute_reversal(): void
    {
        [, , $incident] = $this->resolutionCase(
            true,
            'bank_reversal_reported'
        );

        $this->resolutionTwoSources($incident);

        $proposal = $this->resolutionProposal(
            $incident,
            0,
            'reported_reversal'
        );

        $this->assertSame(
            -680,
            $proposal['delta_minor']
        );

        $this->resolutionReview($proposal);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_financial_resolution_return_followup_does_not_post_refund(): void
    {
        [, , $incident] = $this->resolutionCase(
            true,
            'customer_return_reported'
        );

        $this->resolutionTwoSources($incident);

        $proposal = $this->resolutionProposal(
            $incident,
            680,
            'return_case_followup'
        );

        $this->assertSame(
            0,
            $proposal['delta_minor']
        );

        $this->assertFalse(
            $proposal['refund_executed']
        );
    }

    public function test_financial_resolution_database_rejects_forged_proposal_without_evidence(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_exception_proposals')->insert([
            'operation_key' => (string) Str::uuid(),
            'exception_id' => $incident['id'],
            'recommendation_kind' => 'amount_correction',
            'source_net_minor' => 680,
            'recommended_net_minor' => 600,
            'delta_minor' => -80,
            'evidence_count' => 2,
            'proposed_by' => 2203,
            'request_hmac' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }

    public function test_financial_resolution_reviewer_cannot_be_evidence_collector(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $proposal = $this->resolutionProposal($incident);

        Gate::define(
            CheckoutCodFinancialResolutionService::REVIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2201, 2204],
                true
            )
        );

        $this->actor(2201);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->review(
                $proposal['operation_key'],
                (string) Str::uuid(),
                'ready_for_external_verification',
                'recommendation_documented'
            );
    }

    public function test_financial_resolution_reworked_proposal_old_uuid_replays_after_new_evidence(): void
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

        $this->resolutionReview(
            $first,
            'return_for_rework'
        );

        $this->resolutionEvidence(
            $incident,
            2201,
            'novapay_notice_claim',
            'late'
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
    }

    public function test_financial_resolution_batch_reallocation_remains_a_proposal(): void
    {
        [, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(680);

        $incident = $this->financialExceptionReport(
            'batch',
            $batch,
            'wrong_source_reference'
        );

        $this->financialExceptionReview($incident);
        $this->resolutionTwoSources($incident);

        $proposal = $this->resolutionProposal(
            $incident,
            680,
            'batch_reallocation'
        );

        $this->assertSame(
            0,
            $proposal['delta_minor']
        );

        $this->resolutionReview($proposal);
        $this->actor(1200);

        $this->assertTrue(
            app(CheckoutCodFinancialResolutionStatusService::class)
                ->inspect($incident['exception_key'])['financial_hold']
        );
    }

    public function test_financial_resolution_novapay_claim_can_be_investigated_without_payment(): void
    {
        [, $claim] = $this->bankBatchSource();

        $incident = $this->financialExceptionReport(
            'novapay',
            $claim
        );

        $this->financialExceptionReview($incident);
        $this->resolutionTwoSources($incident);

        $proposal = $this->resolutionProposal(
            $incident,
            620
        );

        $this->assertSame(
            -60,
            $proposal['delta_minor']
        );

        $this->resolutionReview($proposal);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_cod_gateway28_disabled_by_default_is_fail_closed(): void
    {
        config()->set(
            'services.cod_financial_gateway.enabled',
            false
        );

        $this->assertSame(
            false,
            config('services.cod_financial_gateway.enabled')
        );

        $this->postJson(
            '/api/v1/cod/financial-gateway/ingest',
            $this->gateway28Payload()
        )->assertUnauthorized();

        $this->assertDatabaseCount(
            'checkout_cod_gateway_reports',
            0
        );
    }

    public function test_cod_gateway28_accepts_signed_unmatched_report_without_payment(): void
    {
        $response = $this->gateway28Post(
            $this->gateway28Payload()
        );

        $response
            ->assertStatus(202)
            ->assertJsonPath(
                'bank_origin_authenticated',
                false
            )
            ->assertJsonPath(
                'funds_received_verified',
                false
            )
            ->assertJsonPath(
                'cod_paid',
                false
            );

        $this->assertDatabaseCount(
            'checkout_cod_gateway_nonces',
            1
        );

        $this->assertDatabaseCount(
            'checkout_cod_gateway_reports',
            1
        );

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_cod_gateway28_correlates_with_single_cod_bank_claim(): void
    {
        $case = $this->settlementReadyCase();

        $bank = $this->bankSettlementClaim(
            $case,
            680,
            'GW_REF_SINGLE_1001'
        );

        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_SINGLE_1001'
            )
        )->assertStatus(202);

        $this->actor(1200);

        $status = app(
            CheckoutCodFinancialGatewayStatusService::class
        )->inspect(
            'single',
            $bank['operation_key']
        );

        $this->assertSame(
            'gateway_claim_correlated_unverified',
            $status['status']
        );

        $this->assertSame(
            1,
            $status['credit_reports_count']
        );

        $this->assertFalse(
            $status['bank_origin_authenticated']
        );
    }

    public function test_cod_gateway28_correlates_with_batch_claim(): void
    {
        $batch = $this->bankBatchCreate(
            1360,
            'GW_REF_BATCH_1001'
        );

        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_BATCH_1001',
                'credit_reported',
                1360
            )
        )->assertStatus(202);

        $this->actor(1200);

        $status = app(
            CheckoutCodFinancialGatewayStatusService::class
        )->inspect(
            'batch',
            $batch['operation_key']
        );

        $this->assertSame(
            'gateway_claim_correlated_unverified',
            $status['status']
        );

        $this->assertFalse($status['cod_paid']);
    }

    public function test_cod_gateway28_tampered_raw_body_is_rejected(): void
    {
        $data = $this->gateway28Payload();

        $this->gateway28Post(
            $data,
            signedBody: json_encode(
                $data,
                JSON_THROW_ON_ERROR
            ),
            sentBody: json_encode(
                array_merge(
                    $data,
                    ['net_minor' => 681]
                ),
                JSON_THROW_ON_ERROR
            )
        )->assertUnauthorized();

        $this->assertDatabaseCount(
            'checkout_cod_gateway_reports',
            0
        );
    }

    public function test_cod_gateway28_expired_signature_is_rejected(): void
    {
        $this->gateway28Post(
            $this->gateway28Payload(),
            timestamp: now()->timestamp - 301
        )->assertUnauthorized();

        $this->assertDatabaseCount(
            'checkout_cod_gateway_reports',
            0
        );
    }

    public function test_cod_gateway28_future_signature_is_rejected(): void
    {
        $this->gateway28Post(
            $this->gateway28Payload(),
            timestamp: now()->timestamp + 301
        )->assertUnauthorized();
    }

    public function test_cod_gateway28_unknown_key_is_rejected(): void
    {
        $this->gateway28Post(
            $this->gateway28Payload(),
            keyId: 'unknown'
        )->assertUnauthorized();
    }

    public function test_cod_gateway28_replayed_nonce_is_rejected(): void
    {
        $nonce = (string) Str::uuid();
        $data = $this->gateway28Payload();

        $this->gateway28Post(
            $data,
            nonce: $nonce
        )->assertStatus(202);

        $this->gateway28Post(
            $data,
            nonce: $nonce
        )->assertStatus(409);

        $this->assertDatabaseCount(
            'checkout_cod_gateway_nonces',
            1
        );
    }

    public function test_cod_gateway28_same_event_new_nonce_is_idempotent(): void
    {
        $data = $this->gateway28Payload();

        $this->gateway28Post($data)
            ->assertStatus(202)
            ->assertJsonPath('duplicate_event', false);

        $this->gateway28Post($data)
            ->assertStatus(202)
            ->assertJsonPath('duplicate_event', true);

        $this->assertDatabaseCount(
            'checkout_cod_gateway_reports',
            1
        );

        $this->assertDatabaseCount(
            'checkout_cod_gateway_nonces',
            2
        );
    }

    public function test_cod_gateway28_event_uuid_collision_with_new_body_is_rejected(): void
    {
        $data = $this->gateway28Payload();

        $this->gateway28Post($data)
            ->assertStatus(202);

        $data['net_minor'] = 681;

        $this->gateway28Post($data)
            ->assertStatus(409);

        $this->assertDatabaseCount(
            'checkout_cod_gateway_reports',
            1
        );
    }

    public function test_cod_gateway28_secondary_key_rotation_is_supported(): void
    {
        $this->gateway28Post(
            $this->gateway28Payload(),
            keyId: 'secondary'
        )->assertStatus(202);

        $this->assertDatabaseHas(
            'checkout_cod_gateway_reports',
            ['key_id' => 'secondary']
        );
    }

    public function test_cod_gateway28_signed_invalid_amount_is_rejected(): void
    {
        $data = $this->gateway28Payload();
        $data['net_minor'] = -1;

        $this->gateway28Post($data)
            ->assertStatus(422);

        $this->assertDatabaseCount(
            'checkout_cod_gateway_reports',
            0
        );
    }

    public function test_cod_gateway28_amount_discrepancy_is_never_payment_proof(): void
    {
        $case = $this->settlementReadyCase();

        $bank = $this->bankSettlementClaim(
            $case,
            680,
            'GW_REF_DIFF_1001'
        );

        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_DIFF_1001',
                'credit_reported',
                500
            )
        )->assertStatus(202);

        $this->actor(1200);

        $status = app(
            CheckoutCodFinancialGatewayStatusService::class
        )->inspect(
            'single',
            $bank['operation_key']
        );

        $this->assertSame(
            'signed_amount_discrepancy_unverified',
            $status['status']
        );

        $this->assertSame(
            1,
            $status['amount_mismatches_count']
        );

        $this->assertFalse(
            $status['funds_received_verified']
        );
    }

    public function test_cod_gateway28_reversal_blocks_new_single_matching(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();

        $bank = $this->bankSettlementClaim(
            $case,
            680,
            'GW_REF_REV_SINGLE_1'
        );

        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_REV_SINGLE_1',
                'reversal_reported'
            )
        )->assertStatus(202);

        $this->expectException(QueryException::class);

        $this->bankSettlementMatch($claim, $bank);
    }

    public function test_cod_gateway28_reversal_blocks_new_batch_allocation(): void
    {
        [, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate(
            680,
            'GW_REF_REV_BATCH_1'
        );

        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_REV_BATCH_1',
                'reversal_reported'
            )
        )->assertStatus(202);

        $this->expectException(QueryException::class);

        $this->bankBatchAllocate($batch, $claim);
    }

    public function test_cod_gateway28_unmatched_report_can_correlate_later(): void
    {
        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_LATER_1001'
            )
        )->assertStatus(202);

        $case = $this->settlementReadyCase();

        $bank = $this->bankSettlementClaim(
            $case,
            680,
            'GW_REF_LATER_1001'
        );

        $this->actor(1200);

        $status = app(
            CheckoutCodFinancialGatewayStatusService::class
        )->inspect(
            'single',
            $bank['operation_key']
        );

        $this->assertSame(
            1,
            $status['signed_reports_count']
        );

        $this->assertSame(
            'gateway_claim_correlated_unverified',
            $status['status']
        );
    }

    public function test_cod_gateway28_inbox_redacts_external_bank_reference(): void
    {
        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_PRIVATE_1001'
            )
        )->assertStatus(202);

        $this->actor(1200);

        $queue = app(
            CheckoutCodFinancialGatewayStatusService::class
        )->recent();

        $this->assertCount(
            1,
            $queue['items']
        );

        $json = json_encode(
            $queue,
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString(
            'GW_REF_PRIVATE_1001',
            $json
        );

        $this->assertStringNotContainsString(
            'Петренко',
            $json
        );

        $this->assertFalse(
            $queue['automatic_settlement_allowed']
        );
    }

    public function test_cod_gateway28_reader_permissions_are_enforced(): void
    {
        $this->actor(999);

        $this->expectException(
            AuthorizationException::class
        );

        app(
            CheckoutCodFinancialGatewayStatusService::class
        )->recent();
    }

    public function test_cod_gateway28_db_report_history_is_immutable(): void
    {
        $this->gateway28Post(
            $this->gateway28Payload()
        )->assertStatus(202);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_gateway_reports')
            ->update([
                'net_minor' => 2000,
            ]);
    }

    public function test_cod_gateway28_multiple_credit_reports_require_investigation(): void
    {
        $case = $this->settlementReadyCase();

        $bank = $this->bankSettlementClaim(
            $case,
            680,
            'GW_REF_CREDITS_1001'
        );

        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_CREDITS_1001'
            )
        )->assertStatus(202);

        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_CREDITS_1001'
            )
        )->assertStatus(202);

        $this->actor(1200);

        $status = app(
            CheckoutCodFinancialGatewayStatusService::class
        )->inspect(
            'single',
            $bank['operation_key']
        );

        $this->assertSame(
            'multiple_signed_credits_require_investigation',
            $status['status']
        );

        $this->assertFalse(
            $status['cod_paid']
        );
    }

    public function test_cod_gateway28_single_dashboard_surfaces_reversal(): void
    {
        $case = $this->settlementReadyCase();

        $this->bankSettlementClaim(
            $case,
            680,
            'GW_REF_DASH_SINGLE_1'
        );

        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_DASH_SINGLE_1',
                'reversal_reported'
            )
        )->assertStatus(202);

        $this->actor(1200);

        $state = app(
            CheckoutCodSettlementBankStatusService::class
        )->inspect($this->orderKey);

        $this->assertSame(
            'gateway_reversal_reported_requires_investigation',
            $state['status']
        );

        $this->assertTrue(
            $state['gateway_reversal_reported']
        );

        $this->assertFalse(
            $state['funds_received_verified']
        );
    }

    public function test_cod_gateway28_batch_dashboard_surfaces_reversal(): void
    {
        $batch = $this->bankBatchCreate(
            680,
            'GW_REF_DASH_BATCH_1'
        );

        $this->gateway28Post(
            $this->gateway28Payload(
                'GW_REF_DASH_BATCH_1',
                'reversal_reported'
            )
        )->assertStatus(202);

        $this->actor(1200);

        $state = app(
            CheckoutCodBankBatchStatusService::class
        )->inspect(
            $batch['operation_key']
        );

        $this->assertSame(
            'gateway_reversal_reported_requires_investigation',
            $state['status']
        );

        $this->assertTrue(
            $state['gateway_reversal_reported']
        );

        $this->assertFalse(
            $state['cod_paid']
        );
    }
}
