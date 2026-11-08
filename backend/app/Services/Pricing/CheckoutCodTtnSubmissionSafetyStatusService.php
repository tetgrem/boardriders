<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Side-effect-free diagnostic for staged TTN intents and operator safety events. */
final class CheckoutCodTtnSubmissionSafetyStatusService
{
    public function __construct(
        private readonly CheckoutCodTtnAuthorizationPreflightService $preflight,
        private readonly CheckoutCodTtnIntentFingerprint $fingerprints,
        private readonly CheckoutCodTtnSubmissionSafetyPolicy $policy,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(string $orderKey): array
    {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid COD order UUID');
        }
        $orderKey = strtolower($orderKey);

        return DB::transaction(function () use ($orderKey): array {
            $order = DB::table('orders')->where('order_key', $orderKey)->first(['id']);
            if ($order === null) {
                throw new DomainException('COD order not found');
            }
            $intent = DB::table('checkout_cod_ttn_submission_intents')
                ->where('order_id', $order->id)->first();
            if ($intent === null) {
                return [
                    'order_id' => (int) $order->id,
                    'intent_id' => null,
                    'intent_key' => null,
                    'status' => 'not_prepared',
                    'blockers' => ['submission_intent_missing',
                        'trusted_issuer_confirmation_not_connected',
                        'live_ttn_transport_not_connected'],
                    'local_context_current' => false,
                    'automatic_retry_allowed' => false,
                    'provider_call_made_by_this_module' => false,
                    'ttn_submission_allowed' => false,
                    'carrier_waybill_created' => false,
                    'ttn_number' => null,
                    'ready_for_dispatch' => false,
                ];
            }

            $report = $this->preflight->inspect($orderKey);
            $current = false;
            if (($report['all_local_pre_submission_checks_current'] ?? null) === true) {
                try {
                    $snapshot = $this->fingerprints->capture($orderKey, $report);
                    $current = hash_equals(
                            (string) $intent->context_hmac,
                            (string) $snapshot['context_hmac']
                        ) && (int) $intent->draft_id === $snapshot['ids']['draft_id']
                        && (int) $intent->binding_id === $snapshot['ids']['binding_id']
                        && (int) $intent->observation_id === $snapshot['ids']['observation_id']
                        && (int) $intent->sender_profile_id === $snapshot['ids']['sender_profile_id'];
                } catch (DomainException) {
                    $current = false;
                }
            }
            $eventTypes = DB::table('checkout_cod_ttn_submission_safety_events')
                ->where('intent_id', $intent->id)->orderBy('id')
                ->pluck('event_type')->all();
            return array_merge([
                'order_id' => (int) $order->id,
                'intent_id' => (int) $intent->id,
                'intent_key' => (string) $intent->intent_key,
                'safety_event_count' => count($eventTypes),
            ], $this->policy->evaluate($current, $eventTypes));
        }, 3);
    }
}
