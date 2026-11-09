<?php

namespace App\Services\Pricing;

use DomainException;

/** Must guard any future InternetDocument/save entrypoint. */
final class CheckoutCodTtnDispatchTransportGuard
{
    public function __construct(
        private readonly CheckoutCodTtnDispatchReadinessService $readiness
    ) {
    }

    /** @return never */
    public function assertMaySend(string $orderKey): never
    {
        $this->readiness->inspect($orderKey);

        throw new DomainException(
            'TTN submission is disabled: trusted issuer/COD authorization and one-shot transport are not connected'
        );
    }
}
