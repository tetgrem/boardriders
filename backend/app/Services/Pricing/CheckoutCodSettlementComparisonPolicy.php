<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Integer-only comparison of EXPECTED amounts against UNVERIFIED claims.
 * This never proves that the carrier collected or transferred money.
 */
final class CheckoutCodSettlementComparisonPolicy
{
    public const SOURCES = [
        'novapay_statement',
        'bank_statement',
        'manual_claim',
    ];

    public const RESULTS = [
        'consistent_with_estimate',
        'gross_mismatch',
        'net_arithmetic_mismatch',
        'fee_differs_from_estimate',
        'fee_not_estimated',
    ];

    /**
     * All amounts are integer minor units (kopiykas).
     *
     * @return array<string,mixed>
     */
    public function compare(
        int $expectedGross,
        ?int $expectedFee,
        int $claimedGross,
        int $claimedFee,
        int $claimedNet,
        string $currency
    ): array {
        if (
            $currency !== 'UAH'
            || $expectedGross < 100
            || $expectedGross > 100000000
            || (
                $expectedFee !== null
                && ($expectedFee < 0 || $expectedFee > 100000000)
            )
            || min($claimedGross, $claimedFee, $claimedNet) < 0
            || max($claimedGross, $claimedFee, $claimedNet) > 100000000
        ) {
            throw new InvalidArgumentException(
                'Invalid COD settlement values'
            );
        }

        $arithmeticCorrect = $claimedFee <= $claimedGross
            && $claimedNet === $claimedGross - $claimedFee;

        $result = match (true) {
            $claimedGross !== $expectedGross
            => 'gross_mismatch',
            !$arithmeticCorrect
            => 'net_arithmetic_mismatch',
            $expectedFee === null
            => 'fee_not_estimated',
            $claimedFee !== $expectedFee
            => 'fee_differs_from_estimate',
            default
            => 'consistent_with_estimate',
        };

        return [
            'comparison_result' => $result,
            'expected_gross_minor' => $expectedGross,
            'expected_fee_minor' => $expectedFee,
            'claimed_gross_minor' => $claimedGross,
            'claimed_fee_minor' => $claimedFee,
            'claimed_net_minor' => $claimedNet,
            'gross_difference_minor' => $claimedGross - $expectedGross,
            'fee_difference_minor' => $expectedFee === null
                ? null
                : $claimedFee - $expectedFee,
            'claimed_arithmetic_correct' => $arithmeticCorrect,
            'amounts_match_estimate' =>
                $result === 'consistent_with_estimate',
            'source_authenticity_verified' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
            'automatic_settlement_allowed' => false,
        ];
    }

    public function reviewDecision(
        string $result,
        string $decision,
        string $reason
    ): void {
        if (!in_array($result, self::RESULTS, true)) {
            throw new InvalidArgumentException(
                'Unknown settlement comparison'
            );
        }

        $valid = match ($result) {
            'consistent_with_estimate', 'fee_not_estimated' =>
                $decision === 'retain_unverified'
                && $reason === 'external_confirmation_required',

            'gross_mismatch' =>
                $decision === 'escalate_discrepancy'
                && $reason === 'gross_amount_mismatch',

            'net_arithmetic_mismatch' =>
                $decision === 'escalate_discrepancy'
                && $reason === 'net_amount_mismatch',

            'fee_differs_from_estimate' =>
                $decision === 'escalate_discrepancy'
                && $reason === 'fee_amount_mismatch',
        };

        if (!$valid) {
            throw new InvalidArgumentException(
                'Review decision does not match the financial comparison'
            );
        }
    }
}
