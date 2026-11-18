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

trait Cod52LogisticsTestsPart02
{
    public function test_fulfillment_staging_second_uuid_is_blocked(): void
    {
        $this->fulfillmentStagingReady();

        $this->expectException(DomainException::class);

        app(CheckoutCodFulfillmentStagingService::class)->stage(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_fulfillment_staging_needs_permission(): void
    {
        $this->warehouseReviewedDispatchPlan();
        $this->warehouseAcceptedPackCheck();
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFulfillmentStagingService::class)->stage(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_fulfillment_self_review_is_blocked_even_if_gate_allows(): void
    {
        $stage = $this->fulfillmentStagingReady();

        Gate::define(
            CheckoutCodFulfillmentStagingService::REVIEW_ABILITY,
            static fn ($user): bool => in_array(
                (int) $user->getAuthIdentifier(),
                [1601, 1602],
                true
            )
        );

        $this->expectException(DomainException::class);

        app(CheckoutCodFulfillmentStagingService::class)->review(
            $stage['operation_key'],
            (string) Str::uuid(),
            'accepted',
            'internal_custody_verified'
        );
    }

    public function test_fulfillment_independent_review_is_internal_only(): void
    {
        $stage = $this->fulfillmentStagingReady();

        $this->actor(1602);

        $review = app(CheckoutCodFulfillmentStagingService::class)
            ->review(
                $stage['operation_key'],
                (string) Str::uuid(),
                'accepted',
                'internal_custody_verified'
            );

        $this->assertFalse($review['inventory_shipped']);

        $this->actor(1200);

        $status = app(CheckoutCodFulfillmentStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'internal_staging_reviewed',
            $status['status']
        );

        $this->assertTrue($status['internal_staging_reviewed']);
        $this->assertFalse($status['carrier_handoff_allowed']);
        $this->assertFalse($status['actual_ttn_verified']);
    }

    public function test_fulfillment_review_exact_replay_is_idempotent(): void
    {
        $stage = $this->fulfillmentStagingReady();
        $this->actor(1602);

        $key = (string) Str::uuid();
        $svc = app(CheckoutCodFulfillmentStagingService::class);

        $first = $svc->review(
            $stage['operation_key'],
            $key,
            'accepted',
            'internal_custody_verified'
        );

        $this->assertSame(
            $first,
            $svc->review(
                $stage['operation_key'],
                $key,
                'accepted',
                'internal_custody_verified'
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_fulfillment_staging_reviews',
            1
        );
    }

    public function test_fulfillment_rejected_staging_never_allows_handoff(): void
    {
        $stage = $this->fulfillmentStagingReady();
        $this->actor(1602);

        app(CheckoutCodFulfillmentStagingService::class)->review(
            $stage['operation_key'],
            (string) Str::uuid(),
            'rejected',
            'stock_discrepancy'
        );

        $this->actor(1200);

        $status = app(CheckoutCodFulfillmentStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame('staging_rejected', $status['status']);
        $this->assertFalse($status['carrier_handoff_allowed']);
    }

    public function test_fulfillment_hold_before_review_prevents_approval(): void
    {
        $stage = $this->fulfillmentStagingReady();

        $this->actor(1603);

        app(CheckoutCodFulfillmentStagingService::class)->hold(
            $stage['operation_key'],
            (string) Str::uuid(),
            'manual_security_hold'
        );

        $this->actor(1602);

        $this->expectException(DomainException::class);

        app(CheckoutCodFulfillmentStagingService::class)->review(
            $stage['operation_key'],
            (string) Str::uuid(),
            'accepted',
            'internal_custody_verified'
        );
    }

    public function test_fulfillment_hold_after_review_invalidates_internal_readiness(): void
    {
        $stage = $this->fulfillmentStagingReady();

        $this->actor(1602);

        app(CheckoutCodFulfillmentStagingService::class)->review(
            $stage['operation_key'],
            (string) Str::uuid(),
            'accepted',
            'internal_custody_verified'
        );

        $this->actor(1603);

        app(CheckoutCodFulfillmentStagingService::class)->hold(
            $stage['operation_key'],
            (string) Str::uuid(),
            'package_integrity_doubt'
        );

        $this->actor(1200);

        $status = app(CheckoutCodFulfillmentStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'internal_handoff_hold',
            $status['status']
        );

        $this->assertFalse($status['internal_staging_reviewed']);
    }

    public function test_fulfillment_hold_exact_replay_is_idempotent(): void
    {
        $stage = $this->fulfillmentStagingReady();

        $this->actor(1603);

        $key = (string) Str::uuid();
        $svc = app(CheckoutCodFulfillmentStagingService::class);

        $this->assertSame(
            $svc->hold(
                $stage['operation_key'],
                $key,
                'manual_security_hold'
            ),
            $svc->hold(
                $stage['operation_key'],
                $key,
                'manual_security_hold'
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_fulfillment_holds',
            1
        );
    }

    public function test_fulfillment_safety_event_blocks_new_staging(): void
    {
        $this->warehouseReviewedDispatchPlan();
        $this->warehouseAcceptedPackCheck();

        $intent = DB::table('checkout_cod_ttn_submission_intents')
            ->where(
                'order_id',
                DB::table('orders')
                    ->where('order_key', $this->orderKey)
                    ->value('id')
            )
            ->first(['intent_key']);

        $this->event(
            ['intent_key' => $intent->intent_key],
            'reconciliation_required',
            'reported_timeout'
        );

        $this->actor(1601);

        $this->expectException(DomainException::class);

        app(CheckoutCodFulfillmentStagingService::class)->stage(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_fulfillment_queue_redacts_customer_and_carrier_details(): void
    {
        $this->fulfillmentStagingReady();

        $this->actor(1200);

        $queue = app(CheckoutCodFulfillmentQueueService::class)
            ->recent();

        $this->assertCount(1, $queue['items']);

        $json = json_encode($queue, JSON_THROW_ON_ERROR);

        foreach ([
                     'Петренко',
                     '380671234567',
                     self::WAYBILL,
                     'ttn-gate-test-key',
                 ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $json
            );
        }

        $this->assertFalse($queue['carrier_handoff_allowed']);
    }

    public function test_fulfillment_queue_requires_permission(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFulfillmentQueueService::class)->recent();
    }

    public function test_fulfillment_staging_journal_rejects_updates(): void
    {
        $stage = $this->fulfillmentStagingReady();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_fulfillment_staging')
            ->where('id', $stage['id'])
            ->update([
                'expected_units' => 999,
            ]);
    }

    public function test_fulfillment_carrier_handoff_guard_never_ships(): void
    {
        $stage = $this->fulfillmentStagingReady();

        $this->actor(1602);

        app(CheckoutCodFulfillmentStagingService::class)->review(
            $stage['operation_key'],
            (string) Str::uuid(),
            'accepted',
            'internal_custody_verified'
        );

        $this->actor(1200);

        $this->expectException(DomainException::class);

        app(CheckoutCodFulfillmentDispatchGuard::class)
            ->assertMayHandoverToCarrier($this->orderKey);
    }

    public function test_tracking_does_not_exist_before_comparison(): void
    {
        $this->flagged();
        $this->actor(1701);

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnTrackingCaseService::class)->start(
            (string) Str::uuid(),
            (string) Str::uuid()
        );
    }

    public function test_tracking_registration_uses_matching_independent_evidence(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->assertTrue($candidate['number_is_only_candidate']);
        $this->assertFalse($candidate['carrier_ownership_verified']);

        $this->assertDatabaseCount(
            'checkout_cod_ttn_tracking_cases',
            1
        );

        Http::assertNotSent(
            static fn (ProviderRequest $r): bool =>
                ($r->data()['calledMethod'] ?? null) === 'getStatusDocuments'
        );
    }

    public function test_tracking_case_replay_is_idempotent(): void
    {
        $scan = $this->escalated();
        $comparison = $this->compareDocument($scan);

        $this->actor(1701);

        $key = (string) Str::uuid();
        $service = app(CheckoutCodTtnTrackingCaseService::class);

        $this->assertSame(
            $service->start($comparison['operation_key'], $key),
            $service->start($comparison['operation_key'], $key)
        );

        $this->assertDatabaseCount(
            'checkout_cod_ttn_tracking_cases',
            1
        );
    }

    public function test_tracking_cannot_register_another_case_for_same_intent(): void
    {
        $scan = $this->escalated();
        $comparison = $this->compareDocument($scan);

        $this->actor(1701);

        $svc = app(CheckoutCodTtnTrackingCaseService::class);

        $svc->start(
            $comparison['operation_key'],
            (string) Str::uuid()
        );

        $this->expectException(DomainException::class);

        $svc->start(
            $comparison['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_tracking_registration_requires_permission(): void
    {
        $scan = $this->escalated();
        $comparison = $this->compareDocument($scan);

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodTtnTrackingCaseService::class)->start(
            $comparison['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_tracking_poll_calls_only_read_only_carrier_status(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->actor(1702);

        $result = app(CheckoutCodTtnTrackingPollService::class)->poll(
            $candidate['tracking_key'],
            (string) Str::uuid()
        );

        $this->assertSame('observed', $result['outcome']);
        $this->assertSame('7', $result['status_code']);
        $this->assertFalse($result['delivery_confirmed_by_tracking']);
        $this->assertFalse($result['cod_paid']);

        Http::assertSent(
            static fn (ProviderRequest $r): bool =>
                ($r->data()['modelName'] ?? null) === 'TrackingDocument'
                && ($r->data()['calledMethod'] ?? null)
                === 'getStatusDocuments'
                && (
                    $r->data()['methodProperties']['Documents'][0]['DocumentNumber']
                    ?? null
                ) === self::WAYBILL
        );

        Http::assertNotSent(
            static fn (ProviderRequest $r): bool =>
                ($r->data()['calledMethod'] ?? null) === 'save'
        );
    }

    public function test_tracking_same_poll_key_is_idempotent(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->actor(1702);

        $key = (string) Str::uuid();
        $svc = app(CheckoutCodTtnTrackingPollService::class);

        $first = $svc->poll($candidate['tracking_key'], $key);

        $this->assertSame(
            $first,
            $svc->poll($candidate['tracking_key'], $key)
        );

        $this->assertDatabaseCount(
            'checkout_cod_ttn_tracking_observations',
            1
        );
    }

    public function test_tracking_poll_uuid_cannot_be_replayed_by_different_actor(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->actor(1702);

        $key = (string) Str::uuid();

        app(CheckoutCodTtnTrackingPollService::class)->poll(
            $candidate['tracking_key'],
            $key
        );

        Gate::define(
            CheckoutCodTtnTrackingPollService::POLL_ABILITY,
            static fn ($user): bool => in_array(
                (int) $user->getAuthIdentifier(),
                [1702, 1704],
                true
            )
        );

        $this->actor(1704);

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnTrackingPollService::class)->poll(
            $candidate['tracking_key'],
            $key
        );
    }

    public function test_tracking_poll_rejects_unprivileged_user(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodTtnTrackingPollService::class)->poll(
            $candidate['tracking_key'],
            (string) Str::uuid()
        );
    }

    public function test_tracking_status_preserves_last_good_code_after_provider_failure(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->actor(1702);

        $svc = app(CheckoutCodTtnTrackingPollService::class);

        $svc->poll(
            $candidate['tracking_key'],
            (string) Str::uuid()
        );

        $this->trackingShouldFail = true;

        $failure = $svc->poll(
            $candidate['tracking_key'],
            (string) Str::uuid()
        );

        $this->assertSame('provider_failure', $failure['outcome']);
        $this->assertNull($failure['status_code']);

        $this->actor(1200);

        $status = app(CheckoutCodTtnTrackingStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'carrier_temporarily_unavailable',
            $status['status']
        );

        $this->assertSame(
            '7',
            $status['latest_observed_status_code']
        );
    }

    public function test_tracking_mismatched_provider_response_is_inconclusive(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->trackingRows = [
            [
                'Number' => self::OTHER_WAYBILL,
                'StatusCode' => '9',
            ],
        ];

        $this->actor(1702);

        $poll = app(CheckoutCodTtnTrackingPollService::class)->poll(
            $candidate['tracking_key'],
            (string) Str::uuid()
        );

        $this->assertSame('inconclusive', $poll['outcome']);
        $this->assertNull($poll['status_code']);
    }

    public function test_tracking_status_history_records_transitions_without_claiming_delivery(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->actor(1702);

        $poll = app(CheckoutCodTtnTrackingPollService::class);

        $poll->poll(
            $candidate['tracking_key'],
            (string) Str::uuid()
        );

        $this->trackingRows = [
            [
                'Number' => self::WAYBILL,
                'StatusCode' => '9',
            ],
        ];

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addMinutes(2)
        );

        $poll->poll(
            $candidate['tracking_key'],
            (string) Str::uuid()
        );

        $this->actor(1200);

        $status = app(CheckoutCodTtnTrackingStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            '9',
            $status['latest_observed_status_code']
        );

        $this->assertTrue(
            $status['status_changed_since_previous_success']
        );

        $this->assertFalse($status['delivery_confirmed_by_tracking']);
        $this->assertFalse($status['cod_paid_by_tracking']);

        $history = app(CheckoutCodTtnTrackingStatusService::class)
            ->history($candidate['tracking_key']);

        $this->assertCount(2, $history['items']);
        $this->assertSame('9', $history['items'][0]['status_code']);
    }

    public function test_tracking_status_history_does_not_expose_sensitive_information(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->actor(1702);

        app(CheckoutCodTtnTrackingPollService::class)->poll(
            $candidate['tracking_key'],
            (string) Str::uuid()
        );

        $this->actor(1200);

        $payload = json_encode([
            app(CheckoutCodTtnTrackingStatusService::class)
                ->inspect($this->orderKey),
            app(CheckoutCodTtnTrackingStatusService::class)
                ->history($candidate['tracking_key']),
        ], JSON_THROW_ON_ERROR);

        foreach ([
                     'Петренко',
                     '380671234567',
                     self::WAYBILL,
                     'ttn-gate-test-key',
                 ] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }

    public function test_tracking_observations_are_database_immutable(): void
    {
        $candidate = $this->trackingCandidateReady();

        $this->actor(1702);

        $observation = app(CheckoutCodTtnTrackingPollService::class)
            ->poll(
                $candidate['tracking_key'],
                (string) Str::uuid()
            );

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_ttn_tracking_observations')
            ->where('id', $observation['id'])
            ->update(['status_code' => '99']);
    }

    public function test_tracking_scheduler_throttles_and_polls_eligible_candidate(): void
    {
        $candidate = $this->trackingCandidateReady();

        $due = app(CheckoutCodTtnTrackingDueService::class);

        $this->assertContains(
            $candidate['tracking_key'],
            $due->due()
        );

        $this->assertSame(
            0,
            Artisan::call('cod:tracking-poll', ['--limit' => 1])
        );

        $this->assertDatabaseCount(
            'checkout_cod_ttn_tracking_observations',
            1
        );

        $this->assertNotContains(
            $candidate['tracking_key'],
            $due->due()
        );

        $this->travel(16)->minutes();

        $this->assertContains(
            $candidate['tracking_key'],
            $due->due()
        );
    }
}
