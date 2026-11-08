<?php

namespace Tests\Feature;

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

final class CheckoutCodTtnDocumentComparisonJournalTest extends TestCase
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

    public function test_without_intent_document_comparison_status_is_empty(): void
    {
        $this->assertSame('no_intent', $this->compareStatus()['status']);
        Http::assertNothingSent();
    }

    public function test_matching_snapshot_is_observed_but_cannot_authorize_shipping(): void
    {
        $scan = $this->escalated();
        $result = $this->compareDocument($scan);
        $this->assertSame('snapshot_consistent', $result['summary']);
        $this->assertCount(10, $result['checks']);
        $this->assertFalse($result['waybill_linked_to_order']);
        $this->assertFalse($result['reconciliation_hold_released']);
        $this->assertFalse($result['ttn_submission_allowed']);
        $this->assertSame('consistent_snapshot_only', $this->compareStatus()['status']);
        $this->assertSame('reconciliation_required', $this->safetyStatus()['status']);
        Http::assertSentCount(6);
    }

    public function test_scanner_must_be_independently_reviewed_before_comparison(): void
    {
        $scan = $this->scan($this->flagged());
        try {
            $this->compareDocument($scan);
            $this->fail('Review must be present');
        } catch (DomainException) {
            Http::assertSentCount(4);
        }
    }

    public function test_mismatched_recipient_is_reported_without_sensitive_fields(): void
    {
        $this->documentRows[0]['Recipient'] = (string) Str::uuid();
        $result = $this->compareDocument($this->escalated());
        $this->assertSame('snapshot_mismatch', $result['summary']);
        $this->assertSame('mismatch', $result['checks']['Recipient']);
        $this->assertSame('snapshot_mismatch', $this->compareStatus()['status']);
    }

    public function test_different_cost_is_a_mismatch_without_float_rounding(): void
    {
        $this->documentRows[0]['Cost'] = '20.01';
        $result = $this->compareDocument($this->escalated());
        $this->assertSame('mismatch', $result['checks']['Cost']);
        $this->assertSame('snapshot_mismatch', $result['summary']);
    }

    public function test_missing_service_type_fails_closed_as_inconclusive(): void
    {
        unset($this->documentRows[0]['ServiceType']);
        $result = $this->compareDocument($this->escalated());
        $this->assertSame('inconclusive_fields', $result['summary']);
        $this->assertSame('missing', $result['checks']['ServiceType']);
    }

    public function test_list_with_no_candidate_ref_does_not_call_get_document(): void
    {
        $this->scanRows = [['IntDocNumber' => self::WAYBILL]];
        // For the first scan block 15 only needs the number, not Ref.
        $scan = $this->escalated();
        $result = $this->compareDocument($scan);
        $this->assertSame('inconclusive_list', $result['summary']);
        Http::assertSentCount(5);
    }

    public function test_empty_detailed_response_fails_closed(): void
    {
        $this->documentRows = [];
        $result = $this->compareDocument($this->escalated());
        $this->assertSame('inconclusive_document', $result['summary']);
    }

    public function test_comparison_exact_retry_is_idempotent_without_new_http(): void
    {
        $scan = $this->escalated();
        $key = (string) Str::uuid();
        $first = $this->compareDocument($scan, $key);
        $this->assertSame($first, $this->compareDocument($scan, $key));
        Http::assertSentCount(6);
        $this->assertDatabaseCount('checkout_cod_ttn_document_comparisons', 1);
    }

    public function test_new_uuid_cannot_replace_comparison_of_same_scan(): void
    {
        $scan = $this->escalated();
        $this->compareDocument($scan);
        $this->expectException(DomainException::class);
        $this->compareDocument($scan);
    }

    public function test_unauthorized_operator_cannot_compare(): void
    {
        $scan = $this->escalated();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodTtnDocumentComparisonService::class)->compare(
            $scan['operation_key'], (string) Str::uuid()
        );
    }

    public function test_original_scanner_cannot_compare_even_with_gate_override(): void
    {
        $scan = $this->escalated();
        Gate::define(CheckoutCodTtnDocumentComparisonService::COMPARE_ABILITY,
            static fn ($user): bool => in_array((int) $user->getAuthIdentifier(), [909, 911], true));
        $this->actor(909);
        $this->expectException(DomainException::class);
        app(CheckoutCodTtnDocumentComparisonService::class)->compare(
            $scan['operation_key'], (string) Str::uuid()
        );
    }

    public function test_expired_checkout_does_not_block_historical_investigation(): void
    {
        $scan = $this->escalated();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $this->assertSame('snapshot_consistent', $this->compareDocument($scan)['summary']);
    }

    public function test_direct_sql_cannot_change_comparison_result(): void
    {
        $row = $this->compareDocument($this->escalated());
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_ttn_document_comparisons')
            ->where('id', $row['id'])->update(['summary' => 'snapshot_mismatch']);
    }

    public function test_direct_sql_cannot_attach_comparison_to_wrong_intent(): void
    {
        $row = $this->compareDocument($this->escalated());
        $newOrderKey = $this->makeCodOrder();
        $otherId = (int) DB::table('orders')->where('order_key', $newOrderKey)->value('id');
        $data = (array) DB::table('checkout_cod_ttn_document_comparisons')
            ->where('id', $row['id'])->first();
        unset($data['id']);
        $data['operation_key'] = (string) Str::uuid();
        $data['intent_id'] = $otherId;
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_ttn_document_comparisons')->insert($data);
    }

    public function test_no_personal_data_or_api_credentials_are_persisted(): void
    {
        $result = $this->compareDocument($this->escalated());
        $db = (array) DB::table('checkout_cod_ttn_document_comparisons')
            ->where('id', $result['id'])->first();
        $encoded = json_encode([$result, $db, $this->compareStatus()], JSON_THROW_ON_ERROR);
        foreach (['Петренко', '380671234567', self::WAYBILL,
            self::RECIPIENT_COUNTERPARTY, self::RECIPIENT_CONTACT,
            'ttn-gate-test-key', self::DOCUMENT_REF] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_candidate_not_automatically_retried_after_comparison(): void
    {
        $this->compareDocument($this->escalated());
        $this->assertFalse($this->compareStatus()['automatic_retry_allowed']);
        $this->assertSame('reconciliation_required', $this->safetyStatus()['status']);
        Http::assertNotSent(static fn (ProviderRequest $r): bool =>
            ($r->data()['calledMethod'] ?? null) === 'save');
    }
}
