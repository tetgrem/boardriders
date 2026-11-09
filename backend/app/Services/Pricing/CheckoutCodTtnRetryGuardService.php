<?php

namespace App\Services\Pricing;

use DomainException;

/** Explicit fail-closed boundary for any future COD TTN retry transport. */
final class CheckoutCodTtnRetryGuardService
{
    public function __construct(
        private readonly CheckoutCodTtnResolutionStatusService $status
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        $report = $this->status->inspect($orderKey);

        return [
            'intent_id' => $report['intent_id'],
            'resolution_status' => $report['status'],
            'blockers' => [
                'reconciliation_hold_not_released',
                'authoritative_carrier_outcome_not_established',
                'trusted_issuer_confirmation_not_connected',
                'live_ttn_submission_not_implemented',
            ],
            'automatic_retry_allowed' => false,
            'manual_retry_allowed' => false,
            'ttn_submission_allowed' => false,
        ];
    }

    /** Never acts as a bypass for InternetDocument/save. */
    public function assertMaySubmit(string $orderKey): void
    {
        $this->inspect($orderKey);

        throw new DomainException(
            'COD TTN retry is blocked pending trusted carrier reconciliation'
        );
    }
}
