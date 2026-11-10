<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Pure integer-only projection for an unverified batch bank claim. */
final class CheckoutCodBankBatchPolicy
{
    /** @return array<string, mixed> */
    public function evaluate(
        int $claimedNetMinor,
        int $allocatedMinor,
        int $count
    ): array {
        if (
            $claimedNetMinor < 1
            || $claimedNetMinor > 100000000
            || $allocatedMinor < 0
            || $allocatedMinor > $claimedNetMinor
            || $count < 0
            || ($count === 0 && $allocatedMinor !== 0)
            || ($count > 0 && $allocatedMinor === 0)
        ) {
            throw new InvalidArgumentException('Invalid bank batch totals');
        }

        $remaining = $claimedNetMinor - $allocatedMinor;
        $complete = $count > 0 && $remaining === 0;

        return [
            'claimed_net_minor' => $claimedNetMinor,
            'allocated_minor' => $allocatedMinor,
            'remaining_minor' => $remaining,
            'allocation_count' => $count,
            'decision' => $complete
                ? 'allocation_balanced_unverified'
                : 'allocation_partial_unverified',
            'reason_code' => $complete
                ? 'external_confirmation_required'
                : 'unallocated_balance',
            'external_source_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
