<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Decimal-free, deterministic reporting ratios in basis points (10000 = 100%). */
final class CheckoutCodAnalyticsMath
{
    public static function percentBasisPoints(int $part, int $total): ?int
    {
        if ($total < 0 || $part < 0 || $part > $total) {
            throw new InvalidArgumentException('Invalid COD analytics ratio');
        }

        if ($total === 0) {
            return null;
        }

        // Count-only KPI inputs; cap to protect multiplication on 64-bit PHP.
        if ($part > intdiv(PHP_INT_MAX, 10000)) {
            throw new InvalidArgumentException('COD analytics ratio exceeds safe integer range');
        }

        return intdiv($part * 10000 + intdiv($total, 2), $total);
    }

    public static function averageMinutes(int $totalMinutes, int $cases): ?int
    {
        if ($totalMinutes < 0 || $cases < 0) {
            throw new InvalidArgumentException('Invalid COD analytics duration');
        }

        return $cases === 0 ? null : intdiv($totalMinutes, $cases);
    }
}
