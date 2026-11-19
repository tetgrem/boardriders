<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** One-shot provider POST. Never enable HTTP retries for monetary operations. */
final class CheckoutMonoRefund54Gateway
{
    public function cancelOnce(
        string $invoiceId,
        string $extRef,
        int $refundMinor,
        int $capturedMinor,
    ): string {
        if (
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D', $invoiceId)
            || !preg_match('/^[a-f0-9]{32}$/D', $extRef)
            || $refundMinor <= 0
            || $refundMinor > $capturedMinor
        ) {
            throw new DomainException(
                'Invalid provider refund identity or amount'
            );
        }

        $token = config('services.mono_acquiring.token');

        if (!is_string($token) || trim($token) === '') {
            throw new DomainException('Mono merchant token missing');
        }

        $body = [
            'invoiceId' => $invoiceId,
            'extRef' => $extRef,
        ];

        if ($refundMinor < $capturedMinor) {
            $body['amount'] = $refundMinor;
        }

        $response = Http::withHeaders([
            'X-Token' => $token,
        ])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(12)
            ->post(
                'https://api.monobank.ua/api/merchant/invoice/cancel',
                $body
            );

        // Even 400, 500 or timeout may have an uncertain payment outcome.
        // Caller must never resend: only reconcile through invoice/status.
        if (!$response->successful()) {
            throw new RuntimeException(
                'Mono refund submission outcome uncertain'
            );
        }

        $data = $response->json();

        if (
            !is_array($data)
            || !in_array(
                $data['status'] ?? null,
                ['processing', 'success', 'failure'],
                true
            )
        ) {
            throw new RuntimeException(
                'Mono refund response cannot be trusted as final'
            );
        }

        return (string) $data['status'];
    }
}
