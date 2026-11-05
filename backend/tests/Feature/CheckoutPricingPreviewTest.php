<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Pricing\CheckoutPricingPreviewService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BOARDRIDERS — Checkout Pricing Preview.
 *
 * Інтеграційні перевірки:
 * - актуальних цін та залишків;
 * - промокодів;
 * - бонусних списань;
 * - серверного eligibility;
 * - ізоляції ринків;
 * - відсутності side effects.
 *
 * Тільки PostgreSQL boardriders_test!
 */
final class CheckoutPricingPreviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,int> */
    private array $ids = [];

    private int $priceListId;

    // ========================================
    // 1. ПІДГОТОВКА ТЕСТОВИХ ДАНИХ
    // ========================================

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();

        // ----------------------------------------
        // Валюта
        // ----------------------------------------

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Ukrainian hryvnia',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ----------------------------------------
        // Ринок
        // ----------------------------------------

        DB::table('markets')->insertOrIgnore([
            'code' => 'UA',
            'name' => 'Ukraine',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ----------------------------------------
        // Прайс-лист
        // ----------------------------------------

        DB::table('price_lists')->insertOrIgnore([
            'code' => 'UA_RETAIL',
            'name' => 'UA retail test',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'type' => 'retail',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->priceListId = (int) DB::table(
            'price_lists'
        )
            ->where('code', 'UA_RETAIL')
            ->value('id');

        // ----------------------------------------
        // Тестовий склад
        // ----------------------------------------

        $warehouse = (int) DB::table(
            'warehouses'
        )->insertGetId([
            'code' => 'QUOTE-WH-' . Str::random(12),
            'name' => 'Quote test warehouse',
            'kind' => 'owned',
            'country_code' => 'UA',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ----------------------------------------
        // Два тестових SKU
        // ----------------------------------------

        foreach ([1000, 3000] as $price) {

            $product = (int) DB::table(
                'products'
            )->insertGetId([
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $variant = (int) DB::table(
                'product_variants'
            )->insertGetId([
                'product_id' => $product,
                'sku' => 'QUOTE-' . Str::uuid(),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('variant_prices')->insert([
                'variant_id' => $variant,
                'price_list_id' => $this->priceListId,
                'price_minor' => $price,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('inventory_stocks')->insert([
                'warehouse_id' => $warehouse,
                'variant_id' => $variant,
                'on_hand_qty' => 10,
                'reserved_qty' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->ids[] = $variant;
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ========================================
    // 2. КОШИК БЕЗ ЗНИЖОК
    // ========================================

    public function test_guest_gets_verified_prices_without_discounts(): void
    {
        $quote = $this->preview();

        $this->assertSame(
            4000,
            $quote['subtotal_minor']
        );

        $this->assertSame(
            4000,
            $quote['goods_payable_minor']
        );

        $this->assertSame(
            0,
            $quote['loyalty_discount_minor']
        );

        $this->assertSame(
            'login_required',
            $quote['loyalty']['reason']
        );

        $this->assertTrue(
            $quote['informational_only']
        );
    }

    // ========================================
    // 3. ПРОМОКОД БЕЗ БОНУСІВ
    // ========================================

    public function test_promotion_is_distributed_over_actual_cart_prices(): void
    {
        $this->promotion('PROMO10');

        $quote = $this->preview('PROMO10');

        $this->assertSame(
            400,
            $quote['promotion_discount_minor']
        );

        $this->assertSame(
            3600,
            $quote['goods_payable_minor']
        );

        $this->assertSame(
            [100, 300],
            array_column(
                $quote['lines'],
                'promotion_discount_minor'
            )
        );

        // Preview не витрачає промокод.
        $this->assertSame(
            0,
            DB::table('promotion_redemptions')->count()
        );
    }

    // ========================================
    // 4. НЕІСНУЮЧИЙ ПРОМОКОД
    // ========================================

    public function test_unknown_promotion_cannot_change_amount(): void
    {
        $quote = $this->preview('NOT-FOUND');

        $this->assertFalse(
            $quote['promotion']['valid']
        );

        $this->assertSame(
            'code_unavailable',
            $quote['promotion']['reason']
        );

        $this->assertSame(
            0,
            $quote['promotion_discount_minor']
        );

        $this->assertSame(
            4000,
            $quote['goods_payable_minor']
        );
    }

    // ========================================
    // 5. ПРОМОКОД + БОНУСИ
    // ========================================

    public function test_promotion_then_loyalty_are_both_allocated_without_side_effects(): void
    {
        $user = User::factory()->create();

        // Створюємо підтверджений бонусний гаманець.
        $this->fund($user, 500);

        // Обидва SKU явно дозволені серверними
        // правилами бонусної програми.
        $this->policy($this->ids);

        $this->promotion('PROMO10');

        $before = DB::table(
            'loyalty_ledger_entries'
        )->count();

        $quote = $this->preview(
            'PROMO10',
            $user->id,
            120
        );

        // ----------------------------------------
        // Перевірка промознижки
        // ----------------------------------------

        $this->assertSame(
            400,
            $quote['promotion_discount_minor']
        );

        $this->assertSame(
            3600,
            $quote[
            'eligible_goods_after_promotions_minor'
            ]
        );

        // ----------------------------------------
        // Перевірка бонусів
        // ----------------------------------------

        $this->assertSame(
            80,
            $quote['loyalty_discount_minor']
        );

        $this->assertSame(
            3520,
            $quote['goods_payable_minor']
        );

        // ----------------------------------------
        // Розподіл промознижки
        // ----------------------------------------

        $this->assertSame(
            [100, 300],
            array_column(
                $quote['lines'],
                'promotion_discount_minor'
            )
        );

        // ----------------------------------------
        // Розподіл бонусів
        // ----------------------------------------

        $this->assertSame(
            [20, 60],
            array_column(
                $quote['lines'],
                'loyalty_discount_minor'
            )
        );

        // ----------------------------------------
        // Серверний eligibility
        // ----------------------------------------

        $this->assertSame(
            $this->ids,
            $quote[
            'loyalty_eligibility'
            ]['approved_variant_ids']
        );

        // ----------------------------------------
        // Відсутність side effects
        // ----------------------------------------

        $this->assertSame(
            $before,
            DB::table('loyalty_ledger_entries')->count()
        );

        $this->assertSame(
            0,
            DB::table('promotion_redemptions')->count()
        );
    }

    // ========================================
    // 6. НЕМАЄ ДОЗВОЛУ НА БОНУСИ
    // ========================================

    public function test_bonuses_are_blocked_without_approved_product_eligibility(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 500);

        // Створюємо політику, але не додаємо
        // жодного дозволяючого правила.
        $this->policy();

        $quote = $this->preview(
            null,
            $user->id,
            120
        );

        $this->assertSame(
            0,
            $quote[
            'eligible_goods_after_promotions_minor'
            ]
        );

        $this->assertSame(
            'no_eligible_goods',
            $quote['loyalty']['reason']
        );

        $this->assertSame(
            [],
            $quote[
            'loyalty_eligibility'
            ]['approved_variant_ids']
        );

        $this->assertSame(
            0,
            $quote['loyalty_discount_minor']
        );

        $this->assertSame(
            4000,
            $quote['goods_payable_minor']
        );
    }

    // ========================================
    // 7. ГОСТЮ БОНУСИ НЕДОСТУПНІ
    // ========================================

    public function test_guest_cannot_request_bonus_redemption(): void
    {
        $quote = $this->preview(
            null,
            null,
            120
        );

        $this->assertSame(
            'login_required',
            $quote['loyalty']['reason']
        );

        $this->assertSame(
            0,
            $quote['loyalty_discount_minor']
        );

        $this->assertNull(
            $quote['loyalty_eligibility']
        );
    }

    // ========================================
    // 8. НЕДОСТАТНЬО ТОВАРУ
    // ========================================

    public function test_insufficient_stock_is_rejected_even_without_promo(): void
    {
        $this->expectException(
            DomainException::class
        );

        $items = $this->items();

        $items[0]['quantity'] = 11;

        app(
            CheckoutPricingPreviewService::class
        )->preview(
            $items,
            'UA',
            'UA_RETAIL'
        );
    }

    // ========================================
    // 9. ІЗОЛЯЦІЯ РИНКІВ
    // ========================================

    public function test_rejects_price_list_from_other_market_country(): void
    {
        DB::table('markets')->insert([
            'code' => 'PL',
            'name' => 'Poland',
            'country_code' => 'PL',
            'currency_code' => 'UAH',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(
            DomainException::class
        );

        app(
            CheckoutPricingPreviewService::class
        )->preview(
            $this->items(),
            'PL',
            'UA_RETAIL'
        );
    }

    // ========================================
    // 10. ТІЛЬКИ ДОЗВОЛЕНІ SKU
    // ========================================

    public function test_only_server_allowed_variant_receives_loyalty_discount(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 500);

        // Дозволяємо витрачати бонуси
        // тільки на перший SKU.
        $this->policy([
            $this->ids[0],
        ]);

        $quote = $this->preview(
            null,
            $user->id,
            120
        );

        // Перший товар: 1000 копійок.
        // Другий товар: 3000 копійок.
        //
        // До бонусів допущено лише перший.

        $this->assertSame(
            1000,
            $quote[
            'eligible_goods_after_promotions_minor'
            ]
        );

        // 120 бонусів за тестовим курсом
        // відповідають знижці 80 копійок.

        $this->assertSame(
            80,
            $quote['loyalty_discount_minor']
        );

        // Другий SKU не отримує бонусної знижки.

        $this->assertSame(
            [80, 0],
            array_column(
                $quote['lines'],
                'loyalty_discount_minor'
            )
        );

        $this->assertSame(
            3920,
            $quote['goods_payable_minor']
        );

        $this->assertSame(
            [$this->ids[0]],
            $quote[
            'loyalty_eligibility'
            ]['approved_variant_ids']
        );
    }

    // ========================================
    // 11. ЗАМОРОЖЕНИЙ ГАМАНЕЦЬ
    // ========================================

    public function test_frozen_wallet_blocks_discount_even_for_allowed_products(): void
    {
        $user = User::factory()->create();

        $this->fund($user, 500);

        // Обидва товари дозволені правилами.
        $this->policy($this->ids);

        $accountId = DB::table(
            'loyalty_accounts'
        )
            ->where('user_id', $user->id)
            ->value('id');

        DB::table('loyalty_wallets')
            ->where(
                'loyalty_account_id',
                $accountId
            )
            ->update([
                'status' => 'frozen',
            ]);

        $quote = $this->preview(
            null,
            $user->id,
            120
        );

        // Товари формально дозволені.
        $this->assertSame(
            4000,
            $quote[
            'eligible_goods_after_promotions_minor'
            ]
        );

        // Але заморожений гаманець
        // блокує фактичне списання.
        $this->assertSame(
            'wallet_not_active',
            $quote['loyalty']['reason']
        );

        $this->assertSame(
            0,
            $quote['loyalty_discount_minor']
        );

        $this->assertSame(
            4000,
            $quote['goods_payable_minor']
        );
    }

    // ========================================
    // 12. ДОПОМІЖНІ МЕТОДИ
    // ========================================

    /**
     * @return array<int,array{variant_id:int,quantity:int}>
     */
    private function items(): array
    {
        return array_map(
            static fn (int $id): array => [
                'variant_id' => $id,
                'quantity' => 1,
            ],
            $this->ids
        );
    }

    private function preview(
        ?string $promotion = null,
        ?int $userId = null,
        ?int $bonusUnits = null
    ): array {
        return app(
            CheckoutPricingPreviewService::class
        )->preview(
            $this->items(),
            'UA',
            'UA_RETAIL',
            $promotion,
            $userId,
            $bonusUnits
        );
    }

    // ========================================
    // 13. ТЕСТОВИЙ ПРОМОКОД
    // ========================================

    private function promotion(string $code): void
    {
        $promotionId = (int) DB::table(
            'promotions'
        )->insertGetId([
            'price_list_id' => $this->priceListId,
            'name' => 'Preview promotion',
            'type' => 'percent',
            'value' => 1000,
            'min_subtotal_minor' => 0,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('promotion_codes')->insert([
            'promotion_id' => $promotionId,
            'code' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ========================================
    // 14. ТЕСТОВИЙ БОНУСНИЙ ГАМАНЕЦЬ
    // ========================================

    private function fund(
        User $user,
        int $units
    ): void {
        $accountId = (int) DB::table(
            'loyalty_accounts'
        )->insertGetId([
            'user_id' => $user->id,
            'identity_state' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $walletId = (int) DB::table(
            'loyalty_wallets'
        )->insertGetId([
            'loyalty_account_id' => $accountId,

            'market_id' => DB::table('markets')
                ->where('code', 'UA')
                ->value('id'),

            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(
            LoyaltyTransactionService::class
        );

        $grant = $service->grantPending(
            $walletId,
            'quote-grant-command',
            [
                'grant_key' => 'quote-grant',
                'grant_type' => 'purchase',
                'source_type' => 'test_order',
                'source_ref' => 'quote-order',

                'program_code' => 'TEST_PROGRAM',
                'rule_version' => 'v1',

                'units_total' => $units,

                'activation_requirements' => [],

                'rule_snapshot' => [
                    'repay_bonus_debt' => true,
                ],
            ]
        );

        $service->activateGrant(
            $walletId,
            (int) $grant['grant_id'],
            $units,
            'quote-activation'
        );
    }

    // ========================================
    // 15. ТЕСТОВА ПОЛІТИКА БОНУСІВ
    // ========================================

    /**
     * @param array<int,int> $allowedVariantIds
     */
    private function policy(
        array $allowedVariantIds = []
    ): void {
        $baseTime = CarbonImmutable::now('UTC');

        // Спочатку створюємо майбутню
        // версію політики.
        $policyId = (int) DB::table(
            'loyalty_redemption_policies'
        )->insertGetId([
            'market_id' => DB::table('markets')
                ->where('code', 'UA')
                ->value('id'),

            'currency_code' => 'UAH',
            'program_code' => 'TEST_PROGRAM',

            'rule_version' => 'v1',
            'is_enabled' => true,

            // ТІЛЬКИ тестові значення.
            // Це не production-налаштування.
            'bonus_units_per_step' => 3,
            'money_minor_per_step' => 2,
            'max_goods_share_bps' => 2500,
            'max_discount_minor' => 500,
            'minimum_cash_minor' => 0,

            // PostgreSQL дозволяє додавати
            // правила тільки до початку дії політики.
            'effective_from' =>
                $baseTime->addHour(),
        ]);

        // ----------------------------------------
        // Створення дозволяючих правил
        // ----------------------------------------

        foreach ($allowedVariantIds as $variantId) {
            DB::table(
                'loyalty_redemption_eligibility_rules'
            )->insert([
                'policy_id' => $policyId,

                'scope_type' => 'variant',
                'decision' => 'allow',
                'priority' => 10,

                'variant_id' => $variantId,
            ]);
        }

        // ----------------------------------------
        // Активація політики в PHP-тестах
        // ----------------------------------------

        // Пересуваємо лише PHP-годинник.
        // Реальний час PostgreSQL не змінюється.
        //
        // Завдяки цьому правила створюються
        // до початку дії політики, а наступний
        // preview уже бачить її активною.

        CarbonImmutable::setTestNow(
            $baseTime->addHours(2)
        );
    }
}
