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

trait Cod52LogisticsTestsPart01
{
    public function test_investigation_queue_is_empty_before_cases(): void
    {
        $this->actor(1200);
        $result = app(CheckoutCodTtnInvestigationQueueService::class)->recent();
        $this->assertSame([], $result['items']);
        $this->assertNull($result['next_before_id']);
    }

    public function test_investigation_queue_shows_assignment_and_overdue_without_pii(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(2));
        $this->actor(1200);
        $queue = app(CheckoutCodTtnInvestigationQueueService::class)->recent();
        $this->assertCount(1, $queue['items']);
        $this->assertSame('in_progress', $queue['items'][0]['status']);
        $this->assertTrue($queue['items'][0]['overdue']);
        $this->assertFalse($queue['ttn_submission_allowed']);
        $this->assertStringNotContainsString('Петренко', json_encode($queue, JSON_THROW_ON_ERROR));
    }

    public function test_investigation_queue_refuses_unauthorized_reader(): void
    {
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodTtnInvestigationQueueService::class)->recent();
    }

    public function test_investigation_queue_validates_pagination(): void
    {
        $this->actor(1200);
        $this->expectException(InvalidArgumentException::class);
        app(CheckoutCodTtnInvestigationQueueService::class)->recent(101);
    }

    public function test_resolution_requires_reconciliation_hold(): void
    {
        $this->stage();

        $this->actor(1301);

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnResolutionService::class)->capture(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_resolution_capture_is_idempotent_and_never_allows_retry(): void
    {
        $this->flagged();

        $this->actor(1301);

        $key = (string) Str::uuid();
        $service = app(CheckoutCodTtnResolutionService::class);

        $first = $service->capture($this->orderKey, $key);

        $this->assertSame(
            $first,
            $service->capture($this->orderKey, $key)
        );

        $this->assertSame(
            'insufficient_evidence',
            $first['outcome']
        );

        $this->assertFalse($first['automatic_retry_allowed']);

        $this->assertDatabaseCount(
            'checkout_cod_ttn_resolution_snapshots',
            1
        );
    }

    public function test_evidence_snapshot_can_be_independently_reviewed_but_hold_stays(): void
    {
        $intent = $this->flagged();

        $scan = $this->scan($intent);
        $this->independentReview($scan);
        $this->compareDocument($scan);

        $this->actor(1301);

        $snapshot = app(CheckoutCodTtnResolutionService::class)
            ->capture(
                $this->orderKey,
                (string) Str::uuid()
            );

        $this->assertSame(
            'candidate_snapshot_consistent',
            $snapshot['outcome']
        );

        $this->actor(1302);

        $review = app(CheckoutCodTtnResolutionService::class)
            ->review(
                $snapshot['operation_key'],
                (string) Str::uuid(),
                'escalate_to_carrier',
                'carrier_confirmation_required'
            );

        $this->assertFalse($review['ttn_submission_allowed']);

        $this->actor(1200);

        $status = app(CheckoutCodTtnResolutionStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'reviewed_hold_continues',
            $status['status']
        );

        $this->assertTrue($status['snapshot_is_current']);
        $this->assertFalse($status['carrier_ownership_verified']);
        $this->assertFalse($status['automatic_retry_allowed']);

        $this->assertSame(
            'reconciliation_required',
            $this->safetyStatus()['status']
        );

        Http::assertNotSent(
            static fn (ProviderRequest $request): bool =>
                ($request->data()['calledMethod'] ?? null) === 'save'
        );
    }

    public function test_resolution_author_cannot_review_own_snapshot(): void
    {
        $this->flagged();

        $this->actor(1301);

        $snapshot = app(CheckoutCodTtnResolutionService::class)
            ->capture(
                $this->orderKey,
                (string) Str::uuid()
            );

        Gate::define(
            CheckoutCodTtnResolutionService::REVIEW_ABILITY,
            static fn ($user): bool => in_array(
                (int) $user->getAuthIdentifier(),
                [1301, 1302],
                true
            )
        );

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnResolutionService::class)->review(
            $snapshot['operation_key'],
            (string) Str::uuid(),
            'retain_hold',
            'evidence_not_authoritative'
        );
    }

    public function test_retry_guard_blocks_after_two_person_review(): void
    {
        $this->flagged();

        $this->actor(1301);

        $snapshot = app(CheckoutCodTtnResolutionService::class)
            ->capture(
                $this->orderKey,
                (string) Str::uuid()
            );

        $this->actor(1302);

        app(CheckoutCodTtnResolutionService::class)->review(
            $snapshot['operation_key'],
            (string) Str::uuid(),
            'retain_hold',
            'evidence_not_authoritative'
        );

        $this->actor(1200);

        $guard = app(CheckoutCodTtnRetryGuardService::class);

        $this->assertFalse(
            $guard->inspect($this->orderKey)['manual_retry_allowed']
        );

        $this->expectException(DomainException::class);

        $guard->assertMaySubmit($this->orderKey);
    }

    public function test_resolution_database_journal_is_immutable(): void
    {
        $this->flagged();

        $this->actor(1301);

        $snapshot = app(CheckoutCodTtnResolutionService::class)
            ->capture(
                $this->orderKey,
                (string) Str::uuid()
            );

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_ttn_resolution_snapshots')
            ->where('id', $snapshot['id'])
            ->update([
                'outcome' => 'conflicting_evidence',
            ]);
    }

    public function test_dispatch_status_without_intent_is_blocked(): void
    {
        $this->actor(1200);

        $status = app(CheckoutCodTtnDispatchStatusService::class)
            ->inspect($this->orderKey);

        $this->assertNull($status['intent_id']);
        $this->assertNull($status['plan_id']);
        $this->assertFalse($status['ttn_submission_allowed']);

        Http::assertNothingSent();
    }

    public function test_dispatch_plan_is_immutable_preparation_not_provider_call(): void
    {
        $this->stage();
        $this->actor(1401);

        $plan = app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->assertFalse($plan['provider_call_made']);
        $this->assertFalse($plan['ready_for_dispatch']);

        $this->assertDatabaseCount(
            'checkout_cod_ttn_dispatch_plans',
            1
        );

        Http::assertNotSent(
            static fn (ProviderRequest $r): bool =>
                ($r->data()['calledMethod'] ?? null) === 'save'
        );
    }

    public function test_dispatch_plan_exact_operation_retry_is_idempotent(): void
    {
        $this->stage();
        $this->actor(1401);

        $key = (string) Str::uuid();
        $service = app(CheckoutCodTtnDispatchPlanService::class);

        $this->assertSame(
            $service->prepare($this->orderKey, $key),
            $service->prepare($this->orderKey, $key)
        );

        $this->assertDatabaseCount(
            'checkout_cod_ttn_dispatch_plans',
            1
        );
    }

    public function test_dispatch_plan_different_uuid_cannot_replace_first_plan(): void
    {
        $this->stage();
        $this->actor(1401);

        app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_dispatch_plan_requires_permission(): void
    {
        $this->stage();
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_safety_hold_prevents_dispatch_preparation(): void
    {
        $intent = $this->stage();

        $this->event(
            $intent,
            'safety_hold',
            'manual_review'
        );

        $this->actor(1401);

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_dispatch_review_is_idempotent(): void
    {
        $this->stage();
        $this->actor(1401);

        $plan = app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->actor(1402);

        $key = (string) Str::uuid();
        $service = app(CheckoutCodTtnDispatchPlanService::class);

        $this->assertSame(
            $service->review(
                $plan['operation_key'],
                $key,
                'reviewed_for_future_submission',
                'local_plan_checked'
            ),
            $service->review(
                $plan['operation_key'],
                $key,
                'reviewed_for_future_submission',
                'local_plan_checked'
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_ttn_dispatch_reviews',
            1
        );
    }

    public function test_dispatch_reviewer_cannot_approve_own_plan(): void
    {
        $this->stage();
        $this->actor(1401);

        $plan = app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        Gate::define(
            CheckoutCodTtnDispatchPlanService::REVIEW_ABILITY,
            static fn ($user): bool => in_array(
                (int) $user->getAuthIdentifier(),
                [1401, 1402],
                true
            )
        );

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnDispatchPlanService::class)->review(
            $plan['operation_key'],
            (string) Str::uuid(),
            'reviewed_for_future_submission',
            'local_plan_checked'
        );
    }

    public function test_dispatch_queue_redacts_recipient_and_api_key(): void
    {
        $this->stage();
        $this->actor(1401);

        app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->actor(1200);

        $queue = app(CheckoutCodTtnDispatchQueueService::class)
            ->recent();

        $this->assertCount(1, $queue['items']);

        $json = json_encode($queue, JSON_THROW_ON_ERROR);

        foreach ([
                     'Петренко',
                     '380671234567',
                     'ttn-gate-test-key',
                     self::WAYBILL,
                 ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $json
            );
        }
    }

    public function test_dispatch_queue_denies_unauthorized_user(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodTtnDispatchQueueService::class)->recent();
    }

    public function test_dispatch_transport_guard_is_never_a_bypass(): void
    {
        $this->stage();

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnDispatchTransportGuard::class)
            ->assertMaySend($this->orderKey);
    }

    public function test_dispatch_plan_database_record_cannot_be_changed(): void
    {
        $this->stage();
        $this->actor(1401);

        $plan = app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_ttn_dispatch_plans')
            ->where('id', $plan['id'])
            ->update([
                'prepared_by' => 999,
            ]);
    }

    public function test_dispatch_review_database_record_cannot_be_changed(): void
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

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_ttn_dispatch_reviews')
            ->where('id', $review['id'])
            ->update([
                'reviewed_by' => 999,
            ]);
    }

    public function test_warehouse_pack_needs_independently_reviewed_dispatch_plan(): void
    {
        $this->stage();
        $this->actor(1501);

        $this->assertFalse(
            app(CheckoutCodWarehousePackReadinessService::class)
                ->inspect($this->orderKey)['source_ready']
        );

        $this->expectException(DomainException::class);

        app(CheckoutCodWarehousePackService::class)->check(
            $this->orderKey,
            (string) Str::uuid(),
            'box',
            2,
            700,
            300,
            200,
            100
        );
    }

    public function test_warehouse_pack_records_physical_count_but_not_shipment(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $check = $this->warehousePackCheck();

        $this->assertSame(2, $check['counted_units']);
        $this->assertFalse($check['ttn_submission_allowed']);

        $this->assertDatabaseCount(
            'checkout_cod_warehouse_pack_checks',
            1
        );

        Http::assertNotSent(
            static fn (ProviderRequest $request): bool =>
                ($request->data()['calledMethod'] ?? null) === 'save'
        );
    }

    public function test_warehouse_pack_same_uuid_is_idempotent(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $key = (string) Str::uuid();

        $first = $this->warehousePackCheck($key);

        $this->assertSame(
            $first,
            $this->warehousePackCheck($key)
        );

        $this->assertDatabaseCount(
            'checkout_cod_warehouse_pack_checks',
            1
        );
    }

    public function test_warehouse_pack_second_uuid_cannot_replace_first_check(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $this->warehousePackCheck();

        $this->expectException(DomainException::class);

        $this->warehousePackCheck();
    }

    public function test_warehouse_pack_rejects_wrong_measured_gross_weight(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $source = app(CheckoutCodWarehousePackReadinessService::class)
            ->inspect($this->orderKey);

        $this->actor(1501);

        $this->expectException(DomainException::class);

        app(CheckoutCodWarehousePackService::class)->check(
            $this->orderKey,
            (string) Str::uuid(),
            'box',
            $source['total_units'],
            $source['gross_grams'] + 1,
            $source['length_mm'],
            $source['width_mm'],
            $source['height_mm']
        );
    }

    public function test_warehouse_pack_requires_operator_permission(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $source = app(CheckoutCodWarehousePackReadinessService::class)
            ->inspect($this->orderKey);

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodWarehousePackService::class)->check(
            $this->orderKey,
            (string) Str::uuid(),
            'box',
            $source['total_units'],
            $source['gross_grams'],
            $source['length_mm'],
            $source['width_mm'],
            $source['height_mm']
        );
    }

    public function test_safety_hold_blocks_physical_pack_check(): void
    {
        $this->warehouseReviewedDispatchPlan();

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

        $this->actor(1501);

        $this->assertFalse(
            app(CheckoutCodWarehousePackReadinessService::class)
                ->inspect($this->orderKey)['source_ready']
        );
    }

    public function test_warehouse_pack_reviewer_cannot_review_own_check(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $check = $this->warehousePackCheck();

        Gate::define(
            CheckoutCodWarehousePackService::REVIEW_ABILITY,
            static fn ($user): bool => in_array(
                (int) $user->getAuthIdentifier(),
                [1501, 1502],
                true
            )
        );

        $this->expectException(DomainException::class);

        app(CheckoutCodWarehousePackService::class)->review(
            $check['operation_key'],
            (string) Str::uuid(),
            'accepted',
            'physical_count_and_dimensions_verified'
        );
    }

    public function test_warehouse_pack_review_is_idempotent(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $check = $this->warehousePackCheck();

        $this->actor(1502);

        $key = (string) Str::uuid();
        $svc = app(CheckoutCodWarehousePackService::class);

        $this->assertSame(
            $svc->review(
                $check['operation_key'],
                $key,
                'accepted',
                'physical_count_and_dimensions_verified'
            ),
            $svc->review(
                $check['operation_key'],
                $key,
                'accepted',
                'physical_count_and_dimensions_verified'
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_warehouse_pack_reviews',
            1
        );
    }

    public function test_internal_pick_slip_is_pii_free_and_idempotent(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $check = $this->warehouseAcceptedPackCheck();

        $this->actor(1503);

        $key = (string) Str::uuid();
        $svc = app(CheckoutCodInternalPickSlipService::class);

        $first = $svc->generate(
            $check['operation_key'],
            $key
        );

        $this->assertSame(
            $first,
            $svc->generate($check['operation_key'], $key)
        );

        $this->assertStringContainsString(
            'INTERNAL PICK SLIP',
            $first['content']
        );

        $this->assertStringContainsString(
            'NOT A CARRIER LABEL',
            $first['content']
        );

        $this->assertFalse($first['physical_print_confirmed']);

        foreach ([
                     'Петренко',
                     '380671234567',
                     self::WAYBILL,
                     'ttn-gate-test-key',
                 ] as $private) {
            $this->assertStringNotContainsString(
                $private,
                $first['content']
            );
        }

        $this->assertDatabaseCount(
            'checkout_cod_warehouse_pick_slip_events',
            1
        );

        Http::assertNotSent(
            static fn (ProviderRequest $request): bool =>
                ($request->data()['calledMethod'] ?? null) === 'save'
        );
    }

    public function test_internal_pick_slip_cannot_be_generated_after_safety_event(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $check = $this->warehouseAcceptedPackCheck();

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

        $this->actor(1503);

        $this->expectException(DomainException::class);

        app(CheckoutCodInternalPickSlipService::class)->generate(
            $check['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_warehouse_pack_check_record_is_database_immutable(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $check = $this->warehousePackCheck();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_warehouse_pack_checks')
            ->where('id', $check['id'])
            ->update([
                'counted_units' => 999,
            ]);
    }

    public function test_warehouse_queue_does_not_expose_customer_information(): void
    {
        $this->warehouseReviewedDispatchPlan();
        $this->warehousePackCheck();

        $this->actor(1200);

        $queue = app(CheckoutCodWarehousePackQueueService::class)
            ->recent();

        $this->assertCount(1, $queue['items']);
        $this->assertFalse($queue['ttn_submission_allowed']);

        $json = json_encode($queue, JSON_THROW_ON_ERROR);

        foreach ([
                     'Петренко',
                     '380671234567',
                     self::WAYBILL,
                 ] as $private) {
            $this->assertStringNotContainsString(
                $private,
                $json
            );
        }
    }

    public function test_warehouse_queue_denies_unauthorized_reader(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodWarehousePackQueueService::class)->recent();
    }

    public function test_fulfillment_requires_independently_accepted_pack(): void
    {
        $this->warehouseReviewedDispatchPlan();
        $this->actor(1601);

        $this->expectException(DomainException::class);

        app(CheckoutCodFulfillmentStagingService::class)->stage(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    public function test_fulfillment_staging_does_not_ship_inventory_or_pay_cod(): void
    {
        $stage = $this->fulfillmentStagingReady();

        $this->assertFalse($stage['inventory_shipped']);
        $this->assertFalse($stage['carrier_handoff_allowed']);

        $this->assertDatabaseCount(
            'checkout_cod_fulfillment_staging',
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

        $this->assertSame(
            0,
            (int) DB::table('inventory_reservations')
                ->where('order_id', $orderId)
                ->sum('shipped_qty')
        );

        Http::assertNotSent(
            static fn (ProviderRequest $r): bool =>
                ($r->data()['calledMethod'] ?? null) === 'save'
        );
    }

    public function test_fulfillment_staging_exact_key_is_idempotent(): void
    {
        $stage = $this->fulfillmentStagingReady();

        $this->assertSame(
            $stage,
            app(CheckoutCodFulfillmentStagingService::class)->stage(
                $this->orderKey,
                $stage['operation_key']
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_fulfillment_staging',
            1
        );
    }
}
