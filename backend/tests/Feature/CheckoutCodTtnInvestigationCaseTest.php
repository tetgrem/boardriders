<?php

namespace Tests\Feature;

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
}
