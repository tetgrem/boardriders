<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Pricing\CheckoutOrderPreparationService;
use App\Services\Pricing\CheckoutPricingPreviewService;
use App\Services\Pricing\PriceService;
use App\Services\Promotions\PromotionService;
use Carbon\CarbonImmutable;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PricingFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Campaign731Test extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/admin/pricing73/campaigns';

    private int $listId;
    private int $warehouseId;
    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new \RuntimeException(
                'Requires PostgreSQL boardriders_test'
            );
        }

        config()->set('crm65.enabled', true);
        config()->set('pricing73.enabled', true);

        $this->seed(LanguageSeeder::class);
        $this->seed(PricingFoundationSeeder::class);

        $this->listId = (int) DB::table('price_lists')
            ->where('code', 'UA_RETAIL')
            ->value('id');

        DB::table('markets')->insertOrIgnore([
            'code' => 'UA',
            'name' => 'Ukraine',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->warehouseId = (int) DB::table('warehouses')
            ->insertGetId([
                'code' => 'CAMPAIGN731-' . Str::uuid(),
                'name' => 'Campaign test warehouse',
                'kind' => 'owned',
                'country_code' => 'UA',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function staff(string $role = 'owner'): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actorId = (int) $user->id;

        DB::table('checkout_crm_staff65')->insert([
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken(
            $user->createToken(
                'crm65-dashboard',
                ['crm65:access']
            )->plainTextToken
        );
    }

    private function databaseNow(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            DB::selectOne(
                'SELECT CURRENT_TIMESTAMP AS ts'
            )->ts
        )->utc();
    }

    private function brand(string $name): int
    {
        return (int) DB::table('brands')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function variant(
        ?int $brand = null,
        int $stock = 10,
        int $price = 400000
    ): array {
        $slug = 'campaign-' . Str::uuid();

        $productId = (int) DB::table('products')
            ->insertGetId([
                'brand_id' => $brand,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('product_translations')->insert([
            'product_id' => $productId,
            'locale' => 'uk',
            'name' => 'Тестовий товар',
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sku = 'C731-' . Str::uuid();

        $variantId = (int) DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => $sku,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        app(PriceService::class)->setPrice(
            $variantId,
            'UA_RETAIL',
            $price,
            null,
            'TEST-CAMPAIGN731',
            (string) Str::uuid(),
            0
        );

        $stockId = (int) DB::table('inventory_stocks')
            ->insertGetId([
                'warehouse_id' => $this->warehouseId,
                'variant_id' => $variantId,
                'on_hand_qty' => $stock,
                'reserved_qty' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'id' => $variantId,
            'product_id' => $productId,
            'stock_id' => $stockId,
            'sku' => $sku,
            'slug' => $slug,
        ];
    }

    private function terms(array $overrides = []): array
    {
        $now = $this->databaseNow();

        return [
            'price_list_id' => $this->listId,
            'name' => 'Campaign test',
            'badge' => 'SALE',
            'discount_type' => 'percent',
            'discount_value' => 1000,
            'max_discount_minor' => null,
            'priority' => 0,
            'allow_promo' => false,
            'allow_loyalty' => true,
            'scope_type' => 'all',
            'scope_ids' => [],
            'excluded_variant_ids' => [],
            'max_stock' => null,
            'unsold_days' => null,
            'starts_at' => $now->subHour()
                ->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $now->addDays(7)
                ->format('Y-m-d\TH:i:s\Z'),
            ...$overrides,
        ];
    }

    private function preview(array $terms): array
    {
        return $this->postJson(
            self::API . '/preview',
            $terms
        )->assertOk()->json();
    }

    private function create(
        array $terms,
        bool $activate = true
    ): int {
        $preview = $this->preview($terms);

        $id = (int) $this->postJson(
            self::API . '/create',
            [
                ...$terms,
                'operation_key' => (string) Str::uuid(),
                'preview_token' => $preview['preview_token'],
            ]
        )->assertCreated()
            ->assertJsonPath('result.is_active', false)
            ->json('result.campaign_id');

        if ($activate) {
            $this->toggle($id, 1, true);
        }

        return $id;
    }

    private function toggle(
        int $id,
        int $revision,
        bool $active
    ): void {
        $this->postJson(self::API . '/toggle', [
            'operation_key' => (string) Str::uuid(),
            'campaign_id' => $id,
            'expected_revision' => $revision,
            'is_active' => $active,
        ])->assertOk();
    }

    private function price(int $variantId): int
    {
        return (int) DB::table(
            'effective_variant_prices731'
        )->where('variant_id', $variantId)
            ->where('price_list_id', $this->listId)
            ->value('price_minor');
    }

    private function items(int ...$variantIds): array
    {
        return array_map(
            static fn (int $id): array => [
                'variant_id' => $id,
                'quantity' => 1,
            ],
            $variantIds
        );
    }

    private function promo(): void
    {
        $id = (int) DB::table('promotions')->insertGetId([
            'price_list_id' => $this->listId,
            'name' => 'Extra 10 percent',
            'type' => 'percent',
            'value' => 1000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('promotion_codes')->insert([
            'promotion_id' => $id,
            'code' => 'EXTRA731',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================
    // 1. BRAND CAMPAIGN AND PUBLIC PRICES
    // =========================================================

    public function test_campaign731_brand_selection_and_all_public_prices(): void
    {
        $this->staff();

        $adidas = $this->brand('Adidas');
        $nike = $this->brand('Nike');

        $first = $this->variant($adidas);
        $second = $this->variant($adidas);
        $other = $this->variant($nike);

        $terms = $this->terms([
            'scope_type' => 'brand',
            'scope_ids' => [$adidas],
        ]);

        $preview = $this->preview($terms);

        $this->assertSame(2, $preview['count']);

        $id = $this->create($terms);

        $this->assertSame(
            360000,
            $this->price($first['id'])
        );

        $this->assertSame(
            360000,
            $this->price($second['id'])
        );

        $this->assertSame(
            400000,
            $this->price($other['id'])
        );

        $this->getJson(
            '/api/v1/catalog/variants/' . $first['sku']
        )->assertOk()
            ->assertJsonPath('data.price.amount_minor', 360000)
            ->assertJsonPath('data.price.compare_at_minor', 400000)
            ->assertJsonPath('data.campaign.id', $id);

        $this->getJson(
            '/api/v1/catalog/products/'
            . $first['slug']
            . '?locale=uk'
        )->assertOk()
            ->assertJsonPath(
                'data.price_from.amount_minor',
                360000
            )
            ->assertJsonPath(
                'data.variants.0.price.amount_minor',
                360000
            );

        $this->postJson('/api/v1/cart/preview', [
            'locale' => 'uk',
            'items' => $this->items($first['id']),
        ])->assertOk()
            ->assertJsonPath(
                'data.0.price.amount_minor',
                360000
            );

        $quote = app(
            CheckoutPricingPreviewService::class
        )->preview(
            $this->items($first['id']),
            'UA',
            'UA_RETAIL'
        );

        $this->assertSame(
            360000,
            $quote['goods_payable_minor']
        );

        $this->assertDatabaseHas('variant_prices', [
            'variant_id' => $first['id'],
            'price_minor' => 400000,
        ]);
    }

    // =========================================================
    // 2. CATEGORY AND DESCENDANTS
    // =========================================================

    public function test_campaign731_category_includes_descendants(): void
    {
        $this->staff();

        $parent = (int) DB::table('categories')
            ->insertGetId([
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $child = (int) DB::table('categories')
            ->insertGetId([
                'parent_id' => $parent,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $included = $this->variant();
        $excluded = $this->variant();

        DB::table('product_categories')->insert([
            'product_id' => $included['product_id'],
            'category_id' => $child,
            'is_primary' => true,
        ]);

        $this->create($this->terms([
            'scope_type' => 'category',
            'scope_ids' => [$parent],
        ]));

        $this->assertSame(
            360000,
            $this->price($included['id'])
        );

        $this->assertSame(
            400000,
            $this->price($excluded['id'])
        );
    }

    // =========================================================
    // 3. EXCLUDED SKU
    // =========================================================

    public function test_campaign731_explicit_sku_exclusion(): void
    {
        $this->staff();

        $first = $this->variant();
        $second = $this->variant();

        $this->create($this->terms([
            'excluded_variant_ids' => [$second['id']],
        ]));

        $this->assertSame(
            360000,
            $this->price($first['id'])
        );

        $this->assertSame(
            400000,
            $this->price($second['id'])
        );
    }

    // =========================================================
    // 4. LOW STOCK CLEARANCE
    // =========================================================

    public function test_campaign731_low_stock_selection(): void
    {
        $this->staff();

        $low = $this->variant(null, 3);
        $normal = $this->variant(null, 10);
        $empty = $this->variant(null, 0);

        $this->create($this->terms([
            'max_stock' => 3,
            'discount_value' => 3000,
        ]));

        $this->assertSame(
            280000,
            $this->price($low['id'])
        );

        $this->assertSame(
            400000,
            $this->price($normal['id'])
        );

        $this->assertSame(
            400000,
            $this->price($empty['id'])
        );
    }

    // =========================================================
    // 5. OLD UNSOLD STOCK
    // =========================================================

    public function test_campaign731_old_unsold_items_are_selected(): void
    {
        $this->staff();

        $old = $this->variant();
        $recentSale = $this->variant();
        $new = $this->variant();

        $time = $this->databaseNow();

        foreach ([$old, $recentSale] as $item) {
            DB::table('inventory_movements')->insert([
                'inventory_stock_id' => $item['stock_id'],
                'type' => 'receipt',
                'quantity_delta' => 10,
                'created_at' => $time->subDays(100),
            ]);
        }

        DB::table('inventory_movements')->insert([
            'inventory_stock_id' => $recentSale['stock_id'],
            'type' => 'sale',
            'quantity_delta' => -1,
            'created_at' => $time->subDay(),
        ]);

        DB::table('inventory_movements')->insert([
            'inventory_stock_id' => $new['stock_id'],
            'type' => 'receipt',
            'quantity_delta' => 10,
            'created_at' => $time->subDays(10),
        ]);

        $this->create($this->terms([
            'unsold_days' => 90,
            'discount_value' => 4000,
        ]));

        $this->assertSame(
            240000,
            $this->price($old['id'])
        );

        $this->assertSame(
            400000,
            $this->price($recentSale['id'])
        );

        $this->assertSame(
            400000,
            $this->price($new['id'])
        );
    }

    // =========================================================
    // 6. STALE PREVIEW PROTECTION
    // =========================================================

    public function test_campaign731_stale_preview_is_rejected(): void
    {
        $this->staff();

        $variant = $this->variant();
        $terms = $this->terms();

        $preview = $this->preview($terms);

        app(PriceService::class)->setPrice(
            $variant['id'],
            'UA_RETAIL',
            500000,
            null,
            'NEW-TEST-PRICE',
            (string) Str::uuid(),
            1
        );

        $this->postJson(self::API . '/create', [
            ...$terms,
            'operation_key' => (string) Str::uuid(),
            'preview_token' => $preview['preview_token'],
        ])->assertStatus(409);

        $this->assertDatabaseCount(
            'marketing_campaigns731',
            0
        );
    }

    // =========================================================
    // 7. CAMPAIGN PRIORITY
    // =========================================================

    public function test_campaign731_priority_wins_without_stacking(): void
    {
        $this->staff();

        $variant = $this->variant();

        $this->create($this->terms([
            'priority' => 0,
            'discount_value' => 3000,
        ]));

        $highPriority = $this->create($this->terms([
            'priority' => 100,
            'discount_value' => 1000,
        ]));

        $this->assertSame(
            360000,
            $this->price($variant['id'])
        );

        $this->toggle($highPriority, 2, false);

        $this->assertSame(
            280000,
            $this->price($variant['id'])
        );
    }

    // =========================================================
    // 8. BEST PRICE AT EQUAL PRIORITY
    // =========================================================

    public function test_campaign731_same_priority_uses_better_price(): void
    {
        $this->staff();

        $variant = $this->variant();

        $this->create($this->terms([
            'discount_value' => 1000,
        ]));

        $this->create($this->terms([
            'discount_value' => 2500,
        ]));

        $this->assertSame(
            300000,
            $this->price($variant['id'])
        );
    }

    // =========================================================
    // 9. SCHEDULED CAMPAIGN
    // =========================================================

    public function test_campaign731_future_campaign_is_not_applied_early(): void
    {
        $this->staff();

        $variant = $this->variant();

        $this->create($this->terms([
            'starts_at' => $this->databaseNow()
                ->addDay()
                ->format('Y-m-d\TH:i:s\Z'),
        ]));

        $this->assertSame(
            400000,
            $this->price($variant['id'])
        );

        $this->getJson(self::API)
            ->assertOk()
            ->assertJsonPath(
                'items.0.state',
                'scheduled'
            );
    }

    // =========================================================
    // 10. EXPIRED CAMPAIGN
    // =========================================================

    public function test_campaign731_expired_campaign_does_not_change_price(): void
    {
        $this->staff();

        $variant = $this->variant();
        $time = $this->databaseNow();

        $id = DB::table('marketing_campaigns731')
            ->insertGetId([
                'price_list_id' => $this->listId,
                'created_by' => $this->actorId,
                'name' => 'Expired campaign',
                'badge' => 'SALE',
                'discount_type' => 'percent',
                'discount_value' => 1000,
                'is_active' => true,
                'starts_at' => $time->subDays(2),
                'ends_at' => $time->subDay(),
                'selection_json' => '{}',
                'created_at' => $time->subDays(2),
                'updated_at' => $time->subDays(2),
            ]);

        DB::table('marketing_campaign_variants731')->insert([
            'campaign_id' => $id,
            'variant_id' => $variant['id'],
        ]);

        $this->assertSame(
            400000,
            $this->price($variant['id'])
        );

        $this->getJson(self::API)
            ->assertOk()
            ->assertJsonPath(
                'items.0.state',
                'expired'
            );
    }

    // =========================================================
    // 11. FINANCIAL SNAPSHOT
    // =========================================================

    public function test_campaign731_prepared_order_keeps_discounted_price(): void
    {
        $this->staff();

        $variant = $this->variant();

        $campaign = $this->create($this->terms());

        $key = (string) Str::uuid();

        $expires = CarbonImmutable::now('UTC')
            ->addMinutes(30);

        $service = app(
            CheckoutOrderPreparationService::class
        );

        $first = $service->prepare(
            $key,
            $this->items($variant['id']),
            'UA',
            'UA_RETAIL',
            $expires
        );

        $snapshot = $first['financial_snapshot'];

        $this->assertSame(
            360000,
            $snapshot['goods_payable_minor']
        );

        $this->assertSame(
            $campaign,
            $snapshot['snapshot']
            ['automatic_campaigns'][0]['campaign_id']
        );

        $this->toggle($campaign, 2, false);

        $this->assertSame(
            400000,
            $this->price($variant['id'])
        );

        $second = $service->prepare(
            $key,
            $this->items($variant['id']),
            'UA',
            'UA_RETAIL',
            $expires
        );

        $this->assertSame($first, $second);
    }

    // =========================================================
    // 12. BLOCKED PROMOTION CODE
    // =========================================================

    public function test_campaign731_can_block_promotion_code(): void
    {
        $this->staff();

        $variant = $this->variant();

        $this->create($this->terms([
            'allow_promo' => false,
        ]));

        $this->promo();

        $quote = app(PromotionService::class)->preview(
            'EXTRA731',
            $this->items($variant['id']),
            null,
            'UA_RETAIL'
        );

        $this->assertFalse($quote['valid']);

        $this->assertSame(
            'automatic_campaign_blocks_promo',
            $quote['reason']
        );

        $this->assertSame(
            360000,
            $quote['subtotal_minor']
        );
    }

    // =========================================================
    // 13. ALLOWED PROMOTION CODE
    // =========================================================

    public function test_campaign731_allowed_promo_uses_discounted_price(): void
    {
        $this->staff();

        $variant = $this->variant();

        $this->create($this->terms([
            'allow_promo' => true,
        ]));

        $this->promo();

        $quote = app(
            CheckoutPricingPreviewService::class
        )->preview(
            $this->items($variant['id']),
            'UA',
            'UA_RETAIL',
            'EXTRA731'
        );

        $this->assertSame(
            360000,
            $quote['subtotal_minor']
        );

        $this->assertSame(
            36000,
            $quote['promotion_discount_minor']
        );

        $this->assertSame(
            324000,
            $quote['goods_payable_minor']
        );
    }

    // =========================================================
    // 14. LOYALTY REDEMPTION RESTRICTION
    // =========================================================

    public function test_campaign731_campaign_can_block_loyalty_redemption(): void
    {
        $this->staff();

        $variant = $this->variant();

        $this->create($this->terms([
            'allow_loyalty' => false,
        ]));

        $user = User::factory()->create();

        $marketId = DB::table('markets')
            ->where('code', 'UA')
            ->value('id');

        $account = DB::table('loyalty_accounts')
            ->insertGetId([
                'user_id' => $user->id,
                'identity_state' => 'verified',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $wallet = (int) DB::table('loyalty_wallets')
            ->insertGetId([
                'loyalty_account_id' => $account,
                'market_id' => $marketId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $loyalty = app(LoyaltyTransactionService::class);

        $grant = $loyalty->grantPending(
            $wallet,
            'campaign731-grant-command',
            [
                'grant_key' => 'campaign731-grant',
                'grant_type' => 'purchase',
                'source_type' => 'test_order',
                'source_ref' => 'campaign731-order',
                'program_code' => 'TEST_PROGRAM',
                'rule_version' => 'v1',
                'units_total' => 500,
                'activation_requirements' => [],
                'rule_snapshot' => [
                    'repay_bonus_debt' => true,
                ],
            ]
        );

        $loyalty->activateGrant(
            $wallet,
            (int) $grant['grant_id'],
            500,
            'campaign731-grant-activation'
        );

        $time = CarbonImmutable::now('UTC');

        $policy = DB::table(
            'loyalty_redemption_policies'
        )->insertGetId([
            'market_id' => $marketId,
            'currency_code' => 'UAH',
            'program_code' => 'TEST_PROGRAM',
            'rule_version' => 'campaign731-v1',
            'is_enabled' => true,
            'bonus_units_per_step' => 1,
            'money_minor_per_step' => 100,
            'max_goods_share_bps' => 3000,
            'minimum_cash_minor' => 0,
            'effective_from' => $time->addHour(),
        ]);

        DB::table(
            'loyalty_redemption_eligibility_rules'
        )->insert([
            'policy_id' => $policy,
            'scope_type' => 'variant',
            'decision' => 'allow',
            'priority' => 10,
            'variant_id' => $variant['id'],
        ]);

        CarbonImmutable::setTestNow(
            $time->addHours(2)
        );

        $quote = app(
            CheckoutPricingPreviewService::class
        )->preview(
            $this->items($variant['id']),
            'UA',
            'UA_RETAIL',
            null,
            $user->id,
            100
        );

        $this->assertFalse(
            $quote['lines'][0]['loyalty_eligible']
        );

        $this->assertSame(
            0,
            $quote['loyalty_discount_minor']
        );
    }

    // =========================================================
    // 15. IDEMPOTENT CREATION
    // =========================================================

    public function test_campaign731_repeated_creation_is_idempotent(): void
    {
        $this->staff();

        $this->variant();

        $terms = $this->terms();

        $preview = $this->preview($terms);

        $body = [
            ...$terms,
            'operation_key' => (string) Str::uuid(),
            'preview_token' => $preview['preview_token'],
        ];

        $first = $this->postJson(
            self::API . '/create',
            $body
        )->assertCreated()
            ->assertJsonPath('replayed', false)
            ->json('result.campaign_id');

        $this->postJson(
            self::API . '/create',
            $body
        )->assertCreated()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('result.campaign_id', $first);

        $this->assertDatabaseCount(
            'marketing_campaigns731',
            1
        );
    }

    // =========================================================
    // 16. IMMUTABLE CAMPAIGN TERMS
    // =========================================================

    public function test_campaign731_terms_cannot_be_rewritten(): void
    {
        $this->staff();

        $this->variant();

        $id = $this->create($this->terms());

        $this->expectException(QueryException::class);

        DB::table('marketing_campaigns731')
            ->where('id', $id)
            ->update([
                'discount_value' => 9999,
            ]);
    }

    // =========================================================
    // 17. READ-ONLY MANAGER
    // =========================================================

    public function test_campaign731_manager_is_read_only(): void
    {
        $this->staff('manager');

        $this->getJson(self::API)
            ->assertOk();

        $this->postJson(
            self::API . '/preview',
            $this->terms()
        )->assertForbidden();
    }

    // =========================================================
    // 18. AUTHENTICATION
    // =========================================================

    public function test_campaign731_requires_authentication(): void
    {
        $this->getJson(self::API)
            ->assertUnauthorized();
    }
}
