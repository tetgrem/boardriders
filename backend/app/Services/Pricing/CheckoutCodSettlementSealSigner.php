<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use RuntimeException;

final class CheckoutCodSettlementSealSigner
{
    public function __construct(private readonly CheckoutCodDailyReportSigner $canonical)
    {
    }

    public function digest(string $sealKey, string $operationKey, int $actor, array $snapshot): string
    {
        $configured = config('app.key');
        if (!is_string($configured) || $configured === '') {
            throw new RuntimeException('COD settlement seal signing key missing');
        }
        $secret = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true) : $configured;
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('COD settlement seal signing key too short');
        }
        return hash_hmac('sha256', $this->canonical->canonicalJson([
            'cod-settlement-internal-allocation-v50', 1,
            strtolower($sealKey), strtolower($operationKey), $actor, $snapshot,
        ]), $secret);
    }

    public function json(array $snapshot): string
    {
        return $this->canonical->canonicalJson($snapshot);
    }
}
