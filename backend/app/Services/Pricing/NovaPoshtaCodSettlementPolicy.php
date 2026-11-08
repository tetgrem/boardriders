<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Local limit screening only. It never approves NovaPay transfer or COD shipping.
 * Limits are versioned snapshots of published sender-payout channel ceilings.
 */
final class NovaPoshtaCodSettlementPolicy
{
    public const VERSION = 'np-cod-payout-limits-2026-11-v1';

    private const LIMITS_MINOR = [
        'cash_branch' => 39999900,
        'novapay_card' => 10000000,
        'other_ua_card' => 2999900,
    ];

    /** @return array<string, mixed> */
    public function evaluate(
        int $codAmountMinor,
        int $declaredValueMinor,
        string $payoutChannel,
        ?int $estimatedCodFeeMinor,
    ): array {
        if ($codAmountMinor < 100 || $codAmountMinor > 100000000
            || $declaredValueMinor < 100 || $declaredValueMinor > 100000000
            || ($estimatedCodFeeMinor !== null && (
                    $estimatedCodFeeMinor < 0 || $estimatedCodFeeMinor > 100000000
                ))
        ) {
            throw new InvalidArgumentException('Invalid COD amount, declared value or fee');
        }

        if (!array_key_exists($payoutChannel, self::LIMITS_MINOR)) {
            throw new InvalidArgumentException('Unknown COD payout channel');
        }

        $limitMinor = self::LIMITS_MINOR[$payoutChannel];

        $status = $codAmountMinor > $limitMinor
            ? 'blocked_payout_limit'
            : ($estimatedCodFeeMinor === null
                ? 'missing_fee'
                : 'within_published_limit');

        return [
            'policy_version' => self::VERSION,
            'cod_amount_minor' => $codAmountMinor,
            'declared_value_minor' => $declaredValueMinor,
            'payout_channel' => $payoutChannel,
            'payout_limit_minor' => $limitMinor,
            'estimated_cod_fee_minor' => $estimatedCodFeeMinor,
            'local_result' => $status,
            'declared_value_differs_from_cod' => $declaredValueMinor !== $codAmountMinor,
            'declared_value_carrier_verified' => false,
            'payout_channel_contract_verified' => false,
            'cod_eligibility_confirmed' => false,
            'fee_finalized' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
