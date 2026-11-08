<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutCodDeliverySelectionService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** Integration tests: PostgreSQL boardriders_test only. No carrier API or real payments. */
final class CheckoutCodDeliverySelectionTest extends TestCase
{
    use DatabaseTransactions;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException('Only PostgreSQL boardriders_test is allowed');
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
            'code' => 'UA_RETAIL', 'name' => 'UA retail',
            'country_code' => 'UA', 'currency_code' => 'UAH',
            'type' => 'retail', 'is_default' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'COD-DEL-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId, 'price_list_id' => $listId,
            'price_minor' => 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'COD-DEL-WH-' . Str::uuid(),
            'name' => 'COD delivery selection test warehouse',
            'country_code' => 'UA', 'kind' => 'owned', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouseId, 'variant_id' => $this->variantId,
            'on_hand_qty' => 30, 'reserved_qty' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_selects_nova_poshta_branch_without_charging_or_shipping(): void
    {
        $order = $this->preparedCod();
        $destination = (string) Str::uuid();
        $selected = $this->deliverySelect($order, destinationRef: $destination);

        $this->assertSame('nova_poshta', $selected['carrier_code']);
        $this->assertSame('branch', $selected['delivery_type']);
        $this->assertSame($destination, $selected['destination_ref']);
        $this->assertSame('pending', $selected['payment_status']);
        $this->assertSame('awaiting_carrier_verification', $selected['status']);
        $this->assertSame(2000, $selected['goods_due_minor']);
        $this->assertSame('UAH', $selected['currency_code']);
        $this->assertFalse($selected['destination_verified']);
        $this->assertFalse($selected['cod_supported_by_destination']);
        $this->assertFalse($selected['delivery_fee_finalized']);
        $this->assertFalse($selected['ready_for_dispatch']);
        $this->assertDatabaseHas('orders', [
            'id' => $order['order_id'], 'payment_status' => 'pending',
        ]);
        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId, 'reserved_qty' => 2,
        ]);
        $this->assertDatabaseCount('checkout_cod_delivery_selections', 1);
        $this->assertDatabaseCount('checkout_payment_capture_receipts', 0);
        $this->assertSame(
            $selected,
            $this->service()->findForOrder($order['order_id'])
        );
    }

    public function test_parcel_locker_is_only_an_unverified_preference(): void
    {
        $selected = $this->deliverySelect($this->preparedCod(), 'parcel_locker');
        $this->assertSame('parcel_locker', $selected['delivery_type']);
        $this->assertSame('awaiting_carrier_verification', $selected['status']);
        $this->assertFalse($selected['cod_supported_by_destination']);
        $this->assertFalse($selected['ready_for_dispatch']);
    }

    public function test_identical_selection_is_idempotent(): void
    {
        $order = $this->preparedCod();
        $key = (string) Str::uuid();
        $ref = (string) Str::uuid();
        $first = $this->deliverySelect($order, 'branch', $ref, $key);
        $second = $this->deliverySelect($order, 'branch', $ref, $key);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('checkout_cod_delivery_selections', 1);
    }

    public function test_order_cannot_change_destination_after_selection(): void
    {
        $order = $this->preparedCod();
        $key = (string) Str::uuid();
        $this->deliverySelect($order, 'branch', (string) Str::uuid(), $key);
        $this->expectException(DomainException::class);
        $this->deliverySelect($order, 'branch', (string) Str::uuid(), $key);
    }

    public function test_selection_uuid_cannot_be_reused_for_another_order(): void
    {
        $first = $this->preparedCod();
        $second = $this->preparedCod();
        $key = (string) Str::uuid();
        $this->deliverySelect($first, 'branch', null, $key);
        $this->expectException(DomainException::class);
        $this->deliverySelect($second, 'branch', null, $key);
    }

    public function test_cod_request_is_required(): void
    {
        $order = $this->prepared();
        app(CheckoutPaymentChoiceService::class)->choose(
            $order['order_key'], 'cash_on_delivery'
        );
        $this->expectException(DomainException::class);
        $this->deliverySelect($order);
    }

    public function test_online_checkout_cannot_select_cod_delivery(): void
    {
        $order = $this->prepared();
        app(CheckoutPaymentChoiceService::class)->choose(
            $order['order_key'], 'card', 'mono'
        );
        $this->expectException(DomainException::class);
        $this->deliverySelect($order);
    }

    public function test_expired_preparation_cannot_select_delivery(): void
    {
        $expires = CarbonImmutable::now('UTC')->addMinute();
        $order = $this->preparedCod($expires);
        CarbonImmutable::setTestNow($expires->addSecond());
        $this->expectException(DomainException::class);
        $this->deliverySelect($order);
    }

    public function test_canceled_order_cannot_select_delivery(): void
    {
        $order = $this->preparedCod();
        DB::table('orders')->where('id', $order['order_id'])
            ->update(['payment_status' => 'canceled']);
        $this->expectException(DomainException::class);
        $this->deliverySelect($order);
    }

    public function test_unsupported_courier_mode_is_rejected_until_implemented(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->deliverySelect($this->preparedCod(), 'courier');
    }

    public function test_unknown_carrier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->deliverySelect($this->preparedCod(), carrierCode: 'fake_carrier');
    }

    public function test_destination_must_be_uuid_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->deliverySelect($this->preparedCod(), destinationRef: 'fake-branch');
    }

    public function test_released_stock_cannot_select_delivery(): void
    {
        $order = $this->preparedCod();
        app(InventoryService::class)->releaseOrderReservation(
            $order['stock_reservations'][0]['reservation_key'],
            2,
            (string) Str::uuid()
        );
        $this->expectException(DomainException::class);
        $this->deliverySelect($order);
    }

    public function test_direct_sql_cannot_forge_cod_amount(): void
    {
        $order = $this->preparedCod();
        $cod = DB::table('checkout_cod_requests')
            ->where('order_id', $order['order_id'])->first();
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_delivery_selections')->insert([
            'selection_key' => (string) Str::uuid(),
            'order_id' => $order['order_id'],
            'cod_request_id' => $cod->id,
            'carrier_code' => 'nova_poshta',
            'delivery_type' => 'branch',
            'destination_ref' => (string) Str::uuid(),
            'goods_due_minor' => 1,
            'currency_code' => $cod->currency_code,
            'snapshot_sha256' => $cod->snapshot_sha256,
            'checkout_expires_at' => $cod->checkout_expires_at,
        ]);
    }

    public function test_database_blocks_modification_of_selection(): void
    {
        $selected = $this->deliverySelect($this->preparedCod());
        $this->expectException(QueryException::class);
        DB::table('checkout_cod_delivery_selections')
            ->where('id', $selected['id'])
            ->update(['destination_ref' => (string) Str::uuid()]);
    }

    public function test_existing_selection_reports_expired_reservation_window(): void
    {
        $expires = CarbonImmutable::now('UTC')->addMinute();
        $order = $this->preparedCod($expires);
        $selection = $this->deliverySelect($order);
        $this->assertSame('awaiting_carrier_verification', $selection['status']);

        CarbonImmutable::setTestNow($expires->addSecond());
        $later = $this->service()->findForOrder($order['order_id']);
        $this->assertSame('expired', $later['status']);
        $this->assertFalse($later['ready_for_dispatch']);
    }

    /** @return array<string,mixed> */
    private function prepared(?CarbonImmutable $expiresAt = null): array
    {
        return app(CheckoutOrderPreparationService::class)->prepare(
            (string) Str::uuid(),
            [['variant_id' => $this->variantId, 'quantity' => 2]],
            'UA',
            'UA_RETAIL',
            $expiresAt ?? CarbonImmutable::now('UTC')->addMinutes(30),
        );
    }

    /** @return array<string,mixed> */
    private function preparedCod(?CarbonImmutable $expiresAt = null): array
    {
        $order = $this->prepared($expiresAt);
        app(CheckoutPaymentChoiceService::class)->choose(
            $order['order_key'], 'cash_on_delivery'
        );
        app(CheckoutCashOnDeliveryRequestService::class)->request(
            $order['order_key'], (string) Str::uuid()
        );
        return $order;
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function deliverySelect(
        array $order,
        string $deliveryType = 'branch',
        ?string $destinationRef = null,
        ?string $selectionKey = null,
        string $carrierCode = 'nova_poshta',
    ): array {
        return $this->service()->select(
            $order['order_key'],
            $selectionKey ?? (string) Str::uuid(),
            $deliveryType,
            $destinationRef ?? (string) Str::uuid(),
            $carrierCode,
        );
    }

    private function service(): CheckoutCodDeliverySelectionService
    {
        return app(CheckoutCodDeliverySelectionService::class);
    }
}
