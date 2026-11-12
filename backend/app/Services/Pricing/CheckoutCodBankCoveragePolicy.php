<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Pure integer arithmetic for a bank credit spanning one or many COD orders. */
final class CheckoutCodBankCoveragePolicy
{
    /** @return array<string,int|string|bool> */
    public function evaluate(
        int $bankMinor,
        int $claimedMinor,
        int $grossMinor,
        int $feeMinor,
        int $allocatedMinor,
        int $expectedOrders,
        int $readyOrders,
        bool $sourceCurrent,
        bool $risk
    ): array {
        if (
            min($bankMinor, $claimedMinor, $grossMinor, $feeMinor, $allocatedMinor) < 0
            || max($bankMinor, $claimedMinor, $grossMinor, $feeMinor, $allocatedMinor) > 1000000000
            || $bankMinor === 0 || $claimedMinor === 0
            || $expectedOrders < 1 || $expectedOrders > 100000
            || $readyOrders < 0 || $readyOrders > $expectedOrders
        ) {
            throw new InvalidArgumentException('Invalid COD bank coverage amounts');
        }

        $status = match (true) {
            $risk => 'financial_risk_hold',
            !$sourceCurrent => 'source_reconciliation_stale',
            $readyOrders !== $expectedOrders => 'preflight_coverage_incomplete',
            $bankMinor !== $claimedMinor => 'bank_credit_amount_mismatch',
            $allocatedMinor > $bankMinor => 'bank_allocation_overflow',
            $allocatedMinor < $bankMinor => 'bank_allocation_shortfall',
            $feeMinor > $grossMinor || $grossMinor - $feeMinor !== $allocatedMinor
            => 'gross_fee_net_discrepancy',
            default => 'coverage_balanced_unverified',
        };

        return [
            'result_code' => $status,
            'bank_net_minor' => $bankMinor,
            'claimed_net_minor' => $claimedMinor,
            'gross_minor' => $grossMinor,
            'fee_minor' => $feeMinor,
            'allocated_minor' => $allocatedMinor,
            'difference_minor' => $bankMinor - $allocatedMinor,
            'expected_orders_count' => $expectedOrders,
            'ready_orders_count' => $readyOrders,
            'can_compile' => $status === 'coverage_balanced_unverified',
            'bank_origin_authenticated' => false,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }
}
