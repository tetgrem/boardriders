<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Checks per-order COD gross / carrier fee / merchant net.
 * This is NOT an authorization to mark an order paid.
 */
final class CheckoutCodPostingPreflightPolicy
{
    /** @return array<string, int|string|bool> */
    public function evaluate(
        int $expectedGross,
        int $observedGross,
        int $fee,
        int $net,
        bool $reviewed,
        bool $orderPending
    ): array {
        if (
            min($expectedGross, $observedGross, $fee, $net) < 0
            || max($expectedGross, $observedGross, $fee, $net) > 100000000
            || $expectedGross === 0
        ) {
            throw new InvalidArgumentException(
                'Invalid COD posting preflight amounts'
            );
        }

        $result = match (true) {
            !$orderPending => 'order_not_pending',
            !$reviewed => 'reconciliation_not_current',
            $observedGross !== $expectedGross => 'cod_gross_mismatch',
            $fee > $observedGross => 'cod_fee_exceeds_gross',
            $observedGross - $fee !== $net => 'cod_net_mismatch',
            default => 'comparison_complete_unverified',
        };

        return [
            'result_code' => $result,
            'expected_cod_minor' => $expectedGross,
            'observed_cod_minor' => $observedGross,
            'fee_minor' => $fee,
            'bank_allocation_minor' => $net,
            'difference_minor' => $observedGross - $expectedGross,
            'can_prepare_review' =>
                $result === 'comparison_complete_unverified',
            'bank_origin_authenticated' => false,
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }
}
