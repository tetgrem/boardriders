<?php

namespace Tests\Feature;

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
use App\Services\Pricing\CheckoutCodTtnAuthorizationPreflightService;
use App\Services\Pricing\CheckoutCodWaybillDraftService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use App\Services\Pricing\NovaPoshtaCodContractEvidenceService;
use App\Services\Pricing\NovaPoshtaCodExternalAttestationService;
use App\Services\Pricing\NovaPoshtaSenderProfileService;
use App\Services\Pricing\ProductVariantShippingMetricsService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\GenericUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class CheckoutCodTtnSubmissionSafetyJournalTest extends TestCase
{
    use RefreshDatabase;

    private const SENDER_COUNTERPARTY = '22222222-2222-4222-8222-222222222222';
    private const SENDER_CONTACT = '44444444-4444-4444-8444-444444444444';
    private const RECIPIENT_COUNTERPARTY = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const RECIPIENT_CONTACT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private int $variantId;
    private string $orderKey;
    private string $cityRef;
    private string $destinationRef;
    private string $senderCityRef;
    private bool $contactMatches = true;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException('Requires PostgreSQL boardriders_test');
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC'));

        $now = now();

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Ukrainian hryvnia',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('markets')->insert([
            'code' => 'UA',
            'name' => 'Ukraine',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $priceListId = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL',
            'name' => 'Retail UA',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'type' => 'retail',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'sku' => 'TTN-GATE-' . Str::uuid(),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $priceListId,
            'price_minor' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'TTN-GATE-WH-' . Str::uuid(),
            'name' => 'TTN gate warehouse',
            'country_code' => 'UA',
            'kind' => 'owned',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 20,
            'reserved_qty' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(ProductVariantShippingMetricsService::class)->change(
            $this->variantId,
            (string) Str::uuid(),
            0,
            300,
            200,
            100,
            50
        );

        $this->cityRef = (string) Str::uuid();
        $this->destinationRef = (string) Str::uuid();
        $this->senderCityRef = (string) Str::uuid();

        config()->set('services.nova_poshta.api_key', 'ttn-gate-test-key');
        config()->set('services.nova_poshta.sender_city_ref', $this->senderCityRef);

        $this->orderKey = $this->makeCodOrder();

        Http::preventStrayRequests();

        Gate::define(
            NovaPoshtaCodContractEvidenceService::SUBMIT_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 101
        );

        Gate::define(
            NovaPoshtaCodContractEvidenceService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 202
        );

        Gate::define(
            NovaPoshtaCodExternalAttestationService::SUBMIT_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 303
        );

        Gate::define(
            NovaPoshtaCodExternalAttestationService::REVIEW_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 404
        );

        Gate::define(
            NovaPoshtaCodExternalAttestationService::REVOKE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 505
        );

        Gate::define(
            CheckoutCodTtnSubmissionIntentService::PREPARE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 606
        );

        Gate::define(
            CheckoutCodTtnSubmissionSafetyEventService::CONTROL_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 707
        );

        Gate::define(
            CheckoutCodTtnSubmissionSafetyEventService::RECONCILE_ABILITY,
            static fn ($user): bool =>
                (int) $user->getAuthIdentifier() === 808
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
            $key,
            [
                [
                    'variant_id' => $this->variantId,
                    'quantity' => 2,
                ],
            ],
            'UA',
            'UA_RETAIL',
            CarbonImmutable::now('UTC')->addMinutes(30)
        );

        app(CheckoutPaymentChoiceService::class)->choose(
            $key,
            'cash_on_delivery'
        );

        app(CheckoutCashOnDeliveryRequestService::class)->request(
            $key,
            (string) Str::uuid()
        );

        app(CheckoutCodDeliverySelectionService::class)->select(
            $key,
            (string) Str::uuid(),
            'branch',
            $this->destinationRef
        );

        app(CheckoutCodRecipientService::class)->save(
            $key,
            (string) Str::uuid(),
            $this->cityRef,
            'Іван',
            'Петренко',
            '+380671234567'
        );

        return $key;
    }

    private function prepareDraft(
        int $declaredValueMinor = 2000,
        bool $withBinding = true,
        bool $withObservation = true,
    ): void {
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

        Http::fake(function (ProviderRequest $request) {
            $method = $request->data()['calledMethod'] ?? null;

            if ($method === 'getDocumentPrice') {
                return Http::response([
                    'success' => true,
                    'data' => [
                        [
                            'Cost' => '95.00',
                            'CostRedelivery' => '13.20',
                        ],
                    ],
                ]);
            }

            if ($method === 'getCounterpartyContactPersons') {
                $counterparty =
                    $request->data()['methodProperties']['Ref'] ?? null;

                $ref = match ($counterparty) {
                    self::SENDER_COUNTERPARTY => self::SENDER_CONTACT,
                    self::RECIPIENT_COUNTERPARTY => $this->contactMatches
                        ? self::RECIPIENT_CONTACT
                        : (string) Str::uuid(),
                    default => (string) Str::uuid(),
                };

                return Http::response([
                    'success' => true,
                    'data' => [
                        ['Ref' => $ref],
                    ],
                ]);
            }

            return Http::response([
                'success' => false,
                'data' => [],
            ], 400);
        });

        app(CheckoutCodCarrierRateReconciliationService::class)->reconcile(
            $this->orderKey,
            (string) Str::uuid()
        );

        app(CheckoutCodFinancialPreflightService::class)->record(
            $this->orderKey,
            (string) Str::uuid(),
            $declaredValueMinor,
            'cash_branch'
        );

        app(NovaPoshtaSenderProfileService::class)->configure(
            (string) Str::uuid(),
            'ua_primary',
            1,
            $this->senderCityRef,
            self::SENDER_COUNTERPARTY,
            '33333333-3333-4333-8333-333333333333',
            self::SENDER_CONTACT,
            '+380671234567'
        );

        app(CheckoutCodWaybillDraftService::class)->prepare(
            $this->orderKey,
            (string) Str::uuid(),
            'ua_primary',
            'Recipient',
            'Cash',
            'Одяг та аксесуари'
        );

        if ($withBinding) {
            app(CheckoutCodRecipientCarrierBindingService::class)->bind(
                $this->orderKey,
                (string) Str::uuid(),
                self::RECIPIENT_COUNTERPARTY,
                self::RECIPIENT_CONTACT
            );
        }

        if ($withObservation) {
            app(CheckoutCodCarrierContactObservationService::class)->observe(
                $this->orderKey,
                (string) Str::uuid()
            );
        }
    }

    private function addAgreements(bool $all = true): array
    {
        $keys = [];

        $types = $all
            ? [
                'transport_agreement',
                'cod_agreement',
                'payout_agreement',
            ]
            : ['transport_agreement'];

        foreach ($types as $type) {
            $this->actor(101);

            $doc = app(NovaPoshtaCodContractEvidenceService::class)->submit(
                (string) Str::uuid(),
                'ua_primary',
                $type,
                1,
                $type === 'transport_agreement'
                    ? 'nova_poshta'
                    : 'novapay',
                str_repeat('a', 64),
                str_repeat('b', 64),
                CarbonImmutable::now('UTC')->subDay(),
                CarbonImmutable::now('UTC')->addYear()
            );

            $keys[$type] = $doc;

            $this->actor(202);

            app(NovaPoshtaCodContractEvidenceService::class)->review(
                (string) Str::uuid(),
                $doc['evidence_key'],
                'accepted',
                'documents_reviewed'
            );

            $this->actor(303);

            $claim = app(NovaPoshtaCodExternalAttestationService::class)->submit(
                (string) Str::uuid(),
                $doc['evidence_key'],
                'signed_document',
                str_repeat('c', 64),
                str_repeat('b', 64),
                CarbonImmutable::now('UTC')->subHour(),
                CarbonImmutable::now('UTC')->addMonth()
            );

            $keys[$type]['claim_key'] = $claim['claim_key'];

            $this->actor(404);

            app(NovaPoshtaCodExternalAttestationService::class)->review(
                (string) Str::uuid(),
                $claim['claim_key'],
                'accepted',
                'source_checked'
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
            $this->orderKey,
            $key ?? (string) Str::uuid()
        );
    }

    private function event(
        array $intent,
        string $type = 'safety_hold',
        string $reason = 'manual_review',
        ?string $key = null,
    ): array {
        $this->actor(
            $type === 'reconciliation_required' ? 808 : 707
        );

        return app(CheckoutCodTtnSubmissionSafetyEventService::class)->record(
            $intent['intent_key'],
            $key ?? (string) Str::uuid(),
            $type,
            $reason
        );
    }

    // Исправлено: метод status() в PHPUnit является final.
    private function safetyStatus(): array
    {
        return app(
            CheckoutCodTtnSubmissionSafetyStatusService::class
        )->inspect($this->orderKey);
    }

    public function test_initial_order_has_no_submission_intent(): void
    {
        $status = $this->safetyStatus();

        $this->assertSame('not_prepared', $status['status']);
        $this->assertContains(
            'submission_intent_missing',
            $status['blockers']
        );
        $this->assertFalse($status['ttn_submission_allowed']);

        Http::assertNothingSent();
    }

    public function test_incomplete_local_preflight_cannot_stage_intent(): void
    {
        $this->actor(606);

        $this->expectException(DomainException::class);

        $this->prepareIntent();
    }

    public function test_complete_local_evidence_can_stage_but_never_send(): void
    {
        $intent = $this->stage();

        $this->assertSame(
            'prepared_not_authorized',
            $intent['status']
        );
        $this->assertFalse($intent['provider_call_made']);
        $this->assertFalse($intent['ttn_submission_allowed']);
        $this->assertNull($intent['actual_ttn_number']);

        $this->assertDatabaseCount(
            'checkout_cod_ttn_submission_intents',
            1
        );
        $this->assertDatabaseCount(
            'checkout_cod_ttn_submission_safety_events',
            0
        );

        Http::assertSentCount(3);
    }

    public function test_staged_intent_status_rechecks_context_and_is_still_blocked(): void
    {
        $this->stage();

        $status = $this->safetyStatus();

        $this->assertSame(
            'prepared_not_authorized',
            $status['status']
        );
        $this->assertTrue($status['local_context_current']);
        $this->assertFalse($status['ready_for_dispatch']);
        $this->assertContains(
            'live_ttn_transport_not_connected',
            $status['blockers']
        );
    }

    public function test_identical_request_is_idempotent(): void
    {
        $intent = $this->stage();
        $this->actor(606);

        $this->assertSame(
            $intent,
            $this->prepareIntent($intent['intent_key'])
        );

        $this->assertDatabaseCount(
            'checkout_cod_ttn_submission_intents',
            1
        );

        Http::assertSentCount(3);
    }

    public function test_different_key_cannot_replace_intent(): void
    {
        $this->stage();
        $this->actor(606);

        $this->expectException(DomainException::class);

        $this->prepareIntent();
    }

    public function test_user_without_permission_cannot_stage_intent(): void
    {
        $this->prepareDraft();
        $this->addAgreements();
        $this->actor(999);

        $this->expectException(AuthorizationException::class);

        $this->prepareIntent();
    }

    public function test_other_operator_cannot_replay_original_intent(): void
    {
        $intent = $this->stage();

        Gate::define(
            CheckoutCodTtnSubmissionIntentService::PREPARE_ABILITY,
            static fn ($user): bool =>
            in_array(
                (int) $user->getAuthIdentifier(),
                [606, 999],
                true
            )
        );

        $this->actor(999);

        $this->expectException(DomainException::class);

        $this->prepareIntent($intent['intent_key']);
    }

    public function test_safety_hold_is_an_immutable_event(): void
    {
        $intent = $this->stage();
        $event = $this->event($intent);

        $this->assertSame(
            'safety_hold',
            $event['event_type']
        );
        $this->assertSame(707, $event['recorded_by']);
        $this->assertSame(
            'safety_hold',
            $this->safetyStatus()['status']
        );

        $this->assertDatabaseCount(
            'checkout_cod_ttn_submission_safety_events',
            1
        );
    }

    public function test_identical_event_retry_does_not_duplicate_history(): void
    {
        $intent = $this->stage();
        $key = (string) Str::uuid();

        $this->assertSame(
            $this->event($intent, key: $key),
            $this->event($intent, key: $key)
        );

        $this->assertDatabaseCount(
            'checkout_cod_ttn_submission_safety_events',
            1
        );
    }

    public function test_changed_event_request_key_fails_idempotency_check(): void
    {
        $intent = $this->stage();
        $key = (string) Str::uuid();

        $this->event($intent, key: $key);

        $this->expectException(DomainException::class);

        $this->event(
            $intent,
            reason: 'security_hold',
            key: $key
        );
    }

    public function test_reconciliation_flag_never_automatically_retries(): void
    {
        $intent = $this->stage();

        $event = $this->event(
            $intent,
            'reconciliation_required',
            'reported_timeout'
        );

        $status = $this->safetyStatus();

        $this->assertTrue(
            $event['event_is_operator_report_only']
        );
        $this->assertSame(
            'reconciliation_required',
            $status['status']
        );
        $this->assertTrue($status['has_reconciliation_hold']);
        $this->assertFalse($status['automatic_retry_allowed']);
        $this->assertFalse(
            $status['provider_call_made_by_this_module']
        );

        Http::assertSentCount(3);
    }

    public function test_regular_operator_cannot_flag_provider_uncertainty(): void
    {
        $intent = $this->stage();
        $this->actor(707);

        $this->expectException(AuthorizationException::class);

        app(CheckoutCodTtnSubmissionSafetyEventService::class)->record(
            $intent['intent_key'],
            (string) Str::uuid(),
            'reconciliation_required',
            'possible_duplicate'
        );
    }

    public function test_cancellation_is_terminal_even_if_context_remains_current(): void
    {
        $intent = $this->stage();

        $this->event(
            $intent,
            'cancel_before_submission',
            'operator_cancelled'
        );

        $this->assertSame(
            'cancelled',
            $this->safetyStatus()['status']
        );
        $this->assertFalse(
            $this->safetyStatus()['ttn_submission_allowed']
        );

        $this->expectException(DomainException::class);

        $this->event(
            $intent,
            'safety_hold',
            'manual_review'
        );
    }

    public function test_reported_remote_uncertainty_prevents_cancellation(): void
    {
        $intent = $this->stage();

        $this->event(
            $intent,
            'reconciliation_required',
            'reported_unknown_outcome'
        );

        $this->assertSame(
            'reconciliation_required',
            $this->safetyStatus()['status']
        );

        $this->expectException(DomainException::class);

        $this->event(
            $intent,
            'cancel_before_submission',
            'operator_cancelled'
        );
    }

    public function test_cancellation_retry_can_read_historical_event(): void
    {
        $intent = $this->stage();
        $key = (string) Str::uuid();

        $this->assertSame(
            $this->event(
                $intent,
                'cancel_before_submission',
                'customer_cancelled',
                $key
            ),
            $this->event(
                $intent,
                'cancel_before_submission',
                'customer_cancelled',
                $key
            )
        );

        $this->assertDatabaseCount(
            'checkout_cod_ttn_submission_safety_events',
            1
        );
    }

    public function test_expired_checkout_makes_staged_context_stale(): void
    {
        $this->stage();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addHour()
        );

        $result = $this->safetyStatus();

        $this->assertSame('context_stale', $result['status']);
        $this->assertFalse($result['local_context_current']);
    }

    public function test_new_carrier_contact_observation_invalidates_pinned_fingerprint(): void
    {
        $this->stage();

        app(CheckoutCodCarrierContactObservationService::class)->observe(
            $this->orderKey,
            (string) Str::uuid()
        );

        $this->assertSame(
            'context_stale',
            $this->safetyStatus()['status']
        );

        Http::assertSentCount(5);
    }

    public function test_new_contract_revision_invalidates_staged_intent(): void
    {
        $this->stage();
        $this->actor(101);

        app(NovaPoshtaCodContractEvidenceService::class)->submit(
            (string) Str::uuid(),
            'ua_primary',
            'cod_agreement',
            2,
            'novapay',
            str_repeat('a', 64),
            str_repeat('b', 64),
            CarbonImmutable::now('UTC')->subDay(),
            CarbonImmutable::now('UTC')->addYear()
        );

        $this->assertSame(
            'context_stale',
            $this->safetyStatus()['status']
        );
    }

    public function test_new_sender_profile_revision_invalidates_staged_intent(): void
    {
        $this->stage();

        app(NovaPoshtaSenderProfileService::class)->configure(
            (string) Str::uuid(),
            'ua_primary',
            2,
            $this->senderCityRef,
            self::SENDER_COUNTERPARTY,
            '33333333-3333-4333-8333-333333333333',
            self::SENDER_CONTACT,
            '+380681234567'
        );

        $this->assertSame(
            'context_stale',
            $this->safetyStatus()['status']
        );
    }

    public function test_original_key_retry_remains_historical_after_expiry(): void
    {
        $intent = $this->stage();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addHour()
        );

        $this->actor(606);

        $this->assertSame(
            $intent,
            $this->prepareIntent($intent['intent_key'])
        );

        $this->assertSame(
            'context_stale',
            $this->safetyStatus()['status']
        );
    }

    public function test_direct_sql_cannot_modify_staged_intent(): void
    {
        $intent = $this->stage();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_ttn_submission_intents')
            ->where('id', $intent['id'])
            ->update([
                'prepared_by' => 999,
            ]);
    }

    public function test_database_rejects_cancellation_after_possible_remote_outcome(): void
    {
        $intent = $this->stage();

        $this->event(
            $intent,
            'reconciliation_required',
            'reported_timeout'
        );

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_ttn_submission_safety_events')->insert([
            'event_key' => (string) Str::uuid(),
            'intent_id' => $intent['id'],
            'event_type' => 'cancel_before_submission',
            'reason_code' => 'operator_cancelled',
            'recorded_by' => 707,
            'request_hmac' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }

    public function test_direct_sql_cannot_modify_event_history(): void
    {
        $intent = $this->stage();
        $event = $this->event($intent);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_ttn_submission_safety_events')
            ->where('id', $event['id'])
            ->update([
                'reason_code' => 'stale_details',
            ]);
    }

    public function test_database_rejects_cross_order_intent_forgery(): void
    {
        $intent = $this->stage();
        $secondKey = $this->makeCodOrder();

        $secondId = (int) DB::table('orders')
            ->where('order_key', $secondKey)
            ->value('id');

        $row = (array) DB::table('checkout_cod_ttn_submission_intents')
            ->where('id', $intent['id'])
            ->first();

        unset($row['id']);

        $row['intent_key'] = (string) Str::uuid();
        $row['order_id'] = $secondId;

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_ttn_submission_intents')->insert($row);
    }

    public function test_report_never_leaks_recipient_personal_data(): void
    {
        $this->stage();

        $json = json_encode(
            $this->safetyStatus(),
            JSON_THROW_ON_ERROR
        );

        foreach (
            [
                'Петренко',
                '380671234567',
                self::RECIPIENT_CONTACT,
                self::RECIPIENT_COUNTERPARTY,
                'ttn-gate-test-key',
            ] as $sensitive
        ) {
            $this->assertStringNotContainsString(
                $sensitive,
                $json
            );
        }
    }

    public function test_preparation_and_safety_events_never_send_ttn(): void
    {
        $intent = $this->stage();

        $this->event($intent);
        $this->safetyStatus();

        Http::assertNotSent(
            static fn (ProviderRequest $r): bool =>
                ($r->data()['calledMethod'] ?? null) === 'save'
        );

        Http::assertSentCount(3);
    }

    public function test_invalid_order_reference_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CheckoutCodTtnSubmissionSafetyStatusService::class)
            ->inspect('invalid');
    }

    public function test_unknown_order_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        app(CheckoutCodTtnSubmissionSafetyStatusService::class)
            ->inspect((string) Str::uuid());
    }
}
