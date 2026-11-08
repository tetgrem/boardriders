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

final class CheckoutCodTtnReconciliationJournalTest extends TestCase
{
    use RefreshDatabase;

    private const SENDER_COUNTERPARTY = '22222222-2222-4222-8222-222222222222';
    private const SENDER_CONTACT = '44444444-4444-4444-8444-444444444444';
    private const RECIPIENT_COUNTERPARTY = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const RECIPIENT_CONTACT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const WAYBILL = '20450000000001';
    private const OTHER_WAYBILL = '20450000000002';

    private int $variantId;
    private string $orderKey;
    private string $cityRef;
    private string $destinationRef;
    private string $senderCityRef;
    private bool $contactMatches = true;
    private bool $carrierScanFailure = false;
    private array $scanRows = [['IntDocNumber' => self::WAYBILL]];

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

    public function test_unprepared_order_has_no_reconciliation(): void
    {
        $state = $this->reconciliationStatus();
        $this->assertSame('not_prepared', $state['status']);
        $this->assertFalse($state['ttn_submission_allowed']);
        Http::assertNothingSent();
    }

    public function test_intent_without_reconciliation_event_does_not_scan(): void
    {
        $intent = $this->stage();
        try {
            $this->scan($intent);
            $this->fail('A safety flag is required');
        } catch (DomainException) {
            Http::assertSentCount(3);
            $this->assertDatabaseCount('checkout_cod_ttn_reconciliation_scans', 0);
        }
    }

    public function test_reconciliation_event_reports_awaiting_scan(): void
    {
        $this->flagged();
        $this->assertSame('awaiting_scan', $this->reconciliationStatus()['status']);
    }

    public function test_exact_number_is_observed_but_not_linked_to_order(): void
    {
        $intent = $this->flagged();
        $scan = $this->scan($intent);
        $this->assertSame('number_observed_once', $scan['provider_result']);
        $this->assertTrue($scan['number_observed_in_one_response']);
        $this->assertFalse($scan['waybill_linked_to_order']);
        $this->assertFalse($scan['automatic_retry_allowed']);
        $this->assertSame('awaiting_independent_review', $this->reconciliationStatus()['status']);
        Http::assertSentCount(4);
    }

    public function test_candidate_number_is_encrypted_and_not_exposed(): void
    {
        $intent = $this->flagged();
        $scan = $this->scan($intent);
        $row = DB::table('checkout_cod_ttn_reconciliation_scans')->where('id', $scan['id'])->first();
        $this->assertStringNotContainsString(self::WAYBILL, $row->candidate_number_ciphertext);
        $this->assertSame(self::WAYBILL, Crypt::decryptString($row->candidate_number_ciphertext));
        $this->assertStringNotContainsString(self::WAYBILL, json_encode($scan, JSON_THROW_ON_ERROR));
    }

    public function test_negative_page_never_proves_absence_or_allows_retry(): void
    {
        $this->scanRows = [];
        $intent = $this->flagged();
        $scan = $this->scan($intent);
        $this->assertSame('number_absent_in_page', $scan['provider_result']);
        $this->assertFalse($scan['absence_proven']);
        $this->assertFalse($scan['automatic_retry_allowed']);
    }

    public function test_full_page_is_inconclusive(): void
    {
        $this->scanRows = array_fill(0, 100, ['IntDocNumber' => self::OTHER_WAYBILL]);
        $intent = $this->flagged();
        $this->assertSame('response_inconclusive', $this->scan($intent)['provider_result']);
    }

    public function test_same_scan_operation_is_idempotent_without_http(): void
    {
        $intent = $this->flagged();
        $key = (string) Str::uuid();
        $first = $this->scan($intent, $key);
        $this->assertSame($first, $this->scan($intent, $key));
        $this->assertDatabaseCount('checkout_cod_ttn_reconciliation_scans', 1);
        Http::assertSentCount(4);
    }

    public function test_scan_uuid_cannot_be_reused_with_different_number(): void
    {
        $intent = $this->flagged();
        $key = (string) Str::uuid();
        $this->scan($intent, $key);
        $this->expectException(DomainException::class);
        $this->scan($intent, $key, self::OTHER_WAYBILL);
    }

    public function test_new_scan_uuid_preserves_old_history(): void
    {
        $intent = $this->flagged();
        $first = $this->scan($intent);
        $second = $this->scan($intent);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertDatabaseCount('checkout_cod_ttn_reconciliation_scans', 2);
        Http::assertSentCount(5);
    }

