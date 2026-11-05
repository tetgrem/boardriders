<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory\OrderInventoryService;
use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Pricing\CheckoutOrderCancellationService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CheckoutOrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;
    private int $warehouseId;
    private int $promotionId;
    private string $promotionCode;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $this->promotionCode = 'CANCEL-' . strtoupper(Str::random(10));

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

        $listId = (int) DB::table('price_lists')->insertGetId([
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

        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = (int) DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'CANCEL-' . Str::uuid(),
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

        $this->warehouseId = (int) DB::table('warehouses')
            ->insertGetId([
                'code' => 'CANCEL-WH-' . Str::uuid(),
                'name' => 'Cancellation test warehouse',
                'kind' => 'owned',
                'country_code' => 'UA',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $this->warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 10,
            'reserved_qty' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->promotionId = (int) DB::table('promotions')
            ->insertGetId([
                'price_list_id' => $listId,
                'name' => 'Cancellation test promotion',
                'type' => 'percent',
                'value' => 1000,
                'min_subtotal_minor' => 0,
                'starts_at' => $now->copy()->subDay(),
                'ends_at' => $now->copy()->addDay(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        DB::table('promotion_codes')->insert([
            'promotion_id' => $this->promotionId,
            'code' => $this->promotionCode,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_guest_cancellation_releases_stock_but_keeps_snapshot(): void
    {
        $order = $this->prepare();

        $canceled = $this->cancel($order);

        $this->assertSame('canceled', $canceled['payment_status']);
        $this->assertSame(2, $canceled['released_stock_qty']);

        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId,
            'on_hand_qty' => 10,
            'reserved_qty' => 0,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order['order_id'],
            'payment_status' => 'canceled',
        ]);

        $this->assertDatabaseCount('order_financial_snapshots', 1);
        $this->assertDatabaseCount('checkout_order_cancellations', 1);
        $this->assertDatabaseCount('inventory_reservation_operations', 1);
    }

    public function test_cancellation_releases_promotion_quota(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['usage_limit' => 1]);

        $order = $this->prepare(withPromotion: true);

        $canceled = $this->cancel($order);

        $this->assertNotNull(
            $canceled['promotion_reservation_id']
        );

        $this->assertDatabaseHas('promotion_reservation_events', [
            'reservation_id' => $canceled['promotion_reservation_id'],
            'event_type' => 'released',
        ]);

        $this->assertDatabaseCount('promotion_redemptions', 0);

        // Звільнена квота доступна іншому pending-order.
        $nextOrder = $this->prepare(withPromotion: true);

        $this->assertSame(
            'reserved',
            $nextOrder['promotion_reservation']['status']
        );
    }

    public function test_cancellation_restores_available_bonus_units(): void
    {
        $user = User::factory()->create();
        $walletId = $this->fund($user, 500);
        $this->policyAllowVariant();

        $order = $this->prepare($user->id, 120);

        $this->assertSame(
            120,
            (int) DB::table('loyalty_ledger_entries')
                ->where('wallet_id', $walletId)
                ->sum('reserved_delta')
        );

        $canceled = $this->cancel($order);

        $this->assertSame(120, $canceled['released_bonus_units']);
        $this->assertSame(0, $canceled['expired_bonus_units']);

        $this->assertSame(
            0,
            (int) DB::table('loyalty_ledger_entries')
                ->where('wallet_id', $walletId)
                ->sum('reserved_delta')
        );

        $this->assertSame(
            500,
            (int) DB::table('loyalty_ledger_entries')
                ->where('wallet_id', $walletId)
                ->sum('available_delta')
        );
    }

    public function test_combined_checkout_releases_promo_stock_and_bonus(): void
    {
        $user = User::factory()->create();
        $this->fund($user, 500);
        $this->policyAllowVariant();

        $order = $this->prepare(
            userId: $user->id,
            bonusUnits: 120,
            withPromotion: true
        );

        $this->assertSame(1720,
            $order['financial_snapshot']['goods_payable_minor']
        );

        $canceled = $this->cancel($order);

        $this->assertSame(2, $canceled['released_stock_qty']);
        $this->assertSame(120, $canceled['released_bonus_units']);
        $this->assertNotNull($canceled['promotion_reservation_id']);

        $this->assertDatabaseCount('promotion_reservation_events', 1);
        $this->assertDatabaseCount('loyalty_reservations', 1);
        $this->assertDatabaseCount('order_financial_snapshots', 1);

        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId,
            'reserved_qty' => 0,
        ]);
    }

    public function test_repeated_same_cancellation_is_idempotent(): void
    {
        $order = $this->prepare(withPromotion: true);
        $key = (string) Str::uuid();

        $first = $this->cancel($order, $key);
        $second = $this->cancel($order, $key);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('checkout_order_cancellations', 1);
        $this->assertDatabaseCount('promotion_reservation_events', 1);
        $this->assertDatabaseCount('inventory_reservation_operations', 1);
    }

    public function test_different_cancellation_key_is_rejected(): void
    {
        $order = $this->prepare();

        $this->cancel($order, (string) Str::uuid());

        $this->expectException(DomainException::class);

        $this->cancel($order, (string) Str::uuid());
    }

    public function test_paid_order_cannot_be_canceled(): void
    {
        $order = $this->prepare();

        // Симуляція статусу, а не платіжний API.
        DB::table('orders')
            ->where('id', $order['order_id'])
            ->update(['payment_status' => 'paid']);

        $this->expectException(DomainException::class);

        $this->cancel($order);
    }

    public function test_review_order_cannot_be_canceled(): void
    {
        $order = $this->prepare();

        DB::table('orders')
            ->where('id', $order['order_id'])
            ->update(['payment_status' => 'review']);

        $this->expectException(DomainException::class);

        $this->cancel($order);
    }

    public function test_timeout_cannot_cancel_before_expiration(): void
    {
        $order = $this->prepare();

        $this->expectException(DomainException::class);

        $this->cancel(
            $order,
            reason: 'reservation_timeout'
        );
    }

    public function test_expired_pending_checkout_can_be_canceled(): void
    {
        $expiry = CarbonImmutable::now('UTC')->addMinute();

        $order = $this->prepare(
            withPromotion: true,
            expiresAt: $expiry
        );

        CarbonImmutable::setTestNow(
            $expiry->addMinute()
        );

        $canceled = $this->cancel(
            $order,
            reason: 'reservation_timeout'
        );

        $this->assertSame(
            'reservation_timeout',
            $canceled['reason_code']
        );

        $this->assertSame(2, $canceled['released_stock_qty']);

        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId,
            'reserved_qty' => 0,
        ]);

        $this->assertDatabaseCount('promotion_reservation_events', 1);
    }

    public function test_failure_after_promo_release_rolls_back_everything(): void
    {
        $order = $this->prepare(withPromotion: true);

        // Штучно пошкоджуємо лічильник складу в тестовій БД.
        // Сам складський резерв ще містить активні 2 одиниці.
        DB::table('inventory_stocks')
            ->where('variant_id', $this->variantId)
            ->update(['reserved_qty' => 0]);

        try {
            $this->cancel($order);

            $this->fail('Inconsistent stock must abort cancellation');
        } catch (DomainException) {
            $this->assertDatabaseCount(
                'promotion_reservation_events',
                0
            );

            $this->assertDatabaseCount(
                'checkout_order_cancellations',
                0
            );

            $this->assertDatabaseHas('orders', [
                'id' => $order['order_id'],
                'payment_status' => 'pending',
            ]);
        }
    }

    public function test_database_blocks_cancellation_history_mutation(): void
    {
        $order = $this->prepare();
        $this->cancel($order);

        $this->expectException(QueryException::class);

        DB::table('checkout_order_cancellations')
            ->where('order_id', $order['order_id'])
            ->delete();
    }

    public function test_canceled_order_cannot_be_reopened_in_database(): void
    {
        $order = $this->prepare();
        $this->cancel($order);

        $this->expectException(QueryException::class);

        DB::table('orders')
            ->where('id', $order['order_id'])
            ->update(['payment_status' => 'pending']);
    }

    public function test_canceled_order_cannot_receive_new_stock_reservation(): void
    {
        $order = $this->prepare();
        $this->cancel($order);

        $this->expectException(DomainException::class);

        app(OrderInventoryService::class)->reserveLine(
            $order['order_key'],
            $this->warehouseId,
            $this->variantId,
            1,
            'CANCEL-LATE-RESERVE',
            (string) Str::uuid(),
            CarbonImmutable::now('UTC')->addHour()
        );
    }

    public function test_order_without_checkout_preparation_cannot_be_canceled(): void
    {
        $key = (string) Str::uuid();

        DB::table('orders')->insert([
            'order_key' => $key,
            'payment_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(DomainException::class);

        app(CheckoutOrderCancellationService::class)->cancel(
            $key,
            (string) Str::uuid()
        );
    }

    // ========================================
    // ДОПОМІЖНІ МЕТОДИ
    // ========================================

    /**
     * @return array<string,mixed>
     */
    private function prepare(
        ?int $userId = null,
        ?int $bonusUnits = null,
        bool $withPromotion = false,
        ?CarbonImmutable $expiresAt = null,
    ): array {
        return app(CheckoutOrderPreparationService::class)->prepare(
            (string) Str::uuid(),
            [
                [
                    'variant_id' => $this->variantId,
                    'quantity' => 2,
                ],
            ],
            'UA',
            'UA_RETAIL',
            $expiresAt ?? CarbonImmutable::now('UTC')->addMinutes(30),
            $userId,
            $bonusUnits,
            $withPromotion ? $this->promotionCode : null
        );
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function cancel(
        array $order,
        ?string $operationKey = null,
        string $reason = 'customer_cancel',
    ): array {
        return app(CheckoutOrderCancellationService::class)->cancel(
            $order['order_key'],
            $operationKey ?? (string) Str::uuid(),
            $reason
        );
    }

    private function fund(User $user, int $units): int
    {
        $accountId = (int) DB::table('loyalty_accounts')
            ->insertGetId([
                'user_id' => $user->id,
                'identity_state' => 'verified',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $walletId = (int) DB::table('loyalty_wallets')
            ->insertGetId([
                'loyalty_account_id' => $accountId,
                'market_id' => DB::table('markets')
                    ->where('code', 'UA')
                    ->value('id'),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $service = app(LoyaltyTransactionService::class);

        $grant = $service->grantPending(
            $walletId,
            'cancel-test-grant',
            [
                'grant_key' => 'cancel-test-grant',
                'grant_type' => 'purchase',
                'source_type' => 'test_order',
                'source_ref' => 'cancel-test-order',
                'program_code' => 'TEST',
                'rule_version' => 'v1',
                'units_total' => $units,
                'activation_requirements' => [],
                'rule_snapshot' => ['repay_bonus_debt' => true],
            ]
        );

        $service->activateGrant(
            $walletId,
            (int) $grant['grant_id'],
            $units,
            'cancel-test-activation'
        );

        return $walletId;
    }

    private function policyAllowVariant(): void
    {
        $base = CarbonImmutable::now('UTC');

        $policyId = (int) DB::table(
            'loyalty_redemption_policies'
        )->insertGetId([
            'market_id' => DB::table('markets')
                ->where('code', 'UA')
                ->value('id'),
            'currency_code' => 'UAH',
            'program_code' => 'TEST',
            'rule_version' => 'v1',
            'is_enabled' => true,
            'bonus_units_per_step' => 3,
            'money_minor_per_step' => 2,
            'max_goods_share_bps' => 2500,
            'max_discount_minor' => 500,
            'minimum_cash_minor' => 0,
            'effective_from' => $base->addHour(),
        ]);

        DB::table('loyalty_redemption_eligibility_rules')->insert([
            'policy_id' => $policyId,
            'scope_type' => 'variant',
            'variant_id' => $this->variantId,
            'decision' => 'allow',
            'priority' => 10,
        ]);

        CarbonImmutable::setTestNow(
            $base->addHours(2)
        );
    }
}
