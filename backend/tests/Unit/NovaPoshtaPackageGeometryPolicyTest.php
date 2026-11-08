<?php

namespace Tests\Unit;

use App\Services\Pricing\NovaPoshtaPackageGeometryPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NovaPoshtaPackageGeometryPolicyTest extends TestCase
{
    private NovaPoshtaPackageGeometryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new NovaPoshtaPackageGeometryPolicy();
    }

    private function check(
        int $gross = 700,
        int $length = 300,
        int $width = 200,
        int $height = 100,
        string $type = 'branch',
    ): array {
        return $this->policy->evaluate(
            $gross,
            $length,
            $width,
            $height,
            $type,
            [[
                'length_mm' => 200,
                'width_mm' => 100,
                'height_mm' => 50,
            ]]
        );
    }

    public function test_volume_weight_uses_nova_poshta_integer_formula(): void
    {
        $result = $this->check();

        $this->assertSame(1500, $result['volume_weight_grams']);
        $this->assertSame(1500, $result['chargeable_weight_grams']);
        $this->assertSame('within_standard_limits', $result['local_result']);
    }

    public function test_fractional_gram_volume_is_rounded_up(): void
    {
        $result = $this->check(
            gross: 700,
            length: 201,
            width: 101,
            height: 51
        );

        $this->assertSame(259, $result['volume_weight_grams']);
    }

    public function test_actual_weight_wins_when_greater(): void
    {
        $result = $this->check(gross: 5000);
        $this->assertSame(5000, $result['chargeable_weight_grams']);
    }

    public function test_parcel_locker_limits_allow_600_400_300_mm(): void
    {
        $result = $this->check(
            length: 600,
            width: 400,
            height: 300,
            type: 'parcel_locker'
        );

        $this->assertSame(
            'within_standard_limits',
            $result['local_result']
        );

        $this->assertSame(18000, $result['volume_weight_grams']);
    }

    public function test_parcel_locker_dimensions_can_be_rotated(): void
    {
        $result = $this->check(
            length: 300,
            width: 600,
            height: 400,
            type: 'parcel_locker'
        );

        $this->assertSame(
            'within_standard_limits',
            $result['local_result']
        );
    }

    public function test_parcel_locker_oversized_box_is_blocked(): void
    {
        $result = $this->check(
            length: 601,
            width: 400,
            height: 300,
            type: 'parcel_locker'
        );

        $this->assertSame('blocked', $result['local_result']);
        $this->assertContains(
            'parcel_locker_size_limit',
            $result['reasons']
        );
    }

    public function test_parcel_locker_overweight_is_blocked(): void
    {
        $result = $this->check(
            gross: 20001,
            type: 'parcel_locker'
        );

        $this->assertSame('blocked', $result['local_result']);
        $this->assertContains(
            'parcel_locker_weight_limit',
            $result['reasons']
        );
    }

    public function test_standard_branch_oversized_box_requires_manual_review(): void
    {
        $result = $this->check(
            length: 1201,
            width: 700,
            height: 700
        );

        $this->assertSame('manual_review', $result['local_result']);
        $this->assertContains(
            'standard_branch_size_limit',
            $result['reasons']
        );
    }

    public function test_standard_branch_weight_limit_requires_manual_review(): void
    {
        $result = $this->check(gross: 30001);

        $this->assertSame('manual_review', $result['local_result']);
        $this->assertContains(
            'standard_branch_weight_limit',
            $result['reasons']
        );
    }

    public function test_sku_that_cannot_fit_the_box_is_flagged(): void
    {
        $result = $this->check(
            length: 100,
            width: 100,
            height: 100
        );

        $this->assertSame('manual_review', $result['local_result']);
        $this->assertContains(
            'sku_cannot_fit_outer_dimensions',
            $result['reasons']
        );
    }

    public function test_even_a_pass_is_not_carrier_or_cod_approval(): void
    {
        $result = $this->check();

        $this->assertFalse($result['carrier_acceptance_confirmed']);
        $this->assertFalse($result['cod_eligibility_confirmed']);
        $this->assertFalse($result['declared_value_checked']);
        $this->assertFalse($result['ready_for_dispatch']);
        $this->assertTrue($result['item_fit_check_only']);
    }

    public function test_invalid_dimension_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->check(length: 0);
    }

    public function test_unknown_delivery_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->check(type: 'courier');
    }

    public function test_missing_item_dimensions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate(
            700,
            300,
            200,
            100,
            'branch',
            [[
                'length_mm' => 200,
                'width_mm' => null,
                'height_mm' => 50,
            ]]
        );
    }
}
