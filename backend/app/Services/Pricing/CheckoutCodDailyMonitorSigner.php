<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use RuntimeException;

/** Independently signed evidence monitoring, bound to archived report HMAC. */
final class CheckoutCodDailyMonitorSigner
{
    public function __construct(private readonly CheckoutCodDailyReportSigner $canonical)
    {
    }

    public function signal(
        string $reportKey,
        string $reportHmac,
        string $observationHmac,
        string $coverageStatus,
        string $severity,
        string $nextAction
    ): string {
        return $this->digest([
            'cod-daily-monitor-signal-v47', 1, $reportKey, $reportHmac,
            $observationHmac, $coverageStatus, $severity, $nextAction,
        ]);
    }

    public function receipt(string $signalHmac, string $operationKey, int $actor): string
    {
        return $this->digest([
            'cod-daily-monitor-ack-v47', 1, $signalHmac,
            strtolower($operationKey), $actor,
        ]);
    }

    private function digest(array $payload): string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('Missing COD monitoring signing key');
        }
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        if (!is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('COD monitoring signing key too short');
        }
        return hash_hmac('sha256', $this->canonical->canonicalJson($payload), $key);
    }
}
