<?php

namespace Tests\Feature;


use App\Services\Pricing\CheckoutCodSettlementCaseService;
use App\Services\Pricing\CheckoutCodSettlementObservationService;
use App\Services\Pricing\CheckoutCodSettlementReviewService;
use App\Services\Pricing\CheckoutCodSettlementStatusService;
use App\Services\Pricing\CheckoutCodSettlementQueueService;

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

}
