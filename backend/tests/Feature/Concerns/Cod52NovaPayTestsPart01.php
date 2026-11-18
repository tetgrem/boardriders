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

trait Cod52NovaPayTestsPart01
{
    public function test_novapay_register39_one_order_matches_bank_receipt_without_paid(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source();
        $capture = $this->novapayRegister39Capture($post, $rows);
        $this->assertSame(1, $capture['orders_count']);
        $this->assertSame(2000, $capture['gross_minor']);
        $this->assertSame(1320, $capture['fee_minor']);
        $this->assertSame(680, $capture['bank_minor']);
        $this->assertFalse($capture['novapay_origin_authenticated']);
        $this->assertFalse($capture['cod_paid']);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
        $this->assertDatabaseCount('checkout_cod_novapay_register_lines', 1);
        $this->actor(1200);
        $state = app(CheckoutCodNovaPayRegisterStatusService::class)
            ->inspect($post['operation_key']);
        $this->assertSame(
            'novapay_register_allocation_matched_origin_unverified', $state['status']
        );
    }

    public function test_novapay_register39_two_order_batch_matches_one_bank_posting(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source(true);
        $capture = $this->novapayRegister39Capture($post, $rows);
        $this->assertSame(2, $capture['orders_count']);
        $this->assertSame(4000, $capture['gross_minor']);
        $this->assertSame(2640, $capture['fee_minor']);
        $this->assertSame(1360, $capture['bank_minor']);
        $this->assertDatabaseCount('checkout_cod_novapay_register_captures', 1);
        $this->assertDatabaseCount('checkout_cod_novapay_register_lines', 2);
    }

    public function test_novapay_register39_same_request_is_idempotent(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source();
        $key = (string) Str::uuid();
        $first = $this->novapayRegister39Capture($post, $rows, $key);
        $second = $this->novapayRegister39Capture($post, $rows, $key);
        $this->assertSame($first['capture_id'], $second['capture_id']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_novapay_register_lines', 1);
    }

    public function test_novapay_register39_reused_uuid_with_new_document_rejected(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source();
        $key = (string) Str::uuid();
        $this->novapayRegister39Capture($post, $rows, $key);
        $this->expectException(DomainException::class);
        $this->novapayRegister39Capture($post, $rows, $key, str_repeat('c', 64));
    }

    public function test_novapay_register39_second_capture_for_same_bank_posting_rejected(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source();
        $this->novapayRegister39Capture($post, $rows);
        $this->expectException(DomainException::class);
        $this->novapayRegister39Capture($post, $rows);
    }

    public function test_novapay_register39_missing_order_blocks_whole_batch(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source(true);
        array_pop($rows);
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->novapayRegister39Capture($post, $rows);
        } finally {
            $this->assertDatabaseCount('checkout_cod_novapay_register_captures', 0);
        }
    }

    public function test_novapay_register39_wrong_net_amount_blocks_whole_batch(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source(true);
        $rows[0]['net_minor']++;
        $this->expectException(InvalidArgumentException::class);
        $this->novapayRegister39Capture($post, $rows);
    }

    public function test_novapay_register39_duplicate_payment_reference_rejected(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source(true);
        $rows[1]['external_payment_ref'] = $rows[0]['external_payment_ref'];
        $this->expectException(InvalidArgumentException::class);
        $this->novapayRegister39Capture($post, $rows);
    }

    public function test_novapay_register39_no_capture_status(): void
    {
        [, $post] = $this->novapayRegister39Source();
        $this->actor(1200);
        $state = app(CheckoutCodNovaPayRegisterStatusService::class)
            ->inspect($post['operation_key']);
        $this->assertSame('awaiting_novapay_register_extract', $state['status']);
        $this->assertFalse($state['cod_paid']);
    }

    public function test_novapay_register39_later_bank_anomaly_flags_saved_capture(): void
    {
        [$source, $post, $rows] = $this->novapayRegister39Source();
        $this->novapayRegister39Capture($post, $rows);
        $this->refetchRisk37Negative('single', $source);
        $this->actor(1200);
        $state = app(CheckoutCodNovaPayRegisterStatusService::class)
            ->inspect($post['operation_key']);
        $this->assertSame('novapay_register_claim_requires_investigation', $state['status']);
        $this->assertTrue($state['refetch_anomaly']);
        $this->assertFalse($state['cod_paid']);
    }

