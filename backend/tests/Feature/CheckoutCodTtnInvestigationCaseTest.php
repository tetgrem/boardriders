<?php

namespace Tests\Feature;

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

final class CheckoutCodTtnInvestigationCaseTest extends TestCase
{
    use \Tests\Feature\Concerns\Cod52CoreTestsPart01;
    use \Tests\Feature\Concerns\Cod52CoreTestsPart02;
    use \Tests\Feature\Concerns\Cod52CoreTestsPart03;
    use \Tests\Feature\Concerns\Cod52CoreTestsPart04;
    use \Tests\Feature\Concerns\Cod52CoreTestsPart05;
    use \Tests\Feature\Concerns\Cod52LogisticsTestsPart01;
    use \Tests\Feature\Concerns\Cod52LogisticsTestsPart02;
    use \Tests\Feature\Concerns\Cod52SettlementTestsPart01;
    use \Tests\Feature\Concerns\Cod52BankTestsPart01;
    use \Tests\Feature\Concerns\Cod52BankTestsPart02;
    use \Tests\Feature\Concerns\Cod52BankTestsPart03;
    use \Tests\Feature\Concerns\Cod52BankTestsPart04;
    use \Tests\Feature\Concerns\Cod52BankTestsPart05;
    use \Tests\Feature\Concerns\Cod52FinancialTestsPart01;
    use \Tests\Feature\Concerns\Cod52FinancialTestsPart02;
    use \Tests\Feature\Concerns\Cod52NovaPayTestsPart01;
    use \Tests\Feature\Concerns\Cod52Settlement50TestsPart01;
    use \Tests\Feature\Concerns\Cod52Lifecycle51TestsPart01;
    use \Tests\Feature\Concerns\Cod52Returns52TestsPart01;

    use RefreshDatabase;

    private const SENDER_COUNTERPARTY = '22222222-2222-4222-8222-222222222222';
    private const SENDER_CONTACT = '44444444-4444-4444-8444-444444444444';
    private const RECIPIENT_COUNTERPARTY = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const RECIPIENT_CONTACT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const DOCUMENT_REF = '11111111-1111-4111-8111-111111111111';
    private const WAYBILL = '20450000000001';
    private const OTHER_WAYBILL = '20450000000002';

    private int $variantId;
    private string $orderKey;
    private string $cityRef;
    private string $destinationRef;
    private string $senderCityRef;
    private bool $contactMatches = true;
    private bool $carrierScanFailure = false;
    private array $scanRows = [['IntDocNumber' => self::WAYBILL, 'Ref' => self::DOCUMENT_REF]];
    private array $documentRows = [];

    private bool $trackingShouldFail = false;

    private ?array $privatStatement30Fixture = null;


