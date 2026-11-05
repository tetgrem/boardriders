<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PromotionPreviewTest extends TestCase
{
    use DatabaseTransactions;

    private int $variantId;
    private int $promotionId;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        // Тести не повинні змінювати робочу базу даних.
        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Promotion tests require PostgreSQL database boardriders_test.'
            );
        }

        $now = now();
        $suffix = strtoupper(Str::random(12));
        $this->code = 'TEST10-' . $suffix;

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Українська гривня',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('price_lists')->insertOrIgnore([
            'code' => 'UA_RETAIL',
            'name' => 'Роздрібні ціни — Україна',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'type' => 'retail',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $priceListId = (int) DB::table('price_lists')
            ->where('code', 'UA_RETAIL')
            ->value('id');

        $productId = DB::table('products')->insertGetId([
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->variantId = (int) DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => 'PROMO-TEST-' . $suffix,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // 2 599,90 грн — усі фінансові значення в копійках.
        DB::table('variant_prices')->insert([
            'variant_id' => $this->variantId,
            'price_list_id' => $priceListId,
            'price_minor' => 259990,
            'compare_at_minor' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouseId = (int) DB::table('warehouses')
            ->insertGetId([
                'code' => 'PROMO-WH-' . $suffix,
                'name' => 'Тестовий склад промокодів',
                'kind' => 'owned',
                'country_code' => 'UA',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // 5 фізично, 1 зарезервовано: доступно 4.
        DB::table('inventory_stocks')->insert([
            'warehouse_id' => $warehouseId,
            'variant_id' => $this->variantId,
            'on_hand_qty' => 5,
            'reserved_qty' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->promotionId = (int) DB::table('promotions')
            ->insertGetId([
                'price_list_id' => $priceListId,
                'name' => '[TEST] Promo preview',
                'type' => 'percent',
                'value' => 1000,
                'min_subtotal_minor' => 0,
                'max_discount_minor' => null,
                'usage_limit' => null,
                'per_customer_limit' => null,
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
            'usage_limit' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_percent_discount_is_calculated_in_minor_units(): void
    {
        $redemptionsBefore = DB::table('promotion_redemptions')->count();

        $this->postJson('/api/v1/promotions/preview', $this->cart())
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.code', $this->code)
            ->assertJsonPath('data.currency', 'UAH')
            ->assertJsonPath('data.subtotal_minor', 259990)
            ->assertJsonPath('data.discount_minor', 25999)
            ->assertJsonPath('data.total_minor', 233991);

        // Preview не повинен фіксувати використання промокоду.
        $this->assertSame(
            $redemptionsBefore,
            DB::table('promotion_redemptions')->count()
        );
    }

    public function test_unavailable_code_does_not_apply_a_discount(): void
    {
        $this->postJson('/api/v1/promotions/preview', $this->cart('NOT-FOUND'))
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.reason', 'code_unavailable')
            ->assertJsonPath('data.discount_minor', 0)
            ->assertJsonPath('data.total_minor', 259990);
    }

    public function test_reserved_stock_is_not_available_to_buy(): void
    {
        $this->postJson('/api/v1/promotions/preview', $this->cart(null, 5))
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.reason', 'insufficient_stock');
    }

    public function test_minimum_subtotal_is_enforced(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update(['min_subtotal_minor' => 259991]);

        $this->postJson('/api/v1/promotions/preview', $this->cart())
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.reason', 'minimum_not_met');
    }

    public function test_fixed_discount_respects_maximum_discount(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update([
                'type' => 'fixed',
                'value' => 80000,
                'max_discount_minor' => 20000,
            ]);

        $this->postJson('/api/v1/promotions/preview', $this->cart())
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.discount_minor', 20000)
            ->assertJsonPath('data.total_minor', 239990);
    }

    public function test_expired_promotion_is_unavailable(): void
    {
        DB::table('promotions')
            ->where('id', $this->promotionId)
            ->update([
                'starts_at' => now()->subDays(3),
                'ends_at' => now()->subDay(),
            ]);

        $this->postJson('/api/v1/promotions/preview', $this->cart())
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.reason', 'code_unavailable');
    }

    public function test_invalid_quantity_is_rejected_by_validation(): void
    {
        $this->postJson('/api/v1/promotions/preview', $this->cart(null, 0))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');
    }

    /**
     * @return array{code:string, items:array<int, array{variant_id:int, quantity:int}>}
     */
    private function cart(?string $code = null, int $quantity = 1): array
    {
        return [
            'code' => $code ?? $this->code,
            'items' => [
                [
                    'variant_id' => $this->variantId,
                    'quantity' => $quantity,
                ],
            ],
        ];
    }
}
