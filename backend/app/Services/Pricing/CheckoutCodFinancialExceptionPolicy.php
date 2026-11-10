<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Decisions about financial claims, never real refunds or posted payments.
 */
final class CheckoutCodFinancialExceptionPolicy
{
    public const SOURCES = [
        'single',
        'batch',
        'novapay',
    ];

    public const REASONS = [
        'incorrect_amount',
        'bank_reversal_reported',
        'order_cancellation_reported',
        'customer_return_reported',
        'wrong_source_reference',
    ];

    public function assertReport(string $source, string $reason): void
    {
        if (
            !in_array($source, self::SOURCES, true)
            || !in_array($reason, self::REASONS, true)
        ) {
            throw new InvalidArgumentException(
                'Invalid COD financial exception report'
            );
        }
    }

    public function assertReview(string $decision, string $reason): void
    {
        if (!(
            (
                $decision === 'hold_for_investigation'
                && $reason === 'await_external_evidence'
            ) || (
                $decision === 'dismiss_report'
                && $reason === 'report_not_supported'
            )
        )) {
            throw new InvalidArgumentException(
                'Invalid COD financial exception review'
            );
        }
    }

    public function status(?string $reviewDecision): string
    {
        return match ($reviewDecision) {
            null => 'pending_review_hold',
            'hold_for_investigation' => 'investigation_hold',
            'dismiss_report' => 'dismissed',
            default => throw new InvalidArgumentException(
                'Unknown exception decision'
            ),
        };
    }
}
