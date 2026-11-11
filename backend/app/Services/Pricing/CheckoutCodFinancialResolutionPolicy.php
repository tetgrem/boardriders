<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Advice on an unverified claim, never authorization to move money. */
final class CheckoutCodFinancialResolutionPolicy
{
    public const EVIDENCE_KINDS = [
        'bank_notice_claim',
        'novapay_notice_claim',
        'order_record_claim',
        'internal_reconciliation',
    ];

    public function evidence(string $kind, string $sha256): void
    {
        if (
            !in_array($kind, self::EVIDENCE_KINDS, true)
            || !preg_match('/^[a-f0-9]{64}$/D', $sha256)
        ) {
            throw new InvalidArgumentException(
                'Invalid resolution evidence metadata'
            );
        }
    }

    public function proposal(
        string $sourceKind,
        string $exceptionReason,
        string $kind,
        int $originalMinor,
        int $recommendedMinor
    ): void {
        if (
            $originalMinor < 0
            || $originalMinor > 100000000
            || $recommendedMinor < 0
            || $recommendedMinor > 100000000
        ) {
            throw new InvalidArgumentException(
                'Invalid proposed amount'
            );
        }

        $valid = match ($kind) {
            'amount_correction' => in_array(
                $exceptionReason,
                ['incorrect_amount', 'wrong_source_reference'],
                true
            ),

            'reported_reversal' =>
                $sourceKind !== 'novapay'
                && $exceptionReason === 'bank_reversal_reported'
                && $recommendedMinor === 0,

            'batch_reallocation' =>
                $sourceKind === 'batch'
                && $recommendedMinor === $originalMinor,

            'return_case_followup' => in_array(
                    $exceptionReason,
                    ['customer_return_reported', 'order_cancellation_reported'],
                    true
                ) && $recommendedMinor === $originalMinor,

            default => false,
        };

        if (!$valid) {
            throw new InvalidArgumentException(
                'Invalid correction recommendation'
            );
        }
    }

    public function review(string $decision, string $reason): void
    {
        if (!(
            (
                $decision === 'ready_for_external_verification'
                && $reason === 'recommendation_documented'
            )
            || (
                $decision === 'return_for_rework'
                && $reason === 'insufficient_consistency'
            )
        )) {
            throw new InvalidArgumentException(
                'Invalid resolution review'
            );
        }
    }
}
