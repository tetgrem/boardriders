<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use RuntimeException;

/** HMAC-chain for immutable COD correction appendices. */
final class CheckoutCodDailyCorrectionSigner
{
    public function __construct(private readonly CheckoutCodDailyReportSigner $canonical)
    {
    }

    public function observation(string $reportKey, string $reportHmac, array $observation): string
    {
        return $this->sign(['cod-correction-observation-v46', $reportKey, $reportHmac, $observation]);
    }

    public function appendix(
        string $correctionKey, string $operationKey, string $reportKey,
        string $reportHmac, int $author, string $reason, string $observationHmac,
        array $observation
    ): string {
        return $this->sign([
            'cod-correction-appendix-v46', $correctionKey, $operationKey,
            $reportKey, $reportHmac, $author, $reason, $observationHmac, $observation,
        ]);
    }

    public function event(
        string $correctionKey, string $eventKey, string $kind, int $actor,
        string $reason, ?string $previous, string $appendixHmac
    ): string {
        return $this->sign([
            'cod-correction-event-v46', $correctionKey, $eventKey,
            $kind, $actor, $reason, $previous, $appendixHmac,
        ]);
    }

    public function canonicalJson(array $data): string
    {
        return $this->canonical->canonicalJson($data);
    }

    private function sign(array $data): string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('COD correction signing key missing');
        }
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        if (!is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('COD correction signing key too short');
        }
        return hash_hmac('sha256', $this->canonicalJson($data), $key);
    }
}
