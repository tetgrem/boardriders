<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Financial exposure estimates only; neither a refund nor a payment instruction. */
final class CheckoutCodAdjustmentPlanPolicy
{
    /** @return array<string,int|string|bool> */
    public function project(
        string $kind,
        int $grossMinor,
        int $feeMinor,
        int $netMinor,
        int $sourceNetMinor,
        int $recommendedNetMinor,
        int $requestedRefundMinor
    ): array {
        if ($grossMinor < 1 || $grossMinor > 100000000
            || $feeMinor < 0 || $netMinor < 1
            || $feeMinor + $netMinor !== $grossMinor
            || min($sourceNetMinor, $recommendedNetMinor, $requestedRefundMinor) < 0
            || max($sourceNetMinor, $recommendedNetMinor, $requestedRefundMinor) > 100000000) {
            throw new InvalidArgumentException('Invalid COD adjustment amounts');
        }

        if ($kind === 'return_case_followup') {
            if ($requestedRefundMinor < 1 || $requestedRefundMinor > $grossMinor
                || $recommendedNetMinor !== $sourceNetMinor) {
                throw new InvalidArgumentException('Invalid COD customer-return exposure');
            }
            $status = 'customer_refund_claim_unverified';
        } elseif ($kind === 'amount_correction') {
            if ($requestedRefundMinor !== 0 || $recommendedNetMinor === $sourceNetMinor) {
                throw new InvalidArgumentException('Invalid COD amount correction');
            }
            $status = 'net_amount_correction_unverified';
        } elseif ($kind === 'reported_reversal') {
            if ($requestedRefundMinor !== 0 || $recommendedNetMinor !== 0
                || $sourceNetMinor === 0) {
                throw new InvalidArgumentException('Invalid COD reported reversal');
            }
            $status = 'bank_reversal_claim_unverified';
        } else {
            throw new InvalidArgumentException('Unsupported COD adjustment kind');
        }

        return [
            'result_code' => $status,
            'original_gross_minor' => $grossMinor,
            'original_fee_minor' => $feeMinor,
            'original_order_net_minor' => $netMinor,
            'source_net_minor' => $sourceNetMinor,
            'recommended_source_net_minor' => $recommendedNetMinor,
            'proposed_customer_refund_minor' => $requestedRefundMinor,
            'assumed_fee_recovery_minor' => 0,
            'source_net_difference_minor' => $recommendedNetMinor - $sourceNetMinor,
            'bank_origin_authenticated' => false,
            'customer_refund_executed' => false,
            'financial_ledger_changed' => false,
            'payment_posting_allowed' => false,
        ];
    }
}
