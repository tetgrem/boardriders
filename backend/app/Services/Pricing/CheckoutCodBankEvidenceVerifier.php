<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/** A pinned Ed25519 feed key validates the sender, not the bank balance. */
final class CheckoutCodBankEvidenceVerifier
{
    public const CONTEXT = 'boardriders-cod-bank-evidence-v1';
    public const MAX_BYTES = 8192;
    public const MAX_SKEW = 300;

    public static function signedMessage(
        string $providerId,
        string $keyId,
        string $timestamp,
        string $nonce,
        string $rawBody
    ): string {
        return self::CONTEXT . "\n" . $providerId . "\n" . $keyId . "\n"
            . $timestamp . "\n" . $nonce . "\n" . $rawBody;
    }

    /** Check timestamp, key pinned for provider, signature and exact raw bytes. */
    public function verify(
        string $rawBody,
        string $providerId,
        string $keyId,
        string $timestamp,
        string $nonce,
        string $signatureBase64
    ): int {
        $provider = config("services.cod_bank_evidence.providers.{$providerId}");
        $publicKeyBase64 = is_array($provider)
            ? ($provider['public_keys'][$keyId] ?? null)
            : null;

        if (
            config('services.cod_bank_evidence.enabled') !== true
            || !is_array($provider)
            || ($provider['enabled'] ?? false) !== true
            || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $providerId)
            || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $keyId)
            || !preg_match('/^[1-9][0-9]{9,10}$/D', $timestamp)
            || !Str::isUuid($nonce)
            || strlen($rawBody) < 2
            || strlen($rawBody) > self::MAX_BYTES
            || !is_string($publicKeyBase64)
            || !is_string($signatureBase64)
            || !function_exists('sodium_crypto_sign_verify_detached')
        ) {
            throw new AuthorizationException('Signed bank feed authentication denied');
        }

        $publicKey = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($signatureBase64, true);
        if (
            $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || abs(Carbon::now('UTC')->timestamp - (int) $timestamp) > self::MAX_SKEW
        ) {
            throw new AuthorizationException('Invalid bank feed signature envelope');
        }

        if (!sodium_crypto_sign_verify_detached(
            $signature,
            self::signedMessage($providerId, $keyId, $timestamp, $nonce, $rawBody),
            $publicKey
        )) {
            throw new AuthorizationException('Signed bank feed signature mismatch');
        }

        return (int) $timestamp;
    }

    /** @return array<string, mixed> */
    public function payload(string $rawBody, string $accountAlias, int $signedAt): array
    {
        if (strlen($rawBody) < 2 || strlen($rawBody) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Signed bank payload length');
        }

        try {
            $data = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid bank evidence JSON', 0, $e);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Bank evidence must be a JSON object');
        }

        $keys = array_keys($data);
        sort($keys);
        $expected = [
            'event_key', 'event_kind', 'bank_reference', 'bank_transaction_id',
            'reverses_transaction_id', 'amount_minor', 'currency_code',
            'account_alias', 'occurred_at_epoch',
        ];
        sort($expected);

        if (
            $keys !== $expected
            || !is_string($data['event_key']) || !Str::isUuid($data['event_key'])
            || !in_array($data['event_kind'], ['credit', 'reversal'], true)
            || !is_string($data['bank_reference'])
            || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{5,79}$/D', $data['bank_reference'])
            || !is_string($data['bank_transaction_id'])
            || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{5,79}$/D', $data['bank_transaction_id'])
            || $data['account_alias'] !== $accountAlias
            || !is_string($data['account_alias'])
            || !preg_match('/^[a-z][a-z0-9_-]{0,47}$/D', $data['account_alias'])
            || $data['currency_code'] !== 'UAH'
            || !is_int($data['amount_minor'])
            || $data['amount_minor'] < 1 || $data['amount_minor'] > 100000000
            || !is_int($data['occurred_at_epoch'])
            || $data['occurred_at_epoch'] > $signedAt + 60
            || $data['occurred_at_epoch'] < $signedAt - 366 * 86400
        ) {
            throw new InvalidArgumentException('Invalid signed bank evidence fields');
        }

        if ($data['event_kind'] === 'credit') {
            if ($data['reverses_transaction_id'] !== null) {
                throw new InvalidArgumentException('Credit cannot reverse another transaction');
            }
        } elseif (
            !is_string($data['reverses_transaction_id'])
            || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{5,79}$/D', $data['reverses_transaction_id'])
            || $data['reverses_transaction_id'] === $data['bank_transaction_id']
        ) {
            throw new InvalidArgumentException('Reversal must name a different transaction');
        }

        $data['event_key'] = strtolower($data['event_key']);

        return $data;
    }
}
