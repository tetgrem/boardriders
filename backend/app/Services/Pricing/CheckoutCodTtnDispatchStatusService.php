<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Operator-visible, PII-free plan state. */
final class CheckoutCodTtnDispatchStatusService
{
    public function __construct(
        private readonly CheckoutCodTtnDispatchReadinessService $readiness
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        $user = Auth::user();
        $actor = $user?->getAuthIdentifier();

        if (
            (!is_int($actor) && !(is_string($actor) && ctype_digit($actor)))
            || (int) $actor <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Dispatch status view permission denied'
            );
        }

        $readiness = $this->readiness->inspect($orderKey);

        $plan = $readiness['intent_id'] === null
            ? null
            : DB::table('checkout_cod_ttn_dispatch_plans')
                ->where('intent_id', $readiness['intent_id'])
                ->first(['id', 'operation_key', 'prepared_by']);

        $review = $plan === null
            ? null
            : DB::table('checkout_cod_ttn_dispatch_reviews')
                ->where('plan_id', $plan->id)
                ->first(['decision', 'reason_code', 'reviewed_by']);

        $status = match (true) {
            $readiness['status'] !== 'awaiting_independent_review'
            => $readiness['status'],
            $plan === null => 'awaiting_dispatch_plan',
            $review?->decision === 'rejected' => 'rejected',
            default => $readiness['status'],
        };

        return [
            'order_id' => $readiness['order_id'],
            'intent_id' => $readiness['intent_id'],
            'plan_id' => $plan?->id,
            'review_decision' => $review?->decision,
            'status' => $status,
            'blockers' => $readiness['blockers'],
            'local_context_current' => $readiness['local_context_current'],
            'provider_call_made' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
