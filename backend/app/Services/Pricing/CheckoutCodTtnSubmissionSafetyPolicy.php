<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * A strict internal TTN safety state machine. There is NO 'send' transition.
 * A reported possible attempt is NOT evidence that an API call occurred.
 */
final class CheckoutCodTtnSubmissionSafetyPolicy
{
    public const REASONS = [
        'safety_hold' => ['manual_review', 'stale_details', 'security_hold'],
        'cancel_before_submission' => [
            'customer_cancelled', 'operator_cancelled', 'order_invalidated',
        ],
        'reconciliation_required' => [
            'reported_timeout', 'reported_unknown_outcome', 'possible_duplicate',
        ],
    ];

    public function validate(string $type, string $reason): void
    {
        if (!isset(self::REASONS[$type]) || !in_array($reason, self::REASONS[$type], true)) {
            throw new InvalidArgumentException('Invalid TTN safety event type/reason');
        }
    }

    /**
     * Fail-closed classification for future network integration.
     * Never retry an ambiguous result automatically.
     * This does NOT send a request or assert that one was sent.
     *
     * @return array<string, mixed>
     */
    public function classifyPossibleProviderFailure(string $failure): array
    {
        if (!in_array($failure, [
            'timeout', 'connection_lost', 'http_5xx', 'malformed_response',
            'unconfirmed_http_4xx',
        ], true)) {
            throw new InvalidArgumentException('Unknown TTN provider failure class');
        }
        return [
            'classification' => 'outcome_unknown',
            'manual_reconciliation_required' => true,
            'automatic_retry_allowed' => false,
            'carrier_waybill_created' => null,
            'submission_attempt_proven' => false,
        ];
    }

    /**
     * @param list<string> $eventTypes Historical append-only event types.
     * @return array<string, mixed>
     */
    public function evaluate(bool $currentLocalContext, array $eventTypes): array
    {
        foreach ($eventTypes as $type) {
            if (!is_string($type) || !array_key_exists($type, self::REASONS)) {
                throw new InvalidArgumentException('Unexpected TTN safety history');
            }
        }

        $state = match (true) {
            in_array('reconciliation_required', $eventTypes, true) => 'reconciliation_required',
            in_array('cancel_before_submission', $eventTypes, true) => 'cancelled',
            in_array('safety_hold', $eventTypes, true) => 'safety_hold',
            !$currentLocalContext => 'context_stale',
            default => 'prepared_not_authorized',
        };
        $blockers = $state === 'prepared_not_authorized' ? [] : [$state];
        $blockers[] = 'trusted_issuer_confirmation_not_connected';
        $blockers[] = 'live_ttn_transport_not_connected';

        return [
            'status' => $state,
            'local_context_current' => $currentLocalContext,
            'blockers' => $blockers,
            'has_reconciliation_hold' => in_array('reconciliation_required', $eventTypes, true),
            'provider_call_made_by_this_module' => false,
            'carrier_waybill_created' => false,
            'ttn_number' => null,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
