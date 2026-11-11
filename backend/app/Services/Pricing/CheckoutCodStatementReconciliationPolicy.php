<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Integer-only three-way COD comparison. Never posts a payment. */
final class CheckoutCodStatementReconciliationPolicy
{
    public const RESULTS = [
        'balanced_unverified', 'bank_underpayment', 'bank_overpayment',
        'novapay_difference', 'fee_difference', 'upstream_unreviewed',
        'evidence_conflict',
    ];

    /** @return array<string,int|string|bool> */
    public function compare(
        int $claimed,
        int $bank,
        int $novaGross,
        int $novaFee,
        int $novaNet,
        int $allocations,
        bool $upstreamReviewed,
        bool $risk
    ): array {
        if ($claimed < 1 || $claimed > 100000000
            || $bank < 1 || $bank > 100000000
            || min($novaGross, $novaFee, $novaNet) < 0
            || max($novaGross, $novaFee, $novaNet) > 1000000000
            || $allocations < 0 || $allocations > 100000
        ) {
            throw new InvalidArgumentException('Invalid COD reconciliation amounts');
        }

        $result = match (true) {
            $risk => 'evidence_conflict',
            !$upstreamReviewed || $allocations === 0 => 'upstream_unreviewed',
            $novaGross < $novaFee || $novaGross - $novaFee !== $novaNet
            => 'fee_difference',
            $novaNet !== $claimed => 'novapay_difference',
            $bank < $claimed => 'bank_underpayment',
            $bank > $claimed => 'bank_overpayment',
            default => 'balanced_unverified',
        };

        return [
            'result_code' => $result,
            'claimed_net_minor' => $claimed,
            'bank_net_minor' => $bank,
            'novapay_gross_minor' => $novaGross,
            'novapay_fee_minor' => $novaFee,
            'novapay_net_minor' => $novaNet,
            'allocation_count' => $allocations,
            'difference_minor' => $bank - $claimed,
            'balanced_unverified' => $result === 'balanced_unverified',
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
