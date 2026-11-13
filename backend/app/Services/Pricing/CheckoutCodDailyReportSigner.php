<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use RuntimeException;

/** Versioned keyed integrity for archived COD operational reports. */
final class CheckoutCodDailyReportSigner
{
    public function snapshot(
        string $date,
        string $operationKey,
        int $sealedBy,
        array $payload,
        array $reconciliation
    ): string {
        return $this->digest([
            'cod-daily-snapshot-v45', 1, $date, strtolower($operationKey),
            $sealedBy, $payload, $reconciliation,
        ]);
    }

    public function event(
        string $eventKey,
        string $eventKind,
        string $reportKey,
        int $actorId,
        string $snapshotHmac
    ): string {
        return $this->digest([
            'cod-daily-audit-v45', 1, strtolower($eventKey), $eventKind,
            strtolower($reportKey), $actorId, $snapshotHmac,
        ]);
    }

    /** Deterministic across PHP associative-key order and PostgreSQL jsonb. */
    public function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function digest(mixed $value): string
    {
        $configured = config('app.key');
        if (!is_string($configured) || $configured === '') {
            throw new RuntimeException('COD report integrity signing key is missing');
        }

        if (str_starts_with($configured, 'base64:')) {
            $secret = base64_decode(substr($configured, 7), true);
        } else {
            $secret = $configured;
        }

        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('COD report integrity signing key must be at least 32 bytes');
        }

        return hash_hmac('sha256', $this->canonicalJson($value), $secret);
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
