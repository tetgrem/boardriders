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

final class CheckoutCodCarrierContactObservationTest extends TestCase
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
    private bool $carrierFailure = false;
    private array $senderRows = [];
    private array $recipientRows = [];

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
            'product_id' => $productId, 'sku' => 'COD-CONTACT-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $priceListId, 'price_minor' => 1000,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'COD-CONTACT-WH-' . Str::uuid(),
            'name' => 'COD contact tests warehouse',
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
        config()->set('services.nova_poshta.api_key', 'cod-contact-observation-test-key');
        config()->set('services.nova_poshta.sender_city_ref', $this->senderCityRef);
        $this->senderRows = [['Ref' => self::SENDER_CONTACT]];
        $this->recipientRows = [['Ref' => self::RECIPIENT_CONTACT]];
        $this->orderKey = $this->makeCodOrder();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_records_two_observations_but_never_approves_cod(): void
    {
        $this->prepareDraft();
        $saved = $this->observe();
        $this->assertSame('observed_once', $saved['sender_result']);
        $this->assertSame('observed_once', $saved['recipient_result']);
        $this->assertTrue($saved['sender_contact_seen']);
        $this->assertTrue($saved['recipient_contact_seen']);
        $this->assertFalse($saved['person_identity_verified']);
        $this->assertFalse($saved['merchant_cod_agreement_verified']);
        $this->assertFalse($saved['payout_contract_verified']);
        $this->assertFalse($saved['ready_for_dispatch']);
        $this->assertDatabaseCount('checkout_cod_carrier_contact_observations', 1);
        Http::assertSentCount(3); // 1 price + 2 read-only contact calls.
    }

    public function test_identical_operation_is_idempotent_without_new_http(): void
    {
        $this->prepareDraft();
        $key = (string) Str::uuid();
        $this->assertSame($this->observe($key), $this->observe($key));
        Http::assertSentCount(3);
        $this->assertDatabaseCount('checkout_cod_carrier_contact_observations', 1);
    }

    public function test_new_operation_creates_another_immutable_observation(): void
    {
        $this->prepareDraft();
        $first = $this->observe();
        $second = $this->observe();
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($second, app(CheckoutCodCarrierContactObservationService::class)
            ->latestForOrder($this->orderId()));
        $this->assertDatabaseCount('checkout_cod_carrier_contact_observations', 2);
        Http::assertSentCount(5);
    }

    public function test_missing_binding_is_rejected_before_contact_http(): void
    {
        $this->prepareDraft(withBinding: false);
        try {
            $this->observe();
            $this->fail('Missing binding must be rejected');
        } catch (DomainException) {
            Http::assertSentCount(1);
        }
    }

    public function test_expired_checkout_is_rejected_before_contact_http(): void
    {
        $this->prepareDraft();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        try {
            $this->observe();
            $this->fail('Expired checkout must be rejected');
        } catch (DomainException) {
            Http::assertSentCount(1);
        }
    }

    public function test_provider_failure_does_not_write_an_observation(): void
    {
        $this->prepareDraft();
        $this->carrierFailure = true;
        try {
            $this->observe();
            $this->fail('Carrier failure must be rejected');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('checkout_cod_carrier_contact_observations', 0);
        }
    }

    public function test_unknown_recipient_contact_remains_unconfirmed(): void
    {
        $this->recipientRows = [['Ref' => (string) Str::uuid()]];
        $this->prepareDraft();
        $saved = $this->observe();
        $this->assertTrue($saved['sender_contact_seen']);
        $this->assertFalse($saved['recipient_contact_seen']);
        $this->assertSame('not_observed_in_response', $saved['recipient_result']);
    }

    public function test_ambiguous_recipient_contact_is_not_accepted(): void
    {
        $this->recipientRows = [
            ['Ref' => self::RECIPIENT_CONTACT],
            ['Ref' => self::RECIPIENT_CONTACT],
        ];
        $this->prepareDraft();
        $saved = $this->observe();
        $this->assertSame('duplicate_reference', $saved['recipient_result']);
        $this->assertFalse($saved['recipient_contact_seen']);
        $this->assertFalse($saved['cod_eligibility_confirmed']);
    }

    public function test_direct_sql_cannot_modify_evidence(): void
    {
        $this->prepareDraft();
        $saved = $this->observe();
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_carrier_contact_observations')
            ->where('id', $saved['id'])->update(['sender_result' => 'not_observed_in_response']);
    }

    public function test_evidence_does_not_contain_raw_references_or_recipient_pii(): void
    {
        $this->prepareDraft();
        $saved = $this->observe();
        $json = json_encode($saved, JSON_THROW_ON_ERROR);
        $db = json_encode((array) DB::table('checkout_cod_carrier_contact_observations')
            ->where('id', $saved['id'])->first(), JSON_THROW_ON_ERROR);
        foreach ([self::RECIPIENT_COUNTERPARTY, self::RECIPIENT_CONTACT,
                     self::SENDER_COUNTERPARTY, self::SENDER_CONTACT,
                     'Петренко', '380671234567', 'cod-contact-observation-test-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
            $this->assertStringNotContainsString($secret, $db);
        }
    }

    public function test_only_contact_reads_are_sent_after_previous_rate(): void
    {
        $this->prepareDraft();
        $this->observe();
        Http::assertNotSent(static fn (ProviderRequest $request): bool =>
            ($request->data()['calledMethod'] ?? null) === 'save'
        );
        Http::assertSentCount(3);
    }

    public function test_invalid_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(CheckoutCodCarrierContactObservationService::class)
            ->observe('invalid', (string) Str::uuid());
    }

    private function observe(?string $key = null): array
    {
        return app(CheckoutCodCarrierContactObservationService::class)->observe(
            $this->orderKey,
            $key ?? (string) Str::uuid()
        );
    }

    private function prepareDraft(bool $withBinding = true): void
    {
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
                if ($this->carrierFailure) {
                    return Http::response([
                        'success' => false, 'data' => [], 'errors' => ['test failure'],
                    ]);
                }
                $ref = $request->data()['methodProperties']['Ref'] ?? null;
                $rows = match ($ref) {
                    self::SENDER_COUNTERPARTY => $this->senderRows,
                    self::RECIPIENT_COUNTERPARTY => $this->recipientRows,
                    default => [],
                };
                return Http::response(['success' => true, 'data' => $rows]);
            }
            return Http::response(['success' => false, 'data' => []], 400);
        });

        app(CheckoutCodCarrierRateReconciliationService::class)->reconcile(
            $this->orderKey, (string) Str::uuid()
        );
        app(CheckoutCodFinancialPreflightService::class)->record(
            $this->orderKey, (string) Str::uuid(), 3000, 'cash_branch'
        );
        app(NovaPoshtaSenderProfileService::class)->configure(
            (string) Str::uuid(), 'ua_primary', 1, $this->senderCityRef,
            self::SENDER_COUNTERPARTY,
            '33333333-3333-4333-8333-333333333333',
            self::SENDER_CONTACT,
            '+380671234567'
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
