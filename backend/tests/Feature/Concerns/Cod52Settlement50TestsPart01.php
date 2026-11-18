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

trait Cod52Settlement50TestsPart01
{
    public function test_cod_seal50_preview_requires_register_capture(): void
    {
        [, $post] = $this->codSeal50Fixture(false, false);
        $r = app(CheckoutCodSettlementSealService::class)->preview($post['operation_key']);
        $this->assertSame('awaiting_operator_register', $r['status']);
        $this->assertFalse($r['eligible_for_internal_seal']);
        $this->assertFalse($r['cod_paid']);
    }

    public function test_cod_seal50_preview_checks_exact_minor_units(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $r = app(CheckoutCodSettlementSealService::class)->preview($post['operation_key']);
        $this->assertSame('ready_for_internal_allocation_seal', $r['status']);
        $this->assertTrue($r['eligible_for_internal_seal']);
        $this->assertSame(2000, $r['totals']['gross_minor']);
        $this->assertSame(1320, $r['totals']['fee_minor']);
        $this->assertSame(680, $r['totals']['bank_minor']);
        $this->assertFalse($r['novapay_origin_authenticated']);
    }

    public function test_cod_seal50_single_order_seals_without_paid(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $r = $this->codSeal50Execute($post);
        $this->assertSame('sealed_awaiting_authenticated_collection', $r['status']);
        $this->assertSame(1, $r['totals']['orders_count']);
        $this->assertCount(1, $r['lines']);
        $this->assertTrue($r['internal_allocation_sealed']);
        $this->assertFalse($r['customer_payment_confirmed']);
        $this->assertFalse($r['cod_paid']);
        $this->assertSame('pending', DB::table('orders')->where('order_key', $this->orderKey)->value('payment_status'));
        $this->assertDatabaseCount('checkout_cod_settlement_seals', 1);
        $this->assertDatabaseCount('checkout_cod_settlement_seal_lines', 1);
    }

    public function test_cod_seal50_two_order_batch_is_atomic(): void
    {
        [, $post] = $this->codSeal50Fixture(true);
        $r = $this->codSeal50Execute($post);
        $this->assertSame(2, $r['totals']['orders_count']);
        $this->assertSame(4000, $r['totals']['gross_minor']);
        $this->assertSame(2640, $r['totals']['fee_minor']);
        $this->assertSame(1360, $r['totals']['bank_minor']);
        $this->assertDatabaseCount('checkout_cod_settlement_seal_lines', 2);
        $this->assertSame(2, DB::table('orders')->where('payment_status', 'pending')->count());
    }

    public function test_cod_seal50_same_uuid_replays_exact_snapshot(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $op = (string) Str::uuid();
        $a = $this->codSeal50Execute($post, $op);
        $b = $this->codSeal50Execute($post, $op);
        $this->assertFalse($a['idempotent_replay']);
        $this->assertTrue($b['idempotent_replay']);
        $this->assertSame($a['seal_key'], $b['seal_key']);
        $this->assertSame($a['snapshot_hmac'], $b['snapshot_hmac']);
        $this->assertDatabaseCount('checkout_cod_settlement_seals', 1);
    }

    public function test_cod_seal50_different_uuid_cannot_double_seal_posting(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $this->codSeal50Execute($post);
        $this->expectException(DomainException::class);
        $this->codSeal50Execute($post);
    }

    public function test_cod_seal50_uuid_cannot_be_replayed_by_other_actor(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $op = (string) Str::uuid();
        $this->codSeal50Execute($post, $op);
        $this->actor(5002);
        $this->expectException(DomainException::class);
        $this->codSeal50Execute($post, $op);
    }

    public function test_cod_seal50_creator_must_have_separate_gate(): void
    {
        [, $post] = $this->codSeal50Fixture();
        Gate::define(CheckoutCodSettlementSealService::SEAL_ABILITY,
            static fn ($u): bool => false);
        $this->expectException(AuthorizationException::class);
        $this->codSeal50Execute($post);
    }

