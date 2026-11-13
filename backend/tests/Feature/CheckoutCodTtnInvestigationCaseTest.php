<?php

namespace Tests\Feature;

use App\Services\Pricing\CheckoutCodNovaPayRegisterService;
use App\Services\Pricing\CheckoutCodNovaPayRegisterStatusService;
use App\Services\Pricing\CheckoutCodPrivatRefetchService;
use App\Services\Pricing\CheckoutCodPrivatRefetchStatusService;

use App\Services\Pricing\CheckoutCodNovaPayRegisterCsvParser;
use App\Services\Pricing\CheckoutCodNovaPayRegisterCsvImportService;
use Illuminate\Http\UploadedFile;

use App\Services\Pricing\CheckoutCodBankReceiptPostingService;
use App\Services\Pricing\CheckoutCodBankReceiptPostingEvaluator;
use App\Services\Pricing\CheckoutCodBankReceiptPostingStatusService;

use App\Services\Pricing\CheckoutCodRefetchRiskStatusService;

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

    public function test_order_without_intent_reports_no_case(): void
    {
        $result = $this->investigationStatus();
        $this->assertSame('no_intent', $result['status']);
        $this->assertFalse($result['ttn_submission_allowed']);
        Http::assertNothingSent();
    }

    public function test_intent_without_reconciliation_flag_cannot_open_case(): void
    {
        $this->stage();
        $this->assertSame('not_flagged', $this->investigationStatus()['status']);
        $this->expectException(DomainException::class);
        $this->openCase();
    }

    public function test_flagged_intent_can_open_single_case(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->assertSame($intent['id'], $case['intent_id']);
        $this->assertSame('awaiting_assignment', $this->investigationStatus()['status']);
        $this->assertFalse($case['reconciliation_hold_released']);
        $this->assertDatabaseCount('checkout_cod_ttn_investigation_cases', 1);
        Http::assertSentCount(3);
    }

    public function test_same_case_uuid_is_idempotent(): void
    {
        [, $case] = $this->beginCase();
        $this->assertSame($case, $this->openCase($case['case_key']));
        $this->assertDatabaseCount('checkout_cod_ttn_investigation_cases', 1);
    }

    public function test_changed_case_uuid_cannot_replace_intent_case(): void
    {
        $this->beginCase();
        $this->expectException(DomainException::class);
        $this->openCase();
    }

    public function test_unprivileged_user_cannot_open_case(): void
    {
        $this->flagged();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodTtnInvestigationService::class)->open(
            $this->orderKey, (string) Str::uuid(), CarbonImmutable::now('UTC')->addDay()
        );
    }

    public function test_unprivileged_user_cannot_read_case(): void
    {
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodTtnInvestigationStatusService::class)->inspect($this->orderKey);
    }

    public function test_assignment_is_audited_and_reported(): void
    {
        [, $case] = $this->beginCase();
        $event = $this->caseEvent($case);
        $state = $this->investigationStatus();
        $this->assertSame('assign', $event['event_type']);
        $this->assertSame(1111, $state['assignee_id']);
        $this->assertSame('in_progress', $state['status']);
        $this->assertSame(1, $state['event_count']);
    }

    public function test_second_assignment_updates_projection_without_mutating_history(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->caseEvent($case, 'assign', 'reassignment', 2222);
        $state = $this->investigationStatus();
        $this->assertSame(2222, $state['assignee_id']);
        $this->assertSame(2, $state['event_count']);
        $this->assertDatabaseCount('checkout_cod_ttn_investigation_events', 2);
    }

    public function test_cannot_escalate_before_assignment_even_using_service(): void
    {
        [, $case] = $this->beginCase();
        $this->expectException(QueryException::class);
        $this->caseEvent($case, 'escalate', 'carrier_contact_needed');
    }

    public function test_deadline_expiry_marks_case_overdue(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(2));
        $this->assertTrue($this->investigationStatus()['overdue']);
    }

    public function test_reschedule_is_append_only_and_clears_overdue_flag(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->caseEvent($case, 'reschedule', 'carrier_response_pending', null,
            CarbonImmutable::now('UTC')->addDays(3));
        $state = $this->investigationStatus();
        $this->assertFalse($state['overdue']);
        $this->assertSame(2, $state['event_count']);
    }

    public function test_escalation_is_reported_without_ttn_permission(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->caseEvent($case, 'escalate', 'carrier_contact_needed');
        $state = $this->investigationStatus();
        $this->assertSame('escalated', $state['status']);
        $this->assertFalse($state['ttn_submission_allowed']);
        $this->assertFalse($state['automatic_retry_allowed']);
    }

    public function test_event_replay_with_original_key_is_idempotent(): void
    {
        [, $case] = $this->beginCase();
        $key = (string) Str::uuid();
        $this->assertSame($this->caseEvent($case, key: $key),
            $this->caseEvent($case, key: $key));
        $this->assertDatabaseCount('checkout_cod_ttn_investigation_events', 1);
    }

    public function test_same_event_uuid_with_changed_details_is_rejected(): void
    {
        [, $case] = $this->beginCase();
        $key = (string) Str::uuid();
        $this->caseEvent($case, key: $key);
        $this->expectException(DomainException::class);
        $this->caseEvent($case, 'assign', 'reassignment', 2222, key: $key);
    }

    public function test_archive_without_reviewed_scan_is_rejected(): void
    {
        [, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->expectException(QueryException::class);
        $this->caseEvent($case, 'archive', 'inconclusive_reviewed');
    }

    public function test_archive_requires_comparison_when_reason_claims_comparison(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        $this->expectException(QueryException::class);
        $this->caseEvent($case, 'archive', 'comparison_documented');
    }

    public function test_reviewed_scan_can_be_administratively_archived_but_hold_remains(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        $this->caseEvent($case, 'archive', 'inconclusive_reviewed');
        $state = $this->investigationStatus();
        $this->assertSame('administratively_archived', $state['status']);
        $this->assertFalse($state['reconciliation_hold_released']);
        $this->assertFalse($state['automatic_retry_allowed']);
        $this->assertSame('reconciliation_required', $this->safetyStatus()['status']);
    }

    public function test_comparison_documented_can_be_archived_after_comparison(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        $this->compareDocument($scan);
        $this->caseEvent($case, 'archive', 'comparison_documented');
        $state = $this->investigationStatus();
        $this->assertSame('administratively_archived', $state['status']);
        $this->assertSame('snapshot_consistent', $state['latest_document_comparison']);
        $this->assertFalse($state['ttn_submission_allowed']);
        Http::assertNotSent(static fn (ProviderRequest $request): bool =>
            ($request->data()['calledMethod'] ?? null) === 'save');
    }

    public function test_case_opener_cannot_archive_even_with_permission(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        Gate::define(CheckoutCodTtnInvestigationService::ARCHIVE_ABILITY,
            static fn ($user): bool => in_array((int) $user->getAuthIdentifier(), [1012, 1014], true));
        $this->actor(1012);
        $this->expectException(QueryException::class);
        app(CheckoutCodTtnInvestigationService::class)->record(
            $case['case_key'], (string) Str::uuid(), 'archive', 'inconclusive_reviewed'
        );
    }

    public function test_archived_case_rejects_new_events(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $scan = $this->scan($intent);
        $this->independentReview($scan);
        $this->caseEvent($case, 'archive', 'inconclusive_reviewed');
        $this->expectException(DomainException::class);
        $this->caseEvent($case, 'assign', 'reassignment', 2222);
    }

    public function test_database_cannot_rewrite_case(): void
    {
        [, $case] = $this->beginCase();
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_ttn_investigation_cases')->where('id', $case['id'])
            ->update(['opened_by' => 999]);
    }

    public function test_database_cannot_rewrite_event_history(): void
    {
        [, $case] = $this->beginCase();
        $event = $this->caseEvent($case);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_ttn_investigation_events')->where('id', $event['id'])
            ->update(['reason_code' => 'reassignment']);
    }

    public function test_status_redacts_recipient_and_candidate_number(): void
    {
        [$intent, $case] = $this->beginCase();
        $this->caseEvent($case);
        $this->scan($intent);
        $json = json_encode($this->investigationStatus(), JSON_THROW_ON_ERROR);
        foreach (['Петренко', '380671234567', self::RECIPIENT_CONTACT,
                     self::RECIPIENT_COUNTERPARTY, self::WAYBILL, 'ttn-gate-test-key'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

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

    public function test_snapshot_cannot_be_reviewed_after_evidence_changes(): void
    {
        $intent = $this->flagged();
        $scan = $this->scan($intent);

        $this->actor(1301);

        $snapshot = app(CheckoutCodTtnResolutionService::class)
            ->capture(
                $this->orderKey,
                (string) Str::uuid()
            );

        $this->independentReview($scan);

        $this->actor(1302);

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnResolutionService::class)->review(
            $snapshot['operation_key'],
            (string) Str::uuid(),
            'retain_hold',
            'evidence_not_authoritative'
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

    public function test_conflicting_candidate_numbers_are_flagged(): void
    {
        $intent = $this->flagged();

        $this->scan($intent);
        $this->scan(
            $intent,
            number: self::OTHER_WAYBILL
        );

        $this->actor(1200);

        $status = app(CheckoutCodTtnResolutionStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'conflicting_evidence',
            $status['evidence_outcome']
        );

        $this->assertFalse($status['ttn_submission_allowed']);
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

    public function test_uncertain_ttn_outcome_prevents_dispatch_preparation(): void
    {
        $this->flagged();
        $this->actor(1401);

        $this->expectException(DomainException::class);

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

    public function test_independent_review_never_authorizes_live_ttn(): void
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

        $this->assertFalse($review['carrier_permission_proven']);

        $this->actor(1200);

        $status = app(CheckoutCodTtnDispatchStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'awaiting_trusted_carrier_authorization',
            $status['status']
        );

        $this->assertFalse($status['ttn_submission_allowed']);
        $this->assertFalse($status['ready_for_dispatch']);
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

    public function test_new_safety_event_invalidates_pending_dispatch_review(): void
    {
        $intent = $this->stage();
        $this->actor(1401);

        $plan = app(CheckoutCodTtnDispatchPlanService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->event(
            $intent,
            'reconciliation_required',
            'reported_timeout'
        );

        $this->actor(1402);

        $this->expectException(DomainException::class);

        app(CheckoutCodTtnDispatchPlanService::class)->review(
            $plan['operation_key'],
            (string) Str::uuid(),
            'reviewed_for_future_submission',
            'local_plan_checked'
        );
    }

    public function test_rejected_dispatch_plan_stays_blocked(): void
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
            'rejected',
            'manual_risk_rejection'
        );

        $this->actor(1200);

        $status = app(CheckoutCodTtnDispatchStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame('rejected', $status['status']);
        $this->assertFalse($status['automatic_retry_allowed']);
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


    public function test_accepted_pack_enables_internal_slip_not_carrier_label(): void
    {
        $this->warehouseReviewedDispatchPlan();
        $this->warehouseAcceptedPackCheck();

        $this->actor(1200);

        $status = app(CheckoutCodWarehousePackStatusService::class)
            ->inspect($this->orderKey);

        $this->assertSame(
            'internal_pack_verified',
            $status['status']
        );

        $this->assertTrue($status['internal_pick_slip_available']);
        $this->assertFalse($status['carrier_label_available']);
        $this->assertFalse($status['ttn_submission_allowed']);
    }

    public function test_rejected_pack_never_allows_internal_slip(): void
    {
        $this->warehouseReviewedDispatchPlan();

        $check = $this->warehousePackCheck();

        $this->actor(1502);

        app(CheckoutCodWarehousePackService::class)->review(
            $check['operation_key'],
            (string) Str::uuid(),
            'rejected',
            'packaging_damaged'
        );

        $this->actor(1503);

        $this->expectException(DomainException::class);

        app(CheckoutCodInternalPickSlipService::class)->generate(
            $check['operation_key'],
            (string) Str::uuid()
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

    public function test_bank_settlement_requires_existing_financial_case(): void
    {
        $this->actor(1901);
        $this->expectException(DomainException::class);
        app(CheckoutCodSettlementBankReceiptService::class)->record(
            (string) Str::uuid(), (string) Str::uuid(), 'BANKREF_NO_CASE', 680
        );
    }

    public function test_bank_settlement_receipt_is_only_claim_and_keeps_order_pending(): void
    {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case);
        $this->assertSame(680, $bank['claimed_net_minor']);
        $this->assertFalse($bank['funds_received_verified']);
        $this->assertFalse($bank['cod_paid']);
        $this->assertDatabaseCount('checkout_cod_settlement_bank_receipts', 1);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_bank_settlement_receipt_replay_is_idempotent(): void
    {
        $case = $this->settlementReadyCase();
        $this->actor(1901);
        $service = app(CheckoutCodSettlementBankReceiptService::class);
        $key = (string) Str::uuid();
        $a = $service->record($case['case_key'], $key, 'BANKREF_REPLAY_1001', 680);
        $this->assertSame($a, $service->record(
            $case['case_key'], $key, 'BANKREF_REPLAY_1001', 680
        ));
        $this->assertDatabaseCount('checkout_cod_settlement_bank_receipts', 1);
    }

    public function test_bank_settlement_global_reference_cannot_be_reused_under_different_kind(): void
    {
        $case = $this->settlementReadyCase();
        $this->actor(1901);
        $service = app(CheckoutCodSettlementBankReceiptService::class);
        $service->record($case['case_key'], (string) Str::uuid(),
            'BANKREF_DUPLICATE_1001', 680, 'bank_statement_claim');
        $this->expectException(DomainException::class);
        $service->record($case['case_key'], (string) Str::uuid(),
            'BANKREF_DUPLICATE_1001', 680, 'manual_bank_claim');
    }

    public function test_bank_settlement_bank_receipt_needs_permission(): void
    {
        $case = $this->settlementReadyCase();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodSettlementBankReceiptService::class)->record(
            $case['case_key'], (string) Str::uuid(), 'BANKREF_FORBIDDEN', 680
        );
    }

    public function test_bank_settlement_pair_requires_reviewed_novapay_claim(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);
        $bank = $this->bankSettlementClaim($case);
        $this->actor(1902);
        $this->expectException(DomainException::class);
        app(CheckoutCodSettlementBankMatchService::class)->propose(
            $claim['operation_key'], $bank['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_settlement_exact_pair_never_confirms_funds(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case);
        $match = $this->bankSettlementMatch($claim, $bank);
        $this->assertSame('amounts_match_unverified', $match['match_result']);
        $this->assertSame(0, $match['difference_minor']);
        $this->assertFalse($match['funds_received_verified']);
        $this->assertFalse($match['cod_paid']);
        $this->assertDatabaseCount('checkout_cod_settlement_bank_matches', 1);
        Http::assertNotSent(static fn (ProviderRequest $r): bool =>
            ($r->data()['calledMethod'] ?? null) === 'save');
    }

    public function test_bank_settlement_net_mismatch_is_identified(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch(
            $claim, $this->bankSettlementClaim($case, 650)
        );
        $this->assertSame('bank_net_mismatch', $match['match_result']);
        $this->assertSame(-30, $match['difference_minor']);
    }

    public function test_bank_settlement_novapay_discrepancy_takes_priority(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim(2500, 1320, 1180);
        $match = $this->bankSettlementMatch(
            $claim, $this->bankSettlementClaim($case, 1180)
        );
        $this->assertSame('source_claim_discrepancy', $match['match_result']);
        $this->assertSame(0, $match['difference_minor']);
    }

    public function test_bank_settlement_pair_uuid_replay_is_idempotent(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case);
        $key = (string) Str::uuid();
        $match = $this->bankSettlementMatch($claim, $bank, $key);
        $this->assertSame($match, $this->bankSettlementMatch($claim, $bank, $key));
        $this->assertDatabaseCount('checkout_cod_settlement_bank_matches', 1);
    }

    public function test_bank_settlement_same_claim_cannot_be_paired_twice(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case);
        $this->bankSettlementMatch($claim, $bank);
        $this->expectException(DomainException::class);
        $this->bankSettlementMatch($claim, $bank);
    }

    public function test_bank_settlement_novapay_claim_author_cannot_propose_pair(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $bank = $this->bankSettlementClaim($case);
        Gate::define(CheckoutCodSettlementBankMatchService::PROPOSE_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [1802, 1902], true));
        $this->actor(1802);
        $this->expectException(DomainException::class);
        app(CheckoutCodSettlementBankMatchService::class)->propose(
            $claim['operation_key'], $bank['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_settlement_independent_review_keeps_external_confirmation_pending(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case));
        $this->actor(1903);
        $review = app(CheckoutCodSettlementBankReviewService::class)->review(
            $match['operation_key'], (string) Str::uuid(),
            'retain_external_confirmation', 'bank_and_novapay_unverified'
        );
        $this->assertFalse($review['funds_received_verified']);
        $this->actor(1200);
        $status = app(CheckoutCodSettlementBankStatusService::class)
            ->inspect($this->orderKey);
        $this->assertSame('claims_correlated_external_confirmation_required', $status['status']);
        $this->assertSame(1, $status['match_reviews_count']);
        $this->assertFalse($status['cod_paid_by_reconciliation']);
    }

    public function test_bank_settlement_matching_review_replays_idempotently(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case));
        $this->actor(1903);
        $service = app(CheckoutCodSettlementBankReviewService::class);
        $key = (string) Str::uuid();
        $a = $service->review($match['operation_key'], $key,
            'retain_external_confirmation', 'bank_and_novapay_unverified');
        $this->assertSame($a, $service->review($match['operation_key'], $key,
            'retain_external_confirmation', 'bank_and_novapay_unverified'));
        $this->assertDatabaseCount('checkout_cod_settlement_bank_reviews', 1);
    }

    public function test_bank_settlement_mismatch_requires_escalation(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case, 650));
        $this->actor(1903);
        $review = app(CheckoutCodSettlementBankReviewService::class)->review(
            $match['operation_key'], (string) Str::uuid(),
            'escalate_difference', 'bank_net_mismatch'
        );
        $this->assertSame('escalate_difference', $review['decision']);
        $this->actor(1200);
        $this->assertSame('bank_discrepancy_requires_investigation',
            app(CheckoutCodSettlementBankStatusService::class)
                ->inspect($this->orderKey)['status']);
    }

    public function test_bank_settlement_reviewer_cannot_review_own_pair(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case));
        Gate::define(CheckoutCodSettlementBankReviewService::REVIEW_ABILITY,
            static fn ($u): bool => in_array((int) $u->getAuthIdentifier(), [1902, 1903], true));
        $this->actor(1902);
        $this->expectException(DomainException::class);
        app(CheckoutCodSettlementBankReviewService::class)->review(
            $match['operation_key'], (string) Str::uuid(),
            'retain_external_confirmation', 'bank_and_novapay_unverified'
        );
    }

    public function test_bank_settlement_invalid_review_decision_is_rejected(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $match = $this->bankSettlementMatch($claim, $this->bankSettlementClaim($case));
        $this->actor(1903);
        $this->expectException(InvalidArgumentException::class);
        app(CheckoutCodSettlementBankReviewService::class)->review(
            $match['operation_key'], (string) Str::uuid(),
            'escalate_difference', 'bank_net_mismatch'
        );
    }

    public function test_bank_settlement_receipt_is_database_immutable(): void
    {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_settlement_bank_receipts')
            ->where('id', $bank['id'])->update(['claimed_net_minor' => 1]);
    }

    public function test_bank_settlement_status_without_case_is_safe(): void
    {
        $this->actor(1200);
        $report = app(CheckoutCodSettlementBankStatusService::class)
            ->inspect($this->orderKey);
        $this->assertSame('no_settlement_case', $report['status']);
        $this->assertFalse($report['funds_received_verified']);
    }

    public function test_bank_settlement_queue_is_pii_free(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();
        $this->actor(1901);
        $bank = app(CheckoutCodSettlementBankReceiptService::class)->record(
            $case['case_key'], (string) Str::uuid(),
            'BANKREF_PRIVATE_2001', 680
        );
        $this->bankSettlementMatch($claim, $bank);
        $this->actor(1200);
        $queue = app(CheckoutCodSettlementBankQueueService::class)->recent();
        $this->assertCount(1, $queue['items']);
        $json = json_encode($queue, JSON_THROW_ON_ERROR);
        foreach (['BANKREF_PRIVATE_2001', 'Петренко', '380671234567', 'ttn-gate-test-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertFalse($queue['cod_paid']);
    }

    public function test_bank_settlement_queue_requires_reader_permission(): void
    {
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodSettlementBankQueueService::class)->recent();
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

    public function test_bank_batch_record_requires_permission(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodBankBatchService::class)->record(
            (string) Str::uuid(),
            'BATCHREF_DENIED',
            680
        );
    }

    public function test_bank_batch_record_is_unverified_and_has_global_reference(): void
    {
        $batch = $this->bankBatchCreate();

        $this->assertSame(680, $batch['claimed_net_minor']);
        $this->assertFalse($batch['funds_received_verified']);
        $this->assertFalse($batch['cod_paid']);

        $this->assertDatabaseCount('checkout_cod_bank_batches', 1);
        $this->assertDatabaseCount('checkout_cod_bank_reference_claims', 1);
    }

    public function test_bank_batch_record_exact_replay_is_idempotent(): void
    {
        $key = (string) Str::uuid();

        $batch = $this->bankBatchCreate(
            680,
            'BATCHREF_REPLAY_1001',
            $key
        );

        $this->assertSame(
            $batch,
            $this->bankBatchCreate(
                680,
                'BATCHREF_REPLAY_1001',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_bank_reference_claims',
            1
        );
    }

    public function test_bank_batch_duplicate_external_reference_is_blocked(): void
    {
        $this->bankBatchCreate(
            680,
            'BATCHREF_DUPLICATE_1001'
        );

        $this->expectException(DomainException::class);

        $this->bankBatchCreate(
            680,
            'BATCHREF_DUPLICATE_1001'
        );
    }

    public function test_bank_batch_existing_single_bank_reference_cannot_be_reclaimed(): void
    {
        $case = $this->settlementReadyCase();

        $this->actor(1901);

        app(CheckoutCodSettlementBankReceiptService::class)->record(
            $case['case_key'],
            (string) Str::uuid(),
            'BATCHREF_CROSS_PATH_1001',
            680
        );

        $this->expectException(DomainException::class);

        $this->bankBatchCreate(
            680,
            'BATCHREF_CROSS_PATH_1001'
        );
    }

    public function test_bank_batch_reference_cannot_be_reused_as_single_receipt(): void
    {
        $case = $this->settlementReadyCase();

        $this->bankBatchCreate(
            680,
            'BATCHREF_CROSS_PATH_1002'
        );

        $this->actor(1901);

        $this->expectException(QueryException::class);

        app(CheckoutCodSettlementBankReceiptService::class)->record(
            $case['case_key'],
            (string) Str::uuid(),
            'BATCHREF_CROSS_PATH_1002',
            680
        );
    }

    public function test_bank_batch_allocation_requires_reviewed_novapay_claim(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);
        $batch = $this->bankBatchCreate();

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch, $claim);
    }

    public function test_bank_batch_partial_allocation_does_not_settle_cod(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate(1000);
        $allocation = $this->bankBatchAllocate(
            $batch,
            $claim,
            500
        );

        $this->assertSame(500, $allocation['amount_minor']);

        $this->actor(1200);

        $status = app(CheckoutCodBankBatchStatusService::class)
            ->inspect($batch['operation_key']);

        $this->assertSame(500, $status['remaining_minor']);
        $this->assertSame(
            'awaiting_independent_review',
            $status['status']
        );
        $this->assertFalse($status['cod_paid']);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

    public function test_bank_batch_allocation_exact_replay_is_idempotent(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate();
        $key = (string) Str::uuid();

        $first = $this->bankBatchAllocate(
            $batch,
            $claim,
            680,
            $key
        );

        $this->assertSame(
            $first,
            $this->bankBatchAllocate(
                $batch,
                $claim,
                680,
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_bank_batch_allocations',
            1
        );
    }

    public function test_bank_batch_same_claim_cannot_be_allocated_twice_to_one_batch(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(1000);

        $this->bankBatchAllocate($batch, $claim, 300);

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch, $claim, 300);
    }

    public function test_bank_batch_novapay_claim_cannot_be_overallocated_across_batches(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch1 = $this->bankBatchCreate(500);
        $this->bankBatchAllocate($batch1, $claim, 500);

        $batch2 = $this->bankBatchCreate(500);

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch2, $claim, 200);
    }

    public function test_bank_batch_one_transfer_can_cover_two_cod_orders(): void
    {
        [$case1, $claim1] = $this->bankBatchSource();
        [$case2, $claim2] = $this->bankBatchSecondCase();

        $this->assertNotSame(
            $case1['id'],
            $case2['id']
        );

        $batch = $this->bankBatchCreate(1360);

        $this->bankBatchAllocate($batch, $claim1, 680);
        $this->bankBatchAllocate($batch, $claim2, 680);

        $this->actor(1200);

        $status = app(CheckoutCodBankBatchStatusService::class)
            ->inspect($batch['operation_key']);

        $this->assertSame(2, $status['allocation_count']);
        $this->assertSame(0, $status['remaining_minor']);
        $this->assertFalse($status['funds_received_verified']);
    }

    public function test_bank_batch_cannot_allocate_more_than_batch_amount(): void
    {
        [$case1, $claim1] = $this->bankBatchSource();
        [$case2, $claim2] = $this->bankBatchSecondCase();

        $batch = $this->bankBatchCreate(1000);

        $this->bankBatchAllocate($batch, $claim1, 680);

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch, $claim2, 680);
    }

    public function test_bank_batch_independent_review_seals_fully_allocated_batch(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate();
        $this->bankBatchAllocate($batch, $claim);

        $this->actor(2003);

        $review = app(CheckoutCodBankBatchReviewService::class)
            ->review(
                $batch['operation_key'],
                (string) Str::uuid()
            );

        $this->assertSame(
            'allocation_balanced_unverified',
            $review['decision']
        );
        $this->assertFalse($review['cod_paid']);

        $this->actor(1200);

        $this->assertTrue(
            app(CheckoutCodBankBatchStatusService::class)
                ->inspect($batch['operation_key'])['sealed']
        );
    }

    public function test_bank_batch_partial_review_preserves_unallocated_amount(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(1000);

        $this->bankBatchAllocate($batch, $claim, 500);

        $this->actor(2003);

        $review = app(CheckoutCodBankBatchReviewService::class)
            ->review(
                $batch['operation_key'],
                (string) Str::uuid()
            );

        $this->assertSame(
            'allocation_partial_unverified',
            $review['decision']
        );
        $this->assertSame(
            'unallocated_balance',
            $review['reason_code']
        );
    }

    public function test_bank_batch_review_exact_replay_is_idempotent(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate();

        $this->bankBatchAllocate($batch, $claim);

        $this->actor(2003);

        $key = (string) Str::uuid();
        $svc = app(CheckoutCodBankBatchReviewService::class);

        $first = $svc->review(
            $batch['operation_key'],
            $key
        );

        $this->assertSame(
            $first,
            $svc->review($batch['operation_key'], $key)
        );

        $this->assertDatabaseCount(
            'checkout_cod_bank_batch_reviews',
            1
        );
    }

    public function test_bank_batch_creator_cannot_review_even_with_gate_permission(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate();

        $this->bankBatchAllocate($batch, $claim);

        Gate::define(
            CheckoutCodBankBatchReviewService::REVIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2001, 2003],
                true
            )
        );

        $this->actor(2001);

        $this->expectException(DomainException::class);

        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_bank_batch_review_prevents_later_allocations(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate(1000);

        $this->bankBatchAllocate($batch, $claim, 300);

        $this->actor(2003);

        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'],
            (string) Str::uuid()
        );

        $secondClaim = $this->settlementClaim($case['case_key']);

        $this->actor(1803);

        app(CheckoutCodSettlementReviewService::class)->review(
            $secondClaim['operation_key'],
            (string) Str::uuid(),
            'retain_unverified',
            'external_confirmation_required'
        );

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate(
            $batch,
            $secondClaim,
            100
        );
    }

    public function test_bank_batch_cannot_use_claim_already_matched_in_block24(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $this->actor(1901);

        $bank = app(CheckoutCodSettlementBankReceiptService::class)
            ->record(
                $case['case_key'],
                (string) Str::uuid(),
                'BANKREF_PATH24_1001',
                680
            );

        $this->actor(1902);

        app(CheckoutCodSettlementBankMatchService::class)->propose(
            $claim['operation_key'],
            $bank['operation_key'],
            (string) Str::uuid()
        );

        $batch = $this->bankBatchCreate();

        $this->expectException(DomainException::class);

        $this->bankBatchAllocate($batch, $claim);
    }

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

    public function test_financial_exception_source_must_exist(): void
    {
        $this->actor(2101);

        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialExceptionService::class)->report(
            'single',
            (string) Str::uuid(),
            (string) Str::uuid(),
            'incorrect_amount'
        );
    }

    public function test_financial_exception_report_requires_permission(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialExceptionService::class)->report(
            'batch',
            (string) Str::uuid(),
            (string) Str::uuid(),
            'incorrect_amount'
        );
    }

    public function test_financial_exception_creates_immediate_hold_on_bank_claim(): void
    {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case);

        $incident = $this->financialExceptionReport(
            'single',
            $bank
        );

        $this->assertSame(
            'pending_review_hold',
            $incident['status_at_creation']
        );

        $this->assertFalse($incident['refund_executed']);

        $this->actor(1200);

        $status = app(CheckoutCodFinancialExceptionStatusService::class)
            ->inspect('single', $bank['operation_key']);

        $this->assertTrue($status['financial_hold']);
        $this->assertSame(
            'pending_review_hold',
            $status['status']
        );

        $this->assertSame(
            'financial_exception_hold',
            app(CheckoutCodSettlementBankStatusService::class)
                ->inspect($this->orderKey)['status']
        );
    }

    public function test_financial_exception_exact_report_replay_is_idempotent(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $key = (string) Str::uuid();

        $first = $this->financialExceptionReport(
            'single',
            $bank,
            'incorrect_amount',
            $key
        );

        $this->assertSame(
            $first,
            $this->financialExceptionReport(
                'single',
                $bank,
                'incorrect_amount',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_financial_exceptions',
            1
        );
    }

    public function test_financial_exception_reused_report_uuid_with_new_reason_is_rejected(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $key = (string) Str::uuid();

        $this->financialExceptionReport(
            'single',
            $bank,
            'incorrect_amount',
            $key
        );

        $this->expectException(DomainException::class);

        $this->financialExceptionReport(
            'single',
            $bank,
            'wrong_source_reference',
            $key
        );
    }

    public function test_financial_exception_second_open_hold_is_rejected(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $this->financialExceptionReport('single', $bank);

        $this->expectException(DomainException::class);

        $this->financialExceptionReport('single', $bank);
    }

    public function test_financial_exception_unprivileged_reviewer_is_rejected(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        $this->actor(999);
        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialExceptionService::class)->review(
            $incident['exception_key'],
            (string) Str::uuid(),
            'hold_for_investigation',
            'await_external_evidence'
        );
    }

    public function test_financial_exception_reporter_cannot_self_review(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        Gate::define(
            CheckoutCodFinancialExceptionService::REVIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2101, 2102],
                true
            )
        );

        $this->actor(2101);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialExceptionService::class)->review(
            $incident['exception_key'],
            (string) Str::uuid(),
            'hold_for_investigation',
            'await_external_evidence'
        );
    }

    public function test_financial_exception_bank_claim_author_cannot_review(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        Gate::define(
            CheckoutCodFinancialExceptionService::REVIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [1901, 2102],
                true
            )
        );

        $this->actor(1901);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialExceptionService::class)->review(
            $incident['exception_key'],
            (string) Str::uuid(),
            'hold_for_investigation',
            'await_external_evidence'
        );
    }

    public function test_financial_exception_independent_review_keeps_hold(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);
        $review = $this->financialExceptionReview($incident);

        $this->assertSame(
            'hold_for_investigation',
            $review['decision']
        );

        $this->assertFalse($review['refund_executed']);

        $this->actor(1200);

        $this->assertSame(
            'investigation_hold',
            app(CheckoutCodFinancialExceptionStatusService::class)
                ->inspect('single', $bank['operation_key'])['status']
        );
    }

    public function test_financial_exception_dismissal_releases_hold_and_allows_new_report(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        $this->financialExceptionReview(
            $incident,
            'dismiss_report'
        );

        $this->actor(1200);

        $status = app(CheckoutCodFinancialExceptionStatusService::class)
            ->inspect('single', $bank['operation_key']);

        $this->assertFalse($status['financial_hold']);
        $this->assertSame('dismissed', $status['status']);

        $new = $this->financialExceptionReport(
            'single',
            $bank,
            'bank_reversal_reported'
        );

        $this->assertNotSame(
            $incident['exception_key'],
            $new['exception_key']
        );

        $this->assertDatabaseCount(
            'checkout_cod_financial_exceptions',
            2
        );
    }

    public function test_financial_exception_review_exact_replay_is_idempotent(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);
        $key = (string) Str::uuid();

        $first = $this->financialExceptionReview(
            $incident,
            'dismiss_report',
            $key
        );

        $this->assertSame(
            $first,
            $this->financialExceptionReview(
                $incident,
                'dismiss_report',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_financial_exception_reviews',
            1
        );
    }

    public function test_financial_exception_conflicting_second_review_is_rejected(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase()
        );

        $incident = $this->financialExceptionReport('single', $bank);

        $this->financialExceptionReview($incident);

        $this->expectException(DomainException::class);

        $this->financialExceptionReview(
            $incident,
            'dismiss_report'
        );
    }

    public function test_financial_exception_single_match_blocked_by_bank_hold(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();

        $bank = $this->bankSettlementClaim($case);

        $this->financialExceptionReport('single', $bank);

        $this->expectException(QueryException::class);

        $this->bankSettlementMatch($claim, $bank);
    }

    public function test_financial_exception_single_match_allowed_after_dismissal(): void
    {
        [$case, $claim] = $this->bankSettlementReviewedClaim();

        $bank = $this->bankSettlementClaim($case);

        $incident = $this->financialExceptionReport('single', $bank);

        $this->financialExceptionReview(
            $incident,
            'dismiss_report'
        );

        $match = $this->bankSettlementMatch($claim, $bank);

        $this->assertSame(
            'amounts_match_unverified',
            $match['match_result']
        );
    }

    public function test_financial_exception_novapay_hold_blocks_source_review(): void
    {
        $case = $this->settlementReadyCase();
        $claim = $this->settlementClaim($case['case_key']);

        $this->financialExceptionReport(
            'novapay',
            $claim,
            'customer_return_reported'
        );

        $this->actor(1803);
        $this->expectException(QueryException::class);

        app(CheckoutCodSettlementReviewService::class)->review(
            $claim['operation_key'],
            (string) Str::uuid(),
            'retain_unverified',
            'external_confirmation_required'
        );
    }

    public function test_financial_exception_novapay_hold_blocks_batch_allocation(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(680);

        $this->financialExceptionReport(
            'novapay',
            $claim,
            'order_cancellation_reported'
        );

        $this->expectException(QueryException::class);

        $this->bankBatchAllocate($batch, $claim);
    }

    public function test_financial_exception_batch_hold_blocks_new_allocation(): void
    {
        [$case, $claim] = $this->bankBatchSource();
        $batch = $this->bankBatchCreate(680);

        $this->financialExceptionReport(
            'batch',
            $batch,
            'bank_reversal_reported'
        );

        $this->expectException(QueryException::class);

        $this->bankBatchAllocate($batch, $claim);
    }

    public function test_financial_exception_batch_reviewed_before_hold_shows_dispute(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate(680);
        $this->bankBatchAllocate($batch, $claim);

        $this->actor(2003);

        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'],
            (string) Str::uuid()
        );

        $this->financialExceptionReport(
            'batch',
            $batch,
            'bank_reversal_reported'
        );

        $this->actor(1200);

        $status = app(CheckoutCodBankBatchStatusService::class)
            ->inspect($batch['operation_key']);

        $this->assertSame(
            'financial_exception_hold',
            $status['status']
        );

        $this->assertTrue(
            $status['financial_exception_hold']
        );

        $this->assertFalse(
            $status['funds_received_verified']
        );

        $this->assertSame(
            'financial_exception_hold',
            app(CheckoutCodSettlementBankStatusService::class)
                ->inspect($this->orderKey)['status']
        );
    }

    public function test_financial_exception_batch_cannot_be_reviewed_during_hold(): void
    {
        [$case, $claim] = $this->bankBatchSource();

        $batch = $this->bankBatchCreate();
        $this->bankBatchAllocate($batch, $claim);

        $this->financialExceptionReport(
            'batch',
            $batch,
            'wrong_source_reference'
        );

        $this->actor(2003);
        $this->expectException(QueryException::class);

        app(CheckoutCodBankBatchReviewService::class)->review(
            $batch['operation_key'],
            (string) Str::uuid()
        );
    }

    public function test_financial_exception_history_cannot_be_modified(): void
    {
        $batch = $this->bankBatchCreate();

        $incident = $this->financialExceptionReport(
            'batch',
            $batch
        );

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_financial_exceptions')
            ->where('id', $incident['id'])
            ->update([
                'reason_code' => 'wrong_source_reference',
            ]);
    }

    public function test_financial_exception_queue_redacts_references(): void
    {
        $bank = $this->bankSettlementClaim(
            $this->settlementReadyCase(),
            680,
            'EXCEPTION_PRIVATE_REF_1'
        );

        $this->financialExceptionReport('single', $bank);

        $this->actor(1200);

        $queue = app(CheckoutCodFinancialExceptionQueueService::class)
            ->recent();

        $this->assertCount(1, $queue['items']);

        $json = json_encode($queue, JSON_THROW_ON_ERROR);

        foreach ([
                     'EXCEPTION_PRIVATE_REF_1',
                     'Петренко',
                     '380671234567',
                 ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $json
            );
        }

        $this->assertFalse(
            $queue['automatic_settlement_allowed']
        );
    }

    public function test_financial_exception_status_and_queue_require_read_permission(): void
    {
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialExceptionQueueService::class)
            ->recent();
    }

    public function test_financial_exception_does_not_touch_payment_status_or_refund(): void
    {
        $case = $this->settlementReadyCase();
        $bank = $this->bankSettlementClaim($case);

        $incident = $this->financialExceptionReport(
            'single',
            $bank,
            'customer_return_reported'
        );

        $review = $this->financialExceptionReview($incident);

        $this->assertFalse($review['cod_paid']);
        $this->assertFalse($review['refund_executed']);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
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

    public function test_financial_resolution_evidence_requires_investigation_hold(): void
    {
        [, , $incident] = $this->resolutionCase(false);

        $this->actor(2201);
        $this->expectException(QueryException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->addEvidence(
                $incident['exception_key'],
                (string) Str::uuid(),
                'bank_notice_claim',
                str_repeat('a', 64)
            );
    }

    public function test_financial_resolution_two_evidence_records_are_append_only_claims(): void
    {
        [, , $incident] = $this->resolutionCase();
        $this->resolutionTwoSources($incident);

        $this->assertDatabaseCount(
            'checkout_cod_exception_evidence',
            2
        );

        $this->actor(1200);

        $status = app(
            CheckoutCodFinancialResolutionStatusService::class
        )->inspect($incident['exception_key']);

        $this->assertSame(
            'evidence_ready_for_proposal',
            $status['status']
        );
        $this->assertTrue($status['financial_hold']);
        $this->assertFalse(
            $status['bank_source_authenticated']
        );
    }

    public function test_financial_resolution_evidence_uuid_replay_is_idempotent(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->actor(2201);
        $key = (string) Str::uuid();

        $svc = app(CheckoutCodFinancialResolutionService::class);
        $digest = str_repeat('a', 64);

        $first = $svc->addEvidence(
            $incident['exception_key'],
            $key,
            'bank_notice_claim',
            $digest
        );

        $this->assertSame(
            $first,
            $svc->addEvidence(
                $incident['exception_key'],
                $key,
                'bank_notice_claim',
                $digest
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_exception_evidence',
            1
        );
    }

    public function test_financial_resolution_reused_evidence_digest_is_rejected(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionEvidence(
            $incident,
            2201,
            'bank_notice_claim',
            'same'
        );

        $this->expectException(DomainException::class);

        $this->resolutionEvidence(
            $incident,
            2202,
            'internal_reconciliation',
            'same'
        );
    }

    public function test_financial_resolution_evidence_needs_permission(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->actor(999);
        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->addEvidence(
                $incident['exception_key'],
                (string) Str::uuid(),
                'bank_notice_claim',
                str_repeat('b', 64)
            );
    }

    public function test_financial_resolution_reporter_cannot_submit_evidence_even_with_gate(): void
    {
        [, , $incident] = $this->resolutionCase();

        Gate::define(
            CheckoutCodFinancialResolutionService::EVIDENCE_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2101, 2201, 2202],
                true
            )
        );

        $this->actor(2101);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->addEvidence(
                $incident['exception_key'],
                (string) Str::uuid(),
                'bank_notice_claim',
                str_repeat('b', 64)
            );
    }

    public function test_financial_resolution_proposal_requires_two_evidence_authors(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionEvidence(
            $incident,
            2201,
            'bank_notice_claim',
            'one'
        );

        $this->resolutionEvidence(
            $incident,
            2201,
            'internal_reconciliation',
            'two'
        );

        $this->expectException(DomainException::class);

        $this->resolutionProposal($incident);
    }

    public function test_financial_resolution_proposal_requires_distinct_evidence_kinds(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionEvidence(
            $incident,
            2201,
            'bank_notice_claim',
            'one'
        );

        $this->resolutionEvidence(
            $incident,
            2202,
            'bank_notice_claim',
            'two'
        );

        $this->expectException(DomainException::class);

        $this->resolutionProposal($incident);
    }

    public function test_financial_resolution_correction_is_signed_kopiyka_advice_only(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);

        $proposal = $this->resolutionProposal($incident);

        $this->assertSame(
            680,
            $proposal['source_net_minor']
        );

        $this->assertSame(
            600,
            $proposal['recommended_net_minor']
        );

        $this->assertSame(
            -80,
            $proposal['delta_minor']
        );

        $this->assertFalse($proposal['refund_executed']);

        $this->assertDatabaseCount(
            'checkout_cod_exception_proposals',
            1
        );
    }

    public function test_financial_resolution_proposal_replay_is_idempotent(): void
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

        $this->assertSame(
            $first,
            $this->resolutionProposal(
                $incident,
                600,
                'amount_correction',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_exception_proposals',
            1
        );
    }

    public function test_financial_resolution_second_active_proposal_is_rejected(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $this->resolutionProposal($incident);

        $this->expectException(DomainException::class);

        $this->resolutionProposal($incident, 500);
    }

    public function test_financial_resolution_evidence_freezes_during_proposal(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $this->resolutionProposal($incident);

        $this->expectException(DomainException::class);

        $this->resolutionEvidence(
            $incident,
            2201,
            'novapay_notice_claim',
            'late'
        );
    }

    public function test_financial_resolution_proposer_cannot_be_evidence_author(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);

        Gate::define(
            CheckoutCodFinancialResolutionService::PROPOSE_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2201, 2203],
                true
            )
        );

        $this->actor(2201);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->propose(
                $incident['exception_key'],
                (string) Str::uuid(),
                'amount_correction',
                600
            );
    }

    public function test_financial_resolution_reviewer_requires_permission(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $proposal = $this->resolutionProposal($incident);

        $this->actor(999);
        $this->expectException(AuthorizationException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->review(
                $proposal['operation_key'],
                (string) Str::uuid(),
                'ready_for_external_verification',
                'recommendation_documented'
            );
    }

    public function test_financial_resolution_proposer_cannot_review_own_proposal(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $proposal = $this->resolutionProposal($incident);

        Gate::define(
            CheckoutCodFinancialResolutionService::REVIEW_ABILITY,
            static fn ($u): bool => in_array(
                (int) $u->getAuthIdentifier(),
                [2203, 2204],
                true
            )
        );

        $this->actor(2203);
        $this->expectException(DomainException::class);

        app(CheckoutCodFinancialResolutionService::class)
            ->review(
                $proposal['operation_key'],
                (string) Str::uuid(),
                'ready_for_external_verification',
                'recommendation_documented'
            );
    }

    public function test_financial_resolution_review_is_not_funds_or_refund(): void
    {
        [, , $incident] = $this->resolutionCase();

        $this->resolutionTwoSources($incident);
        $proposal = $this->resolutionProposal($incident);

        $decision = $this->resolutionReview($proposal);

        $this->assertSame(
            'ready_for_external_verification',
            $decision['decision']
        );

        $this->assertFalse(
            $decision['financial_hold_released']
        );
        $this->assertFalse($decision['refund_executed']);

        $this->actor(1200);

        $status = app(
            CheckoutCodFinancialResolutionStatusService::class
        )->inspect($incident['exception_key']);

        $this->assertSame(
            'recommendation_awaiting_external_verification',
            $status['status']
        );

        $this->assertTrue($status['financial_hold']);

        $this->assertSame(
            'pending',
            DB::table('orders')
                ->where('order_key', $this->orderKey)
                ->value('payment_status')
        );
    }

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

    public function test_journal_draft34_reviewer_cannot_be_author(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        Gate::define(
            CheckoutCodJournalDraftService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3401
        );

        $this->actor(3401);

        $this->expectException(DomainException::class);

        app(CheckoutCodJournalDraftService::class)->review(
            $draft['operation_key'],
            (string) Str::uuid(),
            'retain_unposted'
        );
    }

    public function test_journal_draft34_reviewer_cannot_be_bank_importer(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        Gate::define(
            CheckoutCodJournalDraftService::REVIEW_ABILITY,
            static fn ($u): bool =>
                (int) $u->getAuthIdentifier() === 3001
        );

        $this->actor(3001);

        $this->expectException(DomainException::class);

        app(CheckoutCodJournalDraftService::class)->review(
            $draft['operation_key'],
            (string) Str::uuid(),
            'retain_unposted'
        );
    }

    public function test_journal_draft34_prepare_replay_idempotent(): void
    {
        [, $packet] = $this->journal34Source();

        $key = (string) Str::uuid();

        $first = $this->journal34Prepare($packet, $key);
        $second = $this->journal34Prepare($packet, $key);

        $this->assertSame(
            $first['draft_id'],
            $second['draft_id']
        );

        $this->assertTrue($second['idempotent_replay']);

        $this->assertDatabaseCount(
            'checkout_cod_journal_drafts',
            1
        );
    }

    public function test_journal_draft34_second_draft_for_packet_rejected(): void
    {
        [, $packet] = $this->journal34Source();

        $this->journal34Prepare($packet);

        $this->expectException(DomainException::class);

        $this->journal34Prepare($packet);
    }

    public function test_journal_draft34_review_replay_idempotent(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        $key = (string) Str::uuid();

        $first = $this->journal34Review($draft, key: $key);
        $second = $this->journal34Review($draft, key: $key);

        $this->assertSame(
            $first['review_id'],
            $second['review_id']
        );

        $this->assertTrue($second['idempotent_replay']);
    }

    public function test_journal_draft34_second_bank_credit_invalidates_review(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        $this->privatStatement30Fixture['transactions'][0]['REFN']
            = 'BANK34_SECOND';

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
            CheckoutCodJournalDraftStatusService::class
        )->inspect($packet['operation_key']);

        $this->assertSame(
            'journal_draft_stale',
            $status['status']
        );

        $this->actor(3402);

        $this->expectException(DomainException::class);

        app(CheckoutCodJournalDraftService::class)->review(
            $draft['operation_key'],
            (string) Str::uuid(),
            'retain_unposted'
        );
    }

    public function test_journal_draft34_financial_hold_invalidates_review(): void
    {
        [$source, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        $this->financialExceptionReport(
            'single',
            $source,
            'incorrect_amount'
        );

        $this->expectException(DomainException::class);

        $this->journal34Review($draft);
    }

    public function test_journal_draft34_entries_are_immutable(): void
    {
        [, $packet] = $this->journal34Source();

        $this->journal34Prepare($packet);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_journal_draft_entries')
            ->update(['amount_minor' => 1]);
    }

    public function test_journal_draft34_database_rejects_forged_extra_entry(): void
    {
        [, $packet] = $this->journal34Source();

        $draft = $this->journal34Prepare($packet);

        $first = DB::table('checkout_cod_journal_draft_entries')
            ->where('draft_id', $draft['draft_id'])
            ->first();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_journal_draft_entries')->insert([
            'draft_id' => $draft['draft_id'],
            'coverage_line_id' => $first->coverage_line_id,
            'order_id' => $first->order_id,
            'entry_kind' => 'carrier_collection',
            'debit_account' => 'draft_carrier_clearing',
            'credit_account' => 'draft_cod_receivable',
            'amount_minor' => 1,
            'created_at' => now(),
        ]);
    }

    public function test_journal_draft34_queue_redacted_permissioned(): void
    {
        [, $packet] = $this->journal34Source();

        $this->journal34Prepare($packet);

        $this->actor(1200);

        $queue = app(
            CheckoutCodJournalDraftStatusService::class
        )->recent();

        $this->assertCount(1, $queue['items']);
        $this->assertFalse(
            $queue['automatic_payment_posting_allowed']
        );

        $encoded = json_encode(
            $queue,
            JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString(
            'UA111111111111111111111111111',
            $encoded
        );

        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        app(
            CheckoutCodJournalDraftStatusService::class
        )->recent();
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

    public function test_adjustment_plan35_return_exposure_remains_unexecuted(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        $this->assertSame('return_case_followup', $plan['plan_kind']);
        $this->assertSame(1000, $plan['requested_refund_minor']);
        $this->assertSame(2000, $plan['original_gross_minor']);
        $this->assertSame(1320, $plan['original_fee_minor']);
        $this->assertSame(680, $plan['original_order_net_minor']);
        $this->assertFalse($plan['customer_refund_executed']);
        $review = $this->adjustment35Review($plan);
        $this->assertSame('retain_unexecuted', $review['decision']);
        $this->actor(1200);
        $status = app(CheckoutCodAdjustmentPlanService::class)->status($plan['operation_key']);
        $this->assertSame('adjustment_reviewed_not_executed', $status['status']);
        $this->assertTrue($status['snapshot_current']);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_adjustment_plan35_full_return_is_claim_only(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal, 2000);
        $this->assertSame(2000, $plan['requested_refund_minor']);
        $this->assertFalse($plan['financial_ledger_changed']);
    }

    public function test_adjustment_plan35_amount_correction_signed_difference(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source(
            reason: 'incorrect_amount', recommendation: 'amount_correction', recommended: 600
        );
        $plan = $this->adjustment35Prepare($draft, $proposal, 0);
        $this->assertSame('amount_correction', $plan['plan_kind']);
        $this->assertSame(-80, $plan['source_net_difference_minor']);
        $this->assertSame(0, $plan['requested_refund_minor']);
        $this->adjustment35Review($plan);
    }

    public function test_adjustment_plan35_bank_reversal_is_not_customer_refund(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source(
            reason: 'bank_reversal_reported', recommendation: 'reported_reversal', recommended: 0
        );
        $plan = $this->adjustment35Prepare($draft, $proposal, 0);
        $this->assertSame('reported_reversal', $plan['plan_kind']);
        $this->assertSame(-680, $plan['source_net_difference_minor']);
        $this->assertSame(0, $plan['requested_refund_minor']);
    }

    public function test_adjustment_plan35_batch_return_allocated_to_one_order(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source('batch');
        $plan = $this->adjustment35Prepare($draft, $proposal, 1000);
        $this->assertSame('batch', DB::table('checkout_cod_adjustment_plans')
            ->where('id', $plan['plan_id'])->value('source_kind'));
        $this->assertSame(680, $plan['original_order_net_minor']);
        $this->assertSame(1000, $plan['requested_refund_minor']);
    }

    public function test_adjustment_plan35_zero_refund_rejected(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $this->expectException(InvalidArgumentException::class);
        $this->adjustment35Prepare($draft, $proposal, 0);
    }

    public function test_adjustment_plan35_over_refund_rejected(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $this->expectException(InvalidArgumentException::class);
        $this->adjustment35Prepare($draft, $proposal, 2001);
    }

    public function test_adjustment_plan35_planner_independent_of_resolution_author(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        Gate::define(CheckoutCodAdjustmentPlanService::PREPARE_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 2203);
        $this->actor(2203);
        $this->expectException(DomainException::class);
        app(CheckoutCodAdjustmentPlanService::class)->prepare(
            $proposal['operation_key'], $draft['operation_key'],
            $this->orderKey, 1000, (string) Str::uuid()
        );
    }

    public function test_adjustment_plan35_reviewer_independent_of_planner(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        Gate::define(CheckoutCodAdjustmentPlanService::REVIEW_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3501);
        $this->actor(3501);
        $this->expectException(DomainException::class);
        app(CheckoutCodAdjustmentPlanService::class)->review(
            $plan['operation_key'], (string) Str::uuid(), 'retain_unexecuted'
        );
    }

    public function test_adjustment_plan35_prepare_idempotent_replay(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $key = (string) Str::uuid();
        $a = $this->adjustment35Prepare($draft, $proposal, 1000, $key);
        $b = $this->adjustment35Prepare($draft, $proposal, 1000, $key);
        $this->assertSame($a['plan_id'], $b['plan_id']);
        $this->assertTrue($b['idempotent_replay']);
    }

    public function test_adjustment_plan35_duplicate_source_order_rejected(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $this->adjustment35Prepare($draft, $proposal);
        $this->expectException(DomainException::class);
        $this->adjustment35Prepare($draft, $proposal);
    }

    public function test_adjustment_plan35_review_idempotent_replay(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        $key = (string) Str::uuid();
        $a = $this->adjustment35Review($plan, key: $key);
        $b = $this->adjustment35Review($plan, key: $key);
        $this->assertSame($a['review_id'], $b['review_id']);
        $this->assertTrue($b['idempotent_replay']);
    }

    public function test_adjustment_plan35_new_bank_evidence_invalidates_review(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'BANK35_NEW';
        $day = now('Europe/Kyiv')->subDay()->toDateString();
        $this->actor(3001);
        app(CheckoutCodPrivatStatementImportService::class)->import(
            (string) Str::uuid(), $day, $day
        );
        $this->actor(1200);
        $status = app(CheckoutCodAdjustmentPlanService::class)->status($plan['operation_key']);
        $this->assertSame('adjustment_plan_stale', $status['status']);
        $this->actor(3502);
        $this->expectException(DomainException::class);
        app(CheckoutCodAdjustmentPlanService::class)->review(
            $plan['operation_key'], (string) Str::uuid(), 'retain_unexecuted'
        );
    }

    public function test_adjustment_plan35_database_history_immutable(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $this->adjustment35Prepare($draft, $proposal);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_adjustment_plans')->update(['requested_refund_minor' => 1]);
    }

    public function test_adjustment_plan35_view_requires_permission(): void
    {
        [, $draft, , $proposal] = $this->adjustment35Source();
        $plan = $this->adjustment35Prepare($draft, $proposal);
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodAdjustmentPlanService::class)->status($plan['operation_key']);
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

    public function test_bank_refetch36_single_exact_remote_credit(): void
    {
        [$source] = $this->bankRefetch36Source();
        $result = $this->bankRefetch36Run('single', $source);
        $this->assertSame('consistent_unverified', $result['result_code']);
        $this->assertSame(1, $result['reference_rows']);
        $this->assertSame(1, $result['exact_rows']);
        $this->assertFalse($result['bank_native_signature_verified']);
        $this->assertFalse($result['funds_received_verified']);
        $this->assertFalse($result['cod_paid']);
        $this->actor(1200);
        $status = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertTrue($status['fresh_consistent_api_read']);
        $this->assertFalse($status['payment_posting_allowed']);
        $this->assertSame('pending', DB::table('orders')
            ->where('order_key', $this->orderKey)->value('payment_status'));
    }

    public function test_bank_refetch36_batch_credit_is_separate_source(): void
    {
        [$source] = $this->bankRefetch36Source('batch');
        $result = $this->bankRefetch36Run('batch', $source);
        $this->assertSame('batch', $result['source_kind']);
        $this->assertSame('consistent_unverified', $result['result_code']);
        $this->actor(1200);
        $s = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('batch', $source['operation_key']);
        $this->assertTrue($s['fresh_consistent_api_read']);
    }

    public function test_bank_refetch36_changed_remote_amount_detected(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->privatStatement30Fixture['transactions'][0]['SUM'] = '7.00';
        $r = $this->bankRefetch36Run('single', $source);
        $this->assertSame('transaction_changed', $r['result_code']);
        $this->assertSame(0, $r['exact_rows']);
        $this->actor(1200);
        $s = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertFalse($s['fresh_consistent_api_read']);
    }

    public function test_bank_refetch36_changed_remote_identity_detected(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'CHANGED36';
        $r = $this->bankRefetch36Run('single', $source);
        $this->assertSame('transaction_changed', $r['result_code']);
        $this->assertSame(0, $r['identity_rows']);
    }

    public function test_bank_refetch36_missing_remote_reference_detected(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->privatStatement30Fixture['transactions'][0]['REF'] = 'ANOTHER_REF36';
        $r = $this->bankRefetch36Run('single', $source);
        $this->assertSame('reference_not_found', $r['result_code']);
        $this->assertSame(0, $r['reference_rows']);
    }

    public function test_bank_refetch36_ambiguous_reference_detected(): void
    {
        [$source] = $this->bankRefetch36Source();
        $other = $this->privatStatement30Fixture['transactions'][0];
        $other['REFN'] = 'ANOTHER36';
        $this->privatStatement30Fixture['transactions'][] = $other;
        $r = $this->bankRefetch36Run('single', $source);
        $this->assertSame('ambiguous_reference', $r['result_code']);
        $this->assertSame(2, $r['reference_rows']);
        $this->assertFalse($r['cod_paid']);
    }

    public function test_bank_refetch36_same_uuid_exact_replay(): void
    {
        [$source] = $this->bankRefetch36Source();
        $key = (string) Str::uuid();
        $first = $this->bankRefetch36Run('single', $source, $key);
        $second = $this->bankRefetch36Run('single', $source, $key);
        $this->assertSame($first['refetch_id'], $second['refetch_id']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertDatabaseCount('checkout_cod_privat_refetches', 1);
    }

    public function test_bank_refetch36_new_uuid_creates_new_audit_snapshot(): void
    {
        [$source] = $this->bankRefetch36Source();
        $a = $this->bankRefetch36Run('single', $source);
        $b = $this->bankRefetch36Run('single', $source);
        $this->assertNotSame($a['refetch_id'], $b['refetch_id']);
        $this->assertDatabaseCount('checkout_cod_privat_refetches', 2);
    }

    public function test_bank_refetch36_importer_cannot_recheck(): void
    {
        [$source] = $this->bankRefetch36Source();
        Gate::define(CheckoutCodPrivatRefetchService::RECHECK_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 3001);
        $this->actor(3001);
        $this->expectException(DomainException::class);
        app(CheckoutCodPrivatRefetchService::class)->recheck(
            'single', $source['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_refetch36_bank_claim_author_cannot_recheck(): void
    {
        [$source] = $this->bankRefetch36Source();
        Gate::define(CheckoutCodPrivatRefetchService::RECHECK_ABILITY,
            static fn ($u): bool => (int) $u->getAuthIdentifier() === 1901);
        $this->actor(1901);
        $this->expectException(DomainException::class);
        app(CheckoutCodPrivatRefetchService::class)->recheck(
            'single', $source['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_refetch36_operator_permission_required(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodPrivatRefetchService::class)->recheck(
            'single', $source['operation_key'], (string) Str::uuid()
        );
    }

    public function test_bank_refetch36_financial_hold_disables_current_status(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->bankRefetch36Run('single', $source);
        $this->financialExceptionReport('single', $source, 'incorrect_amount');
        $this->actor(1200);
        $state = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('financial_investigation_hold', $state['status']);
        $this->assertFalse($state['fresh_consistent_api_read']);
    }

    public function test_bank_refetch36_local_new_transaction_invalidates_old_read(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->bankRefetch36Run('single', $source);
        $this->privatStatement30Fixture['transactions'][0]['REFN'] = 'LATER36';
        $day = now('Europe/Kyiv')->subDay()->toDateString();
        $this->actor(3001);
        app(CheckoutCodPrivatStatementImportService::class)
            ->import((string) Str::uuid(), $day, $day);
        $this->actor(1200);
        $status = app(CheckoutCodPrivatRefetchStatusService::class)
            ->inspect('single', $source['operation_key']);
        $this->assertSame('local_statement_changed', $status['status']);
        $this->assertFalse($status['fresh_consistent_api_read']);
    }

    public function test_bank_refetch36_connector_failure_records_nothing(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->privatStatement30Fixture['status'] = 'FAILED';
        $this->expectException(DomainException::class);
        try {
            $this->bankRefetch36Run('single', $source);
        } finally {
            $this->assertDatabaseCount('checkout_cod_privat_refetches', 0);
        }
    }

    public function test_bank_refetch36_audit_is_immutable(): void
    {
        [$source] = $this->bankRefetch36Source();
        $this->bankRefetch36Run('single', $source);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_privat_refetches')->update(['result_code' => 'reference_not_found']);
    }

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

    // ============================================================
    // BLOCK 37 — REFRESHED BANK API ANOMALIES STOP POSITIVE COD APPROVALS
    // ============================================================

    private function refetchRisk37Negative(string $kind, array $source): array
    {
        $this->bankRefetch36Gate();
        $this->privatStatement30Fixture['transactions'][0]['SUM'] = '7.00';
        return $this->bankRefetch36Run($kind, $source);
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
