<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * No PII, no provider HTTP, no mutation.
 * Historical intent is not authorization.
 */
final class CheckoutCodTtnDispatchReadinessService
{
    public function __construct(
        private readonly CheckoutCodTtnAuthorizationPreflightService $preflight,
        private readonly CheckoutCodTtnIntentFingerprint $fingerprints,
        private readonly CheckoutCodTtnDispatchPolicy $policy,
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid COD order UUID');
        }

        $orderKey = strtolower($orderKey);

        $order = DB::table('orders')
            ->where('order_key', $orderKey)
            ->first(['id']);

        if ($order === null) {
            throw new DomainException('COD order does not exist');
        }

        $intent = DB::table('checkout_cod_ttn_submission_intents')
            ->where('order_id', $order->id)
            ->first();

        if ($intent === null) {
            return array_merge([
                'order_id' => (int) $order->id,
                'intent_id' => null,
                'intent_key' => null,
                'context_hmac' => null,
                'local_context_current' => false,
                'events' => [],
            ], $this->policy->evaluate(false, [], false));
        }

        $events = DB::table('checkout_cod_ttn_submission_safety_events')
            ->where('intent_id', $intent->id)
            ->orderBy('id')
            ->pluck('event_type')
            ->all();

        $localCurrent = false;

        try {
            $report = $this->preflight->inspect($orderKey);
        } catch (DomainException|InvalidArgumentException) {
            $report = [];
        }

        if (($report['all_local_pre_submission_checks_current'] ?? null) === true) {
            try {
                $snapshot = $this->fingerprints->capture(
                    $orderKey,
                    $report
                );

                $localCurrent = hash_equals(
                        (string) $intent->context_hmac,
                        (string) $snapshot['context_hmac']
                    )
                    && (int) $intent->draft_id
                    === $snapshot['ids']['draft_id']
                    && (int) $intent->binding_id
                    === $snapshot['ids']['binding_id']
                    && (int) $intent->observation_id
                    === $snapshot['ids']['observation_id']
                    && (int) $intent->sender_profile_id
                    === $snapshot['ids']['sender_profile_id'];

            } catch (DomainException|InvalidArgumentException) {
                $localCurrent = false;
            }
        }

        $reviewed = DB::table('checkout_cod_ttn_dispatch_plans as p')
            ->join(
                'checkout_cod_ttn_dispatch_reviews as r',
                'r.plan_id',
                '=',
                'p.id'
            )
            ->where('p.intent_id', $intent->id)
            ->where('r.decision', 'reviewed_for_future_submission')
            ->exists();

        return array_merge([
            'order_id' => (int) $order->id,
            'intent_id' => (int) $intent->id,
            'intent_key' => (string) $intent->intent_key,
            'context_hmac' => (string) $intent->context_hmac,
            'local_context_current' => $localCurrent,
            'events' => $events,
        ], $this->policy->evaluate(
            $localCurrent,
            $events,
            $reviewed
        ));
    }
}
