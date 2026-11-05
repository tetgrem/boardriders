<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OrderInventoryService;
use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Pricing\CheckoutOrderCancellationService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPaymentCaptureReceiptService;
use App\Services\Pricing\CheckoutPaymentAttemptService;
use App\Services\Pricing\CheckoutPaymentFinalizationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** No provider API: all receipts in these tests stand for verified provider events. */
final class CheckoutPaymentFinalizationTest extends TestCase
{
    use DatabaseTransactions;

    private int $variantId;
    private int $warehouseId;
    private string $promotionCode;

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
        $this->promotionCode = 'PAID-' . strtoupper(Str::random(10));

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
            'code' => 'UA_RETAIL', 'name' => 'UA retail test',
            'country_code' => 'UA', 'currency_code' => 'UAH',
            'type' => 'retail', 'is_default' => true, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'sku' => 'PAY-FINAL-' . Str::uuid(),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId, 'price_list_id' => $listId,
            'price_minor' => 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->warehouseId = (int) DB::table('warehouses')->insertGetId([
            'code' => 'PAY-FINAL-WH-' . Str::uuid(),
            'name' => 'Payment finalization warehouse', 'kind' => 'owned',
            'country_code' => 'UA', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $this->warehouseId, 'variant_id' => $this->variantId,
            'on_hand_qty' => 10, 'reserved_qty' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $promotionId = (int) DB::table('promotions')->insertGetId([
            'price_list_id' => $listId, 'name' => 'Payment finalization promo',
            'type' => 'percent', 'value' => 1000, 'min_subtotal_minor' => 0,
            'starts_at' => $now->copy()->subDay(),
            'ends_at' => $now->copy()->addDay(), 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('promotion_codes')->insert([
            'promotion_id' => $promotionId, 'code' => $this->promotionCode,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_guest_payment_moves_to_paid_and_keeps_stock_reserved(): void
    {
        $order = $this->prepare();
        $result = $this->finalize($this->receipt($order));

        $this->assertSame('paid', $result['decision']);
        $this->assertSame('accepted', $result['reason_code']);
        $this->assertSame('paid', $result['order_payment_status']);
        $this->assertNull($result['promotion_reservation_id']);
        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId,
            'on_hand_qty' => 10, 'reserved_qty' => 2,
        ]);
        $this->assertDatabaseCount('checkout_payment_capture_decisions', 1);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    }

    public function test_promo_and_bonus_capture_are_atomic_and_do_not_ship_stock(): void
    {
        $user = User::factory()->create();
        $walletId = $this->fund($user, 500);
        $this->policyAllowVariant();
        $order = $this->prepare($user, 120, true);
        $receipt = $this->receipt($order);

        $result = $this->finalize($receipt);

        $this->assertSame('paid', $result['decision']);
        $this->assertNotNull($result['promotion_reservation_id']);
        $this->assertNotNull($result['loyalty_reservation_id']);
        $this->assertDatabaseCount('promotion_redemptions', 1);
        $this->assertDatabaseHas('promotion_redemptions', [
            'order_id' => $order['order_id'], 'discount_minor' => 200,
        ]);
        $this->assertSame(0, (int) DB::table('loyalty_ledger_entries')
            ->where('wallet_id', $walletId)->sum('reserved_delta'));
        $this->assertSame(380, (int) DB::table('loyalty_ledger_entries')
            ->where('wallet_id', $walletId)->sum('available_delta'));
        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId, 'on_hand_qty' => 10, 'reserved_qty' => 2,
        ]);
    }

    public function test_repeated_receipt_processing_does_not_capture_twice(): void
    {
        $order = $this->prepare(withPromotion: true);
        $receipt = $this->receipt($order);

        $first = $this->finalize($receipt);
        $second = $this->finalize($receipt);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('checkout_payment_capture_decisions', 1);
        $this->assertDatabaseCount('promotion_redemptions', 1);
    }

    public function test_wrong_amount_enters_review_without_capture(): void
    {
        $order = $this->prepare(withPromotion: true);
        $result = $this->finalize($this->receipt($order, amount: 1799));

        $this->assertSame('review', $result['decision']);
        $this->assertSame('amount_mismatch', $result['reason_code']);
        $this->assertSame('review', $result['order_payment_status']);
        $this->assertDatabaseCount('promotion_redemptions', 0);
        $this->assertDatabaseHas('inventory_stocks', [
            'variant_id' => $this->variantId, 'reserved_qty' => 2,
        ]);
    }

    public function test_wrong_currency_enters_review(): void
    {
        DB::table('currencies')->insertOrIgnore([
            'code' => 'EUR', 'name' => 'Euro', 'symbol' => '€',
            'minor_units' => 2, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = $this->prepare();
        $result = $this->finalize($this->receipt($order, currency: 'EUR'));

        $this->assertSame('review', $result['decision']);
        $this->assertSame('currency_mismatch', $result['reason_code']);
    }

    public function test_late_webhook_can_capture_if_paid_in_time_and_reserves_remain_active(): void
    {
        $expiry = CarbonImmutable::now('UTC')->addMinute();
        $order = $this->prepare(withPromotion: true, expiry: $expiry);
        $paidAt = CarbonImmutable::now('UTC');

        // The webhook arrives late, but the physical reservation was NOT released.
        CarbonImmutable::setTestNow($expiry->addMinutes(2));
        $receipt = $this->receipt($order, capturedAt: $paidAt);
        $result = $this->finalize($receipt);

        $this->assertSame('paid', $result['decision']);
        $this->assertDatabaseCount('promotion_redemptions', 1);
    }

    public function test_expired_and_released_inventory_forces_review(): void
    {
        $expiry = CarbonImmutable::now('UTC')->addMinute();
        $order = $this->prepare(withPromotion: true, expiry: $expiry);
        $paidAt = CarbonImmutable::now('UTC');
        CarbonImmutable::setTestNow($expiry->addMinute());

        $released = app(InventoryService::class)->expireOrderReservation(
            $order['stock_reservations'][0]['reservation_key']
        );
        $this->assertTrue($released);

        $result = $this->finalize($this->receipt($order, capturedAt: $paidAt));
        $this->assertSame('review', $result['decision']);
        $this->assertSame('stock_mismatch', $result['reason_code']);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    }

    public function test_capture_after_checkout_expiration_is_review(): void
    {
        $expiry = CarbonImmutable::now('UTC')->addMinute();
        $order = $this->prepare(expiry: $expiry);
        CarbonImmutable::setTestNow($expiry->addMinute());

        $result = $this->finalize($this->receipt($order));
        $this->assertSame('review', $result['decision']);
        $this->assertSame('capture_outside_window', $result['reason_code']);
    }

    public function test_canceled_order_keeps_canceled_status_and_reviews_late_capture(): void
    {
        $order = $this->prepare();
        app(CheckoutOrderCancellationService::class)->cancel(
            $order['order_key'], (string) Str::uuid()
        );

        $result = $this->finalize($this->receipt($order));
        $this->assertSame('review', $result['decision']);
        $this->assertSame('order_canceled', $result['reason_code']);
        $this->assertSame('canceled', $result['order_payment_status']);
    }

    public function test_second_separate_capture_is_reviewed_without_changing_paid_order(): void
    {
        $order = $this->prepare(withPromotion: true);
        $this->assertSame('paid', $this->finalize($this->receipt($order))['decision']);

        $second = $this->finalize($this->receipt($order));
        $this->assertSame('review', $second['decision']);
        $this->assertSame('already_paid', $second['reason_code']);
        $this->assertSame('paid', $second['order_payment_status']);
        $this->assertDatabaseCount('promotion_redemptions', 1);
        $this->assertDatabaseCount('checkout_payment_capture_decisions', 2);
    }

    public function test_legacy_stock_method_cannot_bypass_prepared_checkout_payment_checks(): void
    {
        $order = $this->prepare();
        $this->expectException(DomainException::class);
        app(OrderInventoryService::class)->registerCapturedPayment($order['order_key']);
    }

    public function test_previously_released_bonuses_force_review(): void
    {
        $user = User::factory()->create();
        $walletId = $this->fund($user, 500);
        $this->policyAllowVariant();
        $order = $this->prepare($user, 120);

        app(LoyaltyTransactionService::class)->releaseReservation(
            $walletId, $order['loyalty_reservation_id'], 'test-release-before-payment'
        );

        $result = $this->finalize($this->receipt($order));
        $this->assertSame('review', $result['decision']);
        $this->assertSame('bonus_mismatch', $result['reason_code']);
        $this->assertSame(0, (int) DB::table('loyalty_ledger_entries')
            ->where('wallet_id', $walletId)->sum('reserved_delta'));
    }

    public function test_missing_receipt_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->finalize(999999);
    }

    public function test_legacy_order_without_checkout_snapshot_enters_review(): void
    {
        $key = (string) Str::uuid();
        DB::table('orders')->insert([
            'order_key' => $key, 'payment_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $receipt = app(CheckoutPaymentCaptureReceiptService::class)
            ->recordVerifiedCapture(
                'test_gateway', 'evt-' . Str::uuid(), 'cap-' . Str::uuid(),
                $key, 2000, 'UAH', CarbonImmutable::now('UTC')
            );
        $result = $this->finalize($receipt['id']);
        $this->assertSame('review', $result['decision']);
        $this->assertSame('preparation_missing', $result['reason_code']);
    }

    /** @return array<string,mixed> */
    private function prepare(
        ?User $user = null,
        ?int $bonus = null,
        bool $withPromotion = false,
        ?CarbonImmutable $expiry = null,
    ): array {
        $saved = app(CheckoutOrderPreparationService::class)->prepare(
            (string) Str::uuid(),
            [['variant_id' => $this->variantId, 'quantity' => 2]],
            'UA', 'UA_RETAIL',
            $expiry ?? CarbonImmutable::now('UTC')->addMinutes(30),
            $user?->id, $bonus,
            $withPromotion ? $this->promotionCode : null
        );

        app(CheckoutPaymentAttemptService::class)->bindPreparedOrder(
            $saved['order_key'],
            (string) Str::uuid(),
            'test_gateway',
            'pi-' . $saved['order_key'],
            CarbonImmutable::parse($saved['expires_at'])
        );
        return $saved;
    }

    /** @param array<string,mixed> $order */
    private function receipt(
        array $order,
        ?int $amount = null,
        string $currency = 'UAH',
        ?CarbonImmutable $capturedAt = null,
    ): int {
        $attempt = app(CheckoutPaymentAttemptService::class)
            ->findForOrder($order['order_id']);

        $result = app(CheckoutPaymentCaptureReceiptService::class)
            ->recordVerifiedCapture(
                'test_gateway',
                'evt-' . Str::uuid(),
                'cap-' . Str::uuid(),
                $order['order_key'],
                $amount ?? $order['financial_snapshot']['goods_payable_minor'],
                $currency,
                $capturedAt ?? CarbonImmutable::now('UTC'),
                $attempt['provider_payment_id'] ?? null
            );
        return $result['id'];
    }

    /** @return array<string,mixed> */
    private function finalize(int $receiptId): array
    {
        return app(CheckoutPaymentFinalizationService::class)->finalize($receiptId);
    }

    private function fund(User $user, int $units): int
    {
        $account = (int) DB::table('loyalty_accounts')->insertGetId([
            'user_id' => $user->id, 'identity_state' => 'verified',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $wallet = (int) DB::table('loyalty_wallets')->insertGetId([
            'loyalty_account_id' => $account,
            'market_id' => DB::table('markets')->where('code', 'UA')->value('id'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(LoyaltyTransactionService::class);
        $grant = $service->grantPending($wallet, 'payment-test-grant', [
            'grant_key' => 'payment-test-grant',
            'grant_type' => 'purchase',
            'source_type' => 'test_order',
            'source_ref' => 'payment-test',
            'program_code' => 'TEST',
            'rule_version' => 'v1',
            'units_total' => $units,
            'activation_requirements' => [],
            'rule_snapshot' => ['repay_bonus_debt' => true],
        ]);
        $service->activateGrant(
            $wallet, (int) $grant['grant_id'], $units, 'payment-test-activate'
        );
        return $wallet;
    }

    private function policyAllowVariant(): void
    {
        $base = CarbonImmutable::now('UTC');
        $policyId = (int) DB::table('loyalty_redemption_policies')->insertGetId([
            'market_id' => DB::table('markets')->where('code', 'UA')->value('id'),
            'currency_code' => 'UAH', 'program_code' => 'TEST',
            'rule_version' => 'v1', 'is_enabled' => true,
            'bonus_units_per_step' => 3,
            'money_minor_per_step' => 2,
            'max_goods_share_bps' => 2500,
            'max_discount_minor' => 500,
            'minimum_cash_minor' => 0,
            'effective_from' => $base->addHour(),
        ]);
        DB::table('loyalty_redemption_eligibility_rules')->insert([
            'policy_id' => $policyId,
            'scope_type' => 'variant', 'variant_id' => $this->variantId,
            'decision' => 'allow', 'priority' => 10,
        ]);
        CarbonImmutable::setTestNow($base->addHours(2));
    }
}
