<?php

namespace Tests\Feature;

use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodCarrierRateReconciliationService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutCodFinancialPreflightService;
use App\Services\Pricing\CheckoutCodPackageAssessmentService;
use App\Services\Pricing\CheckoutCodParcelManifestService;
use App\Services\Pricing\CheckoutCodRecipientCarrierBindingService;
use App\Services\Pricing\CheckoutCodRecipientService;
use App\Services\Pricing\CheckoutCodWaybillDraftService;
use App\Services\Pricing\CheckoutCodWaybillPayloadPreviewService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use App\Services\Pricing\NovaPoshtaSenderProfileService;
use App\Services\Pricing\ProductVariantShippingMetricsService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class CheckoutCodRecipientCarrierBindingAndPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const COUNTERPARTY = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const CONTACT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private int $variantId;
    private string $orderKey;
    private string $cityRef;
    private string $destinationRef;
    private string $senderCityRef;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new RuntimeException('Requires PostgreSQL boardriders_test');
        }

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
        $priceList = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL', 'name' => 'Retail UA',
            'country_code' => 'UA', 'currency_code' => 'UAH',
            'type' => 'retail', 'is_default' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'TTN-PREVIEW-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $priceList, 'price_minor' => 1000,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'TTN-PREVIEW-WH-' . Str::uuid(),
            'name' => 'TTN preview test warehouse',
            'country_code' => 'UA', 'kind' => 'owned', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 20, 'reserved_qty' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        app(ProductVariantShippingMetricsService::class)->change(
            $this->variantId, (string) Str::uuid(), 0, 300, 200, 100, 50
        );

        $this->cityRef = (string) Str::uuid();
        $this->destinationRef = (string) Str::uuid();
        $this->senderCityRef = (string) Str::uuid();
        config()->set('services.nova_poshta.api_key', 'ttn-preview-test-key');
        config()->set('services.nova_poshta.sender_city_ref', $this->senderCityRef);
        $this->orderKey = $this->makeCodOrder();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_binding_stores_refs_but_never_confirms_recipient_identity(): void
    {
        $this->prepareDraft();
        $saved = $this->bind();
        $this->assertTrue($saved['references_recorded']);
        $this->assertFalse($saved['references_verified_with_carrier']);
        $this->assertFalse($saved['ready_for_dispatch']);
        $this->assertDatabaseCount('checkout_cod_recipient_carrier_bindings', 1);
    }

    public function test_recipient_carrier_refs_are_encrypted_at_rest(): void
    {
        $this->prepareDraft();
        $this->bind();
        $row = DB::table('checkout_cod_recipient_carrier_bindings')->first();
        $this->assertStringNotContainsString(self::COUNTERPARTY, $row->counterparty_ref_ciphertext);
        $this->assertStringNotContainsString(self::CONTACT, $row->contact_ref_ciphertext);
        $this->assertSame(self::COUNTERPARTY, Crypt::decryptString($row->counterparty_ref_ciphertext));
        $this->assertSame(self::CONTACT, Crypt::decryptString($row->contact_ref_ciphertext));
    }

    public function test_identical_binding_request_is_idempotent(): void
    {
        $this->prepareDraft();
        $key = (string) Str::uuid();
        $this->assertSame($this->bind($key), $this->bind($key));
        $this->assertDatabaseCount('checkout_cod_recipient_carrier_bindings', 1);
    }

    public function test_same_uuid_cannot_change_contact_reference(): void
    {
        $this->prepareDraft();
        $key = (string) Str::uuid();
        $this->bind($key);
        $this->expectException(DomainException::class);
        $this->bind($key, contact: (string) Str::uuid());
    }

    public function test_different_uuid_cannot_replace_binding(): void
    {
        $this->prepareDraft();
        $this->bind();
        $this->expectException(DomainException::class);
        $this->bind();
    }

    public function test_invalid_carrier_ref_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->bind(counterparty: 'not-a-uuid');
    }

    public function test_bind_requires_an_existing_waybill_draft(): void
    {
        $this->expectException(DomainException::class);
        $this->bind();
    }

    public function test_database_blocks_binding_update(): void
    {
        $this->prepareDraft();
        $saved = $this->bind();
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_recipient_carrier_bindings')
            ->where('id', $saved['id'])->update(['request_hmac' => str_repeat('a', 64)]);
    }

    public function test_binding_readback_never_contains_refs(): void
    {
        $this->prepareDraft();
        $saved = $this->bind();
        $read = app(CheckoutCodRecipientCarrierBindingService::class)
            ->findForOrder($this->orderId());
        $this->assertSame($saved, $read);
        $json = json_encode($read, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::COUNTERPARTY, $json);
        $this->assertStringNotContainsString(self::CONTACT, $json);
    }

    public function test_preview_without_binding_lists_missing_reference_blocker(): void
    {
        $this->prepareDraft();
        $preview = $this->preview();
        $this->assertFalse($preview['recipient_references_recorded']);
        $this->assertContains('recipient_carrier_references_missing', $preview['blockers']);
        $this->assertFalse($preview['api_submit_allowed']);
        $this->assertNull($preview['actual_ttn_number']);
    }

    public function test_preview_with_binding_is_still_blocked(): void
    {
        $this->prepareDraft();
        $this->bind();
        $preview = $this->preview();
        $this->assertTrue($preview['recipient_references_recorded']);
        $this->assertNotContains('recipient_carrier_references_missing', $preview['blockers']);
        $this->assertContains('recipient_carrier_identity_not_verified', $preview['blockers']);
        $this->assertFalse($preview['ready_for_dispatch']);
        $this->assertFalse($preview['ttn_created']);
    }

    public function test_declared_value_mismatch_requires_new_tariff_assessment(): void
    {
        $this->prepareDraft(declared: 3000);
        $this->bind();
        $preview = $this->preview();
        $this->assertContains('declared_value_rerate_required', $preview['blockers']);
        $this->assertTrue($preview['preview']['declared_value_rerate_required']);
        $this->assertSame('30.00', $preview['preview']['safe_fields']['Cost']);
    }

    public function test_preview_does_not_leak_pii_or_api_key(): void
    {
        $this->prepareDraft();
        $this->bind();
        $json = json_encode($this->preview(), JSON_THROW_ON_ERROR);
        foreach ([self::COUNTERPARTY, self::CONTACT, 'Петренко',
                     '380671234567', 'ttn-preview-test-key'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $json);
        }
    }

    public function test_expired_checkout_is_not_reported_ready(): void
    {
        $this->prepareDraft();
        $this->bind();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $preview = $this->preview();
        $this->assertFalse($preview['local_checks_complete_now']);
        $this->assertContains('checkout_not_currently_ready', $preview['blockers']);
    }

    public function test_preview_has_no_new_http_side_effects(): void
    {
        $this->prepareDraft();
        $this->bind();
        $one = $this->preview();
        $two = $this->preview();
        $this->assertSame($one, $two);
        Http::assertSentCount(1); // Existing rate request only.
    }

    private function bind(?string $key = null, string $counterparty = self::COUNTERPARTY,
                          string $contact = self::CONTACT): array
    {
        return app(CheckoutCodRecipientCarrierBindingService::class)->bind(
            $this->orderKey, $key ?? (string) Str::uuid(), $counterparty, $contact
        );
    }

    private function preview(): array
    {
        return app(CheckoutCodWaybillPayloadPreviewService::class)->preview($this->orderKey);
    }

    private function prepareDraft(int $declared = 3000): void
    {
        app(CheckoutCodParcelManifestService::class)->capture(
            $this->orderKey, (string) Str::uuid(), 100
        );
        app(CheckoutCodPackageAssessmentService::class)->assess(
            $this->orderKey, (string) Str::uuid(), 300, 200, 100
        );
        Http::fake(static function (ProviderRequest $request) {
            if (($request->data()['calledMethod'] ?? null) !== 'getDocumentPrice') {
                return Http::response(['success' => false, 'data' => []], 400);
            }
            return Http::response(['success' => true, 'data' => [[
                'Cost' => '95.00', 'CostRedelivery' => '13.20',
            ]]]);
        });
        app(CheckoutCodCarrierRateReconciliationService::class)->reconcile(
            $this->orderKey, (string) Str::uuid()
        );
        app(CheckoutCodFinancialPreflightService::class)->record(
            $this->orderKey, (string) Str::uuid(), $declared, 'cash_branch'
        );
        app(NovaPoshtaSenderProfileService::class)->configure(
            (string) Str::uuid(), 'ua_primary', 1, $this->senderCityRef,
            '22222222-2222-4222-8222-222222222222',
            '33333333-3333-4333-8333-333333333333',
            '44444444-4444-4444-8444-444444444444',
            '+380671234567'
        );
        app(CheckoutCodWaybillDraftService::class)->prepare(
            $this->orderKey, (string) Str::uuid(), 'ua_primary',
            'Recipient', 'Cash', 'Одяг та аксесуари'
        );
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

    private function orderId(): int
    {
        return (int) DB::table('orders')->where('order_key', $this->orderKey)->value('id');
    }
}
