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

trait Cod52BankTestsPart02
{
    public function test_bank_batch_single_match_cannot_reuse_batch_allocated_claim(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate();
        $this->bankBatchAllocate($batch, $claim);

        $this->actor(1901);

        $bank = app(CheckoutCodSettlementBankReceiptService::class)
            ->record(
                $case['case_key'],
                (string) Str::uuid(),
                'BANKREF_PATH24_1002',
                680
            );

        $this->actor(1902);

        $this->expectException(QueryException::class);

        app(CheckoutCodSettlementBankMatchService::class)->propose(
            $claim['operation_key'],
            $bank['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_bank_batch_history_is_immutable_at_database_level(): void
    {
        $batch = $this->bankBatchCreate();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_bank_batches')
            ->where('id', $batch['id'])
            ->update([
                'claimed_net_minor' => 2000,
            ]);
    }

    public function test_bank_batch_queue_is_pii_free_and_permission_checked(): void
    {
        $batch = $this->bankBatchCreate(
            680,
            'BATCHREF_PRIVATE_1001'
        );

        $this->actor(1200);

        $queue = app(CheckoutCodBankBatchQueueService::class)
            ->recent();

        $this->assertCount(1, $queue['items']);

        $json = json_encode($queue, JSON_THROW_ON_ERROR);

        foreach ([
                     'BATCHREF_PRIVATE_1001',
                     'Петренко',
                     '380671234567',
                     'ttn-gate-test-key',
                 ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $json
            );
        }

        $this->assertFalse($queue['cod_paid']);

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodBankBatchQueueService::class)->recent();
    }

    public function test_cod_bank_evidence29_disabled_source_rejected(): void
    {
        config()->set('services.cod_bank_evidence.enabled', false);
        $this->postJson('/api/v1/cod/bank-evidence/ingest',
            $this->bankEvidence29Payload())->assertUnauthorized();
        $this->assertDatabaseCount('checkout_cod_bank_evidence_events', 0);
    }

    public function test_cod_bank_evidence29_valid_signed_event_is_not_payment(): void
    {
        $this->bankEvidence29Post($this->bankEvidence29Payload())
            ->assertStatus(202)
            ->assertJsonPath('provider_signature_verified', true)
            ->assertJsonPath('funds_received_verified', false)
            ->assertJsonPath('cod_paid', false);
        $this->assertDatabaseCount('checkout_cod_bank_evidence_events', 1);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_cod_bank_evidence29_correlates_single_claim(): void
    {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case, 680, 'BANKREF_PROOF_SINGLE_29');
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_PROOF_SINGLE_29'))
            ->assertStatus(202);
        $this->actor(1200);
        $state = app(CheckoutCodBankEvidenceStatusService::class)
            ->inspect('single', $bank['operation_key']);
        $this->assertSame('signature_correlated_payment_unverified', $state['status']);
        $this->assertSame(1, $state['credit_events_count']);
        $this->assertFalse($state['cod_paid']);
    }

    public function test_cod_bank_evidence29_correlates_batch_claim(): void
    {
        $batch = $this->bankBatchCreate(1360, 'BANKREF_PROOF_BATCH_29');
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_PROOF_BATCH_29', 'credit', 1360))
            ->assertStatus(202);
        $this->actor(1200);
        $this->assertSame('signature_correlated_payment_unverified',
            app(CheckoutCodBankEvidenceStatusService::class)
                ->inspect('batch', $batch['operation_key'])['status']);
    }

    public function test_cod_bank_evidence29_signed_message_tamper_rejected(): void
    {
        $data = $this->bankEvidence29Payload();
        $signed = json_encode($data, JSON_THROW_ON_ERROR);
        $changed = json_encode(array_merge($data, ['amount_minor' => 681]), JSON_THROW_ON_ERROR);
        $this->bankEvidence29Post($data, signedBody: $signed, sentBody: $changed)
            ->assertUnauthorized();
        $this->assertDatabaseCount('checkout_cod_bank_evidence_events', 0);
    }

    public function test_cod_bank_evidence29_expired_envelope_rejected(): void
    {
        $this->bankEvidence29Post($this->bankEvidence29Payload(),
            timestamp: now()->timestamp - 301)->assertUnauthorized();
    }

    public function test_cod_bank_evidence29_wrong_provider_rejected(): void
    {
        $this->bankEvidence29Post($this->bankEvidence29Payload(),
            providerId: 'unknown_provider')->assertUnauthorized();
    }

    public function test_cod_bank_evidence29_unknown_key_rejected(): void
    {
        $this->bankEvidence29Post($this->bankEvidence29Payload(),
            keyId: 'unknown')->assertUnauthorized();
    }

    public function test_cod_bank_evidence29_replayed_nonce_rejected(): void
    {
        $payload = $this->bankEvidence29Payload();
        $nonce = (string) Str::uuid();
        $this->bankEvidence29Post($payload, nonce: $nonce)->assertStatus(202);
        $this->bankEvidence29Post($payload, nonce: $nonce)->assertStatus(409);
        $this->assertDatabaseCount('checkout_cod_bank_evidence_nonces', 1);
    }

    public function test_cod_bank_evidence29_same_event_new_nonce_idempotent(): void
    {
        $payload = $this->bankEvidence29Payload();
        $this->bankEvidence29Post($payload)->assertJsonPath('duplicate_event', false);
        $this->bankEvidence29Post($payload)->assertJsonPath('duplicate_event', true);
        $this->assertDatabaseCount('checkout_cod_bank_evidence_events', 1);
        $this->assertDatabaseCount('checkout_cod_bank_evidence_nonces', 2);
    }

    public function test_cod_bank_evidence29_conflicting_event_uuid_rejected(): void
    {
        $payload = $this->bankEvidence29Payload();
        $this->bankEvidence29Post($payload)->assertStatus(202);
        $payload['amount_minor'] = 681;
        $this->bankEvidence29Post($payload)->assertStatus(409);
        $this->assertDatabaseCount('checkout_cod_bank_evidence_events', 1);
    }

    public function test_cod_bank_evidence29_same_bank_transaction_new_uuid_rejected(): void
    {
        $payload = $this->bankEvidence29Payload();
        $this->bankEvidence29Post($payload)->assertStatus(202);
        $payload['event_key'] = (string) Str::uuid();
        $this->bankEvidence29Post($payload)->assertStatus(409);
        $this->assertDatabaseCount('checkout_cod_bank_evidence_events', 1);
    }

    public function test_cod_bank_evidence29_key_rotation_previous_supported(): void
    {
        $this->bankEvidence29Post($this->bankEvidence29Payload(),
            keyId: 'previous')->assertStatus(202);
        $this->assertDatabaseHas('checkout_cod_bank_evidence_events',
            ['key_id' => 'previous']);
    }

    public function test_cod_bank_evidence29_amount_discrepancy_blocks_match(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case, 680, 'BANKREF_DIFF_SIGNED_29');
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_DIFF_SIGNED_29', 'credit', 500))
            ->assertStatus(202);
        $this->actor(1200);
        $status = app(CheckoutCodBankEvidenceStatusService::class)
            ->inspect('single', $bank['operation_key']);
        $this->assertSame('signed_amount_discrepancy', $status['status']);
        $this->expectException(QueryException::class);
        $this->bankSettlementMatch($claim, $bank);
    }

    public function test_cod_bank_evidence29_signed_reversal_blocks_single_matching(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case, 680, 'BANKREF_REV_SINGLE_29');
        $payload = $this->bankEvidence29Payload('BANKREF_REV_SINGLE_29', 'reversal', 680,
            reversedId: 'BANKTX_ORIGINAL_29');
        $this->bankEvidence29Post($payload)->assertStatus(202);
        $this->expectException(QueryException::class);
        $this->bankSettlementMatch($claim, $bank);
    }

