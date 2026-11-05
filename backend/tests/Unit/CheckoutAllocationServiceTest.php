<?php

namespace Tests\Unit;

use App\Services\Pricing\CheckoutAllocationService;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutAllocationServiceTest extends TestCase
{
    private CheckoutAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CheckoutAllocationService();
    }

    public function test_no_discounts_preserve_original_amounts(): void
    {
        $result = $this->service->allocate([
            $this->item(1, 100),
            $this->item(2, 250),
        ], 0, 0);

        $this->assertSame(350, $result['subtotal_minor']);
        $this->assertSame(350, $result['goods_payable_minor']);
        $this->assertSame(100, $result['lines'][0]['goods_payable_minor']);
        $this->assertSame(250, $result['lines'][1]['goods_payable_minor']);
    }

    public function test_promotion_is_applied_before_loyalty(): void
    {
        $result = $this->service->allocate([
            $this->item(1, 100),
            $this->item(2, 300),
        ], 40, 72);

        $this->assertSame(400, $result['subtotal_minor']);
        $this->assertSame(40, $result['promotion_discount_minor']);
        $this->assertSame(360, $result['eligible_goods_after_promotions_minor']);
        $this->assertSame(72, $result['loyalty_discount_minor']);
        $this->assertSame(288, $result['goods_payable_minor']);

        $this->assertSame(10, $result['lines'][0]['promotion_discount_minor']);
        $this->assertSame(30, $result['lines'][1]['promotion_discount_minor']);

        $this->assertSame(18, $result['lines'][0]['loyalty_discount_minor']);
        $this->assertSame(54, $result['lines'][1]['loyalty_discount_minor']);

        $this->assertSame(72, $result['lines'][0]['goods_payable_minor']);
        $this->assertSame(216, $result['lines'][1]['goods_payable_minor']);
    }

    public function test_rounding_is_deterministic_by_variant_id(): void
    {
        $result = $this->service->allocate([
            $this->item(30, 100),
            $this->item(10, 100),
            $this->item(20, 100),
        ], 1, 0);

        $this->assertSame(
            [0, 1, 0],
            array_column($result['lines'], 'promotion_discount_minor')
        );

        $this->assertSame(299, $result['goods_payable_minor']);
    }

    public function test_ineligible_items_receive_no_discounts(): void
    {
        $result = $this->service->allocate([
            $this->item(1, 100, false, false),
            $this->item(2, 300),
        ], 60, 120);

        $this->assertSame(100, $result['lines'][0]['goods_payable_minor']);
        $this->assertSame(120, $result['lines'][1]['goods_payable_minor']);

        $this->assertSame(0, $result['lines'][0]['promotion_discount_minor']);
        $this->assertSame(0, $result['lines'][0]['loyalty_discount_minor']);

        $this->assertSame(220, $result['goods_payable_minor']);
    }

    public function test_promotion_cannot_exceed_eligible_goods(): void
    {
        $this->expectException(DomainException::class);

        $this->service->allocate([
            $this->item(1, 100, false, true),
            $this->item(2, 200, true, true),
        ], 201, 0);
    }

    public function test_loyalty_cannot_exceed_remaining_eligible_goods(): void
    {
        $this->expectException(DomainException::class);

        $this->service->allocate([
            $this->item(1, 100),
        ], 80, 21);
    }

    public function test_duplicate_variants_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->allocate([
            $this->item(1, 100),
            $this->item(1, 200),
        ], 0, 0);
    }

    public function test_large_amounts_do_not_overflow_intermediate_multiplication(): void
    {
        $result = $this->service->allocate([
            $this->item(20, 5_000_000_000),
            $this->item(10, 5_000_000_000),
        ], 3_000_000_001, 0);

        $this->assertSame(
            [1_500_000_000, 1_500_000_001],
            array_column($result['lines'], 'promotion_discount_minor')
        );

        $this->assertSame(
            6_999_999_999,
            $result['goods_payable_minor']
        );
    }

    public function test_invalid_quantity_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->allocate([
            [
                'variant_id' => 1,
                'quantity' => 100,
                'unit_price_minor' => 100,
                'promotion_eligible' => true,
                'loyalty_eligible' => true,
            ],
        ], 0, 0);
    }

    private function item(
        int $id,
        int $price,
        bool $promotionEligible = true,
        bool $loyaltyEligible = true
    ): array {
        return [
            'variant_id' => $id,
            'quantity' => 1,
            'unit_price_minor' => $price,
            'promotion_eligible' => $promotionEligible,
            'loyalty_eligible' => $loyaltyEligible,
        ];
    }
}
