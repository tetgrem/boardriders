<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use RuntimeException;

final class CheckoutCodCorrectionSlaSigner
{
    public function __construct(private readonly CheckoutCodDailyReportSigner $canonical)
    {
    }

    public function stamp(CarbonImmutable|string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    public function alert(
        string $correctionKey,
        string $appendixHmac,
        int $phaseEventId,
        string $phase,
        string $stage,
        string $severity,
        string $targetRole,
        string $dueAt
    ): string {
        return $this->sign([
            'cod-correction-sla-alert-v48', 1, strtolower($correctionKey),
            $appendixHmac, $phaseEventId, $phase, $stage,
            $severity, $targetRole, $dueAt,
        ]);
    }

    public function receipt(string $alertHmac, string $operationKey, int $actor): string
    {
        return $this->sign([
            'cod-correction-sla-receipt-v48', 1,
            $alertHmac, strtolower($operationKey), $actor,
        ]);
    }

    private function sign(array $payload): string
    {
        $secret = config('app.key');
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('COD SLA signing key missing');
        }
        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7), true);
        }
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('COD SLA signing key must contain at least 32 bytes');
        }
        return hash_hmac('sha256', $this->canonical->canonicalJson($payload), $secret);
    }
}
