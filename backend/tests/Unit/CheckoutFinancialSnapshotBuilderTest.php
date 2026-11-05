<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutFinancialSnapshotBuilder;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutFinancialSnapshotBuilderTest extends TestCase
{
    private CheckoutFinancialSnapshotBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new CheckoutFinancialSnapshotBuilder();
    }

    public function test_builds_reconciled_snapshot_with_unit_details(): void
    {
        $result = $this->builder->build($this->quote());
        $snapshot = $result['snapshot'];

        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame('UAH', $snapshot['currency_code']);
        $this->assertSame(500, $snapshot['subtotal_minor']);
        $this->assertSame(50, $snapshot['promotion_discount_minor']);
        $this->assertSame(90, $snapshot['loyalty_discount_minor']);
        $this->assertSame(360, $snapshot['goods_payable_minor']);
        $this->assertSame(135, $snapshot['loyalty_units_spent']);
        $this->assertSame('DEMO10', $snapshot['promotion_code']);

        $this->assertSame(
            901,
            $snapshot['loyalty_eligibility']
            ['applied_rule_ids_by_variant'][11]
        );

        $this->assertSame(
            [75, 75],
            array_column(
                $snapshot['lines'][0]['units'],
                'cash_minor'
            )
        );

        $this->assertSame(
            210,
            $snapshot['lines'][1]['units'][0]['cash_minor']
        );

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $result['sha256']
        );
    }

    public function test_handles_one_cent_competing_discounts_without_negative_units(): void
    {
        $quote = $this->quote();

        $quote['lines'] = [
            $this->line(11, 2, 1, 1, 1, true),
        ];

        $quote['price_revisions'] = [11 => 1];
        $quote['subtotal_minor'] = 2;
        $quote['promotion_discount_minor'] = 1;
        $quote['loyalty_discount_minor'] = 1;
        $quote['goods_payable_minor'] = 0;
        $quote['eligible_goods_after_promotions_minor'] = 1;

        $quote['promotion']['discount_minor'] = 1;

        $quote['loyalty']['applied_units'] = 3;
        $quote['loyalty']['discount_minor'] = 1;
        $quote['loyalty']['eligible_goods_minor'] = 1;

        $quote['loyalty']['policy']['money_minor_per_step'] = 1;
        $quote['loyalty']['policy']['max_goods_share_bps'] = 10000;

        $units = $this->builder->build($quote)
        ['snapshot']['lines'][0]['units'];

        $this->assertSame(
            [1, 0],
            array_column($units, 'promotion_discount_minor')
        );

        $this->assertSame(
            [0, 1],
            array_column($units, 'loyalty_discount_minor')
        );

        $this->assertSame(
            [0, 0],
            array_column($units, 'cash_minor')
        );
    }

    public function test_uneven_unit_rounding_is_deterministic(): void
    {
        $quote = $this->quote();

        $quote['lines'] = [
            $this->line(11, 3, 100, 50, 50, true),
        ];

        $quote['price_revisions'] = [11 => 1];
        $quote['subtotal_minor'] = 300;
        $quote['promotion_discount_minor'] = 50;
        $quote['loyalty_discount_minor'] = 50;
        $quote['goods_payable_minor'] = 200;
        $quote['eligible_goods_after_promotions_minor'] = 250;

        $quote['promotion']['discount_minor'] = 50;
        $quote['loyalty']['applied_units'] = 75;
        $quote['loyalty']['discount_minor'] = 50;
        $quote['loyalty']['eligible_goods_minor'] = 250;

        $units = $this->builder->build($quote)
        ['snapshot']['lines'][0]['units'];

        $this->assertSame(
            [17, 17, 16],
            array_column($units, 'promotion_discount_minor')
        );

        $this->assertSame(
            [17, 16, 17],
            array_column($units, 'loyalty_discount_minor')
        );

        $this->assertSame(
            [66, 67, 67],
            array_column($units, 'cash_minor')
        );
    }

    public function test_identical_input_has_identical_checksum(): void
    {
        $first = $this->builder->build($this->quote());
        $second = $this->builder->build($this->quote());

        $this->assertSame(
            $first['snapshot'],
            $second['snapshot']
        );

        $this->assertSame(
            $first['sha256'],
            $second['sha256']
        );
    }

    public function test_rejects_line_mismatch(): void
    {
        $quote = $this->quote();
        $quote['lines'][0]['goods_payable_minor']++;

        $this->expectException(DomainException::class);

        $this->builder->build($quote);
    }

    public function test_rejects_aggregate_mismatch(): void
    {
        $quote = $this->quote();
        $quote['subtotal_minor']++;

        $this->expectException(DomainException::class);

        $this->builder->build($quote);
    }

    public function test_requires_price_revision_for_each_sku(): void
    {
        $quote = $this->quote();

        unset($quote['price_revisions'][11]);

        $this->expectException(InvalidArgumentException::class);

        $this->builder->build($quote);
    }

    public function test_rejects_loyalty_discount_for_ineligible_sku(): void
    {
        $quote = $this->quote();
        $quote['lines'][0]['loyalty_eligible'] = false;

        $this->expectException(DomainException::class);

        $this->builder->build($quote);
    }

    public function test_rejects_mismatched_policy_version(): void
    {
        $quote = $this->quote();

        $quote['loyalty_eligibility']['rule_version'] =
            'different';

        $this->expectException(DomainException::class);

        $this->builder->build($quote);
    }

    public function test_rejects_invalid_promotion_snapshot(): void
    {
        $quote = $this->quote();
        $quote['promotion']['valid'] = false;

        $this->expectException(DomainException::class);

        $this->builder->build($quote);
    }

    public function test_rejects_missing_eligibility_rule_for_redeemed_sku(): void
    {
        $quote = $this->quote();

        unset(
            $quote['loyalty_eligibility']
            ['matched_rule_ids'][11]
        );

        $this->expectException(DomainException::class);

        $this->builder->build($quote);
    }

    public function test_rejects_unsafe_amount_overflow(): void
    {
        $quote = $this->quote();

        $quote['lines'][0]['quantity'] = 2;
        $quote['lines'][0]['unit_price_minor'] = PHP_INT_MAX;

        $this->expectException(DomainException::class);

        $this->builder->build($quote);
    }

    /**
     * @return array<string,mixed>
     */
    private function quote(): array
    {
        return [
            'informational_only' => true,

            'market_code' => 'UA',
            'price_list_code' => 'UA_RETAIL',
            'currency_code' => 'UAH',

            'subtotal_minor' => 500,
            'promotion_discount_minor' => 50,
            'loyalty_discount_minor' => 90,
            'goods_payable_minor' => 360,

            'eligible_goods_after_promotions_minor' => 450,

            'price_revisions' => [
                11 => 1,
                22 => 3,
            ],

            'lines' => [
                $this->line(11, 2, 100, 20, 30, true),
                $this->line(22, 1, 300, 30, 60, true),
            ],

            'promotion' => [
                'valid' => true,
                'code' => 'DEMO10',
                'discount_minor' => 50,
            ],

            'loyalty' => [
                'applied_units' => 135,
                'discount_minor' => 90,
                'eligible_goods_minor' => 450,
                'spendable_wallet_units' => 500,

                'policy' => [
                    'id' => 55,
                    'program_code' => 'TEST',
                    'rule_version' => 'v1',

                    'bonus_units_per_step' => 3,
                    'money_minor_per_step' => 2,

                    'max_goods_share_bps' => 2500,
                    'max_discount_minor' => 500,
                    'minimum_cash_minor' => 0,
                ],
            ],

            'loyalty_eligibility' => [
                'policy_id' => 55,
                'rule_version' => 'v1',

                'approved_variant_ids' => [
                    11,
                    22,
                ],

                'matched_rule_ids' => [
                    11 => 901,
                    22 => 902,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function line(
        int $id,
        int $qty,
        int $price,
        int $promo,
        int $loyalty,
        bool $eligible
    ): array {
        return [
            'variant_id' => $id,
            'quantity' => $qty,
            'unit_price_minor' => $price,

            'subtotal_minor' => $qty * $price,
            'promotion_discount_minor' => $promo,
            'loyalty_discount_minor' => $loyalty,

            'goods_payable_minor' =>
                $qty * $price - $promo - $loyalty,

            'loyalty_eligible' => $eligible,
        ];
    }
}
