<?php

namespace Tests\Feature;

use App\Services\Loyalty\LoyaltyRedemptionEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Тільки PostgreSQL boardriders_test.
 * Всі значення політик у тестах є прикладами,
 * а не production-налаштуваннями.
 */
final class LoyaltyRedemptionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $marketUa;
    private int $marketOther;
    private int $policyUa;
    private CarbonImmutable $basePolicyAt;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Ukrainian hryvnia',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->marketUa = $this->market('UA');
        $this->marketOther = $this->market('UA_SECOND');

        // Політику створюємо ДО набрання нею чинності.
        $this->basePolicyAt = CarbonImmutable::now('UTC')->addHour();

        $this->policyUa = $this->policy(
            $this->marketUa,
            'v1',
            true,
            $this->basePolicyAt
        );

        // Лише PHP-годинник у тесті.
        // PostgreSQL clock_timestamp() лишається реальним.
        CarbonImmutable::setTestNow(
            $this->basePolicyAt->addMinute()
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_no_rules_means_no_products_can_use_bonuses(): void
    {
        $sku = $this->product();

        $result = $this->resolve($this->marketUa, [$sku]);

        $this->assertSame([], $result['approved_variant_ids']);
        $this->assertSame($this->policyUa, $result['policy_id']);
        $this->assertSame('v1', $result['rule_version']);
    }

    public function test_explicit_global_allow_is_required_for_all_products(): void
    {
        $first = $this->product();
        $second = $this->product();

        $this->rule($this->policyUa, 'all', 'allow');

        $result = $this->resolve(
            $this->marketUa,
            [$second, $first]
        );

        $this->assertSame(
            [$second, $first],
            $result['approved_variant_ids']
        );
    }

    public function test_brand_allow_only_matches_its_own_products(): void
    {
        $brandA = $this->brand();
        $brandB = $this->brand();

        $skuA = $this->product($brandA);
        $skuB = $this->product($brandB);

        $this->rule(
            $this->policyUa,
            'brand',
            'allow',
            $brandA
        );

        $result = $this->resolve(
            $this->marketUa,
            [$skuA, $skuB]
        );

        $this->assertSame(
            [$skuA],
            $result['approved_variant_ids']
        );
    }

    public function test_category_rule_applies_to_descendant_category(): void
    {
        $parent = $this->category();
        $child = $this->category($parent);
        $unrelated = $this->category();

        $skuChild = $this->product(null, [$child]);
        $skuOther = $this->product(null, [$unrelated]);

        $this->rule(
            $this->policyUa,
            'category',
            'allow',
            $parent
        );

        $result = $this->resolve(
            $this->marketUa,
            [$skuChild, $skuOther]
        );

        $this->assertSame(
            [$skuChild],
            $result['approved_variant_ids']
        );
    }

    public function test_deny_wins_on_equal_priority(): void
    {
        $brand = $this->brand();
        $sku = $this->product($brand);

        $this->rule(
            $this->policyUa,
            'all',
            'allow',
            null,
            0
        );

        $deny = $this->rule(
            $this->policyUa,
            'brand',
            'deny',
            $brand,
            0
        );

        $result = $this->resolve(
            $this->marketUa,
            [$sku]
        );

        $this->assertSame(
            [],
            $result['approved_variant_ids']
        );

        $this->assertSame(
            $deny,
            $result['matched_rule_ids'][$sku]
        );
    }

    public function test_higher_priority_variant_exception_can_override_brand_deny(): void
    {
        $brand = $this->brand();

        $allowed = $this->product($brand);
        $denied = $this->product($brand);

        $this->rule(
            $this->policyUa,
            'brand',
            'deny',
            $brand,
            10
        );

        $this->rule(
            $this->policyUa,
            'variant',
            'allow',
            $allowed,
            20
        );

        $result = $this->resolve(
            $this->marketUa,
            [$allowed, $denied]
        );

        $this->assertSame(
            [$allowed],
            $result['approved_variant_ids']
        );
    }

    public function test_new_disabled_version_disables_previous_allow_rules(): void
    {
        $sku = $this->product();

        $this->rule(
            $this->policyUa,
            'all',
            'allow'
        );

        $newPolicy = $this->policy(
            $this->marketUa,
            'v2',
            false,
            $this->basePolicyAt->addSeconds(30)
        );

        $result = $this->resolve(
            $this->marketUa,
            [$sku]
        );

        $this->assertSame(
            $newPolicy,
            $result['policy_id']
        );

        $this->assertSame(
            [],
            $result['approved_variant_ids']
        );
    }

    public function test_rules_in_another_market_do_not_authorize_ua_products(): void
    {
        $sku = $this->product();

        $otherPolicy = $this->policy(
            $this->marketOther,
            'v1',
            true,
            $this->basePolicyAt
        );

        $this->rule(
            $otherPolicy,
            'all',
            'allow'
        );

        $this->assertSame(
            [],
            $this->resolve(
                $this->marketUa,
                [$sku]
            )['approved_variant_ids']
        );

        $this->assertSame(
            [$sku],
            $this->resolve(
                $this->marketOther,
                [$sku]
            )['approved_variant_ids']
        );
    }

    public function test_inactive_brand_and_inactive_category_do_not_grant_access(): void
    {
        $brand = $this->brand();
        $category = $this->category();

        $sku = $this->product(
            $brand,
            [$category]
        );

        $this->rule(
            $this->policyUa,
            'category',
            'allow',
            $category
        );

        DB::table('categories')
            ->where('id', $category)
            ->update(['is_active' => false]);

        $this->assertSame(
            [],
            $this->resolve(
                $this->marketUa,
                [$sku]
            )['approved_variant_ids']
        );

        $this->rule(
            $this->policyUa,
            'all',
            'allow'
        );

        DB::table('brands')
            ->where('id', $brand)
            ->update(['is_active' => false]);

        $this->assertSame(
            [],
            $this->resolve(
                $this->marketUa,
                [$sku]
            )['approved_variant_ids']
        );
    }

    public function test_rules_cannot_be_updated(): void
    {
        $id = $this->rule(
            $this->policyUa,
            'all',
            'allow'
        );

        $this->expectException(QueryException::class);

        DB::table('loyalty_redemption_eligibility_rules')
            ->where('id', $id)
            ->update(['decision' => 'deny']);
    }

    public function test_already_effective_policy_cannot_receive_new_rules(): void
    {
        $policy = $this->policy(
            $this->marketOther,
            'sealed',
            true,
            CarbonImmutable::now('UTC')->subDay()
        );

        $this->expectException(QueryException::class);

        $this->rule(
            $policy,
            'all',
            'allow'
        );
    }

    public function test_duplicate_or_invalid_variant_identifiers_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolve(
            $this->marketUa,
            [17, 17]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function resolve(
        int $marketId,
        array $variantIds
    ): array {
        return app(LoyaltyRedemptionEligibilityService::class)
            ->resolve($marketId, $variantIds);
    }

    private function market(string $code): int
    {
        return (int) DB::table('markets')->insertGetId([
            'code' => $code,
            'name' => $code,
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function policy(
        int $marketId,
        string $version,
        bool $enabled = true,
        ?\DateTimeInterface $from = null
    ): int {
        return (int) DB::table(
            'loyalty_redemption_policies'
        )->insertGetId([
            'market_id' => $marketId,
            'currency_code' => 'UAH',
            'program_code' => 'TEST_PROGRAM',
            'rule_version' => $version,
            'is_enabled' => $enabled,

            // Виключно тестові значення.
            'bonus_units_per_step' => 3,
            'money_minor_per_step' => 2,
            'max_goods_share_bps' => 2500,
            'max_discount_minor' => 500,
            'minimum_cash_minor' => 0,

            'effective_from' =>
                $from ?? $this->basePolicyAt,
        ]);
    }

    private function rule(
        int $policyId,
        string $scope,
        string $decision,
        ?int $subject = null,
        int $priority = 0
    ): int {
        $row = [
            'policy_id' => $policyId,
            'scope_type' => $scope,
            'decision' => $decision,
            'priority' => $priority,
        ];

        if ($scope !== 'all') {
            $row[$scope . '_id'] = $subject;
        }

        return (int) DB::table(
            'loyalty_redemption_eligibility_rules'
        )->insertGetId($row);
    }

    private function brand(): int
    {
        return (int) DB::table('brands')->insertGetId([
            'name' => 'Brand ' . Str::random(8),
            'slug' => 'brand-' . Str::lower(Str::random(12)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function category(?int $parent = null): int
    {
        return (int) DB::table('categories')->insertGetId([
            'parent_id' => $parent,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<int,int> $categoryIds
     */
    private function product(
        ?int $brandId = null,
        array $categoryIds = []
    ): int {
        $productId = (int) DB::table('products')->insertGetId([
            'brand_id' => $brandId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($categoryIds as $categoryId) {
            DB::table('product_categories')->insert([
                'product_id' => $productId,
                'category_id' => $categoryId,
            ]);
        }

        return (int) DB::table(
            'product_variants'
        )->insertGetId([
            'product_id' => $productId,
            'sku' => 'ELIGIBILITY-' . Str::uuid(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
