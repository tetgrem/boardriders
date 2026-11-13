<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/** Tamper-evident, filter-bound keyset cursor. No server-side pagination state. */
final class CheckoutCodFinanceCenterCursor
{
    public function fingerprint(
        string $source,
        string $severity,
        bool $unacknowledged
    ): string {
        return hash(
            'sha256',
            json_encode(
                [$source, $severity, $unacknowledged],
                JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @param array{
     *   priority:int,
     *   observed_at:string,
     *   source:string,
     *   source_id:int
     * } $last
     */
    public function encode(array $last, string $fingerprint): string
    {
        $payload = [
            'v' => 1,
            'p' => $last['priority'],
            't' => CarbonImmutable::parse($last['observed_at'])
                ->utc()->format('Y-m-d\TH:i:s.u\Z'),
            's' => $last['source'],
            'i' => $last['source_id'],
            'f' => $fingerprint,
            'exp' => CarbonImmutable::now('UTC')->addHour()->timestamp,
        ];

        $data = $this->urlEncode(
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        return $data . '.' . hash_hmac(
                'sha256',
                $data,
                $this->key()
            );
    }

    /**
     * @return array{
     *   priority:int,
     *   observed_at:string,
     *   source:string,
     *   source_id:int
     * }
     */
    public function decode(string $token, string $fingerprint): array
    {
        if (
            strlen($token) > 1024
            || !preg_match('/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/D', $token)
        ) {
            throw new InvalidArgumentException(
                'Invalid finance center cursor'
            );
        }

        [$data, $mac] = explode('.', $token, 2);

        if (!hash_equals(
            hash_hmac('sha256', $data, $this->key()),
            $mac
        )) {
            throw new InvalidArgumentException(
                'Finance center cursor signature mismatch'
            );
        }

        $decoded = $this->urlDecode($data);

        try {
            $p = json_decode(
                $decoded,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new InvalidArgumentException(
                'Malformed finance center cursor payload'
            );
        }

        if (
            !is_array($p)
            || ($p['v'] ?? null) !== 1
            || !in_array($p['p'] ?? null, [1, 2, 3], true)
            || !in_array(
                $p['s'] ?? null,
                ['case', 'case_alert', 'monitor', 'sla'],
                true
            )
            || !is_int($p['i'] ?? null)
            || $p['i'] < 1
            || !is_int($p['exp'] ?? null)
            || $p['exp'] < CarbonImmutable::now('UTC')->timestamp
            || !is_string($p['f'] ?? null)
            || !hash_equals($fingerprint, $p['f'])
            || !is_string($p['t'] ?? null)
            || !preg_match(
                '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/D',
                $p['t']
            )
        ) {
            throw new InvalidArgumentException(
                'Expired or incompatible finance center cursor'
            );
        }

        try {
            $t = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.u\Z',
                $p['t'],
                'UTC'
            );
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                'Invalid cursor timestamp'
            );
        }

        if (
            $t === false
            || $t->utc()->format('Y-m-d\TH:i:s.u\Z') !== $p['t']
        ) {
            throw new InvalidArgumentException(
                'Invalid cursor timestamp'
            );
        }

        return [
            'priority' => $p['p'],
            'observed_at' => $p['t'],
            'source' => $p['s'],
            'source_id' => $p['i'],
        ];
    }

    private function key(): string
    {
        $configured = config('app.key');

        if (!is_string($configured) || $configured === '') {
            throw new RuntimeException(
                'Finance center cursor signing key missing'
            );
        }

        $secret = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;

        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException(
                'Finance center cursor signing key too short'
            );
        }

        return $secret;
    }

    private function urlEncode(string $s): string
    {
        return rtrim(
            strtr(base64_encode($s), '+/', '-_'),
            '='
        );
    }

    private function urlDecode(string $s): string
    {
        $decoded = base64_decode(
            strtr($s, '-_', '+/')
            . str_repeat('=', (4 - strlen($s) % 4) % 4),
            true
        );

        if ($decoded === false) {
            throw new InvalidArgumentException(
                'Malformed finance center cursor'
            );
        }

        return $decoded;
    }
}
