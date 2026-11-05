<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CheckoutOrderPreparationTest extends TestCase
{
    use RefreshDatabase;

    private int $variantId;
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
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
            'product_id' => $productId, 'sku' => 'PREP-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $listId,
            'price_minor' => 1000,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'PREP-WH-' . Str::uuid(), 'name' => 'Checkout test warehouse',
            'country_code' => 'UA', 'kind' => 'owned', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $this->warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 10, 'reserved_qty' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_guest_order_reserves_stock_and_stores_financial_snapshot(): void
    {
        $saved = $this->prepare((string) Str::uuid());
        $this->assertSame('pending', $saved['payment_status']);
        $this->assertSame(2000, $saved['financial_snapshot']['goods_payable_minor']);
        $this->assertSame(0, $saved['bonus_units_reserved']);
        $this->assertCount(1, $saved['stock_reservations']);
        $this->assertSame($this->variantId, $saved['stock_reservations'][0]['variant_id']);
        $this->assertSame(2, $saved['stock_reservations'][0]['quantity']);
        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId, 'reserved_qty' => 2,
        ]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_financial_snapshots', 1);
        $this->assertDatabaseCount('checkout_order_preparations', 1);
    }

    public function test_same_order_key_and_request_are_idempotent(): void
    {
        $key = (string) Str::uuid();
        $expiry = CarbonImmutable::now('UTC')->addMinutes(30);
        $first = $this->prepare($key, null, null, $expiry);
        $second = $this->prepare($key, null, null, $expiry);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('order_financial_snapshots', 1);
        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId, 'reserved_qty' => 2,
        ]);
    }

    public function test_same_order_key_with_different_quantity_is_rejected(): void
    {
        $key = (string) Str::uuid();
        $expiry = CarbonImmutable::now('UTC')->addMinutes(30);
        $this->prepare($key, null, null, $expiry);
        try {
            $this->prepare($key, null, null, $expiry, 3);
            $this->fail('Changed checkout request must be rejected');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different', $exception->getMessage());
        }
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_insufficient_stock_rolls_back_new_order(): void
    {
        $key = (string) Str::uuid();
        try {
            $this->prepare($key, null, null, null, 11);
            $this->fail('Oversold order must fail');
        } catch (DomainException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
            $this->assertDatabaseCount('order_financial_snapshots', 0);
            $this->assertDatabaseHas('inventory_stocks', [
                'variant_id' => $this->variantId, 'reserved_qty' => 0,
            ]);
        }
    }

    public function test_missing_promotion_code_is_rejected_during_checkout(): void
    {
        $this->expectException(DomainException::class);
        app(CheckoutOrderPreparationService::class)->prepare(
            (string) Str::uuid(), $this->items(), 'UA', 'UA_RETAIL',
            CarbonImmutable::now('UTC')->addMinutes(30),
            null, null, 'PROMO10'
        );
    }

    public function test_guest_cannot_request_bonuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->prepare((string) Str::uuid(), null, 120);
    }

    public function test_verified_customer_reserves_exact_bonuses_and_stock_atomically(): void
    {
        $user = User::factory()->create();
        $walletId = $this->fund($user, 500);
        $this->policyAllowVariant();

        $saved = $this->prepare((string) Str::uuid(), $user->id, 120);
        $this->assertSame(120, $saved['bonus_units_reserved']);
        $this->assertSame($walletId, $saved['loyalty_wallet_id']);
        $this->assertNotNull($saved['loyalty_reservation_id']);
        $this->assertSame(80, $saved['financial_snapshot']['snapshot']['loyalty_discount_minor']);
        $this->assertSame(1920, $saved['financial_snapshot']['goods_payable_minor']);
        $this->assertDatabaseHas('loyalty_reservations', [
            'id' => $saved['loyalty_reservation_id'], 'wallet_id' => $walletId,
            'units_total' => 120,
        ]);
        $this->assertSame(120, (int) DB::table('loyalty_ledger_entries')
            ->where('wallet_id', $walletId)->sum('reserved_delta'));
        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId, 'reserved_qty' => 2,
        ]);
    }

    public function test_bonus_request_exceeding_available_amount_rolls_back_order(): void
    {
        $user = User::factory()->create();
        $this->fund($user, 30);
        $this->policyAllowVariant();
        try {
            $this->prepare((string) Str::uuid(), $user->id, 120);
            $this->fail('Unavailable bonus redemption must fail');
        } catch (DomainException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
            $this->assertDatabaseCount('loyalty_reservations', 0);
            $this->assertDatabaseHas('inventory_stocks', [
                'variant_id' => $this->variantId, 'reserved_qty' => 0,
            ]);
        }
    }

    public function test_mismatched_market_and_price_list_roll_back(): void
    {
        DB::table('markets')->insert([
            'code' => 'PL', 'name' => 'Poland', 'country_code' => 'PL',
            'currency_code' => 'UAH', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            app(CheckoutOrderPreparationService::class)->prepare(
                (string) Str::uuid(), $this->items(), 'PL', 'UA_RETAIL',
                CarbonImmutable::now('UTC')->addMinutes(30)
            );
            $this->fail('Cross-market checkout must fail');
        } catch (DomainException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
        }
    }

    public function test_preparation_metadata_cannot_be_changed_in_database(): void
    {
        $saved = $this->prepare((string) Str::uuid());
        $this->expectException(QueryException::class);
        DB::table('checkout_order_preparations')
            ->where('order_id', $saved['order_id'])
            ->update(['bonus_units_reserved' => 1]);
    }

    /** @return array<int,array{variant_id:int,quantity:int}> */
    private function items(int $quantity = 2): array
    {
        return [['variant_id' => $this->variantId, 'quantity' => $quantity]];
    }

    private function prepare(
        string $key,
        ?int $userId = null,
        ?int $bonusUnits = null,
        ?CarbonImmutable $expiry = null,
        int $quantity = 2,
    ): array {
        return app(CheckoutOrderPreparationService::class)->prepare(
            $key, $this->items($quantity), 'UA', 'UA_RETAIL',
            $expiry ?? CarbonImmutable::now('UTC')->addMinutes(30),
            $userId, $bonusUnits
        );
    }

    private function fund(User $user, int $units): int
    {
        $accountId = (int) DB::table('loyalty_accounts')->insertGetId([
            'user_id' => $user->id, 'identity_state' => 'verified',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $walletId = (int) DB::table('loyalty_wallets')->insertGetId([
            'loyalty_account_id' => $accountId,
            'market_id' => DB::table('markets')->where('code', 'UA')->value('id'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(LoyaltyTransactionService::class);
        $grant = $service->grantPending($walletId, 'checkout-test-grant', [
            'grant_key' => 'checkout-test-grant', 'grant_type' => 'purchase',
            'source_type' => 'test_order', 'source_ref' => 'checkout-test',
            'program_code' => 'TEST', 'rule_version' => 'v1',
            'units_total' => $units, 'activation_requirements' => [],
            'rule_snapshot' => ['repay_bonus_debt' => true],
        ]);
        $service->activateGrant(
            $walletId, (int) $grant['grant_id'], $units, 'checkout-test-activate'
        );
        return $walletId;
    }

    private function policyAllowVariant(): void
    {
        $base = CarbonImmutable::now('UTC');
        $id = (int) DB::table('loyalty_redemption_policies')->insertGetId([
            'market_id' => DB::table('markets')->where('code', 'UA')->value('id'),
            'currency_code' => 'UAH', 'program_code' => 'TEST',
            'rule_version' => 'v1', 'is_enabled' => true,
            'bonus_units_per_step' => 3, 'money_minor_per_step' => 2,
            'max_goods_share_bps' => 2500,
            'max_discount_minor' => 500,
            'minimum_cash_minor' => 0,
            'effective_from' => $base->addHour(),
        ]);
        DB::table('loyalty_redemption_eligibility_rules')->insert([
            'policy_id' => $id, 'scope_type' => 'variant',
            'variant_id' => $this->variantId,
            'decision' => 'allow', 'priority' => 10,
        ]);
        CarbonImmutable::setTestNow($base->addHours(2));
    }
}
