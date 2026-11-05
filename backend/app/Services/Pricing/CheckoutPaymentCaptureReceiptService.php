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
 * Immutable inbox для ФАКТИЧНО підтверджених зовнішнім провайдером capture.
 *
 * КРИТИЧНО: цей метод НЕ верифікує підпис webhook і НЕ опитує провайдера.
 * Його має викликати тільки довірений adapter ПІСЛЯ перевірки походження,
 * статусу capture і відповідності provider payment ID.
 * Не публікувати як HTTP endpoint.
 *
 * Запис receipt НЕ переводить order у paid і НЕ списує резерви.
 */
final class CheckoutPaymentCaptureReceiptService
{
    /** @return array<string,mixed> */
    public function recordVerifiedCapture(
        string $providerCode,
        string $providerEventId,
        string $providerCaptureId,
        string $orderKey,
        int $amountMinor,
        string $currencyCode,
        DateTimeInterface $capturedAt,
        ?string $providerPaymentId = null,
    ): array {
        $providerCode = strtolower(trim($providerCode));
        $providerEventId = trim($providerEventId);
        $providerCaptureId = trim($providerCaptureId);
        $orderKey = strtolower(trim($orderKey));
        $currencyCode = strtoupper(trim($currencyCode));
        $providerPaymentId = $providerPaymentId === null ? null : trim($providerPaymentId);

        if (
            !preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $providerCode)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D', $providerEventId)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D', $providerCaptureId)
            || !Str::isUuid($orderKey)
            || !preg_match('/^[A-Z]{3}$/D', $currencyCode)
            || $amountMinor <= 0
            || ($providerPaymentId !== null && !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D', $providerPaymentId
            ))
        ) {
            throw new InvalidArgumentException('Invalid verified capture parameters');
        }

        $captured = CarbonImmutable::instance($capturedAt)->utc();

        if ($captured->greaterThan(CarbonImmutable::now('UTC')->addMinutes(5))) {
            throw new InvalidArgumentException('Capture timestamp is too far in the future');
        }

        $captureTimestamp = $captured->format('Y-m-d H:i:s.uP');

        // Порядок полів є частиною канонічного формату v1.
        $payload = [
            'schema_version' => 1,
            'provider_code' => $providerCode,
            'provider_event_id' => $providerEventId,
            'provider_capture_id' => $providerCaptureId,
            'order_key' => $orderKey,
            'amount_minor' => $amountMinor,
            'currency_code' => $currencyCode,
            'captured_at' => $captureTimestamp,
        ];

        // Keep the legacy canonical JSON unchanged when no intent reference exists.
        if ($providerPaymentId !== null) {
            $payload['provider_payment_id'] = $providerPaymentId;
        }

        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid capture payload', 0, $exception);
        }

        $sha256 = hash('sha256', $json);

        return DB::transaction(function () use (
            $providerCode, $providerEventId, $providerCaptureId,
            $orderKey, $amountMinor, $currencyCode,
            $captureTimestamp, $json, $sha256, $providerPaymentId
        ): array {
            // Захист одночасних повторів за обома зовнішніми ключами.
            $locks = [
                'capture:event:' . $providerCode . ':' . $providerEventId,
                'capture:payment:' . $providerCode . ':' . $providerCaptureId,
            ];
            sort($locks, SORT_STRING);

            foreach ($locks as $lock) {
                DB::select(
                    'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                    [$lock]
                );
            }

            $existing = DB::table('checkout_payment_capture_receipts')
                ->where('provider_code', $providerCode)
                ->where(function ($query) use ($providerEventId, $providerCaptureId) {
                    $query->where('provider_event_id', $providerEventId)
                        ->orWhere('provider_capture_id', $providerCaptureId);
                })
                ->first();

            if ($existing !== null) {
                if (
                    !hash_equals((string) $existing->capture_sha256, $sha256)
                    || $existing->capture_json !== $json
                ) {
                    throw new DomainException(
                        'Provider event or payment ID reused with different capture details'
                    );
                }

                return $this->decode($existing);
            }

            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->first(['id']);

            if ($order === null) {
                throw new DomainException('Order for verified capture not found');
            }

            $id = (int) DB::table('checkout_payment_capture_receipts')
                ->insertGetId([
                    'order_id' => $order->id,
                    'provider_code' => $providerCode,
                    'provider_event_id' => $providerEventId,
                    'provider_capture_id' => $providerCaptureId,
                    'provider_payment_id' => $providerPaymentId,
                    'amount_minor' => $amountMinor,
                    'currency_code' => $currencyCode,
                    'captured_at' => $captureTimestamp,
                    'capture_json' => $json,
                    'capture_sha256' => $sha256,
                ]);

            return $this->findById($id);
        }, 3);
    }

    /** @return array<string,mixed>|null */
    public function findByProviderCapture(
        string $providerCode,
        string $providerCaptureId
    ): ?array {
        $row = DB::table('checkout_payment_capture_receipts')
            ->where('provider_code', strtolower(trim($providerCode)))
            ->where('provider_capture_id', trim($providerCaptureId))
            ->first();

        return $row === null ? null : $this->decode($row);
    }

    /** @return array<string,mixed> */
    private function findById(int $id): array
    {
        $row = DB::table('checkout_payment_capture_receipts')
            ->where('id', $id)->first();

        if ($row === null) {
            throw new RuntimeException('Recorded capture receipt not found');
        }

        return $this->decode($row);
    }

    /** @return array<string,mixed> */
    private function decode(object $row): array
    {
        $json = (string) $row->capture_json;
        $sha256 = hash('sha256', $json);

        if (!hash_equals((string) $row->capture_sha256, $sha256)) {
            throw new RuntimeException('Capture receipt checksum mismatch');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid stored capture JSON', 0, $exception);
        }

        if (
            !is_array($data)
            || ($data['schema_version'] ?? null) !== 1
            || ($data['provider_code'] ?? null) !== $row->provider_code
            || ($data['provider_event_id'] ?? null) !== $row->provider_event_id
            || ($data['provider_capture_id'] ?? null) !== $row->provider_capture_id
            || ($data['provider_payment_id'] ?? null) !== $row->provider_payment_id
            || ($data['amount_minor'] ?? null) !== (int) $row->amount_minor
            || ($data['currency_code'] ?? null) !== $row->currency_code
            || ($data['captured_at'] ?? null) !== CarbonImmutable::parse(
                $row->captured_at
            )->utc()->format('Y-m-d H:i:s.uP')
        ) {
            throw new RuntimeException('Capture receipt metadata mismatch');
        }

        $orderKey = DB::table('orders')
            ->where('id', $row->order_id)->value('order_key');

        if ($orderKey !== $data['order_key']) {
            throw new RuntimeException('Capture receipt order mismatch');
        }

        return [
            'id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'provider_code' => (string) $row->provider_code,
            'provider_event_id' => (string) $row->provider_event_id,
            'provider_capture_id' => (string) $row->provider_capture_id,
            'provider_payment_id' => $row->provider_payment_id === null
                ? null : (string) $row->provider_payment_id,
            'amount_minor' => (int) $row->amount_minor,
            'currency_code' => (string) $row->currency_code,
            'captured_at' => $data['captured_at'],
            'received_at' => (string) $row->received_at,
            'sha256' => $sha256,
            'status' => 'recorded',
        ];
    }
}