    private array $trackingRows = [
        [
            'Number' => self::WAYBILL,
            'StatusCode' => '7',
        ],
    ];


    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new RuntimeException('Requires PostgreSQL boardriders_test');
        }
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC'));
        $now = now();
        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH', 'name' => 'Ukrainian hryvnia', 'symbol' => '₴',
            'minor_units' => 2, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('markets')->insert([
            'code' => 'UA', 'name' => 'Ukraine', 'country_code' => 'UA',
            'currency_code' => 'UAH', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $priceListId = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL', 'name' => 'Retail UA',
            'country_code' => 'UA', 'currency_code' => 'UAH',
            'type' => 'retail', 'is_default' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'TTN-GATE-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId, 'price_list_id' => $priceListId,
            'price_minor' => 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'TTN-GATE-WH-' . Str::uuid(), 'name' => 'TTN gate warehouse',
            'country_code' => 'UA', 'kind' => 'owned', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouseId, 'variant_id' => $this->variantId,
            'on_hand_qty' => 20, 'reserved_qty' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        app(ProductVariantShippingMetricsService::class)->change(
            $this->variantId, (string) Str::uuid(), 0, 300, 200, 100, 50
        );

        $this->cityRef = (string) Str::uuid();
        $this->destinationRef = (string) Str::uuid();
        $this->senderCityRef = (string) Str::uuid();
        $this->documentRows = [[
            'Ref' => self::DOCUMENT_REF, 'IntDocNumber' => self::WAYBILL,
            'Sender' => self::SENDER_COUNTERPARTY,
            'Recipient' => self::RECIPIENT_COUNTERPARTY,
            'CitySender' => $this->senderCityRef,
            'CityRecipient' => $this->cityRef,
            'RecipientAddress' => $this->destinationRef,
            'ServiceType' => 'WarehouseWarehouse',
            'Weight' => '0.700', 'Cost' => '20.00',
            'RecipientName' => 'Петренко', 'RecipientsPhone' => '380671234567',
        ]];
        config()->set('services.nova_poshta.api_key', 'ttn-gate-test-key');
        config()->set('services.nova_poshta.sender_city_ref', $this->senderCityRef);
        $this->orderKey = $this->makeCodOrder();
        Http::preventStrayRequests();

        Gate::define(NovaPoshtaCodContractEvidenceService::SUBMIT_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 101);
        Gate::define(NovaPoshtaCodContractEvidenceService::REVIEW_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 202);
        Gate::define(NovaPoshtaCodExternalAttestationService::SUBMIT_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 303);
        Gate::define(NovaPoshtaCodExternalAttestationService::REVIEW_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 404);
        Gate::define(NovaPoshtaCodExternalAttestationService::REVOKE_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 505);
        Gate::define(CheckoutCodTtnSubmissionIntentService::PREPARE_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 606);
        Gate::define(CheckoutCodTtnSubmissionSafetyEventService::CONTROL_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 707);
        Gate::define(CheckoutCodTtnSubmissionSafetyEventService::RECONCILE_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 808);
        Gate::define(CheckoutCodTtnReconciliationScanService::SCAN_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 909);
        Gate::define(CheckoutCodTtnReconciliationReviewService::REVIEW_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 910);
        Gate::define(CheckoutCodTtnDocumentComparisonService::COMPARE_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 911);
        Gate::define(CheckoutCodTtnInvestigationService::OPEN_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 1012);
        Gate::define(CheckoutCodTtnInvestigationService::MANAGE_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 1013);
        Gate::define(CheckoutCodTtnInvestigationService::ARCHIVE_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 1014);
        Gate::define(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 1200);

        Gate::define(
            CheckoutCodTtnResolutionService::CAPTURE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1301
        );

        Gate::define(
            CheckoutCodTtnResolutionService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1302
        );


        Gate::define(
            CheckoutCodTtnDispatchPlanService::PREPARE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1401
        );

        Gate::define(
            CheckoutCodTtnDispatchPlanService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1402
        );

        Gate::define(
            CheckoutCodWarehousePackService::CHECK_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1501
        );

        Gate::define(
            CheckoutCodWarehousePackService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1502
        );

        Gate::define(
            CheckoutCodInternalPickSlipService::GENERATE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1503
        );

        Gate::define(
            CheckoutCodFulfillmentStagingService::STAGE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1601
        );

        Gate::define(
            CheckoutCodFulfillmentStagingService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1602
        );

        Gate::define(
            CheckoutCodFulfillmentStagingService::HOLD_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1603
        );


        Gate::define(
            CheckoutCodTtnTrackingCaseService::START_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1701
        );

        Gate::define(
            CheckoutCodTtnTrackingPollService::POLL_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1702
        );

        Gate::define(
            CheckoutCodSettlementCaseService::OPEN_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1801
        );

        Gate::define(
            CheckoutCodSettlementObservationService::RECORD_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1802
        );

        Gate::define(
            CheckoutCodSettlementReviewService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1803
        );

        Gate::define(
            CheckoutCodSettlementBankReceiptService::RECORD_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1901
        );

        Gate::define(
            CheckoutCodSettlementBankMatchService::PROPOSE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1902
        );

        Gate::define(
            CheckoutCodSettlementBankReviewService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 1903
        );

        Gate::define(
            CheckoutCodBankBatchService::RECORD_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 2001
        );

        Gate::define(
            CheckoutCodBankBatchAllocationService::ALLOCATE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 2002
        );

        Gate::define(
            CheckoutCodBankBatchReviewService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 2003
        );

        Gate::define(
            CheckoutCodFinancialExceptionService::REPORT_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 2101
        );

        Gate::define(
            CheckoutCodFinancialExceptionService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 2102
        );

        Gate::define(
            CheckoutCodFinancialResolutionService::EVIDENCE_ABILITY,
            static fn ($user): bool => in_array(
                (int) $user->getAuthIdentifier(),
                [2201, 2202],
                true
            )
        );

        Gate::define(
            CheckoutCodFinancialResolutionService::PROPOSE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 2203
        );

        Gate::define(
            CheckoutCodFinancialResolutionService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 2204
        );

    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function actor(int $id): void
    {
        Auth::setUser(new GenericUser(['id' => $id]));
    }

    private function makeCodOrder(): string
    {
        $key = (string) Str::uuid();
        app(CheckoutOrderPreparationService::class)->prepare(
            $key, [['variant_id' => $this->variantId, 'quantity' => 2]],
            'UA', 'UA_RETAIL', CarbonImmutable::now('UTC')->addMinutes(30)
        );
        app(CheckoutPaymentChoiceService::class)->choose($key, 'cash_on_delivery');
        app(CheckoutCashOnDeliveryRequestService::class)->request($key, (string) Str::uuid());
        app(CheckoutCodDeliverySelectionService::class)->select(
            $key, (string) Str::uuid(), 'branch', $this->destinationRef
        );
        app(CheckoutCodRecipientService::class)->save(
            $key, (string) Str::uuid(), $this->cityRef,
            'Іван', 'Петренко', '+380671234567'
        );
        return $key;
    }

    private function prepareDraft(
        int $declaredValueMinor = 2000,
        bool $withBinding = true,
        bool $withObservation = true,
    ): void {
        app(CheckoutCodParcelManifestService::class)->capture(
            $this->orderKey, (string) Str::uuid(), 100
        );
        app(CheckoutCodPackageAssessmentService::class)->assess(
            $this->orderKey, (string) Str::uuid(), 300, 200, 100
        );

        Http::fake(function (ProviderRequest $request) {
            if (
                $this->privatStatement30Fixture !== null
                && str_starts_with(
                    $request->url(),
                    'https://acp.privatbank.ua/api/statements/transactions'
                )
            ) {
                return Http::response(
                    $this->privatStatement30Fixture,
                    200
                );
            }

            $method = $request->data()['calledMethod'] ?? null;


            if ($method === 'getStatusDocuments') {
                if ($this->trackingShouldFail) {
                    return Http::response([
                        'success' => false,
                        'data' => [],
                        'errors' => ['test provider failure'],
                    ], 503);
                }

                return Http::response([
                    'success' => true,
                    'data' => $this->trackingRows,
                ]);
            }

            if ($method === 'getDocumentPrice') {
                return Http::response(['success' => true, 'data' => [[
                    'Cost' => '95.00', 'CostRedelivery' => '13.20',
                ]]]);
            }
            if ($method === 'getCounterpartyContactPersons') {
                $counterparty = $request->data()['methodProperties']['Ref'] ?? null;
                $ref = match ($counterparty) {
                    self::SENDER_COUNTERPARTY => self::SENDER_CONTACT,
                    self::RECIPIENT_COUNTERPARTY => $this->contactMatches
                        ? self::RECIPIENT_CONTACT : (string) Str::uuid(),
                    default => (string) Str::uuid(),
                };
                return Http::response(['success' => true, 'data' => [['Ref' => $ref]]]);
            }
            if ($method === 'getDocument') {
                return Http::response(['success' => true, 'data' => $this->documentRows]);
            }
            if ($method === 'getDocumentList') {
                if ($this->carrierScanFailure) {
                    return Http::response(['success' => false, 'data' => [], 'errors' => ['test']], 400);
                }
                return Http::response(['success' => true, 'data' => $this->scanRows]);
            }
            return Http::response(['success' => false, 'data' => []], 400);
        });
        app(CheckoutCodCarrierRateReconciliationService::class)->reconcile(
            $this->orderKey, (string) Str::uuid()
        );
        app(CheckoutCodFinancialPreflightService::class)->record(
            $this->orderKey, (string) Str::uuid(), $declaredValueMinor, 'cash_branch'
        );
        app(NovaPoshtaSenderProfileService::class)->configure(
            (string) Str::uuid(), 'ua_primary', 1, $this->senderCityRef,
            self::SENDER_COUNTERPARTY,
            '33333333-3333-4333-8333-333333333333',
            self::SENDER_CONTACT, '+380671234567'
        );
        app(CheckoutCodWaybillDraftService::class)->prepare(
            $this->orderKey, (string) Str::uuid(), 'ua_primary',
            'Recipient', 'Cash', 'Одяг та аксесуари'
        );
        if ($withBinding) {
            app(CheckoutCodRecipientCarrierBindingService::class)->bind(
                $this->orderKey, (string) Str::uuid(),
                self::RECIPIENT_COUNTERPARTY, self::RECIPIENT_CONTACT
            );
        }
        if ($withObservation) {
            app(CheckoutCodCarrierContactObservationService::class)->observe(
                $this->orderKey, (string) Str::uuid()
            );
        }
    }

    private function addAgreements(bool $all = true): array
    {
        $keys = [];
        $types = $all
            ? ['transport_agreement', 'cod_agreement', 'payout_agreement']
            : ['transport_agreement'];
        foreach ($types as $type) {
            $this->actor(101);
            $doc = app(NovaPoshtaCodContractEvidenceService::class)->submit(
                (string) Str::uuid(), 'ua_primary', $type, 1,
                $type === 'transport_agreement' ? 'nova_poshta' : 'novapay',
                str_repeat('a', 64), str_repeat('b', 64),
                CarbonImmutable::now('UTC')->subDay(),
                CarbonImmutable::now('UTC')->addYear()
            );
            $keys[$type] = $doc;
            $this->actor(202);
            app(NovaPoshtaCodContractEvidenceService::class)->review(
                (string) Str::uuid(), $doc['evidence_key'],
                'accepted', 'documents_reviewed'
            );
            $this->actor(303);
            $claim = app(NovaPoshtaCodExternalAttestationService::class)->submit(
                (string) Str::uuid(), $doc['evidence_key'], 'signed_document',
                str_repeat('c', 64), str_repeat('b', 64),
                CarbonImmutable::now('UTC')->subHour(),
                CarbonImmutable::now('UTC')->addMonth()
            );
            $keys[$type]['claim_key'] = $claim['claim_key'];
            $this->actor(404);
            app(NovaPoshtaCodExternalAttestationService::class)->review(
                (string) Str::uuid(), $claim['claim_key'],
                'accepted', 'source_checked'
            );
        }
        return $keys;
    }

    private function stage(?string $key = null): array
    {
        $this->prepareDraft();
        $this->addAgreements();
        $this->actor(606);
        return $this->prepareIntent($key);
    }

    private function prepareIntent(?string $key = null): array
    {
        return app(CheckoutCodTtnSubmissionIntentService::class)->prepare(
            $this->orderKey, $key ?? (string) Str::uuid()
        );
    }

    private function event(
        array $intent,
        string $type = 'safety_hold',
        string $reason = 'manual_review',
        ?string $key = null,
    ): array {
        $this->actor($type === 'reconciliation_required' ? 808 : 707);
        return app(CheckoutCodTtnSubmissionSafetyEventService::class)->record(
            $intent['intent_key'], $key ?? (string) Str::uuid(), $type, $reason
        );
    }

    private function safetyStatus(): array
    {
        return app(CheckoutCodTtnSubmissionSafetyStatusService::class)->inspect($this->orderKey);
    }

    /** @return array<string, mixed> */
    private function flagged(): array
    {
        $intent = $this->stage();
        $this->event($intent, 'reconciliation_required', 'reported_timeout');
        return $intent;
    }

    /** @return array<string, mixed> */
    private function scan(array $intent, ?string $key = null, string $number = self::WAYBILL): array
    {
        $this->actor(909);
        return app(CheckoutCodTtnReconciliationScanService::class)->inspect(
            $intent['intent_key'], $key ?? (string) Str::uuid(), $number
        );
    }

    /** @return array<string, mixed> */
    private function independentReview(
        array $scan, ?string $key = null,
        string $decision = 'escalate_to_carrier', string $reason = 'candidate_seen',
    ): array {
        $this->actor(910);
        return app(CheckoutCodTtnReconciliationReviewService::class)->review(
            $scan['operation_key'], $key ?? (string) Str::uuid(), $decision, $reason
        );
    }

    private function reconciliationStatus(): array
    {
        return app(CheckoutCodTtnReconciliationStatusService::class)->inspect($this->orderKey);
    }

    private function escalated(): array
    {
        $scan = $this->scan($this->flagged());
        $this->independentReview($scan);
        return $scan;
    }

    private function compareDocument(array $scan, ?string $key = null): array
    {
        $this->actor(911);
        return app(CheckoutCodTtnDocumentComparisonService::class)->compare(
            $scan['operation_key'], $key ?? (string) Str::uuid()
        );
    }

    private function compareStatus(): array
    {
        return app(CheckoutCodTtnDocumentComparisonStatusService::class)->inspect($this->orderKey);
    }

    private function openCase(?string $key = null, ?CarbonImmutable $deadline = null): array
    {
        $this->actor(1012);
        return app(CheckoutCodTtnInvestigationService::class)->open(
            $this->orderKey, $key ?? (string) Str::uuid(),
            $deadline ?? CarbonImmutable::now('UTC')->addDay()
        );
    }

    /** @return array{0:array,1:array} */
    private function beginCase(): array
    {
        $intent = $this->flagged();
        return [$intent, $this->openCase()];
    }

    private function caseEvent(
        array $case,
        string $type = 'assign',
        string $reason = 'initial_assignment',
        ?int $assignee = 1111,
        ?CarbonImmutable $deadline = null,
        ?string $key = null,
    ): array {
        $this->actor($type === 'archive' ? 1014 : 1013);
        return app(CheckoutCodTtnInvestigationService::class)->record(
            $case['case_key'], $key ?? (string) Str::uuid(),
            $type, $reason, $type === 'assign' ? $assignee : null, $deadline
        );
    }

    private function investigationStatus(): array
    {
        $this->actor(1200);
        return app(CheckoutCodTtnInvestigationStatusService::class)->inspect($this->orderKey);
    }













































































































    private function warehouseReviewedDispatchPlan(): array
    {
        $this->stage();

        $this->actor(1401);

        $plan = app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->actor(1402);

        app(CheckoutCodTtnDispatchPlanService::class)->review(
            $plan['operation_key'],
            (string) Str::uuid(),
            'reviewed_for_future_submission',
            'local_plan_checked'
        );

        return $plan;
    }

    private function warehousePackCheck(?string $key = null): array
    {
        $this->actor(1501);

        $source = app(CheckoutCodWarehousePackReadinessService::class)
            ->inspect($this->orderKey);

        $this->assertTrue($source['source_ready']);

        return app(CheckoutCodWarehousePackService::class)->check(
            $this->orderKey,
            $key ?? (string) Str::uuid(),
            'box',
            $source['total_units'],
            $source['gross_grams'],
            $source['length_mm'],
            $source['width_mm'],
            $source['height_mm']
        );
    }

    private function warehouseAcceptedPackCheck(): array
    {
        $check = $this->warehousePackCheck();

        $this->actor(1502);

        app(CheckoutCodWarehousePackService::class)->review(
            $check['operation_key'],
            (string) Str::uuid(),
            'accepted',
            'physical_count_and_dimensions_verified'
        );

        return $check;
    }



































    private function fulfillmentStagingReady(): array
    {
        $this->warehouseReviewedDispatchPlan();
        $this->warehouseAcceptedPackCheck();
        $this->actor(1601);

        return app(CheckoutCodFulfillmentStagingService::class)->stage(
            $this->orderKey,
            (string) Str::uuid()
        );
    }




































    private function trackingCandidateReady(): array
    {
        $scan = $this->escalated();
        $comparison = $this->compareDocument($scan);

        $this->assertSame(
            'snapshot_consistent',
            $comparison['summary']
        );

        $this->actor(1701);

        return app(CheckoutCodTtnTrackingCaseService::class)->start(
            $comparison['operation_key'],
            (string) Str::uuid()
        );
    }































    private function settlementReadyCase(): array
    {
        $this->stage();
        $this->actor(1801);

        return app(CheckoutCodSettlementCaseService::class)->open(
            $this->orderKey,
            (string) Str::uuid()
        );
    }

    private function settlementClaim(
        string $caseKey,
        int $gross = 2000,
        int $fee = 1320,
        int $net = 680,
        ?string $operationKey = null,
        ?string $reference = null
    ): array {
        $this->actor(1802);

        return app(CheckoutCodSettlementObservationService::class)
            ->record(
                $caseKey,
                $operationKey ?? (string) Str::uuid(),
                'novapay_statement',
                $reference ?? ('STATEMENT_' . Str::random(16)),
                $gross,
                $fee,
                $net
            );
    }



























    private function bankSettlementReviewedClaim(
        int $gross = 2000,
        int $fee = 1320,
        int $net = 680
    ): array {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim(
            $case['case_key'], gross: $gross, fee: $fee, net: $net
        );
        $this->actor(1803);
        $mismatch = $gross !== 2000;
        app(CheckoutCodSettlementReviewService::class)->review(
            $claim['operation_key'], (string) Str::uuid(),
            $mismatch ? 'escalate_discrepancy' : 'retain_unverified',
            $mismatch ? 'gross_amount_mismatch' : 'external_confirmation_required'
        );
        return [$case, $claim];
    }

    private function bankSettlementClaim(array $case, int $netMinor = 680, ?string $key = null): array
    {
        $this->actor(1901);
        return app(CheckoutCodSettlementBankReceiptService::class)->record(
            $case['case_key'], (string) Str::uuid(),
            $key ?? ('BANKREF_' . Str::random(16)), $netMinor
        );
    }

    private function bankSettlementMatch(array $claim, array $bank, ?string $key = null): array
    {
        $this->actor(1902);
        return app(CheckoutCodSettlementBankMatchService::class)->propose(
            $claim['operation_key'], $bank['operation_key'], $key ?? (string) Str::uuid()
        );
    }











































    /** @return array{0:array,1:array} */
    private function bankBatchSource(
        int $gross = 2000,
        int $fee = 1320,
        int $net = 680
    ): array {
        $case = $this->settlementReadyCase();

        $claim = $this->settlementClaim(
            $case['case_key'],
            gross: $gross,
            fee: $fee,
            net: $net
        );

        $this->actor(1803);

        app(CheckoutCodSettlementReviewService::class)->review(
            $claim['operation_key'],
            (string) Str::uuid(),
            'retain_unverified',
            'external_confirmation_required'
        );

        return [$case, $claim];
    }

    private function bankBatchCreate(
        int $net = 680,
        ?string $ref = null,
        ?string $key = null
    ): array {
        $this->actor(2001);

        return app(CheckoutCodBankBatchService::class)->record(
            $key ?? (string) Str::uuid(),
            $ref ?? ('BATCHREF_' . Str::random(16)),
            $net
        );
    }

    private function bankBatchAllocate(
        array $batch,
        array $claim,
        int $amount = 680,
        ?string $key = null
    ): array {
        $this->actor(2002);

        return app(CheckoutCodBankBatchAllocationService::class)->allocate(
            $batch['operation_key'],
            $claim['operation_key'],
            $key ?? (string) Str::uuid(),
            $amount
        );
    }

    /** Build another checkout/preflight without duplicating sender contracts. */
    private function bankBatchSecondCase(): array
    {
        $previous = $this->orderKey;

        try {
            $this->orderKey = $this->makeCodOrder();

            app(CheckoutCodParcelManifestService::class)->capture(
                $this->orderKey,
                (string) Str::uuid(),
                100
            );

            app(CheckoutCodPackageAssessmentService::class)->assess(
                $this->orderKey,
                (string) Str::uuid(),
                300,
                200,
                100
            );

            app(CheckoutCodCarrierRateReconciliationService::class)->reconcile(
                $this->orderKey,
                (string) Str::uuid()
            );

            app(CheckoutCodFinancialPreflightService::class)->record(
                $this->orderKey,
                (string) Str::uuid(),
                2000,
                'cash_branch'
            );

            $this->actor(1801);

            $case = app(CheckoutCodSettlementCaseService::class)->open(
                $this->orderKey,
                (string) Str::uuid()
            );

            $claim = $this->settlementClaim($case['case_key']);

            $this->actor(1803);

            app(CheckoutCodSettlementReviewService::class)->review(
                $claim['operation_key'],
                (string) Str::uuid(),
                'retain_unverified',
                'external_confirmation_required'
            );

            return [$case, $claim];
        } finally {
            $this->orderKey = $previous;
        }
    }













































    private function financialExceptionReport(
        string $kind,
        array $source,
        string $reason = 'incorrect_amount',
        ?string $key = null
    ): array {
        $this->actor(2101);

        return app(CheckoutCodFinancialExceptionService::class)->report(
            $kind,
            $source['operation_key'],
            $key ?? (string) Str::uuid(),
            $reason
        );
    }

    private function financialExceptionReview(
        array $incident,
        string $decision = 'hold_for_investigation',
        ?string $key = null
    ): array {
        $this->actor(2102);

        return app(CheckoutCodFinancialExceptionService::class)->review(
            $incident['exception_key'],
            $key ?? (string) Str::uuid(),
            $decision,
            $decision === 'dismiss_report'
                ? 'report_not_supported'
                : 'await_external_evidence'
        );
    }

















































    /** @return array{0:array,1:array,2:array} */
    private function resolutionCase(
        bool $independentlyHeld = true,
        string $reason = 'incorrect_amount'
    ): array {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case);

        $incident = $this->financialExceptionReport(
            'single',
            $bank,
            $reason
        );

        if ($independentlyHeld) {
            $this->financialExceptionReview($incident);
        }

        return [$case, $bank, $incident];
    }

    private function resolutionEvidence(
        array $incident,
        int $actor,
        string $kind,
        string $seed
    ): array {
        $this->actor($actor);

        return app(CheckoutCodFinancialResolutionService::class)
            ->addEvidence(
                $incident['exception_key'],
                (string) Str::uuid(),
                $kind,
                hash(
                    'sha256',
                    $incident['exception_key'] . $seed
                )
            );
    }

    private function resolutionTwoSources(array $incident): void
    {
        $this->resolutionEvidence(
            $incident,
            2201,
            'bank_notice_claim',
            'notice'
        );

        $this->resolutionEvidence(
            $incident,
            2202,
            'internal_reconciliation',
            'accounting'
        );
    }

    private function resolutionProposal(
        array $incident,
        int $recommended = 600,
        string $kind = 'amount_correction',
        ?string $key = null
    ): array {
        $this->actor(2203);

        return app(CheckoutCodFinancialResolutionService::class)
            ->propose(
                $incident['exception_key'],
                $key ?? (string) Str::uuid(),
                $kind,
                $recommended
            );
    }

    private function resolutionReview(
        array $proposal,
        string $decision = 'ready_for_external_verification',
        ?string $key = null
    ): array {
        $this->actor(2204);

        return app(CheckoutCodFinancialResolutionService::class)
            ->review(
                $proposal['operation_key'],
                $key ?? (string) Str::uuid(),
                $decision,
                $decision === 'return_for_rework'
                    ? 'insufficient_consistency'
                    : 'recommendation_documented'
            );
    }

























































    /**
     * Signed first-party gateway request.
     * No bank-native attestation implied.
     */
    private function gateway28Post(
        array $payload,
        ?string $nonce = null,
        ?int $timestamp = null,
        ?string $signedBody = null,
        ?string $sentBody = null,
        string $keyId = 'primary'
    ): \Illuminate\Testing\TestResponse {
        $secret = 'gateway-test-secret-which-is-longer-than-32-chars';

        config()->set(
            'services.cod_financial_gateway.enabled',
            true
        );

        config()->set(
            'services.cod_financial_gateway.keys',
            [
                'primary' => $secret,
                'secondary' => $secret . '-rotated',
            ]
        );

        $body = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
        );

        $signedBody ??= $body;
        $sentBody ??= $body;
        $nonce ??= (string) Str::uuid();
        $timestamp ??= now()->timestamp;

        $stamp = (string) $timestamp;

        $keySecret = $keyId === 'secondary'
            ? $secret . '-rotated'
            : $secret;

        $sig = app(
            CheckoutCodFinancialGatewaySignature::class
        )->sign(
            $keyId,
            $stamp,
            $nonce,
            $signedBody,
            $keySecret
        );

        return $this->call(
            'POST',
            '/api/v1/cod/financial-gateway/ingest',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_COD_GATEWAY_KEY' => $keyId,
                'HTTP_X_COD_GATEWAY_TIMESTAMP' => $stamp,
                'HTTP_X_COD_GATEWAY_NONCE' => $nonce,
                'HTTP_X_COD_GATEWAY_SIGNATURE' => $sig,
            ],
            $sentBody
        );
    }

    private function gateway28Payload(
        string $reference = 'GW_REF_UNMATCHED_1001',
        string $kind = 'credit_reported',
        int $amount = 680,
        ?string $eventKey = null
    ): array {
        return [
            'event_key' => $eventKey ?? (string) Str::uuid(),
            'event_kind' => $kind,
            'bank_reference' => $reference,
            'net_minor' => $amount,
            'currency_code' => 'UAH',
        ];
    }















































    private function bankEvidence29Payload(
        string $reference = 'BANKREF_EVIDENCE_29',
        string $kind = 'credit',
        int $amount = 680,
        ?string $eventKey = null,
        ?string $transactionId = null,
        ?string $reversedId = null
    ): array {
        return [
            'event_key' => $eventKey ?? (string) Str::uuid(),
            'event_kind' => $kind,
            'bank_reference' => $reference,
            'bank_transaction_id' => $transactionId ?? ('BANKTX_' . Str::random(20)),
            'reverses_transaction_id' => $reversedId,
            'amount_minor' => $amount,
            'currency_code' => 'UAH',
            'account_alias' => 'cod_primary_ua',
            'occurred_at_epoch' => now()->timestamp,
        ];
    }

    private function bankEvidence29Post(
        array $payload,
        ?string $nonce = null,
        ?int $timestamp = null,
        ?string $signedBody = null,
        ?string $sentBody = null,
        string $keyId = 'active',
        string $providerId = 'provider_primary'
    ): \Illuminate\Testing\TestResponse {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat("\x09", 32));
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);
        $oldPair = sodium_crypto_sign_seed_keypair(str_repeat("\x0a", 32));
        $oldSecret = sodium_crypto_sign_secretkey($oldPair);
        $oldPublic = sodium_crypto_sign_publickey($oldPair);

        config()->set('services.cod_bank_evidence', [
            'enabled' => true,
            'providers' => [
                'provider_primary' => [
                    'enabled' => true,
                    'account_alias' => 'cod_primary_ua',
                    'public_keys' => [
                        'active' => base64_encode($public),
                        'previous' => base64_encode($oldPublic),
                    ],
                ],
            ],
        ]);

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signedBody ??= $body;
        $sentBody ??= $body;
        $nonce ??= (string) Str::uuid();
        $timestamp ??= now()->timestamp;
        $stamp = (string) $timestamp;
        $message = CheckoutCodBankEvidenceVerifier::signedMessage(
            $providerId, $keyId, $stamp, $nonce, $signedBody
        );
        $sig = base64_encode(sodium_crypto_sign_detached(
            $message,
            $keyId === 'previous' ? $oldSecret : $secret
        ));

        return $this->call(
            'POST', '/api/v1/cod/bank-evidence/ingest', [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_COD_BANK_PROVIDER' => $providerId,
                'HTTP_X_COD_BANK_KEY' => $keyId,
                'HTTP_X_COD_BANK_TIMESTAMP' => $stamp,
                'HTTP_X_COD_BANK_NONCE' => $nonce,
                'HTTP_X_COD_BANK_SIGNATURE' => $sig,
            ],
            $sentBody
        );
    }

















































    private function privatStatement30Import(
        string $reference,
        string $amount
    ): void {
        $this->actor(3001);

        Gate::define(
            CheckoutCodPrivatStatementImportService::IMPORT_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3001
        );

        $iban = 'UA111111111111111111111111111';

        config()->set(
            'services.privatbank_autoclient',
            [
                'enabled' => true,
                'token' => 'privat-statement-test-token-0001',
                'account_iban' => $iban,
                'account_alias' => 'pb_primary_ua',
            ]
        );


        $this->privatStatement30Fixture = [
            'status' => 'SUCCESS',
            'type' => 'transactions',
            'exist_next_page' => false,
            'next_page_id' => null,
            'transactions' => [
                [
                    'AUT_MY_ACC' => $iban,
                    'FL_REAL' => 'r',
                    'PR_PR' => 'r',
                    'CCY' => 'UAH',
                    'TRANTYPE' => 'C',
                    'REF' => $reference,
                    'REFN' => '1',
                    'SUM' => $amount,
                    'DAT_OD' => now('Europe/Kyiv')
                        ->subDay()
                        ->format('d.m.Y'),
                ],
            ],
        ];


        $day = now('Europe/Kyiv')->subDay()->toDateString();

        app(CheckoutCodPrivatStatementImportService::class)
            ->import(
                (string) Str::uuid(),
                $day,
                $day
            );
    }





    // Block 31: reviewed bank statement / NovaPay comparison.
    private function statement31Gate(): void
    {
        Gate::define(
            CheckoutCodStatementReconciliationService::PROPOSE_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 3101
        );
        Gate::define(
            CheckoutCodStatementReconciliationService::REVIEW_ABILITY,
            static fn ($user): bool => (int) $user->getAuthIdentifier() === 3102
        );
    }

    private function statement31EntryId(string $reference): int
    {
        $fingerprint = hash_hmac(
            'sha256',
            json_encode(['cod-bank-reference-v1', $reference], JSON_THROW_ON_ERROR),
            (string) config('app.key')
        );
        return (int) DB::table('checkout_cod_privat_statement_entries')
            ->where('reference_hmac', $fingerprint)->orderByDesc('id')->value('id');
    }

    private function statement31Single(
        string $reference = 'STMT31_SINGLE_REF',
        string $bankAmount = '6.80',
        bool $withMatchReview = true
    ): array {
        $this->statement31Gate();
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case, 680, $reference);
        if ($withMatchReview) {
            $match = $this->bankSettlementMatch($claim, $bank);
            $this->actor(1903);
            app(CheckoutCodSettlementBankReviewService::class)->review(
                $match['operation_key'], (string) Str::uuid(),
                'retain_external_confirmation', 'bank_and_novapay_unverified'
            );
        }
        $this->privatStatement30Import($reference, $bankAmount);
        return [$bank, $this->statement31EntryId($reference)];
    }

    private function statement31Batch(string $reference = 'STMT31_BATCH_REF'): array
    {
        $this->statement31Gate();
        [, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(680, $reference);
        $this->bankBatchAllocate($batch, $claim);
        $this->actor(2003);
        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'], (string) Str::uuid()
        );
        $this->privatStatement30Import($reference, '6.80');
        return [$batch, $this->statement31EntryId($reference)];
    }

    private function statement31Propose(string $kind, array $source, int $entryId, ?string $key = null): array
    {
        $this->actor(3101);
        return app(CheckoutCodStatementReconciliationService::class)->propose(
            $kind, $source['operation_key'], $entryId, $key ?? (string) Str::uuid()
        );
    }

    private function statement31Review(array $proposal, string $decision = 'acknowledge_comparison', ?string $key = null): array
    {
        $this->actor(3102);
        return app(CheckoutCodStatementReconciliationService::class)->review(
            $proposal['operation_key'], $key ?? (string) Str::uuid(), $decision
        );
    }



































    // ============================================================
    // BLOCK 32 — PER-ORDER COD POSTING PREFLIGHT
    // ============================================================

    private function posting32Gate(): void
    {
        Gate::define(
            CheckoutCodPostingPreflightService::PREPARE_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3201
        );

        Gate::define(
            CheckoutCodPostingPreflightService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3202
        );
    }

    private function posting32Source(
        string $kind = 'single'
    ): array {
        if ($kind === 'single') {
            [$source, $entry] = $this->statement31Single();
        } else {
            [$source, $entry] = $this->statement31Batch();
        }

        $proposal = $this->statement31Propose(
            $kind,
            $source,
            $entry
        );

        $this->statement31Review($proposal);
        $this->posting32Gate();

        return [$source, $proposal];
    }

    private function posting32Prepare(
        array $proposal,
        ?string $orderKey = null,
        ?string $operationKey = null
    ): array {
        $this->actor(3201);

        return app(
            CheckoutCodPostingPreflightService::class
        )->prepare(
            $proposal['operation_key'],
            $orderKey ?? $this->orderKey,
            $operationKey ?? (string) Str::uuid()
        );
    }

    private function posting32Review(
        array $preflight,
        string $decision = 'retain_unverified',
        ?string $reviewKey = null
    ): array {
        $this->actor(3202);

        return app(
            CheckoutCodPostingPreflightService::class
        )->review(
            $preflight['operation_key'],
            $reviewKey ?? (string) Str::uuid(),
            $decision
        );
    }





























    // ============================================================
    // BLOCK 33 — COD BANK CREDIT COVERAGE REGISTER
    // ============================================================

    private function coverage33Gate(): void
    {
        Gate::define(CheckoutCodBankCoverageService::COMPILE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3301);
        Gate::define(CheckoutCodBankCoverageService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3302);
    }

    private function coverage33Source(string $kind = 'single'): array
    {
        [$source, $reconciliation] = $this->posting32Source($kind);
        $preflight = $this->posting32Prepare($reconciliation);
        $this->posting32Review($preflight);
        $this->coverage33Gate();
        return [$source, $reconciliation, $preflight];
    }

    private function coverage33Compile(string $kind, array $source, ?string $key = null): array
    {
        $this->actor(3301);
        return app(CheckoutCodBankCoverageService::class)->compile(
            $kind, $source['operation_key'], $key ?? (string) Str::uuid()
        );
    }

    private function coverage33Review(array $packet, ?string $key = null,
                                      string $decision = 'retain_unverified'): array
    {
        $this->actor(3302);
        return app(CheckoutCodBankCoverageService::class)->review(
            $packet['operation_key'], $key ?? (string) Str::uuid(), $decision
        );
    }

    private function coverage33TwoOrderBatch(bool $secondReady): array
    {
        $this->statement31Gate();
        [$firstCase, $firstClaim] = $this->bankBatchSource();
        [$secondCase, $secondClaim] = $this->bankBatchSecondCase();
        $batch = $this->bankBatchCreate(1360, 'BANK33_MULTI_CREDIT');
        $this->bankBatchAllocate($batch, $firstClaim, 680);
        $this->bankBatchAllocate($batch, $secondClaim, 680);

        $this->actor(2003);
        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'], (string) Str::uuid()
        );
        $this->privatStatement30Import('BANK33_MULTI_CREDIT', '13.60');
        $entryId = $this->statement31EntryId('BANK33_MULTI_CREDIT');
        $reconciliation = $this->statement31Propose('batch', $batch, $entryId);
        $this->statement31Review($reconciliation);
        $this->posting32Gate();
        $first = $this->posting32Prepare($reconciliation, $this->orderKey);
        $this->posting32Review($first);

        $secondOrderKey = DB::table('checkout_cod_settlement_cases as c')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->where('c.case_key', $secondCase['case_key'])
            ->value('o.order_key');
        $this->assertNotNull($secondOrderKey);

        if ($secondReady) {
            $second = $this->posting32Prepare($reconciliation, (string) $secondOrderKey);
            $this->posting32Review($second);
        }
        $this->coverage33Gate();
        return [$batch, $reconciliation];
    }































    // ============================================================
    // BLOCK 34 — UNPOSTED DOUBLE-ENTRY COD JOURNAL DRAFTS
    // ============================================================

    private function journal34Gate(): void
    {
        Gate::define(
            CheckoutCodJournalDraftService::PREPARE_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3401
        );

        Gate::define(
            CheckoutCodJournalDraftService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3402
        );
    }

    private function journal34Source(string $kind = 'single'): array
    {
        [$source] = $this->coverage33Source($kind);

        $packet = $this->coverage33Compile($kind, $source);

        $this->coverage33Review($packet);

        $this->journal34Gate();

        return [$source, $packet];
    }

    private function journal34MultiOrderSource(): array
    {
        [$batch] = $this->coverage33TwoOrderBatch(true);

        $packet = $this->coverage33Compile('batch', $batch);

        $this->coverage33Review($packet);

        $this->journal34Gate();

        return [$batch, $packet];
    }

    private function journal34Prepare(
        array $packet,
        ?string $key = null
    ): array {
        $this->actor(3401);

        return app(CheckoutCodJournalDraftService::class)->prepare(
            $packet['operation_key'],
            $key ?? (string) Str::uuid()
        );
    }

    private function journal34Review(
        array $draft,
        string $decision = 'retain_unposted',
        ?string $key = null
    ): array {
        $this->actor(3402);

        return app(CheckoutCodJournalDraftService::class)->review(
            $draft['operation_key'],
            $key ?? (string) Str::uuid(),
            $decision
        );
    }































    // ============================================================
    // BLOCK 35 — UNEXECUTED COD ADJUSTMENT AND RETURN CLAIMS
    // ============================================================

    private function adjustment35Gate(): void
    {
        Gate::define(CheckoutCodAdjustmentPlanService::PREPARE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3501);
        Gate::define(CheckoutCodAdjustmentPlanService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3502);
    }

    private function adjustment35Source(
        string $kind = 'single',
        string $reason = 'customer_return_reported',
        string $recommendation = 'return_case_followup',
        int $recommended = 680
    ): array {
        [$source, $packet] = $this->journal34Source($kind);
        $draft = $this->journal34Prepare($packet);
        $this->journal34Review($draft);
        $incident = $this->financialExceptionReport($kind, $source, $reason);
        $this->financialExceptionReview($incident);
        $this->resolutionTwoSources($incident);
        $proposal = $this->resolutionProposal($incident, $recommended, $recommendation);
        $this->resolutionReview($proposal);
        $this->adjustment35Gate();
        return [$source, $draft, $incident, $proposal];
    }

    private function adjustment35Prepare(
        array $draft,
        array $proposal,
        int $requested = 1000,
        ?string $key = null
    ): array {
        $this->actor(3501);
        return app(CheckoutCodAdjustmentPlanService::class)->prepare(
            $proposal['operation_key'], $draft['operation_key'],
            $this->orderKey, $requested, $key ?? (string) Str::uuid()
        );
    }

    private function adjustment35Review(
        array $plan,
        string $decision = 'retain_unexecuted',
        ?string $key = null
    ): array {
        $this->actor(3502);
        return app(CheckoutCodAdjustmentPlanService::class)->review(
            $plan['operation_key'], $key ?? (string) Str::uuid(), $decision
        );
    }































    // ============================================================
    // BLOCK 36 — PRIVATBANK FRESH HTTPS REFETCH COMPARISONS
    // ============================================================

    private function bankRefetch36Gate(): void
    {
        Gate::define(CheckoutCodPrivatRefetchService::RECHECK_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3601);
    }

    private function bankRefetch36Source(string $kind = 'single'): array
    {
        [$source, $entry] = $kind === 'single'
            ? $this->statement31Single()
            : $this->statement31Batch();
        $this->bankRefetch36Gate();
        return [$source, $entry];
    }

    private function bankRefetch36Run(
        string $kind, array $source, ?string $key = null
    ): array {
        $this->actor(3601);
        return app(CheckoutCodPrivatRefetchService::class)->recheck(
            $kind, $source['operation_key'], $key ?? (string) Str::uuid()
        );
    }

































    // ============================================================
    // BLOCK 37 — REFRESHED BANK API ANOMALIES STOP POSITIVE COD APPROVALS
    // ============================================================

    private function refetchRisk37Negative(string $kind, array $source): array
    {
        $this->bankRefetch36Gate();
        $this->privatStatement30Fixture['transactions'][0]['SUM'] = '7.00';
        return $this->bankRefetch36Run($kind, $source);
    }





















    // ============================================================
    // BLOCK 38 — AUTHENTICATED BANK CREDIT -> INTERNAL LEDGER
    // ============================================================

    private function bankReceipt38Gate(): void
    {
        Gate::define(CheckoutCodBankReceiptPostingService::POST_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3801);
    }

    private function bankReceipt38Source(string $kind = 'single', bool $multi = false): array
    {
        if ($multi) {
            [$source, $packet] = $this->journal34MultiOrderSource();
        } else {
            [$source, $packet] = $this->journal34Source($kind);
        }
        $draft = $this->journal34Prepare($packet);
        $this->journal34Review($draft);
        $this->bankRefetch36Gate();
        $refetch = $this->bankRefetch36Run($kind, $source);
        $this->bankReceipt38Gate();
        return [$source, $draft, $refetch];
    }

    private function bankReceipt38Post(array $draft, array $refetch, ?string $key = null): array
    {
        $this->actor(3801);
        return app(CheckoutCodBankReceiptPostingService::class)->post(
            $draft['operation_key'], $refetch['operation_key'],
            $key ?? (string) Str::uuid()
        );
    }








































    // ============================================================
    // BLOCK 39 — NORMALIZED NOVAPAY AFTERPAYMENT REGISTRY CLAIMS
    // ============================================================

    private function novapayRegister39Gate(): void
    {
        Gate::define(CheckoutCodNovaPayRegisterService::CAPTURE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3901);
    }

    private function novapayRegister39Source(bool $multi = false): array
    {
        [$source, $draft, $refetch] = $this->bankReceipt38Source(
            $multi ? 'batch' : 'single', $multi
        );
        $post = $this->bankReceipt38Post($draft, $refetch);
        $packetId = DB::table('checkout_cod_journal_drafts')
            ->where('id', $post['journal_draft_id'])->value('coverage_packet_id');
        $allocated = DB::table('checkout_cod_bank_coverage_lines as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->where('l.packet_id', $packetId)->orderBy('o.id')->get([
                'o.order_key', 'l.gross_minor', 'l.fee_minor', 'l.allocated_minor',
            ]);
        $rows = [];
        foreach ($allocated as $i => $r) {
            $rows[] = [
                'order_key' => (string) $r->order_key,
                'shipment_number' => '20450000000' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'external_payment_ref' => 'NOVAPAY_REG_39_' . ($i + 1),
                'gross_minor' => (int) $r->gross_minor,
                'fee_minor' => (int) $r->fee_minor,
                'net_minor' => (int) $r->allocated_minor,
            ];
        }
        $this->novapayRegister39Gate();
        return [$source, $post, $rows];
    }

    private function novapayRegister39Capture(
        array $post, array $rows, ?string $key = null,
        string $documentSha256 = ''
    ): array {
        $this->actor(3901);
        return app(CheckoutCodNovaPayRegisterService::class)->capture(
            $post['operation_key'], $key ?? (string) Str::uuid(),
            $documentSha256 !== '' ? $documentSha256 : str_repeat('b', 64),
            $rows
        );
    }



































    // ============================================================
    // BLOCK 40 — CANONICAL CSV UPLOAD FOR NOVAPAY REGISTER CLAIMS
    // ============================================================

    private function novapayCsv40Bytes(array $rows, string $eol = "\n"): string
    {
        $columns = CheckoutCodNovaPayRegisterCsvParser::HEADERS;
        $lines = [implode(',', $columns)];

        foreach ($rows as $r) {
            $fields = [];
            foreach ($columns as $column) {
                $fields[] = (string) $r[$column];
            }
            $lines[] = implode(',', $fields);
        }

        return implode($eol, $lines) . $eol;
    }

    private function novapayCsv40Import(
        array $posting,
        string $bytes,
        ?string $key = null
    ): array {
        $this->actor(3901);

        return app(CheckoutCodNovaPayRegisterCsvImportService::class)->import(
            $posting['operation_key'],
            $key ?? (string) Str::uuid(),
            $bytes
        );
    }


























    // ============================================================
    // BLOCK 41 — COD OPERATIONS / NOVAPAY CRM REVIEW QUEUE
    // ============================================================

    private function operations41Reviewer(): void
    {
        Gate::define(
            CheckoutCodOperationsReviewService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 1200
        );

        $this->actor(1200);
    }




























    // ============================================================
    // BLOCK 42 — MANAGED COD OPERATIONAL CASES
    // ============================================================

    private function codCase42Gate(): void
    {
        Gate::define(
            CheckoutCodOperationsCaseService::MANAGE_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [1200, 1201],
                true
            )
        );

        Gate::define(
            CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [1200, 1201],
                true
            )
        );
    }

    private function codCase42Open(array $posting): array
    {
        $this->codCase42Gate();
        $this->actor(1200);

        return app(CheckoutCodOperationsCaseService::class)->open(
            $posting['operation_key'],
            (string) Str::uuid()
        );
    }

    private function codCase42Action(
        array $case,
        string $action,
        string $reason,
        ?string $key = null
    ): array {
        return app(CheckoutCodOperationsCaseService::class)->act(
            $case['case_key'],
            $key ?? (string) Str::uuid(),
            $case['revision'],
            $case['snapshot_hmac'],
            $action,
            $reason
        );
    }


































    // ============================================================
    // BLOCK 43 — SLA OUTBOX, QUEUE AND CRM INBOX
    // ============================================================

    private function codAlert43Gate(): void
    {
        $this->codCase42Gate();

        Gate::define(
            CheckoutCodCaseAlertInboxService::TRIAGE_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 1201
        );
    }

    private function codAlert43Open(): array
    {
        [, $posting] = $this->novapayRegister39Source();

        $this->codAlert43Gate();
        $this->actor(1200);

        return $this->codCase42Open($posting);
    }

    private function codAlert43Time(
        array $case,
        int $minutesAfterDue
    ): void {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse($case['sla_due_at'])
                ->addMinutes($minutesAfterDue)
        );
    }


































    // ============================================================
    // BLOCK 44 — MANAGEMENT ANALYTICS: FINANCE, SLA, STAFF, ALERTS
    // ============================================================

    private function codAnalytics44Gate(): void
    {
        Gate::define(
            CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(), [1200, 1201], true
            )
        );
        Gate::define(
            CheckoutCodAnalyticsService::VIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1200
        );
        $this->actor(1200);
    }

    private function codAnalytics44Report(?string $from = null, ?string $to = null): array
    {
        $this->codAnalytics44Gate();
        return app(CheckoutCodAnalyticsService::class)->report($from, $to);
    }



























    // ============================================================
    // BLOCK 45 — IMMUTABLE DAILY COD REPORTS
    // ============================================================

    private function codDaily45Gate(): void
    {
        $this->codAnalytics44Gate();

        Gate::define(
            CheckoutCodDailyReportService::VIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [1200, 1201],
                true
            )
        );

        Gate::define(
            CheckoutCodDailyReportService::SEAL_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1200
        );

        Gate::define(
            CheckoutCodDailyReportService::EXPORT_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1200
        );
    }

    private function codDaily45Fixture(
        bool $withCase = false,
        bool $withRegister = false
    ): array {
        [, $post, $rows] = $this->novapayRegister39Source();

        if ($withRegister) {
            $this->novapayRegister39Capture($post, $rows);
        }

        $case = null;

        if ($withCase) {
            $this->codCase42Gate();
            $this->actor(1200);
            $case = $this->codCase42Open($post);
        }

        $day = CarbonImmutable::now('UTC')->toDateString();

        // Advance one calendar day only after creating the bank fixtures.
        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addDay()
        );

        $this->codDaily45Gate();

        return [
            'day' => $day,
            'post' => $post,
            'rows' => $rows,
            'case' => $case,
        ];
    }

    private function codDaily45Seal(
        string $day,
        ?string $operationKey = null
    ): array {
        return app(CheckoutCodDailyReportService::class)->seal(
            $day,
            $operationKey ?? (string) Str::uuid()
        );
    }






































    // ============================================================
    // BLOCK 46 — IMMUTABLE FINANCIAL CORRECTION APPENDICES
    // ============================================================

    private function codCorrection46Gate(): void
    {
        $allowed = static fn ($u): bool => in_array(
            (int) $u->getAuthIdentifier(), [1200, 1201, 1202, 1203, 1204], true
        );
        Gate::define(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY, $allowed);
        Gate::define(CheckoutCodAnalyticsService::VIEW_ABILITY, $allowed);
        Gate::define(CheckoutCodDailyReportService::VIEW_ABILITY, $allowed);
        Gate::define(CheckoutCodDailyCorrectionService::VIEW_ABILITY, $allowed);
        Gate::define(CheckoutCodDailyCorrectionService::PROPOSE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1201);
        Gate::define(CheckoutCodDailyCorrectionService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1202);
        Gate::define(CheckoutCodDailyCorrectionService::PUBLISH_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1203);
        Gate::define(CheckoutCodDailyCorrectionService::EXPORT_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1204);
    }

    private function codCorrection46Fixture(bool $lateRegister = true): array
    {
        $f = $this->codDaily45Fixture();
        $sealed = $this->codDaily45Seal($f['day']);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        if ($lateRegister) {
            $this->novapayRegister39Capture($f['post'], $f['rows']);
        }
        $this->codCorrection46Gate();
        $this->actor(1201);
        return ['report' => $sealed, 'posting' => $f['post']];
    }

    private function codCorrection46Propose(array $fixture, ?string $op = null, string $reason = 'late_register'): array
    {
        return app(CheckoutCodDailyCorrectionService::class)->propose(
            $fixture['report']['report_key'], $op ?? (string) Str::uuid(), $reason
        );
    }

    private function codCorrection46Action(array $c, string $action): array
    {
        $actor = match ($action) {
            'approve', 'reject' => 1202,
            'publish' => 1203,
        };
        $this->actor($actor);
        $reason = match ($action) {
            'approve' => 'evidence_reviewed',
            'reject' => 'insufficient_evidence',
            'publish' => 'approved_appendix',
        };
        return app(CheckoutCodDailyCorrectionService::class)->act(
            $c['correction_key'], (string) Str::uuid(), $action, $reason
        );
    }

    private function codCorrection46AddGatewayReversal(): void
    {
        $reference = (string) DB::table('checkout_cod_bank_receipt_postings')
            ->orderByDesc('id')->value('reference_hmac');
        $at = CarbonImmutable::now('UTC');
        $nonceId = (int) DB::table('checkout_cod_gateway_nonces')->insertGetId([
            'key_id' => 'testprovider46',
            'nonce' => (string) Str::uuid(),
            'body_sha256' => str_repeat('a', 64),
            'received_at' => $at,
        ]);
        DB::table('checkout_cod_gateway_reports')->insert([
            'event_key' => (string) Str::uuid(),
            'nonce_id' => $nonceId,
            'key_id' => 'testprovider46',
            'reference_hmac' => $reference,
            'event_kind' => 'reversal_reported',
            'net_minor' => 680,
            'currency_code' => 'UAH',
            'signed_at_epoch' => $at->timestamp,
            'body_sha256' => str_repeat('a', 64),
            'received_at' => $at,
        ]);
    }






































    // ============================================================
    // BLOCK 47 — IMMUTABLE DAILY MONITORING, ACKNOWLEDGEMENTS
    // ============================================================

    private function codMonitor47Gate(): void
    {
        $this->codCorrection46Gate();

        Gate::define(
            CheckoutCodDailyMonitorService::VIEW_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [1200, 1204], true)
        );

        Gate::define(
            CheckoutCodDailyMonitorService::ACK_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1204
        );
    }

    private function codMonitor47Fixture(bool $lateRegister = true): array
    {
        $fixture = $this->codCorrection46Fixture($lateRegister);
        $this->codMonitor47Gate();
        return $fixture;
    }

    private function codMonitor47Scan(int $limit = 50): array
    {
        return app(CheckoutCodDailyMonitorService::class)->scan($limit);
    }

    private function codMonitor47Latest(string $scope = 'all'): array
    {
        $this->actor(1204);

        return app(CheckoutCodDailyMonitorService::class)
            ->listing($scope)['items'][0];
    }









































    // ============================================================
    // BLOCK 48 — COD CORRECTION SLA, ESCALATION AND ROLE-SCOPED CRM
    // ============================================================

    private function codSla48Gate(): void
    {
        $this->codCorrection46Gate();
        $viewers = static fn ($u): bool => in_array(
            (int) $u->getAuthIdentifier(), [1200, 1201, 1202, 1203, 1204, 1205, 1206], true
        );
        Gate::define(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY, $viewers);
        Gate::define(CheckoutCodDailyReportService::VIEW_ABILITY, $viewers);
        Gate::define(CheckoutCodCorrectionSlaInboxService::VIEW_ABILITY, $viewers);
        Gate::define(CheckoutCodCorrectionSlaInboxService::ACK_ABILITY, $viewers);
        Gate::define(CheckoutCodCorrectionSlaInboxService::REVIEW_TEAM_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1204);
        Gate::define(CheckoutCodCorrectionSlaInboxService::PUBLICATION_TEAM_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1205);
        Gate::define(CheckoutCodCorrectionSlaInboxService::MANAGEMENT_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1206);
    }

    private function codSla48Fixture(): array
    {
        $f = $this->codCorrection46Fixture();
        $this->actor(1201);
        $correction = $this->codCorrection46Propose($f);
        $this->codSla48Gate();
        return [$f, $correction];
    }

    private function codSla48Advance(array $correction, string $kind, int $minutes): void
    {
        $started = DB::table('checkout_cod_daily_correction_events')
            ->where('correction_id', $correction['id'])
            ->where('event_kind', $kind)
            ->orderByDesc('id')->value('created_at');
        $this->assertNotNull($started);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($started)->addMinutes($minutes));
    }

    private function codSla48Scan(int $limit = 50): array
    {
        return app(CheckoutCodCorrectionSlaScanService::class)->scan($limit);
    }

    private function codSla48Inbox(string $scope = 'all', int $actor = 1204): array
    {
        $this->actor($actor);
        return app(CheckoutCodCorrectionSlaInboxService::class)->listing($scope)['items'];
    }





































    // ============================================================
    // BLOCK 49 — UNIFIED FINANCE CENTER
    // ============================================================

    private function codFinance49Gate(): void
    {
        $this->codSla48Gate();

        $viewers = static fn ($u): bool => in_array(
            (int) $u->getAuthIdentifier(),
            [1204, 1205, 1206],
            true
        );

        Gate::define(
            CheckoutCodFinanceCenterService::VIEW_ABILITY,
            $viewers
        );

        Gate::define(
            CheckoutCodFinanceCenterService::METRICS_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 1206
        );

        Gate::define(
            CheckoutCodDailyMonitorService::VIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [1204, 1206],
                true
            )
        );

        Gate::define(
            CheckoutCodAnalyticsService::VIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 1206
        );

        Gate::define(
            'cod.operations.analytics.view',
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 1206
        );
    }

    private function codFinance49Fixture(int $minutes = 480): array
    {
        [$f, $correction] = $this->codSla48Fixture();

        $this->codFinance49Gate();

        $this->codSla48Advance(
            $correction,
            'proposed',
            $minutes
        );

        $this->codSla48Scan();
        $this->codMonitor47Scan();

        return [$f, $correction];
    }

































    // ============================================================
    // BLOCK 50 — IMMUTABLE INTERNAL COD SETTLEMENT ALLOCATIONS
    // ============================================================

    private function codSeal50Gate(): void
    {
        Gate::define(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [5001, 5002, 3901, 1200], true));
        Gate::define(CheckoutCodSettlementSealService::VIEW_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [5001, 5002], true));
        Gate::define(CheckoutCodSettlementSealService::SEAL_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [5001, 5002], true));
    }

    private function codSeal50Fixture(bool $multi = false, bool $capture = true): array
    {
        [$source, $post, $rows] = $this->novapayRegister39Source($multi);
        if ($capture) {
            $this->novapayRegister39Capture($post, $rows);
        }
        $this->codSeal50Gate();
        $this->actor(5001);
        return [$source, $post, $rows];
    }

    private function codSeal50Execute(array $post, ?string $operation = null): array
    {
        return app(CheckoutCodSettlementSealService::class)->seal(
            $post['operation_key'], $operation ?? (string) Str::uuid()
        );
    }






































    // ============================================================
    // BLOCK 51 — NOVA POSHTA TTN LIFECYCLE EVIDENCE AND CRM QUEUE
    // ============================================================

    private function codLife51Fixture(): array
    {
        $candidate = $this->trackingCandidateReady();
        Gate::define(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [5101, 5102], true));
        Gate::define(CheckoutCodTtnLifecycleService::VIEW_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [5101, 5102], true));
        Gate::define(CheckoutCodTtnLifecycleService::SYNC_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 5101);
        Gate::define(CheckoutCodTtnTrackingPollService::POLL_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1702);
        return $candidate;
    }

    private function codLife51Poll(string $trackingKey, string $code): array
    {
        $this->trackingRows = [['Number' => self::WAYBILL, 'StatusCode' => $code]];
        $this->actor(1702);
        return app(CheckoutCodTtnTrackingPollService::class)
            ->poll($trackingKey, (string) Str::uuid());
    }

    private function codLife51Sync(string $trackingKey): array
    {
        $this->actor(5101);
        return app(CheckoutCodTtnLifecycleService::class)->sync($trackingKey);
    }







































    // ============================================================
    // BLOCK 52 — OMS RETURN / RMA WITH INDEPENDENT WAREHOUSE REVIEW
    // ============================================================

    private function codReturn52Gate(): void
    {
        Gate::define(CheckoutOrderReturnService::VIEW_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [5201, 5202, 5203, 5204], true));
        Gate::define(CheckoutOrderReturnService::OPEN_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 5201);
        Gate::define(CheckoutOrderReturnService::INSPECT_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 5202);
        Gate::define(CheckoutOrderReturnService::DECIDE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 5203);
        Gate::define(CheckoutOrderReturnService::CANCEL_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 5204);
    }

    private function codReturn52Open(int $quantity = 1, ?string $key = null): array
    {
        $this->codReturn52Gate();
        $this->actor(5201);
        return app(CheckoutOrderReturnService::class)->open(
            $this->orderKey, $key ?? (string) Str::uuid(),
            'customer_return', [['variant_id' => $this->variantId, 'quantity' => $quantity]]
        );
    }

    private function codReturn52WarehouseId(): int
    {
        return (int) DB::table('checkout_order_stock_reservations as s')
            ->join('checkout_order_preparations as p', 'p.id', '=', 's.checkout_preparation_id')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_key', $this->orderKey)->value('s.warehouse_id');
    }

    private function codReturn52Inspect(array $opened, array $conditions, ?string $key = null): array
    {
        $this->actor(5202);
        return app(CheckoutOrderReturnService::class)->inspect(
            $opened['return_key'], $key ?? (string) Str::uuid(),
            $this->codReturn52WarehouseId(), 'WAREHOUSE-ACT-52', $conditions
        );
    }

    private function codReturn52Conditions(array $opened, string $condition = 'resellable'): array
    {
        return array_map(static fn (array $unit): array => [
            'variant_id' => $unit['variant_id'],
            'unit_index' => $unit['unit_index'],
            'condition' => $condition,
        ], $opened['units']);
    }

















































}
