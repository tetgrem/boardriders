<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Тільки PostgreSQL boardriders_test.
 * Інтеграція pending-order + coupon + stock + bonus + immutable snapshot.
 */
final class CheckoutOrderPromotionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;
    private int $warehouseId;
    private int $promotionId;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $this->code = 'ORDER-' . strtoupper(Str::random(10));

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
            'sku' => 'ORDER-PROMO-' . Str::uuid(),
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

        $this->warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'ORDER-PROMO-WH-' . Str::uuid(),
            'name' => 'Promotion checkout warehouse',
            'country_code' => 'UA',
            'kind' => 'owned',
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

        $this->promotionId = (int) DB::table('promotions')->insertGetId([
            'price_list_id' => $listId,
            'name' => 'Checkout integration promo',
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
            'code' => $this->code,
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

    public function test_promotion_checkout_reserves_quota_and_stock_and_snapshots_discount(): void
    {
        $saved = $this->prepare((string) Str::uuid());

        $this->assertSame('pending', $saved['payment_status']);
        $this->assertSame('reserved', $saved['promotion_reservation']['status']);
        $this->assertSame($this->code, $saved['promotion_reservation']['promotion_code']);
        $this->assertSame(200, $saved['promotion_reservation']['discount_minor']);
        $this->assertSame(1800, $saved['financial_snapshot']['goods_payable_minor']);

        $this->assertSame(
            $this->code,
            $saved['financial_snapshot']['snapshot']['promotion_code']
        );

        $this->assertSame(
            200,
            $saved['financial_snapshot']['snapshot']['promotion_discount_minor']
        );

        $this->assertSame(0, $saved['bonus_units_reserved']);

        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId,
            'reserved_qty' => 2,
        ]);

        $this->assertDatabaseCount('promotion_reservations', 1);
        $this->assertDatabaseCount('promotion_redemptions', 0);
        $this->assertDatabaseCount('order_financial_snapshots', 1);
    }

    public function test_retry_of_same_order_is_idempotent_with_promotion(): void
    {
        $key = (string) Str::uuid();
        $expires = CarbonImmutable::now('UTC')->addMinutes(30);

        $first = $this->prepare($key, null, null, $this->code, $expires);
        $second = $this->prepare($key, null, null, $this->code, $expires);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('promotion_reservations', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('order_financial_snapshots', 1);
    }

    public function test_same_order_key_cannot_switch_promotion_codes(): void
    {
        $secondCode = 'SECOND-' . strtoupper(Str::random(8));

        DB::table('promotion_codes')->insert([
            'promotion_id' => $this->promotionId,
            'code' => $secondCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $key = (string) Str::uuid();
        $expires = CarbonImmutable::now('UTC')->addMinutes(30);

        $this->prepare($key, null, null, $this->code, $expires);

        try {
            $this->prepare($key, null, null, $secondCode, $expires);
            $this->fail('Changing coupon on existing order must fail');
        } catch (DomainException $exception) {
            $this->assertStringContainsString(
                'different',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('promotion_reservations', 1);
    }

    public function test_last_promotion_quota_cannot_be_reserved_twice(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['usage_limit' => 1]);

        $first = $this->prepare((string) Str::uuid());

        try {
            $this->prepare((string) Str::uuid());
            $this->fail('Promotion quota should be exhausted');
        } catch (DomainException) {
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('promotion_reservations', 1);
            $this->assertDatabaseCount('inventory_reservations', 1);

            $this->assertSame(
                'reserved',
                $first['promotion_reservation']['status']
            );

            $this->assertDatabaseHas('inventory_stocks', [
                'variant_id' => $this->variantId,
                'reserved_qty' => 2,
            ]);
        }
    }

    public function test_promotion_and_exact_bonus_units_share_same_financial_snapshot(): void
    {
        $user = User::factory()->create();

        $walletId = $this->fund($user, 500);
        $this->policyAllowVariant();

        $saved = $this->prepare(
            (string) Str::uuid(),
            $user->id,
            120
        );

        $this->assertSame(
            200,
            $saved['promotion_reservation']['discount_minor']
        );

        $this->assertSame(120, $saved['bonus_units_reserved']);
        $this->assertSame($walletId, $saved['loyalty_wallet_id']);
        $this->assertNotNull($saved['loyalty_reservation_id']);

        $this->assertSame(
            1800,
            $saved['financial_snapshot']['snapshot']['eligible_goods_after_promotions_minor']
        );

        $this->assertSame(
            80,
            $saved['financial_snapshot']['snapshot']['loyalty_discount_minor']
        );

        $this->assertSame(
            1720,
            $saved['financial_snapshot']['goods_payable_minor']
        );

        $this->assertDatabaseCount('loyalty_reservations', 1);
        $this->assertDatabaseCount('promotion_reservations', 1);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    }

    public function test_failed_bonus_request_does_not_consume_promotion_or_stock(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 30);
        $this->policyAllowVariant();

        try {
            $this->prepare(
                (string) Str::uuid(),
                $user->id,
                120
            );

            $this->fail('Unavailable bonus units should abort checkout');
        } catch (DomainException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('promotion_reservations', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
            $this->assertDatabaseCount('loyalty_reservations', 0);
        }
    }

    public function test_stock_rejection_after_promo_reservation_rolls_back_both(): void
    {
        // Загалом 2 шт., але на жодному окремому складі немає 2.
        DB::table('inventory_stocks')
            ->where('variant_id', $this->variantId)
            ->update(['on_hand_qty' => 1]);

        $secondWarehouse = (int) DB::table('warehouses')->insertGetId([
            'code' => 'ORDER-SECOND-WH-' . Str::uuid(),
            'name' => 'Second warehouse',
            'country_code' => 'UA',
            'kind' => 'owned',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $secondWarehouse,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 1,
            'reserved_qty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->prepare((string) Str::uuid());
            $this->fail('Single warehouse cannot satisfy quantity');
        } catch (DomainException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('promotion_reservations', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
            $this->assertDatabaseCount('order_financial_snapshots', 0);

            $this->assertSame(
                0,
                (int) DB::table('inventory_stocks')->sum('reserved_qty')
            );
        }
    }

    public function test_invalid_promotion_is_not_silently_ignored(): void
    {
        try {
            $this->prepare(
                (string) Str::uuid(),
                null,
                null,
                'NOT-FOUND'
            );

            $this->fail('Invalid promotion should abort order');
        } catch (DomainException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('promotion_reservations', 0);
            $this->assertDatabaseCount('order_financial_snapshots', 0);
        }
    }

    public function test_promotion_per_customer_limit_requires_authenticated_user(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['per_customer_limit' => 1]);

        $this->expectException(DomainException::class);

        $this->prepare((string) Str::uuid());
    }

    public function test_verified_user_with_no_bonus_request_is_not_charged_bonuses(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 500);
        $this->policyAllowVariant();

        $saved = $this->prepare(
            (string) Str::uuid(),
            $user->id,
            null
        );

        $this->assertSame(0, $saved['bonus_units_reserved']);

        $this->assertSame(
            0,
            $saved['financial_snapshot']['snapshot']['loyalty_discount_minor']
        );

        $this->assertSame(
            1800,
            $saved['financial_snapshot']['goods_payable_minor']
        );

        $this->assertDatabaseCount('loyalty_reservations', 0);
    }

    private function prepare(
        string $key,
        ?int $userId = null,
        ?int $bonusUnits = null,
        ?string $code = null,
        ?CarbonImmutable $expires = null,
    ): array {
        return app(CheckoutOrderPreparationService::class)->prepare(
            $key,
            [['variant_id' => $this->variantId, 'quantity' => 2]],
            'UA',
            'UA_RETAIL',
            $expires ?? CarbonImmutable::now('UTC')->addMinutes(30),
            $userId,
            $bonusUnits,
            $code ?? $this->code
        );
    }

    private function fund(User $user, int $units): int
    {
        $account = (int) DB::table('loyalty_accounts')->insertGetId([
            'user_id' => $user->id,
            'identity_state' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wallet = (int) DB::table('loyalty_wallets')->insertGetId([
            'loyalty_account_id' => $account,
            'market_id' => DB::table('markets')
                ->where('code', 'UA')->value('id'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(LoyaltyTransactionService::class);

        $grant = $service->grantPending(
            $wallet,
            'promotion-checkout-grant',
            [
                'grant_key' => 'promotion-checkout-grant',
                'grant_type' => 'purchase',
                'source_type' => 'test_order',
                'source_ref' => 'promotion-checkout-test',
                'program_code' => 'TEST',
                'rule_version' => 'v1',
                'units_total' => $units,
                'activation_requirements' => [],
                'rule_snapshot' => ['repay_bonus_debt' => true],
            ]
        );

        $service->activateGrant(
            $wallet,
            (int) $grant['grant_id'],
            $units,
            'promotion-checkout-activate'
        );

        return $wallet;
    }

    private function policyAllowVariant(): void
    {
        $base = CarbonImmutable::now('UTC');

        $policy = (int) DB::table('loyalty_redemption_policies')
            ->insertGetId([
                'market_id' => DB::table('markets')
                    ->where('code', 'UA')->value('id'),
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
            'policy_id' => $policy,
            'scope_type' => 'variant',
            'variant_id' => $this->variantId,
            'decision' => 'allow',
            'priority' => 10,
        ]);

        CarbonImmutable::setTestNow($base->addHours(2));
    }
}
