<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * First-party gateway signing protocol.
 * This is not a bank-native signature verification adapter.
 */
final class CheckoutCodFinancialGatewaySignature
{
    public const CONTEXT = 'boardriders-cod-gateway-v1';
    public const MAX_BYTES = 8192;
    public const MAX_SKEW_SECONDS = 300;

    public function sign(
        string $keyId,
        string $timestamp,
        string $nonce,
        string $rawBody,
        string $secret
    ): string {
        return hash_hmac(
            'sha256',
            self::CONTEXT . "\n"
            . $keyId . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . $rawBody,
            $secret
        );
    }

    public function authenticate(
        string $rawBody,
        string $keyId,
        string $timestamp,
        string $nonce,
        string $signature
    ): int {
        if (config('services.cod_financial_gateway.enabled') !== true) {
            throw new AuthorizationException('COD gateway disabled');
        }

        $keys = config('services.cod_financial_gateway.keys', []);
        $secret = is_array($keys) ? ($keys[$keyId] ?? null) : null;

        if (
            !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $keyId)
            || !is_string($secret)
            || strlen($secret) < 32
            || !preg_match('/^[1-9][0-9]{9,10}$/D', $timestamp)
            || !Str::isUuid($nonce)
            || !preg_match('/^[a-f0-9]{64}$/D', $signature)
            || strlen($rawBody) < 2
            || strlen($rawBody) > self::MAX_BYTES
        ) {
            throw new AuthorizationException(
                'Invalid COD gateway authentication'
            );
        }

        $epoch = (int) $timestamp;
        $now = Carbon::now('UTC')->timestamp;

        if (abs($now - $epoch) > self::MAX_SKEW_SECONDS) {
            throw new AuthorizationException(
                'Expired COD gateway request'
            );
        }

        $expected = $this->sign(
            $keyId,
            $timestamp,
            $nonce,
            $rawBody,
            $secret
        );

        if (!hash_equals($expected, $signature)) {
            throw new AuthorizationException(
                'Incorrect COD gateway signature'
            );
        }

        return $epoch;
    }

    /**
     * @return array{
     *   event_key:string,
     *   event_kind:string,
     *   bank_reference:string,
     *   net_minor:int,
     *   currency_code:string
     * }
     */
    public function payload(string $rawBody): array
    {
        if (
            strlen($rawBody) < 2
            || strlen($rawBody) > self::MAX_BYTES
        ) {
            throw new InvalidArgumentException(
                'Invalid gateway message length'
            );
        }

        try {
            $payload = json_decode(
                $rawBody,
                true,
                8,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'Malformed gateway JSON',
                0,
                $e
            );
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException(
                'Gateway message must be an object'
            );
        }

        $actualKeys = array_keys($payload);
        sort($actualKeys);

        $requiredKeys = [
            'bank_reference',
            'currency_code',
            'event_key',
            'event_kind',
            'net_minor',
        ];
        sort($requiredKeys);

        if (
            $actualKeys !== $requiredKeys
            || !is_string($payload['event_key'])
            || !Str::isUuid($payload['event_key'])
            || !is_string($payload['event_kind'])
            || !in_array(
                $payload['event_kind'],
                ['credit_reported', 'reversal_reported'],
                true
            )
            || !is_string($payload['bank_reference'])
            || !preg_match(
                '/^[A-Za-z][A-Za-z0-9_-]{5,79}$/D',
                $payload['bank_reference']
            )
            || $payload['currency_code'] !== 'UAH'
            || !is_int($payload['net_minor'])
            || $payload['net_minor'] < 1
            || $payload['net_minor'] > 100000000
        ) {
            throw new InvalidArgumentException(
                'Invalid gateway message fields'
            );
        }

        $payload['event_key'] = strtolower($payload['event_key']);

        return $payload;
    }
}
