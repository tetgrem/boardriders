<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/** Internal COD handoff state machine. No carrier acceptance or stock shipment. */
final class CheckoutCodFulfillmentStagePolicy
{
    /** @return array<string,mixed> */
    public function evaluate(
        bool $packCurrent,
        bool $staged,
        ?string $review,
        bool $held
    ): array {
        if (
            $review !== null
            && !in_array($review, ['accepted', 'rejected'], true)
        ) {
            throw new InvalidArgumentException('Invalid fulfillment review');
        }

        if (!$staged && ($review !== null || $held)) {
            throw new InvalidArgumentException(
                'Fulfillment state without staging'
            );
        }

        $status = match (true) {
            !$packCurrent => 'pack_source_not_current',
            !$staged => 'awaiting_internal_staging',
            $held => 'internal_handoff_hold',
            $review === 'rejected' => 'staging_rejected',
            $review === null => 'awaiting_independent_staging_review',
            default => 'internal_staging_reviewed',
        };

        return [
            'status' => $status,
            'pack_source_current' => $packCurrent,
            'internal_staging_reviewed' =>
                $packCurrent && !$held && $review === 'accepted',
            'carrier_acceptance_verified' => false,
            'actual_ttn_verified' => false,
            'cod_payment_confirmed' => false,
            'inventory_shipped' => false,
            'carrier_handoff_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
