<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;

final class CheckoutOrderReturnSigner
{
    public function __construct(private readonly CheckoutCodDailyReportSigner $canonical)
    {
    }

    public function json(array $data): string
    {
        return $this->canonical->canonicalJson($data);
    }

    public function digest(array $data): string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') {
            throw new DomainException('Returns signing key missing');
        }
        $secret = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true) : $key;
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new DomainException('Returns signing key invalid');
        }
        return hash_hmac('sha256', $this->json([
            'domain' => 'boardriders-rma52', 'version' => 1, 'data' => $data,
        ]), $secret);
    }
}
