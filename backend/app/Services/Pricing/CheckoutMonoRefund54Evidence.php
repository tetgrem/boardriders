<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;

/** Validate a trusted Mono invoice/status response, without storing card data. */
final class CheckoutMonoRefund54Evidence
{
    /**
     * @param array<string,mixed> $status
     * @param array<string,int> $knownRefunds
     * @return array{state:string, matched_amount_minor:?int, evidence_sha256:string}
     */
    public function inspect(
        array $status,
        string $invoiceId,
        string $orderKey,
        int $originalMinor,
        string $extRef,
        int $refundMinor,
        array $knownRefunds,
    ): array {
        if (
            ($status['invoiceId'] ?? null) !== $invoiceId
            || ($status['reference'] ?? null) !== $orderKey
            || ($status['amount'] ?? null) !== $originalMinor
            || ($status['ccy'] ?? null) !== 980
            || !in_array($status['status'] ?? null, ['success', 'reversed'], true)
        ) {
            throw new DomainException('Mono invoice status does not match captured order');
        }

        $cancels = $status['cancelList'] ?? [];

        if (!is_array($cancels) || !array_is_list($cancels)) {
            throw new DomainException('Invalid Mono cancellation list');
        }

        $known = $knownRefunds;
        $found = null;
        $compact = [];

        foreach ($cancels as $cancel) {
            if (
                !is_array($cancel)
                || !is_string($cancel['extRef'] ?? null)
                || !array_key_exists($cancel['extRef'], $known)
                || !is_int($cancel['amount'] ?? null)
                || ($cancel['ccy'] ?? null) !== 980
                || !is_string($cancel['status'] ?? null)
            ) {
                throw new DomainException('Unknown or malformed external Mono refund');
            }

            if (
                $cancel['amount'] <= 0
                || $cancel['amount'] !== $known[$cancel['extRef']]
                || !in_array($cancel['status'], [
                    'processing', 'success', 'failure',
                ], true)
            ) {
                throw new DomainException('Unexpected Mono cancellation state');
            }

            $compact[] = [
                $cancel['extRef'],
                $cancel['amount'],
                $cancel['status'],
            ];

            if ($cancel['extRef'] !== $extRef) {
                continue;
            }

            if ($found !== null || $cancel['amount'] !== $refundMinor) {
                throw new DomainException(
                    'Mono refund reference reused or amount changed'
                );
            }

            $found = $cancel;
        }

        sort($compact);

        $state = $found === null
            ? 'absent'
            : (string) $found['status'];

        $matched = $state === 'success'
            ? $refundMinor
            : null;

        // Only a fingerprint of non-PII fields is kept in audit.
        $fingerprint = hash('sha256', json_encode([
            $invoiceId,
            $orderKey,
            $originalMinor,
            (string) $status['status'],
            $compact,
        ], JSON_THROW_ON_ERROR));

        return [
            'state' => $state,
            'matched_amount_minor' => $matched,
            'evidence_sha256' => $fingerprint,
        ];
    }
}
