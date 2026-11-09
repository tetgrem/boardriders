<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Pure fail-closed dispatch readiness policy; never grants carrier submission. */
final class CheckoutCodTtnDispatchPolicy
{
    /**
     * @param list<string> $events
     * @return array<string,mixed>
     */
    public function evaluate(
        bool $localCurrent,
        array $events,
        bool $reviewed
    ): array {
        foreach ($events as $event) {
            if (
                !is_string($event)
                || !array_key_exists(
                    $event,
                    CheckoutCodTtnSubmissionSafetyPolicy::REASONS
                )
            ) {
                throw new InvalidArgumentException(
                    'Unknown COD TTN safety history'
                );
            }
        }

        $state = match (true) {
            in_array('reconciliation_required', $events, true)
            => 'reconciliation_required',

            in_array('cancel_before_submission', $events, true)
            => 'cancelled',

            in_array('safety_hold', $events, true)
            => 'safety_hold',

            !$localCurrent => 'local_context_stale',

            !$reviewed => 'awaiting_independent_review',

            default => 'awaiting_trusted_carrier_authorization',
        };

        return [
            'status' => $state,
            'blockers' => array_values(array_unique(array_merge(
                $state === 'awaiting_trusted_carrier_authorization'
                    ? []
                    : [$state],
                [
                    'trusted_issuer_confirmation_not_connected',
                    'carrier_cod_acceptance_not_verified',
                    'live_ttn_transport_not_connected',
                ]
            ))),
            'reconciliation_hold_released' => false,
            'issuer_contract_verified' => false,
            'carrier_cod_acceptance_verified' => false,
            'provider_call_made' => false,
            'ttn_created' => false,
            'automatic_retry_allowed' => false,
            'manual_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
