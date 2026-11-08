<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\CheckoutCashOnDeliveryRequestService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentChoiceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class CheckoutCashOnDeliveryRequestTest extends TestCase
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
            throw new RuntimeException(
                'Only PostgreSQL boardriders_test is allowed'
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

        $listId = (int) DB::table('price_lists')
            ->insertGetId([
                'code' => 'UA_RETAIL',
                'name' => 'UA retail',
                'country_code' => 'UA',
                'currency_code' => 'UAH',
                'type' => 'retail',
                'is_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $productId = (int) DB::table('products')
            ->insertGetId([
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->variantId = (int) DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'COD-REQUEST-' . Str::uuid(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $listId,
            'price_minor' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouseId = (int) DB::table('warehouses')
            ->insertGetId([
                'code' => 'COD-REQUEST-WH-' . Str::uuid(),
                'name' => 'COD request test warehouse',
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_creates_cod_request_without_marking_order_paid(): void
    {
        $order = $this->prepared();
        $this->chooseCod($order);

        $request = $this->request($order);

        $this->assertSame(
            'cash_on_delivery',
            $request['payment_method']
        );

        $this->assertSame(
            'awaiting_logistics',
            $request['status']
        );

        $this->assertSame('pending', $request['payment_status']);
        $this->assertSame(2000, $request['goods_due_minor']);
        $this->assertSame('UAH', $request['currency_code']);
        $this->assertFalse($request['ready_for_dispatch']);
        $this->assertFalse($request['requires_online_capture']);
        $this->assertFalse($request['shipping_amount_finalized']);

        $this->assertSame(
            $order['financial_snapshot']['sha256'],
            $request['snapshot_sha256']
        );

        $this->assertDatabaseHas('orders', [
            'id' => $order['order_id'],
            'payment_status' => 'pending',
        ]);

        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId,
            'reserved_qty' => 2,
        ]);

        $this->assertDatabaseCount('checkout_cod_requests', 1);
        $this->assertDatabaseCount('checkout_payment_attempts', 0);
        $this->assertDatabaseCount('checkout_payment_capture_receipts', 0);
    }

    public function test_identical_request_is_idempotent(): void
    {
        $order = $this->prepared();
        $this->chooseCod($order);

        $key = (string) Str::uuid();

        $first = $this->request($order, $key);
        $second = $this->request($order, $key);

        $this->assertSame($first, $second);

        $this->assertDatabaseCount('checkout_cod_requests', 1);
    }

    public function test_same_order_cannot_use_another_request_key(): void
    {
        $order = $this->prepared();
        $this->chooseCod($order);

        $this->request($order);

        $this->expectException(DomainException::class);

        $this->request($order);
    }

    public function test_cod_requires_saved_payment_choice(): void
    {
        $order = $this->prepared();

        $this->expectException(DomainException::class);

        $this->request($order);
    }

    public function test_online_payment_choice_cannot_create_cod_request(): void
    {
        $order = $this->prepared();

        app(CheckoutPaymentChoiceService::class)->choose(
            $order['order_key'],
            'card',
            'mono'
        );

        $this->expectException(DomainException::class);

        $this->request($order);
    }

    public function test_expired_checkout_cannot_create_cod_request(): void
    {
        $expiry = CarbonImmutable::now('UTC')->addMinute();

        $order = $this->prepared($expiry);
        $this->chooseCod($order);

        CarbonImmutable::setTestNow(
            $expiry->addMinute()
        );

        $this->expectException(DomainException::class);

        $this->request($order);
    }

    public function test_paid_order_cannot_create_cod_request(): void
    {
        $order = $this->prepared();
        $this->chooseCod($order);

        DB::table('orders')
            ->where('id', $order['order_id'])
            ->update(['payment_status' => 'paid']);

        $this->expectException(DomainException::class);

        $this->request($order);
    }

    public function test_canceled_order_cannot_create_cod_request(): void
    {
        $order = $this->prepared();
        $this->chooseCod($order);

        DB::table('orders')
            ->where('id', $order['order_id'])
            ->update(['payment_status' => 'canceled']);

        $this->expectException(DomainException::class);

        $this->request($order);
    }

    public function test_released_stock_cannot_create_cod_request(): void
    {
        $order = $this->prepared();
        $this->chooseCod($order);

        app(InventoryService::class)->releaseOrderReservation(
            $order['stock_reservations'][0]['reservation_key'],
            2,
            (string) Str::uuid()
        );

        $this->expectException(DomainException::class);

        $this->request($order);
    }

    public function test_request_key_cannot_be_shared_by_two_orders(): void
    {
        $firstOrder = $this->prepared();
        $secondOrder = $this->prepared();

        $this->chooseCod($firstOrder);
        $this->chooseCod($secondOrder);

        $key = (string) Str::uuid();

        $this->request($firstOrder, $key);

        $this->expectException(DomainException::class);

        $this->request($secondOrder, $key);
    }

    public function test_database_rejects_incorrect_cod_amount(): void
    {
        $order = $this->prepared();

        $choice = $this->chooseCod($order);

        $preparation = DB::table('checkout_order_preparations')
            ->where('order_id', $order['order_id'])
            ->first();

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_requests')->insert([
            'request_key' => (string) Str::uuid(),
            'order_id' => $order['order_id'],
            'payment_choice_id' => $choice['id'],
            'financial_snapshot_id' =>
                $order['financial_snapshot']['id'],
            'goods_due_minor' => 1,
            'currency_code' => 'UAH',
            'snapshot_sha256' =>
                $order['financial_snapshot']['sha256'],
            'checkout_expires_at' => $preparation->expires_at,
        ]);
    }

    public function test_database_blocks_modification_of_cod_request(): void
    {
        $order = $this->prepared();
        $this->chooseCod($order);

        $request = $this->request($order);

        $this->expectException(QueryException::class);

        DB::table('checkout_cod_requests')
            ->where('id', $request['id'])
            ->update(['goods_due_minor' => 1]);
    }

    public function test_existing_cod_request_reports_expiration(): void
    {
        $expiry = CarbonImmutable::now('UTC')->addMinute();

        $order = $this->prepared($expiry);
        $this->chooseCod($order);

        $request = $this->request($order);

        $this->assertSame(
            'awaiting_logistics',
            $request['status']
        );

        CarbonImmutable::setTestNow(
            $expiry->addMinute()
        );

        $later = app(
            CheckoutCashOnDeliveryRequestService::class
        )->findForOrder($order['order_id']);

        $this->assertSame('expired', $later['status']);
        $this->assertFalse($later['ready_for_dispatch']);
    }

    public function test_unknown_order_has_no_cod_request(): void
    {
        $this->assertNull(
            app(CheckoutCashOnDeliveryRequestService::class)
                ->findForOrder(99999999)
        );
    }

    // ========================================
    // ДОПОМІЖНІ МЕТОДИ
    // ========================================

    /** @return array<string,mixed> */
    private function prepared(
        ?CarbonImmutable $expiresAt = null
    ): array {
        return app(
            CheckoutOrderPreparationService::class
        )->prepare(
            (string) Str::uuid(),
            [
                [
                    'variant_id' => $this->variantId,
                    'quantity' => 2,
                ],
            ],
            'UA',
            'UA_RETAIL',
            $expiresAt ?? CarbonImmutable::now('UTC')
            ->addMinutes(30)
        );
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function chooseCod(array $order): array
    {
        return app(CheckoutPaymentChoiceService::class)
            ->choose(
                $order['order_key'],
                'cash_on_delivery'
            );
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function request(
        array $order,
        ?string $requestKey = null
    ): array {
        return app(
            CheckoutCashOnDeliveryRequestService::class
        )->request(
            $order['order_key'],
            $requestKey ?? (string) Str::uuid()
        );
    }
}
