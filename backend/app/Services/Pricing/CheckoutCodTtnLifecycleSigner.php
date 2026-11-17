<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;

final class CheckoutCodTtnLifecycleSigner
{
    public function __construct(private readonly CheckoutCodDailyReportSigner $canonical)
    {
    }

    public function json(array $payload): string
    {
        return $this->canonical->canonicalJson($payload);
    }

    public function digest(array $payload): string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') {
            throw new DomainException('COD lifecycle signing key missing');
        }
        $secret = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true) : $key;
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new DomainException('COD lifecycle signing key invalid');
        }
        return hash_hmac('sha256', $this->json([
            'domain' => 'cod-ttn-lifecycle-v51',
            'payload' => $payload,
        ]), $secret);
    }
}
