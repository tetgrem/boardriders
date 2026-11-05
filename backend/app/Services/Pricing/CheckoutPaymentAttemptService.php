<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Внутрішня прив'язка provider payment intent до immutable checkout snapshot.
 *
 * Provider payment ID дозволено передавати ЛИШЕ після довіреної відповіді
 * провайдера, який створив намір з точною сумою та валютою.
 * Цей клас не викликає API провайдера та не перевіряє webhook.
 *
 * v1: один платіжний намір на одне замовлення. Підтримка нових спроб
 * після відмови потребує окремої моделі життєвого циклу payment attempts.
 */
final class CheckoutPaymentAttemptService
{
    public function __construct(
        private readonly OrderFinancialSnapshotService $snapshots,
    ) {
    }

    /** @return array<string,mixed> */
    public function bindPreparedOrder(
        string $orderKey,
        string $attemptKey,
        string $providerCode,
        string $providerPaymentId,
        DateTimeInterface $expiresAt,
    ): array {
        $orderKey = strtolower(trim($orderKey));
        $attemptKey = strtolower(trim($attemptKey));
        $providerCode = strtolower(trim($providerCode));
        $providerPaymentId = trim($providerPaymentId);

        if (
            !Str::isUuid($orderKey) || !Str::isUuid($attemptKey)
            || !preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $providerCode)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D', $providerPaymentId)
        ) {
            throw new InvalidArgumentException('Invalid payment attempt parameters');
        }

        $expires = CarbonImmutable::instance($expiresAt)->utc();
        if ($expires->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new InvalidArgumentException('Payment attempt must expire in the future');
        }
        $expiresText = $expires->format('Y-m-d H:i:s.uP');