    public function test_scan_requires_separate_operator_permission(): void
    {
        $intent = $this->flagged();
        $this->actor(999);
        $this->expectException(AuthorizationException::class);
        app(CheckoutCodTtnReconciliationScanService::class)->inspect(
            $intent['intent_key'], (string) Str::uuid(), self::WAYBILL
        );
    }

    public function test_provider_failure_cannot_create_scan_record(): void
    {
        $intent = $this->flagged();
        $this->carrierScanFailure = true;
        try {
            $this->scan($intent);
            $this->fail('Carrier failure must not be saved as a successful scan');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('checkout_cod_ttn_reconciliation_scans', 0);
        }
    }

    public function test_independent_review_escalates_but_does_not_release_hold(): void
    {
        $scan = $this->scan($this->flagged());
        $review = $this->independentReview($scan);
        $this->assertSame('escalate_to_carrier', $review['decision']);
        $this->assertFalse($review['reconciliation_hold_released']);
        $this->assertSame('escalated_to_carrier', $this->reconciliationStatus()['status']);
        $this->assertFalse($this->safetyStatus()['automatic_retry_allowed']);
        $this->assertSame('reconciliation_required', $this->safetyStatus()['status']);
    }

    public function test_scanner_cannot_review_own_result_even_if_gate_allows(): void
    {
        $scan = $this->scan($this->flagged());
        Gate::define(CheckoutCodTtnReconciliationReviewService::REVIEW_ABILITY,
            static fn ($user): bool => in_array((int) $user->getAuthIdentifier(), [909, 910], true));
        $this->actor(909);
        $this->expectException(DomainException::class);
        app(CheckoutCodTtnReconciliationReviewService::class)->review(
            $scan['operation_key'], (string) Str::uuid(), 'escalate_to_carrier', 'candidate_seen'
        );
    }

    public function test_negative_scan_cannot_be_escalated_as_found(): void
    {
        $this->scanRows = [];
        $scan = $this->scan($this->flagged());
        $this->expectException(InvalidArgumentException::class);
        $this->independentReview($scan);
    }

    public function test_independent_review_can_keep_hold_after_empty_page(): void
    {
        $this->scanRows = [];
        $scan = $this->scan($this->flagged());
        $result = $this->independentReview($scan,
            decision: 'keep_reconciliation_hold', reason: 'insufficient_api_evidence');
        $this->assertSame('keep_reconciliation_hold', $result['decision']);
        $this->assertSame('hold_continues', $this->reconciliationStatus()['status']);
    }

    public function test_review_retry_is_idempotent(): void
    {
        $scan = $this->scan($this->flagged());
        $key = (string) Str::uuid();
        $this->assertSame($this->independentReview($scan, $key),
            $this->independentReview($scan, $key));
        $this->assertDatabaseCount('checkout_cod_ttn_reconciliation_reviews', 1);
    }

    public function test_new_review_uuid_cannot_override_old_decision(): void
    {
        $scan = $this->scan($this->flagged());
        $this->independentReview($scan);
        $this->expectException(DomainException::class);
        $this->independentReview($scan);
    }

    public function test_expired_checkout_does_not_prevent_investigation(): void
    {
        $intent = $this->flagged();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $scan = $this->scan($intent);
        $this->assertSame('number_observed_once', $scan['provider_result']);
        $this->assertSame('reconciliation_required', $this->safetyStatus()['status']);
    }

    public function test_database_rejects_scan_modification(): void
    {
        $scan = $this->scan($this->flagged());
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_ttn_reconciliation_scans')
            ->where('id', $scan['id'])->update(['provider_result' => 'number_absent_in_page']);
    }

    public function test_database_rejects_self_review(): void
    {
        $scan = $this->scan($this->flagged());
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_ttn_reconciliation_reviews')->insert([
            'operation_key' => (string) Str::uuid(), 'scan_id' => $scan['id'],
            'decision' => 'escalate_to_carrier', 'reason_code' => 'candidate_seen',
            'reviewed_by' => 909, 'request_hmac' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }

    public function test_report_never_exposes_candidate_or_recipient_personal_data(): void
    {
        $scan = $this->scan($this->flagged());
        $this->independentReview($scan);
        $json = json_encode($this->reconciliationStatus(), JSON_THROW_ON_ERROR);
        foreach ([self::WAYBILL, 'Петренко', '380671234567',
                     self::RECIPIENT_CONTACT, 'ttn-gate-test-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        Http::assertSentCount(4);
    }
}
