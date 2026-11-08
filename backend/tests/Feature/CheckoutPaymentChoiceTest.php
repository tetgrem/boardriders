<?php

namespace Tests\Feature;

use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentAttemptService;
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

final class CheckoutPaymentChoiceTest extends TestCase
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
                'sku' => 'PAY-CHOICE-' . Str::uuid(),
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
                'code' => 'PAY-CHOICE-' . Str::uuid(),
                'name' => 'Payment choice test warehouse',
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

    public function test_selects_card_with_mono(): void
    {
        $order = $this->prepared();

        $choice = $this->choose($order, 'card', 'mono');

        $this->assertSame('card', $choice['payment_method']);
        $this->assertSame('mono', $choice['provider_code']);
        $this->assertTrue($choice['requires_online_capture']);
        $this->assertSame('selected', $choice['status']);

        $this->assertSame(
            $choice,
            app(CheckoutPaymentChoiceService::class)
                ->findForOrder($order['order_id'])
        );

        $this->assertDatabaseCount('checkout_payment_choices', 1);
    }

    public function test_selects_apple_pay_with_mono(): void
    {
        $choice = $this->choose(
            $this->prepared(),
            'apple_pay',
            'mono'
        );

        $this->assertSame('apple_pay', $choice['payment_method']);
        $this->assertTrue($choice['requires_online_capture']);
    }

    public function test_selects_google_pay_with_liqpay(): void
    {
        $choice = $this->choose(
            $this->prepared(),
            'google_pay',
            'liqpay'
        );

        $this->assertSame('google_pay', $choice['payment_method']);
        $this->assertSame('liqpay', $choice['provider_code']);
    }

    public function test_cash_on_delivery_does_not_create_a_capture_or_mark_paid(): void
    {
        $order = $this->prepared();

        $choice = $this->choose(
            $order,
            'cash_on_delivery'
        );

        $this->assertNull($choice['provider_code']);
        $this->assertFalse($choice['requires_online_capture']);

        $this->assertDatabaseHas('orders', [
            'id' => $order['order_id'],
            'payment_status' => 'pending',
        ]);

        $this->assertDatabaseCount(
            'checkout_payment_attempts',
            0
        );

        $this->assertDatabaseCount(
            'checkout_payment_capture_receipts',
            0
        );
    }

    public function test_identical_selection_is_idempotent(): void
    {
        $order = $this->prepared();

        $first = $this->choose(
            $order,
            'google_pay',
            'mono'
        );

        $second = $this->choose(
            $order,
            'google_pay',
            'mono'
        );

        $this->assertSame($first, $second);

        $this->assertDatabaseCount(
            'checkout_payment_choices',
            1
        );
    }

    public function test_existing_choice_cannot_be_swapped_to_another_method(): void
    {
        $order = $this->prepared();

        $this->choose($order, 'card', 'mono');

        $this->expectException(DomainException::class);

        $this->choose($order, 'cash_on_delivery');
    }

    public function test_online_method_requires_explicit_provider(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->choose(
            $this->prepared(),
            'apple_pay'
        );
    }

    public function test_cash_on_delivery_forbids_provider_code(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->choose(
            $this->prepared(),
            'cash_on_delivery',
            'mono'
        );
    }

    public function test_unknown_method_is_rejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->choose(
            $this->prepared(),
            'crypto',
            'mono'
        );
    }

    public function test_order_without_checkout_preparation_cannot_choose_payment(): void
    {
        $key = (string) Str::uuid();

        DB::table('orders')->insert([
            'order_key' => $key,
            'payment_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(
            DomainException::class
        );

        app(CheckoutPaymentChoiceService::class)
            ->choose($key, 'cash_on_delivery');
    }

    public function test_paid_order_cannot_select_a_new_method(): void
    {
        $order = $this->prepared();

        DB::table('orders')
            ->where('id', $order['order_id'])
            ->update(['payment_status' => 'paid']);

        $this->expectException(
            DomainException::class
        );

        $this->choose($order, 'card', 'mono');
    }

    public function test_expired_checkout_cannot_select_a_new_method(): void
    {
        $expiry = CarbonImmutable::now('UTC')->addMinute();

        $order = $this->prepared($expiry);

        CarbonImmutable::setTestNow(
            $expiry->addMinute()
        );

        $this->expectException(
            DomainException::class
        );

        $this->choose($order, 'cash_on_delivery');
    }

    public function test_cash_on_delivery_blocks_online_attempt_at_database_level(): void
    {
        $order = $this->prepared();

        $this->choose($order, 'cash_on_delivery');

        $this->expectException(
            QueryException::class
        );

        $this->attempt($order, 'mono');
    }

    public function test_online_provider_cannot_differ_from_saved_choice(): void
    {
        $order = $this->prepared();

        $this->choose($order, 'google_pay', 'mono');

        $this->expectException(
            QueryException::class
        );

        $this->attempt($order, 'liqpay');
    }

    public function test_matching_provider_can_create_a_payment_attempt(): void
    {
        $order = $this->prepared();

        $this->choose($order, 'apple_pay', 'mono');

        $attempt = $this->attempt($order, 'mono');

        $this->assertSame(
            'mono',
            $attempt['provider_code']
        );

        $this->assertSame(
            $order['order_id'],
            $attempt['order_id']
        );

        $this->assertDatabaseCount(
            'checkout_payment_attempts',
            1
        );
    }

    public function test_existing_payment_attempt_prevents_late_method_selection(): void
    {
        $order = $this->prepared();

        $this->attempt($order, 'test_gateway');

        $this->expectException(
            DomainException::class
        );

        $this->choose($order, 'cash_on_delivery');
    }

    public function test_database_rejects_modifying_selected_payment_method(): void
    {
        $order = $this->prepared();

        $choice = $this->choose(
            $order,
            'card',
            'mono'
        );

        $this->expectException(
            QueryException::class
        );

        DB::table('checkout_payment_choices')
            ->where('id', $choice['id'])
            ->update([
                'payment_method' => 'cash_on_delivery',
            ]);
    }

    public function test_unknown_order_cannot_choose_payment(): void
    {
        $this->expectException(
            DomainException::class
        );

        app(CheckoutPaymentChoiceService::class)->choose(
            (string) Str::uuid(),
            'cash_on_delivery'
        );
    }

    // ========================================
    // ДОПОМІЖНІ МЕТОДИ
    // ========================================

    /**
     * @return array<string,mixed>
     */
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
    private function choose(
        array $order,
        string $method,
        ?string $provider = null
    ): array {
        return app(CheckoutPaymentChoiceService::class)
            ->choose(
                $order['order_key'],
                $method,
                $provider
            );
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function attempt(
        array $order,
        string $provider
    ): array {
        return app(CheckoutPaymentAttemptService::class)
            ->bindPreparedOrder(
                $order['order_key'],
                (string) Str::uuid(),
                $provider,
                'pi-' . Str::uuid(),
                CarbonImmutable::now('UTC')->addMinutes(20)
            );
    }
}
