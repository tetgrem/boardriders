<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** PII-free OMS projection: internal custody is not carrier acceptance. */
final class CheckoutCodFulfillmentStatusService
{
    public function __construct(
        private readonly CheckoutCodFulfillmentSourceService $sources,
        private readonly CheckoutCodFulfillmentStagePolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
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
                'COD fulfillment status permission denied'
            );
        }

        $source = $this->sources->inspect($orderKey);

        $stage = DB::table('checkout_cod_fulfillment_staging')
            ->where('order_id', $source['order_id'])
            ->first([
                'id',
                'pack_check_id',
                'source_hmac',
            ]);

        $review = $stage === null
            ? null
            : DB::table('checkout_cod_fulfillment_staging_reviews')
                ->where('staging_id', $stage->id)
                ->first(['decision']);

        $held = $stage !== null
            && DB::table('checkout_cod_fulfillment_holds')
                ->where('staging_id', $stage->id)
                ->exists();

        $current = $source['pack_current'] === true
            && $stage !== null
            && (int) $stage->pack_check_id
            === (int) $source['pack_check_id']
            && hash_equals(
                (string) $stage->source_hmac,
                (string) $source['source_hmac']
            );

        return array_merge([
            'order_id' => $source['order_id'],
            'intent_id' => $source['intent_id'],
            'staging_id' => $stage?->id,
            'review_decision' => $review?->decision,
            'has_internal_hold' => $held,
        ], $this->policy->evaluate(
            $stage === null ? $source['pack_current'] : $current,
            $stage !== null,
            $review?->decision,
            $held
        ));
    }
}