        return DB::transaction(function () use (
            $orderKey, $attemptKey, $providerCode, $providerPaymentId,
            $expires, $expiresText
        ): array {
            // Спочатку order lock: однаковий порядок із cancel/finalize.
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null) {
                throw new DomainException('Payment order not found');
            }
            $orderId = (int) $order->id;

            $preparation = DB::table('checkout_order_preparations')
                ->where('order_id', $orderId)->first();
            if ($preparation === null) {
                throw new DomainException('Payment order has no checkout preparation');
            }

            $snapshot = $this->snapshots->findForOrder($orderId);
            if (
                $snapshot === null
                || $snapshot['id'] !== (int) $preparation->financial_snapshot_id
            ) {
                throw new DomainException('Missing or inconsistent financial snapshot');
            }

            $amount = $snapshot['goods_payable_minor'];
            $currency = $snapshot['currency_code'];
            if ($amount <= 0) {
                throw new DomainException('Zero-cash checkout does not require provider payment');
            }

            $payload = [
                'schema_version' => 1,
                'attempt_key' => $attemptKey,
                'order_key' => $orderKey,
                'order_id' => $orderId,
                'financial_snapshot_id' => $snapshot['id'],
                'provider_code' => $providerCode,
                'provider_payment_id' => $providerPaymentId,
                'amount_minor' => $amount,
                'currency_code' => $currency,
                'financial_snapshot_sha256' => $snapshot['sha256'],
                'expires_at' => $expiresText,
            ];

            try {
                $json = json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            } catch (JsonException $exception) {
                throw new RuntimeException('Cannot encode payment attempt request', 0, $exception);
            }
            $requestHash = hash('sha256', $json);

            $existing = DB::table('checkout_payment_attempts')
                ->where('order_id', $orderId)->first();
            if ($existing !== null) {
                // Повтор — тільки ті самі точні дані, навіть якщо вже paid.
                if (
                    $existing->attempt_key !== $attemptKey
                    || !hash_equals((string) $existing->request_sha256, $requestHash)
                    || $existing->request_json !== $json
                ) {
                    throw new DomainException('Order already has a different payment attempt');
                }

                return $this->decode($existing);
            }

            if ($order->payment_status !== 'pending') {
                throw new DomainException('Payment attempt requires pending order');
            }

            $now = CarbonImmutable::now('UTC');
            if (
                CarbonImmutable::parse($preparation->expires_at)->lessThanOrEqualTo($now)
                || $expires->lessThanOrEqualTo($now)
                || $expires->greaterThan(CarbonImmutable::parse($preparation->expires_at))
            ) {
                throw new DomainException('Payment attempt exceeds checkout reservation window');
            }

            if (DB::table('checkout_payment_capture_receipts')
                ->where('order_id', $orderId)->exists()
            ) {
                throw new DomainException('Cannot bind payment intent after captured payment');
            }

            if (DB::table('checkout_payment_attempts')
                ->where('attempt_key', $attemptKey)->exists()
            ) {
                throw new DomainException('Payment attempt key already belongs to another order');
            }

            if (DB::table('checkout_payment_attempts')
                ->where('provider_code', $providerCode)
                ->where('provider_payment_id', $providerPaymentId)->exists()
            ) {
                throw new DomainException('Provider payment ID already bound to another order');
            }

            $id = (int) DB::table('checkout_payment_attempts')->insertGetId([
                'attempt_key' => $attemptKey,
                'order_id' => $orderId,
                'financial_snapshot_id' => $snapshot['id'],
                'provider_code' => $providerCode,
                'provider_payment_id' => $providerPaymentId,
                'amount_minor' => $amount,
                'currency_code' => $currency,
                'financial_snapshot_sha256' => $snapshot['sha256'],
                'request_json' => $json,
                'request_sha256' => $requestHash,
                'expires_at' => $expiresText,
            ]);

            $row = DB::table('checkout_payment_attempts')->where('id', $id)->first();
            if ($row === null) {
                throw new RuntimeException('Payment attempt missing after insert');
            }
            return $this->decode($row);
        }, 3);
    }

    /** @return array<string,mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }
        $row = DB::table('checkout_payment_attempts')->where('order_id', $orderId)->first();
        return $row === null ? null : $this->decode($row);
    }

    /** @return array<string,mixed>|null */
    public function findByProviderPayment(string $providerCode, string $providerPaymentId): ?array
    {
        $row = DB::table('checkout_payment_attempts')
            ->where('provider_code', strtolower(trim($providerCode)))
            ->where('provider_payment_id', trim($providerPaymentId))->first();
        return $row === null ? null : $this->decode($row);
    }

    /** @return array<string,mixed> */
    private function decode(object $row): array
    {
        $json = (string) $row->request_json;
        $sha256 = hash('sha256', $json);
        if (!hash_equals((string) $row->request_sha256, $sha256)) {
            throw new RuntimeException('Payment attempt request checksum mismatch');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid payment attempt JSON', 0, $exception);
        }

        if (
            !is_array($data)
            || ($data['schema_version'] ?? null) !== 1
            || ($data['attempt_key'] ?? null) !== (string) $row->attempt_key
            || ($data['order_id'] ?? null) !== (int) $row->order_id
            || ($data['financial_snapshot_id'] ?? null) !== (int) $row->financial_snapshot_id
            || ($data['provider_code'] ?? null) !== $row->provider_code
            || ($data['provider_payment_id'] ?? null) !== $row->provider_payment_id
            || ($data['amount_minor'] ?? null) !== (int) $row->amount_minor
            || ($data['currency_code'] ?? null) !== $row->currency_code
            || ($data['financial_snapshot_sha256'] ?? null) !== $row->financial_snapshot_sha256
            || ($data['expires_at'] ?? null) !== CarbonImmutable::parse(
                $row->expires_at
            )->utc()->format('Y-m-d H:i:s.uP')
        ) {
            throw new RuntimeException('Payment attempt metadata mismatch');
        }

        $orderKey = DB::table('orders')->where('id', $row->order_id)->value('order_key');
        if ($orderKey === null || $data['order_key'] !== $orderKey) {
            throw new RuntimeException('Payment attempt order key mismatch');
        }

        return [
            'id' => (int) $row->id,
            'attempt_key' => (string) $row->attempt_key,
            'order_id' => (int) $row->order_id,
            'order_key' => (string) $orderKey,
            'financial_snapshot_id' => (int) $row->financial_snapshot_id,
            'provider_code' => (string) $row->provider_code,
            'provider_payment_id' => (string) $row->provider_payment_id,
            'amount_minor' => (int) $row->amount_minor,
            'currency_code' => (string) $row->currency_code,
            'financial_snapshot_sha256' => (string) $row->financial_snapshot_sha256,
            'request_sha256' => $sha256,
            'expires_at' => (string) $row->expires_at,
            'created_at' => (string) $row->created_at,
            'status' => 'bound',
        ];
    }
}
