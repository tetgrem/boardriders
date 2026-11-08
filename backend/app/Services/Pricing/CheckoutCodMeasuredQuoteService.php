<?php

namespace App\Services\Pricing;

use DomainException;

/**
 * Trusted internal bridge. Never expose without customer/order authorization.
 */
final class CheckoutCodMeasuredQuoteService
{
    public function __construct(
        private readonly CheckoutCodParcelManifestService $manifests,
        private readonly CheckoutCodShippingQuoteService $quotes,
    ) {
    }

    /** @return array<string, mixed> */
    public function estimate(
        string $orderKey,
        string $manifestKey,
        string $quoteKey,
        int $packagingGrams,
    ): array {
        // Capture first: provider quote can never precede the manifest in this flow.
        $manifest = $this->manifests->capture(
            $orderKey, $manifestKey, $packagingGrams
        );

        $quote = $this->quotes->quote(
            $orderKey,
            $quoteKey,
            $manifest['gross_grams'],
            1, // One parcel. Multi-seat/split shipment is deliberately unsupported.
        );

        if (
            $quote['order_id'] !== $manifest['order_id']
            || $quote['weight_grams'] !== $manifest['gross_grams']
            || $quote['seats_amount'] !== 1
        ) {
            throw new DomainException('Advisory quote and parcel manifest differ');
        }

        return [
            'manifest' => $manifest,
            'quote' => $quote,
            'estimated_only' => true,
            'cod_eligibility_confirmed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
