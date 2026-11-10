<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Compares UNVERIFIED operator-supplied NovaPay and bank claims in kopiykas. */
final class CheckoutCodBankSettlementPolicy
{
    public const RESULTS = [
        'amounts_match_unverified',
        'bank_net_mismatch',
        'source_claim_discrepancy',
    ];

    /** @return array<string,mixed> */
    public function compare(
        int $novaPayNetMinor,
        int $bankNetMinor,
        string $novaPayComparison,
        string $currencyCode
    ): array {
        if (
            $currencyCode !== 'UAH'
            || min($novaPayNetMinor, $bankNetMinor) < 0
            || max($novaPayNetMinor, $bankNetMinor) > 100000000
            || !in_array(
                $novaPayComparison,
                CheckoutCodSettlementComparisonPolicy::RESULTS,
                true
            )
        ) {
            throw new InvalidArgumentException('Invalid COD bank matching values');
        }

        $result = match (true) {
            $novaPayComparison !== 'consistent_with_estimate'
            => 'source_claim_discrepancy',
            $novaPayNetMinor !== $bankNetMinor
            => 'bank_net_mismatch',
            default => 'amounts_match_unverified',
        };

        return [
            'match_result' => $result,
            'novapay_net_minor' => $novaPayNetMinor,
            'bank_net_minor' => $bankNetMinor,
            'difference_minor' => $bankNetMinor - $novaPayNetMinor,
            'amounts_equal' => $novaPayNetMinor === $bankNetMinor,
            'external_source_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }

    public function assertReview(string $result, string $decision, string $reason): void
    {
        $valid = match ($result) {
            'amounts_match_unverified' =>
                $decision === 'retain_external_confirmation'
                && $reason === 'bank_and_novapay_unverified',
            'bank_net_mismatch', 'source_claim_discrepancy' =>
                $decision === 'escalate_difference' && $reason === $result,
            default => false,
        };

        if (!$valid) {
            throw new InvalidArgumentException('Invalid bank reconciliation review');
        }
    }
}
