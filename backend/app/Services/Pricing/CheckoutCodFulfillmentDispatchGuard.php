<?php

namespace App\Services\Pricing;

use DomainException;

/** Explicit boundary: never calls InventoryService::shipOrderReservation. */
final class CheckoutCodFulfillmentDispatchGuard
{
    public function __construct(
        private readonly CheckoutCodFulfillmentStatusService $statuses
    ) {
    }

    public function assertMayHandoverToCarrier(string $orderKey): never
    {
        $this->statuses->inspect($orderKey);

        throw new DomainException(
            'COD carrier handoff blocked: verified TTN and carrier acceptance are not available'
        );
    }
}
