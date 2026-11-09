<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** PII-free packing status for future warehouse CRM. */
final class CheckoutCodWarehousePackStatusService
{
    public function __construct(
        private readonly CheckoutCodWarehousePackReadinessService $readiness
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        $this->authorize();

        $source = $this->readiness->inspect($orderKey);

        $check = DB::table('checkout_cod_warehouse_pack_checks')
            ->where('order_id', $source['order_id'])
            ->first(['id', 'source_hmac']);

        $review = $check === null
            ? null
            : DB::table('checkout_cod_warehouse_pack_reviews')
                ->where('pack_check_id', $check->id)
                ->first(['decision']);

        $current = $check !== null
            && $source['source_ready'] === true
            && hash_equals(
                (string) $check->source_hmac,
                (string) $source['source_hmac']
            );

        $state = match (true) {
            $check === null && !$source['source_ready']
            => 'awaiting_verified_dispatch_plan',

            $check === null
            => 'awaiting_physical_pack_check',

            !$current
            => 'pack_evidence_stale_or_held',

            $review === null
            => 'awaiting_independent_pack_review',

            $review->decision === 'rejected'
            => 'pack_rejected',

            default
            => 'internal_pack_verified',
        };

        return [
            'order_id' => $source['order_id'],
            'intent_id' => $source['intent_id'],
            'pack_check_id' => $check?->id,
            'review_decision' => $review?->decision,
            'status' => $state,
            'source_current' => $current,
            'internal_pick_slip_available' =>
                $current && $review?->decision === 'accepted',
            'carrier_label_available' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Warehouse status permission denied'
            );
        }
    }
}