    public function test_novapay_register39_cannot_capture_after_bank_anomaly(): void
    {
        [$source, $post, $rows] = $this->novapayRegister39Source();
        $this->refetchRisk37Negative('single', $source);
        $this->expectException(DomainException::class);
        $this->novapayRegister39Capture($post, $rows);
    }

    public function test_novapay_register39_requires_permission(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodNovaPayRegisterService::class)->capture(
            $post['operation_key'], (string) Str::uuid(), str_repeat('b', 64), $rows
        );
    }

    public function test_novapay_register39_bank_poster_cannot_capture(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source();
        Gate::define(CheckoutCodNovaPayRegisterService::CAPTURE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3801);
        $this->actor(3801);
        $this->expectException(DomainException::class);
        app(CheckoutCodNovaPayRegisterService::class)->capture(
            $post['operation_key'], (string) Str::uuid(), str_repeat('b', 64), $rows
        );
    }

    public function test_novapay_register39_source_references_are_not_stored_raw(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source();
        $this->novapayRegister39Capture($post, $rows);
        $dbRow = DB::table('checkout_cod_novapay_register_lines')->first();
        $text = json_encode($dbRow, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($rows[0]['external_payment_ref'], $text);
        $this->assertStringNotContainsString($rows[0]['shipment_number'], $text);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $dbRow->external_payment_hmac);
    }

    public function test_novapay_register39_lines_cannot_be_modified(): void
    {
        [, $post, $rows] = $this->novapayRegister39Source();
        $this->novapayRegister39Capture($post, $rows);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_novapay_register_lines')->update(['net_minor' => 1]);
    }

    public function test_novapay_register39_reader_permission_required(): void
    {
        [, $post] = $this->novapayRegister39Source();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodNovaPayRegisterStatusService::class)
            ->inspect($post['operation_key']);
    }

    public function test_novapay_register39_posting_without_all_lines_is_rejected_at_commit(): void
    {
        [, $post] = $this->novapayRegister39Source();
        $this->expectException(QueryException::class);
        DB::transaction(static function () use ($post): void {
            DB::table('checkout_cod_novapay_register_captures')->insert([
                'operation_key' => (string) Str::uuid(),
                'bank_posting_id' => $post['posting_id'],
                'source_kind' => 'operator_extract',
                'document_sha256' => str_repeat('b', 64),
                'orders_count' => 1,
                'gross_minor' => 2000,
                'fee_minor' => 1320,
                'bank_minor' => 680,
                'snapshot_hmac' => str_repeat('c', 64),
                'captured_by' => 3901,
                'request_hmac' => str_repeat('d', 64),
                'created_at' => now(),
            ]);
            DB::statement('SET CONSTRAINTS cod_npr_complete_at_commit IMMEDIATE');
        });
    }

    public function test_novapay_csv40_single_bank_register_import_succeeds_without_paid(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();
        $bytes = $this->novapayCsv40Bytes($rows);

        $r = $this->novapayCsv40Import($posting, $bytes);

        $this->assertSame(1, $r['imported_rows_count']);
        $this->assertSame(hash('sha256', $bytes), $r['document_sha256']);
        $this->assertSame('boardriders_canonical_csv_v1', $r['input_format']);
        $this->assertFalse($r['novapay_origin_authenticated']);
        $this->assertFalse($r['cod_paid']);

        $this->assertDatabaseHas('checkout_cod_novapay_register_captures', [
            'id' => $r['capture_id'],
            'document_sha256' => hash('sha256', $bytes),
        ]);

        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_novapay_csv40_multiorder_bank_batch_imports_all_lines(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source(true);

        $r = $this->novapayCsv40Import(
            $posting,
            $this->novapayCsv40Bytes($rows)
        );

        $this->assertSame(2, $r['imported_rows_count']);
        $this->assertSame(1360, $r['bank_minor']);
        $this->assertDatabaseCount('checkout_cod_novapay_register_lines', 2);
        $this->assertDatabaseCount('checkout_cod_bank_receipt_postings', 1);
    }

    public function test_novapay_csv40_same_file_and_uuid_are_idempotent(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();
        $bytes = $this->novapayCsv40Bytes($rows);
        $key = (string) Str::uuid();

        $first = $this->novapayCsv40Import($posting, $bytes, $key);
        $second = $this->novapayCsv40Import($posting, $bytes, $key);

        $this->assertSame($first['capture_id'], $second['capture_id']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_novapay_register_captures', 1);
    }

    public function test_novapay_csv40_same_uuid_different_bytes_are_rejected(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();
        $bytes = $this->novapayCsv40Bytes($rows);
        $key = (string) Str::uuid();

        $this->novapayCsv40Import($posting, $bytes, $key);

        $this->expectException(DomainException::class);

        $this->novapayCsv40Import(
            $posting,
            "\xEF\xBB\xBF" . $bytes,
            $key
        );
    }

    public function test_novapay_csv40_amount_mismatch_creates_no_capture(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();
        $rows[0]['net_minor']++;

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->novapayCsv40Import(
                $posting,
                $this->novapayCsv40Bytes($rows)
            );
        } finally {
            $this->assertDatabaseCount('checkout_cod_novapay_register_captures', 0);
        }
    }

    public function test_novapay_csv40_duplicate_payment_ref_creates_no_capture(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source(true);

        $rows[1]['external_payment_ref'] = $rows[0]['external_payment_ref'];

        $this->expectException(InvalidArgumentException::class);

        $this->novapayCsv40Import(
            $posting,
            $this->novapayCsv40Bytes($rows)
        );
    }

    public function test_novapay_csv40_missing_order_cannot_commit_batch(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source(true);
        array_pop($rows);

        $this->expectException(InvalidArgumentException::class);

        $this->novapayCsv40Import(
            $posting,
            $this->novapayCsv40Bytes($rows)
        );
    }

    public function test_novapay_csv40_authorization_precedes_file_parsing(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodNovaPayRegisterCsvImportService::class)->import(
            $posting['operation_key'],
            (string) Str::uuid(),
            "\0not-a-csv"
        );
    }

    public function test_novapay_csv40_later_negative_bank_read_blocks_import(): void
    {
        [$source, $posting, $rows] = $this->novapayRegister39Source();

        $this->refetchRisk37Negative('single', $source);

        $this->expectException(DomainException::class);

        $this->novapayCsv40Import(
            $posting,
            $this->novapayCsv40Bytes($rows)
        );
    }

    public function test_novapay_csv40_route_requires_sanctum_auth(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('cod.novapay.register.csv-import');

        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('throttle:5,1', $route->gatherMiddleware());
    }

    public function test_novapay_csv40_http_upload_success(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();
        $bytes = $this->novapayCsv40Bytes($rows);

        $this->actingAs(new GenericUser(['id' => 3901]), 'sanctum');

        $response = $this->post(
            '/api/v1/admin/cod/novapay/register/csv-import',
            [
                'posting_key' => $posting['operation_key'],
                'operation_key' => (string) Str::uuid(),
                'file' => UploadedFile::fake()
                    ->createWithContent('register.csv', $bytes),
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201)
            ->assertJsonPath('document_sha256', hash('sha256', $bytes))
            ->assertJsonPath('novapay_origin_authenticated', false)
            ->assertJsonPath('cod_paid', false);

        $this->assertDatabaseCount('checkout_cod_novapay_register_lines', 1);
    }

    public function test_novapay_csv40_http_invalid_csv_is_422(): void
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->actingAs(new GenericUser(['id' => 3901]), 'sanctum');

        $this->post(
            '/api/v1/admin/cod/novapay/register/csv-import',
            [
                'posting_key' => $posting['operation_key'],
                'operation_key' => (string) Str::uuid(),
                'file' => UploadedFile::fake()
                    ->createWithContent('bad.csv', 'bad,data'),
            ],
            ['Accept' => 'application/json']
        )->assertStatus(422);

        $this->assertDatabaseCount('checkout_cod_novapay_register_captures', 0);
    }

    public function test_novapay_csv40_http_operator_without_permission_is_403(): void
    {
        [, $posting, $rows] = $this->novapayRegister39Source();

        $this->actingAs(new GenericUser(['id' => 999]), 'sanctum');

        $this->post(
            '/api/v1/admin/cod/novapay/register/csv-import',
            [
                'posting_key' => $posting['operation_key'],
                'operation_key' => (string) Str::uuid(),
                'file' => UploadedFile::fake()->createWithContent(
                    'register.csv',
                    $this->novapayCsv40Bytes($rows)
                ),
            ],
            ['Accept' => 'application/json']
        )->assertStatus(403);
    }
}
