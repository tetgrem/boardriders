<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutCodRecipientService;
use App\Services\Pricing\CheckoutCodShippingQuoteService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class CheckoutCodDeliveryDetailsTest extends TestCase
{
    use DatabaseTransactions;

    private int $variantId;
    private string $orderKey;
    private string $cityRef;
    private string $warehouseRef;
    private string $recipientKey;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new \RuntimeException(
                'COD delivery tests require PostgreSQL boardriders_test'
            );
        }

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

        $this->variantId = (int) DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'COD-DETAILS-' . Str::uuid(),
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

        $warehouseId = (int) DB::table('warehouses')
            ->insertGetId([
                'code' => 'COD-DETAILS-WH-' . Str::uuid(),
                'name' => 'COD details test warehouse',
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

        $this->cityRef = (string) Str::uuid();
        $this->warehouseRef = (string) Str::uuid();
        $this->recipientKey = (string) Str::uuid();
        $this->orderKey = $this->makeCodOrder();

        config()->set(
            'services.nova_poshta.api_key',
            'cod-details-test-key'
        );

        config()->set(
            'services.nova_poshta.sender_city_ref',
            (string) Str::uuid()
        );

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_recipient_is_saved_for_selected_cod_order(): void
    {
        $saved = $this->saveRecipient();

        $this->assertSame('Іван', $saved['first_name']);
        $this->assertSame('Петренко', $saved['last_name']);
        $this->assertSame('+380671234567', $saved['phone']);
        $this->assertSame($this->cityRef, $saved['city_ref']);
        $this->assertFalse($saved['ready_for_dispatch']);

        $this->assertDatabaseCount('checkout_cod_recipients', 1);
    }

    public function test_recipient_personal_data_is_encrypted_at_rest(): void
    {
        $saved = $this->saveRecipient();

        $row = DB::table('checkout_cod_recipients')
            ->where('id', $saved['id'])
            ->first();

        $this->assertStringNotContainsString(
            'Петренко',
            $row->last_name_ciphertext
        );

        $this->assertStringNotContainsString(
            '380671234567',
            $row->phone_ciphertext
        );

        $this->assertNotSame(
            'Іван',
            $row->first_name_ciphertext
        );
    }

    public function test_same_recipient_key_and_payload_are_idempotent(): void
    {
        $first = $this->saveRecipient();
        $second = $this->saveRecipient();

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('checkout_cod_recipients', 1);
    }

    public function test_changed_recipient_payload_is_rejected(): void
    {
        $this->saveRecipient();

        $this->expectException(DomainException::class);

        $this->saveRecipient(phone: '+380931234567');
    }

    public function test_new_recipient_key_for_same_order_is_rejected(): void
    {
        $this->saveRecipient();

        $this->expectException(DomainException::class);

        $this->saveRecipient(key: (string) Str::uuid());
    }

    public function test_recipient_key_cannot_be_reused_for_another_order(): void
    {
        $this->saveRecipient();
        $another = $this->makeCodOrder();

        $this->expectException(DomainException::class);

        $this->saveRecipient(orderKey: $another);
    }

    public function test_recipient_rejects_invalid_phone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->saveRecipient(phone: '0671234567');
    }

    public function test_recipient_rejects_name_with_control_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->saveRecipient(firstName: "Іван\n");
    }

    public function test_recipient_rejects_invalid_city_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->saveRecipient(cityRef: 'not-a-uuid');
    }

    public function test_recipient_rejects_expired_checkout(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addHour()
        );

        $this->expectException(DomainException::class);

        $this->saveRecipient();
    }

    public function test_recipient_rejects_released_stock(): void
    {
        $reservationKey = DB::table('inventory_reservations')
            ->where('order_id', $this->orderId())
            ->value('reservation_key');

        app(InventoryService::class)->releaseOrderReservation(
            (string) $reservationKey,
            2,
            (string) Str::uuid()
        );

        $this->expectException(DomainException::class);

        $this->saveRecipient();
    }

    public function test_recipient_record_is_immutable_at_database_level(): void
    {
        $saved = $this->saveRecipient();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_recipients')
            ->where('id', $saved['id'])
            ->update([
                'city_ref' => (string) Str::uuid(),
            ]);
    }

    public function test_recipient_cannot_be_deleted_directly(): void
    {
        $saved = $this->saveRecipient();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_recipients')
            ->where('id', $saved['id'])
            ->delete();
    }

    public function test_quote_binds_provider_cost_to_real_order_amount(): void
    {
        $this->saveRecipient();
        $this->fakeProvider('85.50', '12.30');

        $result = $this->makeQuote();

        $this->assertSame(2000, $result['goods_due_minor']);
        $this->assertSame(8550, $result['delivery_cost_minor']);
        $this->assertSame(1230, $result['cod_transfer_fee_minor']);
        $this->assertSame('estimated', $result['status']);
        $this->assertTrue($result['estimated_only']);
        $this->assertFalse($result['delivery_fee_finalized']);
        $this->assertFalse($result['cod_eligibility_confirmed']);
        $this->assertFalse($result['ready_for_dispatch']);

        $this->assertDatabaseCount('checkout_cod_shipping_quotes', 1);

        Http::assertSentCount(2);

        Http::assertSent(
            static function (ProviderRequest $request): bool {
                $data = $request->data();

                return ($data['calledMethod'] ?? null) ===
                    'getDocumentPrice'
                    && ($data['methodProperties']['Cost'] ?? null) ===
                    '20.00'
                    && (
                        $data['methodProperties']
                        ['RedeliveryCalculate']['Amount'] ?? null
                    ) === '20.00'
                    && ($data['methodProperties']['Weight'] ?? null) ===
                    '1.250';
            }
        );
    }

    public function test_quote_missing_cod_fee_stays_unconfirmed(): void
    {
        $this->saveRecipient();
        $this->fakeProvider('85.50', null);

        $result = $this->makeQuote();

        $this->assertSame(
            'awaiting_cod_fee',
            $result['status']
        );

        $this->assertNull($result['cod_transfer_fee_minor']);
        $this->assertFalse($result['cod_fee_available']);
    }

    public function test_quote_is_idempotent_and_avoids_another_provider_request(): void
    {
        $this->saveRecipient();
        $this->fakeProvider('85.50', '12.30');

        $key = (string) Str::uuid();

        $first = $this->makeQuote($key);
        $second = $this->makeQuote($key);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('checkout_cod_shipping_quotes', 1);

        Http::assertSentCount(2);
    }

    public function test_quote_rejects_different_weight_for_same_key(): void
    {
        $this->saveRecipient();
        $this->fakeProvider('85.50', '12.30');

        $key = (string) Str::uuid();
        $this->makeQuote($key);

        $this->expectException(DomainException::class);

        $this->makeQuote($key, weight: 2000);
    }

    public function test_quote_rejects_different_key_for_same_order(): void
    {
        $this->saveRecipient();
        $this->fakeProvider('85.50', '12.30');

        $this->makeQuote();

        $this->expectException(DomainException::class);

        $this->makeQuote();
    }

    public function test_quote_requires_recipient(): void
    {
        $this->expectException(DomainException::class);

        $this->makeQuote();
    }

    public function test_quote_rejects_zero_weight_before_provider_call(): void
    {
        $this->saveRecipient();

        $this->expectException(InvalidArgumentException::class);

        $this->makeQuote(weight: 0);
    }

    public function test_quote_rejects_expired_checkout(): void
    {
        $this->saveRecipient();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now('UTC')->addHour()
        );

        $this->expectException(DomainException::class);

        $this->makeQuote();
    }

    public function test_quote_rejects_unverified_warehouse(): void
    {
        $this->saveRecipient();
        $this->fakeProvider('85.50', '12.30', wrongCity: true);

        $this->expectException(DomainException::class);

        $this->makeQuote();
    }

    public function test_quote_rejects_malformed_provider_price_without_persisting(): void
    {
        $this->saveRecipient();
        $this->fakeProvider('85.999', '12.30');

        try {
            $this->makeQuote();

            $this->fail(
                'Malformed quote must not be persisted'
            );
        } catch (\RuntimeException $exception) {
            $this->assertDatabaseCount(
                'checkout_cod_shipping_quotes',
                0
            );
        }
    }

    public function test_quote_is_immutable_at_database_level(): void
    {
        $this->saveRecipient();
        $this->fakeProvider('85.50', '12.30');

        $saved = $this->makeQuote();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_shipping_quotes')
            ->where('id', $saved['id'])
            ->update([
                'delivery_cost_minor' => 1,
            ]);
    }

    public function test_quote_can_be_read_after_creation(): void
    {
        $this->saveRecipient();
        $this->fakeProvider('85.50', '12.30');

        $saved = $this->makeQuote();

        $read = app(CheckoutCodShippingQuoteService::class)
            ->findForOrder($this->orderId());

        $this->assertSame($saved, $read);
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
            $this->warehouseRef
        );

        return $key;
    }

    private function saveRecipient(
        ?string $orderKey = null,
        ?string $key = null,
        ?string $cityRef = null,
        string $firstName = 'Іван',
        string $lastName = 'Петренко',
        string $phone = '+380671234567',
    ): array {
        return app(CheckoutCodRecipientService::class)->save(
            $orderKey ?? $this->orderKey,
            $key ?? $this->recipientKey,
            $cityRef ?? $this->cityRef,
            $firstName,
            $lastName,
            $phone,
        );
    }

    private function makeQuote(
        ?string $key = null,
        int $weight = 1250,
    ): array {
        return app(CheckoutCodShippingQuoteService::class)->quote(
            $this->orderKey,
            $key ?? (string) Str::uuid(),
            $weight
        );
    }

    private function orderId(): int
    {
        return (int) DB::table('orders')
            ->where('order_key', $this->orderKey)
            ->value('id');
    }

    private function fakeProvider(
        string $delivery,
        ?string $codFee,
        bool $wrongCity = false,
    ): void {
        $city = $wrongCity
            ? (string) Str::uuid()
            : $this->cityRef;

        $warehouse = $this->warehouseRef;

        $response = [
            'Cost' => $delivery,
        ];

        if ($codFee !== null) {
            $response['CostRedelivery'] = $codFee;
        }

        Http::fake(
            function (ProviderRequest $request) use (
                $city,
                $warehouse,
                $response
            ) {
                return match (
                    $request->data()['calledMethod'] ?? null
                ) {
                    'getWarehouses' => Http::response([
                        'success' => true,
                        'data' => [
                            [
                                'Ref' => $warehouse,
                                'CityRef' => $city,
                                'CategoryOfWarehouse' => 'Branch',
                                'WarehouseStatus' => 'Working',
                                'Description' => 'Відділення №1',
                            ],
                        ],
                    ]),

                    'getDocumentPrice' => Http::response([
                        'success' => true,
                        'data' => [$response],
                    ]),

                    default => Http::response([
                        'success' => false,
                        'data' => [],
                    ], 400),
                };
            }
        );
    }
}
