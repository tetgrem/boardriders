<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Pricing\PriceService;
use Database\Seeders\PricingFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Pricing73Test extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/admin/pricing73';

    private int $listId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new \RuntimeException(
                'Requires PostgreSQL boardriders_test'
            );
        }

        config()->set('pricing73.enabled', true);
        config()->set('crm65.enabled', true);

        $this->seed(PricingFoundationSeeder::class);

        $this->listId = (int) DB::table('price_lists')
            ->where('code', 'UA_RETAIL')->value('id');
    }

    private function staff(string $role = 'owner'): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

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

        return $user;
    }

    private function variant(): int
    {
        $product = DB::table('products')->insertGetId([
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('product_variants')->insertGetId([
            'product_id' => $product,
            'sku' => 'PR73-' . Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function line(
        int $variant,
        int $price = 199900,
        int $revision = 0
    ): array {
        return [
            'variant_id' => $variant,
            'price_minor' => $price,
            'compare_at_minor' => null,
            'expected_revision' => $revision,
        ];
    }

    private function command(
        string $action,
        array $data,
        ?string $key = null
    ) {
        return $this->postJson(self::API . '/submit', [
            'operation_key' => $key ?? (string) Str::uuid(),
            'action' => $action,
            ...$data,
        ]);
    }

    private function priceBody(array $lines): array
    {
        return [
            'price_list_id' => $this->listId,
            'lines' => $lines,
        ];
    }

    private function promotionBody(array $overrides = []): array
    {
        return [
            'price_list_id' => $this->listId,
            'name' => 'Test promotion',
            'type' => 'percent',
            'value' => 1000,
            'usage_limit' => 100,
            ...$overrides,
        ];
    }

    private function promotion(): int
    {
        return (int) $this->command(
            'promotion_create',
            $this->promotionBody()
        )->assertCreated()->json('result.promotion_id');
    }

    private function market(): int
    {
        return (int) $this->command('market_create', [
            'price_list_id' => $this->listId,
            'code' => 'UA_TEST',
            'name' => 'Ukraine test',
        ])->assertCreated()->json('result.market_id');
    }

    private function policyBody(int $market): array
    {
        return [
            'market_id' => $market,
            'expected_policy_id' => null,
            'program_code' => 'BOARDRIDERS',
            'is_enabled' => true,
            'bonus_units_per_step' => 1,
            'money_minor_per_step' => 100,
            'max_goods_share_bps' => 3000,
            'max_discount_minor' => null,
            'minimum_cash_minor' => 100,
            'rules' => [
                [
                    'scope_type' => 'all',
                    'scope_id' => null,
                    'decision' => 'allow',
                    'priority' => 0,
                ],
            ],
        ];
    }

    public function test_pricing73_prices_can_be_saved_in_batch(): void
    {
        $this->staff();

        $first = $this->variant();
        $second = $this->variant();

        $this->command('prices_save', $this->priceBody([
            $this->line($first, 199900),
            $this->line($second, 249900),
        ]))->assertCreated()
            ->assertJsonPath('result.line_count', 2);

        $this->assertDatabaseHas('variant_prices', [
            'variant_id' => $first,
            'price_minor' => 199900,
            'revision' => 1,
        ]);

        $this->assertDatabaseHas('variant_prices', [
            'variant_id' => $second,
            'price_minor' => 249900,
            'revision' => 1,
        ]);

        $this->assertDatabaseCount('variant_price_changes', 2);
        $this->assertDatabaseCount('checkout_pricing_admin_events73', 1);
    }

    public function test_pricing73_old_price_is_saved(): void
    {
        $this->staff();

        $variant = $this->variant();
        $line = $this->line($variant);
        $line['compare_at_minor'] = 249900;

        $this->command(
            'prices_save',
            $this->priceBody([$line])
        )->assertCreated();

        $this->assertDatabaseHas('variant_prices', [
            'variant_id' => $variant,
            'price_minor' => 199900,
            'compare_at_minor' => 249900,
        ]);
    }

    public function test_pricing73_invalid_compare_price_is_rejected(): void
    {
        $this->staff();

        $line = $this->line($this->variant(), 200000);
        $line['compare_at_minor'] = 100000;

        $this->command(
            'prices_save',
            $this->priceBody([$line])
        )->assertStatus(409);

        $this->assertDatabaseCount('variant_prices', 0);
    }

    public function test_pricing73_stale_batch_rolls_back_all_prices(): void
    {
        $this->staff();

        $first = $this->variant();
        $second = $this->variant();

        app(PriceService::class)->setPrice(
            $second,
            'UA_RETAIL',
            100000,
            null,
            'TEST-INITIAL',
            (string) Str::uuid(),
            0
        );

        $this->command('prices_save', $this->priceBody([
            $this->line($first, 200000, 0),
            $this->line($second, 300000, 0),
        ]))->assertStatus(409);

        $this->assertDatabaseMissing('variant_prices', [
            'variant_id' => $first,
        ]);

        $this->assertDatabaseHas('variant_prices', [
            'variant_id' => $second,
            'price_minor' => 100000,
            'revision' => 1,
        ]);

        $this->assertDatabaseCount('checkout_pricing_admin_events73', 0);
    }

    public function test_pricing73_retry_does_not_duplicate_price_history(): void
    {
        $this->staff();

        $key = (string) Str::uuid();

        $body = $this->priceBody([
            $this->line($this->variant()),
        ]);

        $first = $this->command('prices_save', $body, $key)
            ->assertCreated()
            ->assertJsonPath('replayed', false)
            ->json('event_id');

        $this->command('prices_save', $body, $key)
            ->assertCreated()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('event_id', $first);

        $this->assertDatabaseCount('variant_price_changes', 1);
    }

    public function test_pricing73_new_sku_without_price_is_visible(): void
    {
        $this->staff();

        $id = $this->variant();

        $sku = DB::table('product_variants')
            ->where('id', $id)->value('sku');

        $this->getJson(
            self::API . '/prices?price_list_id=' . $this->listId
            . '&price_status=missing&q=' . urlencode($sku)
        )->assertOk()
            ->assertJsonPath('items.0.id', $id)
            ->assertJsonPath('items.0.price_minor', null)
            ->assertJsonPath('items.0.revision', 0);
    }

    public function test_pricing73_promotion_starts_disabled(): void
    {
        $this->staff();

        $response = $this->command(
            'promotion_create',
            $this->promotionBody(['code' => 'SUMMER10'])
        )->assertCreated()
            ->assertJsonPath('result.is_active', false)
            ->assertJsonPath('result.code', 'SUMMER10');

        $this->assertDatabaseHas('promotions', [
            'id' => $response->json('result.promotion_id'),
            'is_active' => false,
            'value' => 1000,
        ]);
    }

    public function test_pricing73_percent_above_100_is_rejected(): void
    {
        $this->staff();

        $this->command('promotion_create', $this->promotionBody([
            'value' => 10001,
        ]))->assertStatus(409);
    }

    public function test_pricing73_promotion_can_be_enabled(): void
    {
        $this->staff();

        $id = $this->promotion();

        $this->command('promotion_toggle', [
            'promotion_id' => $id,
            'expected_revision' => 1,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('result.revision', 2);

        $this->assertDatabaseHas('promotions', [
            'id' => $id,
            'is_active' => true,
        ]);
    }

    public function test_pricing73_stale_promotion_revision_is_rejected(): void
    {
        $this->staff();

        $id = $this->promotion();

        $this->command('promotion_toggle', [
            'promotion_id' => $id,
            'expected_revision' => 0,
            'is_active' => true,
        ])->assertStatus(409);
    }

    public function test_pricing73_can_generate_additional_code(): void
    {
        $this->staff();

        $id = $this->promotion();

        $response = $this->command('code_create', [
            'promotion_id' => $id,
            'expected_revision' => 1,
            'usage_limit' => 5,
        ])->assertCreated()
            ->assertJsonPath('result.revision', 2);

        $this->assertMatchesRegularExpression(
            '/^BDR-[A-F0-9]{12}$/',
            $response->json('result.code')
        );

        $this->assertDatabaseHas('promotion_codes', [
            'id' => $response->json('result.code_id'),
            'usage_limit' => 5,
        ]);
    }

    public function test_pricing73_code_cannot_be_changed_through_other_promotion(): void
    {
        $this->staff();

        $first = $this->promotion();
        $second = $this->promotion();

        $codeId = DB::table('promotion_codes')
            ->where('promotion_id', $first)
            ->value('id');

        $this->command('code_toggle', [
            'promotion_id' => $second,
            'code_id' => $codeId,
            'expected_revision' => 1,
            'is_active' => false,
        ])->assertNotFound();
    }

    public function test_pricing73_duplicate_code_is_rejected(): void
    {
        $this->staff();

        $body = $this->promotionBody([
            'code' => 'UNIQUE73',
        ]);

        $this->command('promotion_create', $body)
            ->assertCreated();

        $this->command('promotion_create', $body)
            ->assertStatus(409);

        $this->assertDatabaseCount('promotions', 1);
    }

    public function test_pricing73_market_uses_price_list_currency(): void
    {
        $this->staff();

        $market = $this->market();

        $this->assertDatabaseHas('markets', [
            'id' => $market,
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'code' => 'UA_TEST',
        ]);
    }

    public function test_pricing73_policy_is_created_with_future_activation(): void
    {
        $this->staff();

        $market = $this->market();

        $response = $this->command(
            'policy_create',
            $this->policyBody($market)
        )->assertCreated()
            ->assertJsonPath('result.rules_count', 1);

        $id = $response->json('result.policy_id');

        $this->assertDatabaseHas('loyalty_redemption_policies', [
            'id' => $id,
            'market_id' => $market,
            'currency_code' => 'UAH',
            'max_goods_share_bps' => 3000,
        ]);

        $this->assertDatabaseHas('loyalty_redemption_eligibility_rules', [
            'policy_id' => $id,
            'scope_type' => 'all',
            'decision' => 'allow',
        ]);

        $this->getJson(self::API . "/policies/{$market}")
            ->assertOk()
            ->assertJsonPath('latest.id', $id)
            ->assertJsonPath('active_policy_id', null);
    }

    public function test_pricing73_duplicate_policy_scopes_are_rejected(): void
    {
        $this->staff();

        $body = $this->policyBody($this->market());
        $body['rules'][] = $body['rules'][0];

        $this->command('policy_create', $body)
            ->assertStatus(409);

        $this->assertDatabaseCount('loyalty_redemption_policies', 0);
    }

    public function test_pricing73_policy_cannot_be_replaced_while_pending(): void
    {
        $this->staff();

        $body = $this->policyBody($this->market());

        $id = $this->command('policy_create', $body)
            ->assertCreated()->json('result.policy_id');

        $body['expected_policy_id'] = $id;

        $this->command('policy_create', $body)
            ->assertStatus(409);
    }

    public function test_pricing73_audit_history_is_signed_and_readable(): void
    {
        $this->staff();

        $this->promotion();

        $this->getJson(self::API . '/history')
            ->assertOk()
            ->assertJsonPath('items.0.action', 'promotion_create');
    }

    public function test_pricing73_audit_cannot_be_updated(): void
    {
        $this->staff();

        $this->promotion();

        $this->expectException(QueryException::class);

        DB::table('checkout_pricing_admin_events73')
            ->update(['action' => 'code_create']);
    }

    public function test_pricing73_manager_is_read_only(): void
    {
        $this->staff('manager');

        $this->getJson(self::API . '/overview')->assertOk();

        $this->command(
            'promotion_create',
            $this->promotionBody()
        )->assertForbidden();
    }

    public function test_pricing73_support_has_no_access(): void
    {
        $this->staff('support');

        $this->getJson(self::API . '/overview')
            ->assertForbidden();
    }

    public function test_pricing73_feature_flag_is_enforced(): void
    {
        $this->staff();

        config()->set('pricing73.enabled', false);

        $this->getJson(self::API . '/overview')
            ->assertStatus(503);
    }

    public function test_pricing73_requires_authentication(): void
    {
        $this->getJson(self::API . '/overview')
            ->assertUnauthorized();
    }
}
