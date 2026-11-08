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

final class CheckoutCodTtnAuthorizationPreflightTest extends TestCase
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

    private function inspect(): array
    {
        return app(CheckoutCodTtnAuthorizationPreflightService::class)->inspect($this->orderKey);
    }

    public function test_initial_order_has_no_draft_and_cannot_submit(): void
    {
        $result = $this->inspect();
        $this->assertContains('waybill_draft_missing', $result['blockers']);
        $this->assertFalse($result['ttn_submission_allowed']);
        $this->assertNull($result['actual_ttn_number']);
        Http::assertNothingSent();
    }

    public function test_completed_local_cod_without_contracts_remains_blocked(): void
    {
        $this->prepareDraft();
        $result = $this->inspect();
        $this->assertTrue($result['local_cod_checks_current']);
        $this->assertTrue($result['carrier_contacts_observed']);
        $this->assertContains('internal_contract_reviews_incomplete', $result['blockers']);
        $this->assertFalse($result['all_local_pre_submission_checks_current']);
    }

    public function test_all_internal_reviews_and_attestations_still_cannot_dispatch(): void
    {
        $this->prepareDraft();
        $this->addAgreements();
        $result = $this->inspect();
        $this->assertTrue($result['all_local_pre_submission_checks_current']);
        $this->assertTrue($result['external_material_reviews_current']);
        $this->assertFalse($result['ttn_submission_allowed']);
        $this->assertFalse($result['cod_eligibility_confirmed']);
        $this->assertFalse($result['ready_for_dispatch']);
        $this->assertContains('trusted_issuer_contract_confirmation_unavailable', $result['blockers']);
        Http::assertSentCount(3);
    }

    public function test_declared_value_mismatch_requires_new_rate(): void
    {
        $this->prepareDraft(declaredValueMinor: 3000);
        $this->addAgreements();
        $result = $this->inspect();
        $this->assertTrue($result['declared_value_rerate_required']);
        $this->assertContains('declared_value_rerate_required', $result['blockers']);
        $this->assertFalse($result['all_local_pre_submission_checks_current']);
    }

    public function test_missing_recipient_binding_is_reported(): void
    {
        $this->prepareDraft(withBinding: false, withObservation: false);
        $result = $this->inspect();
        $this->assertContains('recipient_carrier_binding_missing', $result['blockers']);
        $this->assertFalse($result['carrier_contacts_observed']);
    }

    public function test_missing_contact_observation_is_reported(): void
    {
        $this->prepareDraft(withObservation: false);
        $result = $this->inspect();
        $this->assertContains('carrier_contact_observation_missing_or_inconclusive', $result['blockers']);
    }

    public function test_unknown_recipient_contact_fails_closed(): void
    {
        $this->contactMatches = false;
        $this->prepareDraft();
        $result = $this->inspect();
        $this->assertFalse($result['carrier_contacts_observed']);
    }

    public function test_expired_checkout_invalidates_local_checks(): void
    {
        $this->prepareDraft();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $result = $this->inspect();
        $this->assertFalse($result['local_cod_checks_current']);
        $this->assertContains('local_cod_readiness_incomplete', $result['blockers']);
    }

    public function test_superseded_sender_profile_blocks_old_draft(): void
    {
        $this->prepareDraft();
        app(NovaPoshtaSenderProfileService::class)->configure(
            (string) Str::uuid(), 'ua_primary', 2, $this->senderCityRef,
            self::SENDER_COUNTERPARTY,
            '33333333-3333-4333-8333-333333333333',
            self::SENDER_CONTACT, '+380681234567'
        );
        $result = $this->inspect();
        $this->assertContains('sender_profile_superseded', $result['blockers']);
        $this->assertFalse($result['sender_profile_current']);
    }

    public function test_new_unreviewed_contract_revision_invalidates_attestations(): void
    {
        $this->prepareDraft();
        $this->addAgreements();
        $this->actor(101);
        app(NovaPoshtaCodContractEvidenceService::class)->submit(
            (string) Str::uuid(), 'ua_primary', 'cod_agreement', 2, 'novapay',
            str_repeat('a', 64), str_repeat('b', 64),
            CarbonImmutable::now('UTC')->subDay(),
            CarbonImmutable::now('UTC')->addYear()
        );
        $result = $this->inspect();
        $this->assertFalse($result['external_material_reviews_current']);
        $this->assertContains('external_source_attestations_incomplete', $result['blockers']);
    }

    public function test_revoked_claim_invalidates_preflight(): void
    {
        $this->prepareDraft();
        $agreements = $this->addAgreements();
        $this->actor(505);
        app(NovaPoshtaCodExternalAttestationService::class)->revoke(
            (string) Str::uuid(), $agreements['cod_agreement']['claim_key'],
            'security_hold'
        );
        $result = $this->inspect();
        $this->assertFalse($result['external_material_reviews_current']);
    }

    public function test_preflight_repeats_have_no_http_or_database_side_effects(): void
    {
        $this->prepareDraft();
        $this->addAgreements();
        $first = $this->inspect();
        $this->assertSame($first, $this->inspect());
        $this->assertDatabaseCount('np_cod_external_attestation_claims', 3);
        $this->assertDatabaseCount('np_cod_external_attestation_reviews', 3);
        Http::assertSentCount(3);
    }

    public function test_report_contains_no_recipient_pii_or_api_key(): void
    {
        $this->prepareDraft();
        $this->addAgreements();
        $json = json_encode($this->inspect(), JSON_THROW_ON_ERROR);
        foreach (['Петренко', '380671234567', self::RECIPIENT_CONTACT,
                     self::RECIPIENT_COUNTERPARTY, 'ttn-gate-test-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_invalid_order_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(CheckoutCodTtnAuthorizationPreflightService::class)->inspect('invalid');
    }

    public function test_unknown_order_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        app(CheckoutCodTtnAuthorizationPreflightService::class)
            ->inspect((string) Str::uuid());
    }
}