    public function test_cod_seal50_capture_operator_cannot_seal_own_claim(): void
    {
        [, $post] = $this->codSeal50Fixture();
        Gate::define(CheckoutCodSettlementSealService::SEAL_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3901);
        $this->actor(3901);
        $this->expectException(DomainException::class);
        $this->codSeal50Execute($post);
    }

    public function test_cod_seal50_bank_poster_cannot_seal_own_posting(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $poster = (int) DB::table('checkout_cod_bank_receipt_postings')
            ->where('operation_key', $post['operation_key'])->value('posted_by');
        Gate::define(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn ($u): bool => true);
        Gate::define(CheckoutCodSettlementSealService::SEAL_ABILITY,
            static fn ($u): bool => true);
        $this->actor($poster);
        $this->expectException(DomainException::class);
        $this->codSeal50Execute($post);
    }

    public function test_cod_seal50_negative_bank_refetch_blocks_allocation(): void
    {
        [$source, $post] = $this->codSeal50Fixture();
        $this->refetchRisk37Negative('single', $source);
        $this->actor(5001);
        $preview = app(CheckoutCodSettlementSealService::class)->preview($post['operation_key']);
        $this->assertFalse($preview['eligible_for_internal_seal']);
        $this->assertContains('bank_source_disputed_or_held', $preview['issues']);
        $this->expectException(DomainException::class);
        $this->codSeal50Execute($post);
    }

    public function test_cod_seal50_later_gateway_reversal_blocks_new_seal(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $this->codCorrection46AddGatewayReversal();
        $this->actor(5001);
        $this->expectException(DomainException::class);
        $this->codSeal50Execute($post);
    }

    public function test_cod_seal50_reversal_after_seal_preserves_history_and_flags_risk(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $sealed = $this->codSeal50Execute($post);
        $this->codCorrection46AddGatewayReversal();
        $this->actor(5001);
        $r = app(CheckoutCodSettlementSealService::class)->show($sealed['seal_key']);
        $this->assertTrue($r['integrity_valid']);
        $this->assertTrue($r['current_financial_risk']);
        $this->assertFalse($r['cod_paid']);
        $this->assertDatabaseCount('checkout_cod_settlement_seals', 1);
    }

    public function test_cod_seal50_status_read_verifies_signature_and_lines(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $sealed = $this->codSeal50Execute($post);
        $svc = app(CheckoutCodSettlementSealService::class);
        $r = $svc->show($sealed['seal_key']);
        $this->assertTrue($r['integrity_valid']);
        $this->assertSame($sealed['lines'], $r['lines']);
        $p = $svc->preview($post['operation_key']);
        $this->assertSame('sealed_awaiting_authenticated_collection', $p['status']);
        $this->assertTrue($p['integrity_valid']);
    }

    public function test_cod_seal50_postgresql_immutable_header(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $this->codSeal50Execute($post);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_settlement_seals')
            ->update(['snapshot_hmac' => str_repeat('a', 64)]);
    }

    public function test_cod_seal50_postgresql_immutable_lines(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $this->codSeal50Execute($post);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_settlement_seal_lines')->delete();
    }

    public function test_cod_seal50_api_route_auth_and_registration(): void
    {
        foreach (['cod.seal50.preview', 'cod.seal50.create', 'cod.seal50.show'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }

    public function test_cod_seal50_http_success_returns_unpaid_snapshot(): void
    {
        [, $post] = $this->codSeal50Fixture();
        $this->actingAs(new GenericUser(['id' => 5001]), 'sanctum');
        $this->postJson('/api/v1/admin/cod/settlement-seals', [
            'posting_key' => $post['operation_key'],
            'operation_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('cod_paid', false)
            ->assertJsonPath('totals.bank_minor', 680)
            ->assertJsonPath('internal_allocation_sealed', true);
    }

    public function test_cod_seal50_http_bad_uuid_returns_422(): void
    {
        $this->codSeal50Gate();
        $this->actingAs(new GenericUser(['id' => 5001]), 'sanctum');
        $this->postJson('/api/v1/admin/cod/settlement-seals', [
            'posting_key' => 'bad', 'operation_key' => (string) Str::uuid(),
        ])->assertStatus(422);
    }
}
