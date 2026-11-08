<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodCarrierRateReconciliationService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutCodFinancialPreflightService;
use App\Services\Pricing\CheckoutCodPackageAssessmentService;
use App\Services\Pricing\CheckoutCodParcelManifestService;
use App\Services\Pricing\CheckoutCodRecipientService;
use App\Services\Pricing\CheckoutCodWaybillDraftService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use App\Services\Pricing\NovaPoshtaSenderProfileService;
use App\Services\Pricing\ProductVariantShippingMetricsService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class CheckoutCodWaybillDraftTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;
    private string $orderKey;
    private string $cityRef;
    private string $destinationRef;
    private string $senderCityRef;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
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
        $listId = (int) DB::table('price_lists')->insertGetId([
            'code' => 'UA_RETAIL', 'name' => 'Retail UA',
            'country_code' => 'UA', 'currency_code' => 'UAH',
            'type' => 'retail', 'is_default' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'TTN-DRAFT-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId, 'price_list_id' => $listId,
            'price_minor' => 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'TTN-DRAFT-WH-' . Str::uuid(),
            'name' => 'TTN draft test warehouse', 'country_code' => 'UA',
            'kind' => 'owned', 'is_active' => true,
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
        config()->set('services.nova_poshta.api_key', 'ttn-draft-test-key');
        config()->set('services.nova_poshta.sender_city_ref', $this->senderCityRef);
        $this->orderKey = $this->makeCodOrder();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_creates_internal_waybill_draft_with_pinned_values(): void
    {
        $this->completeLocals();
        $sender = $this->sender();
        $draft = $this->draft();
        $this->assertSame($sender['id'], $draft['sender_profile_id']);
        $this->assertSame(700, $draft['gross_grams']);
        $this->assertSame(2000, $draft['cod_amount_minor']);
        $this->assertSame(3000, $draft['declared_value_minor']);
        $this->assertSame('WarehouseWarehouse', $draft['service_type']);
        $this->assertFalse($draft['api_submit_allowed']);
        $this->assertNull($draft['actual_ttn_number']);
        $this->assertDatabaseCount('checkout_cod_waybill_drafts', 1);
    }

    public function test_local_draft_never_calls_internet_document_save(): void
    {
        $this->completeLocals();
        $this->sender();
        $this->draft();
        Http::assertSentCount(1); // Only the earlier rate quote.
        Http::assertNotSent(static fn (ProviderRequest $r): bool =>
            ($r->data()['calledMethod'] ?? null) === 'save'
        );
    }

    public function test_missing_local_financial_preflight_blocks_draft(): void
    {
        $this->sender();
        $this->expectException(DomainException::class);
        $this->draft();
    }

    public function test_missing_sender_profile_blocks_draft(): void
    {
        $this->completeLocals();
        $this->expectException(DomainException::class);
        $this->draft();
    }

    public function test_repeated_same_draft_key_and_payload_are_idempotent(): void
    {
        $this->completeLocals();
        $this->sender();
        $key = (string) Str::uuid();
        $this->assertSame($this->draft($key), $this->draft($key));
        $this->assertDatabaseCount('checkout_cod_waybill_drafts', 1);
    }

    public function test_changed_payer_on_same_draft_key_is_rejected(): void
    {
        $this->completeLocals();
        $this->sender();
        $key = (string) Str::uuid();
        $this->draft($key);
        $this->expectException(DomainException::class);
        $this->draft($key, payer: 'Sender');
    }

    public function test_new_key_cannot_replace_existing_order_draft(): void
    {
        $this->completeLocals();
        $this->sender();
        $this->draft();
        $this->expectException(DomainException::class);
        $this->draft();
    }

    public function test_invalid_payer_is_rejected_before_any_database_mutation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->draft(payer: 'Buyer');
    }

    public function test_invalid_description_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->draft(description: "bad\nline");
    }

    public function test_expired_checkout_is_not_ready_for_draft(): void
    {
        $this->completeLocals();
        $this->sender();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $this->expectException(DomainException::class);
        $this->draft();
    }

    public function test_released_stock_is_not_ready_for_draft(): void
    {
        $this->completeLocals();
        $this->sender();
        $key = (string) DB::table('inventory_reservations')
            ->where('order_id', $this->orderId())->value('reservation_key');
        app(InventoryService::class)->releaseOrderReservation(
            $key, 2, (string) Str::uuid()
        );
        $this->expectException(DomainException::class);
        $this->draft();
    }

    public function test_draft_cannot_be_modified_by_direct_sql(): void
    {
        $this->completeLocals();
        $this->sender();
        $draft = $this->draft();
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_waybill_drafts')
            ->where('id', $draft['id'])->update(['cod_amount_minor' => 1]);
    }

    public function test_database_rejects_cross_order_draft_forgery(): void
    {
        $this->completeLocals();
        $sender = $this->sender();
        $draft = $this->draft();
        $otherOrder = $this->makeCodOrder();
        $otherId = (int) DB::table('orders')->where('order_key', $otherOrder)->value('id');
        $row = (array) DB::table('checkout_cod_waybill_drafts')->where('id', $draft['id'])->first();
        unset($row['id']);
        $row['draft_key'] = (string) Str::uuid();
        $row['order_id'] = $otherId;
        $this->assertNotEquals($otherId, $draft['order_id']);
        $this->assertSame($sender['id'], (int) $row['sender_profile_id']);
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_waybill_drafts')->insert($row);
    }

    public function test_sender_profile_revision_does_not_mutate_existing_draft(): void
    {
        $this->completeLocals();
        $this->sender();
        $key = (string) Str::uuid();
        $old = $this->draft($key);
        $this->sender(revision: 2, phone: '+380681234567');
        $this->assertSame($old, $this->draft($key));
        $this->assertSame($old, app(CheckoutCodWaybillDraftService::class)->findForOrder($this->orderId()));
    }

    public function test_draft_output_and_storage_do_not_duplicate_recipient_pii(): void
    {
        $this->completeLocals();
        $this->sender();
        $json = json_encode($this->draft(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Петренко', $json);
        $this->assertStringNotContainsString('380671234567', $json);
        $this->assertContains('recipient_contact_reference_missing',
            json_decode($json, true, 512, JSON_THROW_ON_ERROR)['missing_external_evidence']);
        $this->assertDatabaseMissing('checkout_cod_waybill_drafts', [
            'cargo_description' => 'Петренко',
        ]);
    }

    public function test_draft_rejects_sender_city_different_from_quoted_city(): void
    {
        $this->completeLocals();
        $this->sender(city: (string) Str::uuid());
        $this->expectException(DomainException::class);
        $this->draft();
    }

    public function test_missing_carrier_fee_prevents_a_draft(): void
    {
        $this->completeLocals(withFee: false);
        $this->sender();
        $this->expectException(DomainException::class);
        $this->draft();
    }

    private function draft(
        ?string $key = null,
        string $payer = 'Recipient',
        string $method = 'Cash',
        string $description = 'Одяг та аксесуари',
    ): array {
        return app(CheckoutCodWaybillDraftService::class)->prepare(
            $this->orderKey, $key ?? (string) Str::uuid(),
            'ua_primary', $payer, $method, $description
        );
    }

    private function sender(
        int $revision = 1,
        string $phone = '+380671234567',
        ?string $city = null,
    ): array
    {
        return app(NovaPoshtaSenderProfileService::class)->configure(
            (string) Str::uuid(), 'ua_primary', $revision,
            $city ?? $this->senderCityRef,
            '22222222-2222-4222-8222-222222222222',
            '33333333-3333-4333-8333-333333333333',
            '44444444-4444-4444-8444-444444444444',
            $phone
        );
    }

    private function completeLocals(bool $withFee = true): void
    {
        app(CheckoutCodParcelManifestService::class)->capture(
            $this->orderKey, (string) Str::uuid(), 100
        );
        app(CheckoutCodPackageAssessmentService::class)->assess(
            $this->orderKey, (string) Str::uuid(), 300, 200, 100
        );
        Http::fake(static function (ProviderRequest $request) use ($withFee) {
            if (($request->data()['calledMethod'] ?? null) !== 'getDocumentPrice') {
                return Http::response(['success' => false, 'data' => []], 400);
            }
            $row = ['Cost' => '95.00'];
            if ($withFee) {
                $row['CostRedelivery'] = '13.20';
            }
            return Http::response(['success' => true, 'data' => [$row]]);
        });
        app(CheckoutCodCarrierRateReconciliationService::class)->reconcile(
            $this->orderKey, (string) Str::uuid()
        );
        app(CheckoutCodFinancialPreflightService::class)->record(
            $this->orderKey, (string) Str::uuid(), 3000, 'cash_branch'
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
        app(CheckoutCashOnDeliveryRequestService::class)->request(
            $key, (string) Str::uuid()
        );
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
        return (int) DB::table('orders')
            ->where('order_key', $this->orderKey)->value('id');
    }
}
