<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Synthetic HMAC-SHA256 webhook contract for the existing test_gateway.
 *
 * Intentionally unavailable in production. This is NOT a real payment provider
 * implementation and must not be exposed via a public HTTP route.
 *
 * Transport contract:
 *   timestamp: decimal Unix seconds (10-11 digits)
 *   signature: 'v1=' . hex(HMAC_SHA256(timestamp . '.' . exact rawBody, secret))
 *   config: services.boardriders_test_gateway.webhook_secret (at least 32 bytes)
 *
 * The signed JSON must contain exactly:
 *   event_type = 'payment.captured', event_id, capture_id,
 *   provider_payment_id, amount_minor (integer), currency_code,
 *   captured_at = YYYY-MM-DDTHH:MM:SS.ffffffZ.
 * No client-supplied order key is accepted. The order is derived exclusively
 * from the previously bound provider_payment_id.
 */
final class SandboxCaptureWebhookService
{
    private const PROVIDER = 'test_gateway';
    private const MAX_AGE_SECONDS = 300;
    private const MAX_BODY_BYTES = 16384;

    public function __construct(
        private readonly CheckoutPaymentAttemptService $attempts,
        private readonly CheckoutPaymentCaptureReceiptService $receipts,
        private readonly CheckoutPaymentFinalizationService $finalization,
    ) {
    }

    /** @return array{receipt:array<string,mixed>,decision:array<string,mixed>} */
    public function handleSignedCapture(
        string $rawBody,
        string $timestamp,
        string $signature,
    ): array {
        // Deliberate hard block: no real provider has been configured.
        if (!app()->environment('local', 'testing')) {
            throw new RuntimeException('Sandbox payment webhook is disabled outside local/testing');
        }

        $secret = config('services.boardriders_test_gateway.webhook_secret');
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('Sandbox webhook secret is missing or too short');
        }

        if ($rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('Invalid sandbox webhook body length');
        }

        if (
            !preg_match('/^[0-9]{10,11}$/D', $timestamp)
            || !preg_match('/^v1=[0-9a-f]{64}$/D', $signature)
        ) {
            throw new DomainException('Webhook authentication failed');
        }

        $now = CarbonImmutable::now('UTC')->timestamp;
        if (abs($now - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            throw new DomainException('Webhook timestamp is outside allowed window');
        }

        // MAC over the EXACT original bytes, never over JSON re-serialization.
        $expected = 'v1=' . hash_hmac(
                'sha256',
                $timestamp . '.' . $rawBody,
                $secret
            );

        if (!hash_equals($expected, $signature)) {
            throw new DomainException('Webhook authentication failed');
        }

        try {
            $payload = json_decode(
                $rawBody,
                true,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid signed webhook JSON', 0, $exception);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Signed webhook must be a JSON object');
        }

        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $allowed = [
            'amount_minor', 'capture_id', 'captured_at', 'currency_code',
            'event_id', 'event_type', 'provider_payment_id',
        ];
        sort($allowed, SORT_STRING);

        if (
            $keys !== $allowed
            || ($payload['event_type'] ?? null) !== 'payment.captured'
            || !is_string($payload['event_id'])
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D', $payload['event_id'])
            || !is_string($payload['capture_id'])
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D', $payload['capture_id'])
            || !is_string($payload['provider_payment_id'])
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D', $payload['provider_payment_id'])
            || !is_int($payload['amount_minor'])
            || $payload['amount_minor'] <= 0
            || !is_string($payload['currency_code'])
            || !preg_match('/^[A-Z]{3}$/D', $payload['currency_code'])
            || !is_string($payload['captured_at'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $payload['captured_at'])
        ) {
            throw new InvalidArgumentException('Invalid signed capture payload');
        }

        try {
            $capturedAt = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.u\Z',
                $payload['captured_at'],
                'UTC'
            );
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid captured_at timestamp', 0, $exception);
        }

        if (
            !$capturedAt instanceof CarbonImmutable
            || $capturedAt->format('Y-m-d\TH:i:s.u\Z') !== $payload['captured_at']
        ) {
            throw new InvalidArgumentException('Invalid captured_at timestamp');
        }

        // Atomic envelope: either both the receipt and finalization commit,
        // or neither is saved. Finalization itself may return a review decision.
        return DB::transaction(function () use ($payload, $capturedAt): array {
            $attempt = $this->attempts->findByProviderPayment(
                self::PROVIDER,
                $payload['provider_payment_id']
            );

            if ($attempt === null) {
                // No order is derivable from an unbound payment identifier.
                // Do not invent an order mapping or record a fake receipt.
                throw new DomainException('Signed provider payment is not bound to a checkout');
            }

            $receipt = $this->receipts->recordVerifiedCapture(
                self::PROVIDER,
                $payload['event_id'],
                $payload['capture_id'],
                $attempt['order_key'],
                $payload['amount_minor'],
                $payload['currency_code'],
                $capturedAt,
                $payload['provider_payment_id'],
            );

            $decision = $this->finalization->finalize((int) $receipt['id']);

            return [
                'receipt' => $receipt,
                'decision' => $decision,
            ];
        }, 3);
    }
}
