<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pricing\CheckoutCodAnalyticsMath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CheckoutCodAnalyticsMathTest extends TestCase
{
    public function test_cod_analytics44_zero_denominator_is_unknown_not_zero_percent(): void
    {
        $this->assertNull(CheckoutCodAnalyticsMath::percentBasisPoints(0, 0));
    }

    public function test_cod_analytics44_rate_uses_integer_basis_points(): void
    {
        $this->assertSame(3333, CheckoutCodAnalyticsMath::percentBasisPoints(1, 3));
        $this->assertSame(6667, CheckoutCodAnalyticsMath::percentBasisPoints(2, 3));
    }

    public function test_cod_analytics44_full_rate_is_100_percent(): void
    {
        $this->assertSame(10000, CheckoutCodAnalyticsMath::percentBasisPoints(7, 7));
    }

    public function test_cod_analytics44_rejects_excess_numerator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CheckoutCodAnalyticsMath::percentBasisPoints(2, 1);
    }

    public function test_cod_analytics44_mean_minutes_truncates_deterministically(): void
    {
        $this->assertSame(7, CheckoutCodAnalyticsMath::averageMinutes(23, 3));
        $this->assertNull(CheckoutCodAnalyticsMath::averageMinutes(0, 0));
    }
}