    public function test_cod_bank_evidence29_signed_reversal_blocks_batch_allocation(): void
    {
        [, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(680, 'BANKREF_REV_BATCH_29');
        $payload = $this->bankEvidence29Payload('BANKREF_REV_BATCH_29', 'reversal', 680,
            reversedId: 'BANKTX_ORIGINAL_BATCH_29');
        $this->bankEvidence29Post($payload)->assertStatus(202);
        $this->expectException(QueryException::class);
        $this->bankBatchAllocate($batch, $claim);
    }

    public function test_cod_bank_evidence29_multiple_credits_block_match(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case, 680, 'BANKREF_MULTI_CREDIT_29');
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_MULTI_CREDIT_29'))
            ->assertStatus(202);
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_MULTI_CREDIT_29'))
            ->assertStatus(202);
        $this->expectException(QueryException::class);
        $this->bankSettlementMatch($claim, $bank);
    }

    public function test_cod_bank_evidence29_unmatched_then_late_bank_claim_correlates(): void
    {
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_LATE_29'))
            ->assertStatus(202);
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case, 680, 'BANKREF_LATE_29');
        $this->actor(1200);
        $this->assertSame(1, app(CheckoutCodBankEvidenceStatusService::class)
            ->inspect('single', $bank['operation_key'])['events_count']);
    }

    public function test_cod_bank_evidence29_queue_redacts_transaction_references(): void
    {
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_PRIVATE_29',
            transactionId: 'BANKTX_PRIVATE_29'))->assertStatus(202);
        $this->actor(1200);
        $queue = app(CheckoutCodBankEvidenceStatusService::class)->recent();
        $json = json_encode($queue, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $queue['items']);
        $this->assertStringNotContainsString('BANKREF_PRIVATE_29', $json);
        $this->assertStringNotContainsString('BANKTX_PRIVATE_29', $json);
        $this->assertFalse($queue['automatic_settlement_allowed']);
    }

    public function test_cod_bank_evidence29_read_permission_enforced(): void
    {
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodBankEvidenceStatusService::class)->recent();
    }

    public function test_cod_bank_evidence29_history_immutable(): void
    {
        $this->bankEvidence29Post($this->bankEvidence29Payload())->assertStatus(202);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_bank_evidence_events')->update(['amount_minor' => 1]);
    }

    public function test_cod_bank_evidence29_order_remains_unpaid_after_valid_credit(): void
    {
        $case = $this->settlementReadyCase();
        $this->bankSettlementClaim($case, 680, 'BANKREF_PAY_SAFE_29');
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_PAY_SAFE_29'))
            ->assertStatus(202)->assertJsonPath('refund_executed', false);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_cod_bank_evidence29_reversal_surfaces_in_single_dashboard(): void
    {
        $case = $this->settlementReadyCase();
        $this->bankSettlementClaim($case, 680, 'BANKREF_DASH_SINGLE_29');
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_DASH_SINGLE_29',
            'reversal', 680, reversedId: 'BANKTX_DASH_OLD_29'))->assertStatus(202);
        $this->actor(1200);
        $status = app(CheckoutCodSettlementBankStatusService::class)->inspect($this->orderKey);
        $this->assertSame('bank_evidence_requires_investigation', $status['status']);
        $this->assertTrue($status['bank_evidence_alert']);
    }

    public function test_cod_bank_evidence29_reversal_surfaces_in_batch_dashboard(): void
    {
        $batch = $this->bankBatchCreate(680, 'BANKREF_DASH_BATCH_29');
        $this->bankEvidence29Post($this->bankEvidence29Payload('BANKREF_DASH_BATCH_29',
            'reversal', 680, reversedId: 'BANKTX_DASH_B_29'))->assertStatus(202);
        $this->actor(1200);
        $status = app(CheckoutCodBankBatchStatusService::class)
            ->inspect($batch['operation_key']);
        $this->assertSame('bank_evidence_requires_investigation', $status['status']);
        $this->assertTrue($status['bank_evidence_alert']);
    }

    public function test_privat_statement30_conflicting_amount_blocks_cod_single_match(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();

        $bank = $this->bankSettlementClaim(
            $case,
            680,
            'BANKREF_COD_SYNC_30'
        );

        // Statement reports 5.00 UAH, claim expects 6.80 UAH.
        $this->privatStatement30Import(
            'BANKREF_COD_SYNC_30',
            '5.00'
        );

        $this->actor(1200);

        $state = app(
            CheckoutCodSettlementBankStatusService::class
        )->inspect($this->orderKey);

        $this->assertTrue(
            $state['privatbank_statement_alert']
        );

        $this->assertSame(
            'privatbank_statement_requires_investigation',
            $state['status']
        );

        $this->expectException(QueryException::class);

        $this->bankSettlementMatch(
            $claim,
            $bank
        );
    }

    public function test_privat_statement30_conflicting_amount_blocks_cod_batch_allocation(): void
    {
        [, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate(
            680,
            'BANKREF_COD_BATCH_30'
        );

        $this->privatStatement30Import(
            'BANKREF_COD_BATCH_30',
            '5.00'
        );

        $this->actor(1200);

        $state = app(
            CheckoutCodBankBatchStatusService::class
        )->inspect(
            $batch['operation_key']
        );

        $this->assertTrue(
            $state['privatbank_statement_alert']
        );

        $this->assertSame(
            'privatbank_statement_requires_investigation',
            $state['status']
        );

        $this->expectException(QueryException::class);

        $this->bankBatchAllocate(
            $batch,
            $claim
        );
    }

    public function test_statement_reconciliation31_single_balanced_remains_unpaid(): void
    {
        [$bank, $entry] = $this->statement31Single();
        $p = $this->statement31Propose('single', $bank, $entry);
        $this->assertSame('balanced_unverified', $p['result_code']);
        $this->assertSame(2000, $p['novapay_gross_minor']);
        $this->assertSame(1320, $p['novapay_fee_minor']);
        $this->assertSame(680, $p['novapay_net_minor']);
        $r = $this->statement31Review($p);
        $this->assertSame('acknowledge_comparison', $r['decision']);
        $this->assertFalse($r['funds_received_verified']);
        $this->actor(1200);
        $state = app(CheckoutCodStatementReconciliationStatusService::class)
            ->inspect('single', $bank['operation_key']);
        $this->assertSame('comparison_reviewed_payment_unverified', $state['status']);
        $this->assertFalse($state['cod_paid']);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_statement_reconciliation31_batch_balanced_requires_allocation_review(): void
    {
        [$batch, $entry] = $this->statement31Batch();
        $p = $this->statement31Propose('batch', $batch, $entry);
        $this->assertSame('balanced_unverified', $p['result_code']);
        $this->assertSame(1, $p['allocation_count']);
        $this->statement31Review($p);
        $this->actor(1200);
        $this->assertSame('comparison_reviewed_payment_unverified',
            app(CheckoutCodStatementReconciliationStatusService::class)
                ->inspect('batch', $batch['operation_key'])['status']);
    }

    public function test_statement_reconciliation31_bank_shortage_must_be_escalated(): void
    {
        [$bank, $entry] = $this->statement31Single(bankAmount: '5.00');
        $p = $this->statement31Propose('single', $bank, $entry);
        $this->assertSame('bank_underpayment', $p['result_code']);
        $this->assertSame(-180, $p['difference_minor']);
        $this->statement31Review($p, 'escalate_investigation');
        $this->actor(1200);
        $this->assertSame('investigation_escalated',
            app(CheckoutCodStatementReconciliationStatusService::class)
                ->inspect('single', $bank['operation_key'])['status']);
    }

    public function test_statement_reconciliation31_bank_overpayment(): void
    {
        [$bank, $entry] = $this->statement31Single(bankAmount: '7.00');
        $p = $this->statement31Propose('single', $bank, $entry);
        $this->assertSame('bank_overpayment', $p['result_code']);
        $this->assertSame(20, $p['difference_minor']);
    }

    public function test_statement_reconciliation31_unreviewed_prior_source_is_not_balanced(): void
    {
        [$bank, $entry] = $this->statement31Single(withMatchReview: false);
        $p = $this->statement31Propose('single', $bank, $entry);
        $this->assertSame('upstream_unreviewed', $p['result_code']);
    }

    public function test_statement_reconciliation31_cannot_acknowledge_difference(): void
    {
        [$bank, $entry] = $this->statement31Single(bankAmount: '5.00');
        $p = $this->statement31Propose('single', $bank, $entry);
        $this->actor(3102);
        $this->expectException(DomainException::class);
        app(CheckoutCodStatementReconciliationService::class)->review(
            $p['operation_key'], (string) Str::uuid(), 'acknowledge_comparison'
        );
    }

    public function test_statement_reconciliation31_proposer_cannot_be_source_recorder(): void
    {
        [$bank, $entry] = $this->statement31Single();
        Gate::define(CheckoutCodStatementReconciliationService::PROPOSE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1901);
        $this->actor(1901);
        $this->expectException(DomainException::class);
        app(CheckoutCodStatementReconciliationService::class)->propose(
            'single', $bank['operation_key'], $entry, (string) Str::uuid()
        );
    }

    public function test_statement_reconciliation31_reviewer_cannot_be_proposer(): void
    {
        [$bank, $entry] = $this->statement31Single();
        $p = $this->statement31Propose('single', $bank, $entry);
        Gate::define(CheckoutCodStatementReconciliationService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3101);
        $this->actor(3101);
        $this->expectException(DomainException::class);
        app(CheckoutCodStatementReconciliationService::class)->review(
            $p['operation_key'], (string) Str::uuid(), 'acknowledge_comparison'
        );
    }

    public function test_statement_reconciliation31_proposal_replay_is_idempotent(): void
    {
        [$bank, $entry] = $this->statement31Single();
        $key = (string) Str::uuid();
        $a = $this->statement31Propose('single', $bank, $entry, $key);
        $b = $this->statement31Propose('single', $bank, $entry, $key);
        $this->assertSame($a['reconciliation_id'], $b['reconciliation_id']);
        $this->assertTrue($b['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_statement_reconciliations', 1);
    }

    public function test_statement_reconciliation31_second_proposal_for_same_source_is_rejected(): void
    {
        [$bank, $entry] = $this->statement31Single();
        $this->statement31Propose('single', $bank, $entry);
        $this->expectException(DomainException::class);
        $this->statement31Propose('single', $bank, $entry);
    }

    public function test_statement_reconciliation31_review_replay_is_idempotent(): void
    {
        [$bank, $entry] = $this->statement31Single();
        $p = $this->statement31Propose('single', $bank, $entry);
        $key = (string) Str::uuid();
        $a = $this->statement31Review($p, key: $key);
        $b = $this->statement31Review($p, key: $key);
        $this->assertSame($a['review_id'], $b['review_id']);
        $this->assertTrue($b['idempotent_replay']);
    }
}
