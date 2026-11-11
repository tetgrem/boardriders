<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Authenticate first-party gateway deliveries; append-only reports.
 * Authentication of the messenger != independent proof from the bank.
 */
final class CheckoutCodFinancialGatewayService
{
    public function __construct(
        private readonly CheckoutCodFinancialGatewaySignature $signature
    ) {
    }

    /** @return array<string,mixed> */
    public function accept(
        string $rawBody,
        string $keyId,
        string $timestamp,
        string $nonce,
        string $signature
    ): array {
        $epoch = $this->signature->authenticate(
            $rawBody,
            $keyId,
            $timestamp,
            $nonce,
            $signature
        );

        $payload = $this->signature->payload($rawBody);
        $hash = hash('sha256', $rawBody);
        $nonce = strtolower($nonce);
        $referenceHmac = $this->referenceHmac(
            $payload['bank_reference']
        );

        return DB::transaction(function () use (
            $payload,
            $keyId,
            $epoch,
            $nonce,
            $hash,
            $referenceHmac
        ): array {
            // The PostgreSQL unique constraint prevents nonce replay.
            $inserted = DB::table('checkout_cod_gateway_nonces')
                ->insertOrIgnore([
                    'key_id' => $keyId,
                    'nonce' => $nonce,
                    'body_sha256' => $hash,
                    'received_at' => now(),
                ]);

            if ($inserted !== 1) {
                throw new DomainException(
                    'Gateway delivery nonce already used'
                );
            }

            $nonceId = DB::table('checkout_cod_gateway_nonces')
                ->where('key_id', $keyId)
                ->where('nonce', $nonce)
                ->value('id');

            // Serialize correlation against reconciliation operations.
            DB::table('checkout_cod_bank_reference_claims')
                ->where('reference_hmac', $referenceHmac)
                ->lockForUpdate()
                ->first(['source_id']);

            $existing = DB::table('checkout_cod_gateway_reports')
                ->where('event_key', $payload['event_key'])
                ->first();

            if ($existing !== null) {
                if (!hash_equals(
                    (string) $existing->body_sha256,
                    $hash
                )) {
                    throw new DomainException(
                        'Gateway event UUID conflict'
                    );
                }

                return $this->result(
                    (string) $existing->event_key,
                    true
                );
            }

            $reportInserted = DB::table('checkout_cod_gateway_reports')
                ->insertOrIgnore([
                    'event_key' => $payload['event_key'],
                    'nonce_id' => $nonceId,
                    'key_id' => $keyId,
                    'reference_hmac' => $referenceHmac,
                    'event_kind' => $payload['event_kind'],
                    'net_minor' => $payload['net_minor'],
                    'currency_code' => 'UAH',
                    'signed_at_epoch' => $epoch,
                    'body_sha256' => $hash,
                    'received_at' => now(),
                ]);

            if ($reportInserted !== 1) {
                // Another delivery may have inserted the same event
                // concurrently with a different delivery nonce.
                $other = DB::table('checkout_cod_gateway_reports')
                    ->where('event_key', $payload['event_key'])
                    ->first();

                if (
                    $other === null
                    || !hash_equals(
                        (string) $other->body_sha256,
                        $hash
                    )
                ) {
                    throw new DomainException(
                        'Gateway event UUID conflict'
                    );
                }

                return $this->result(
                    $payload['event_key'],
                    true
                );
            }

            return $this->result(
                $payload['event_key'],
                false
            );
        }, 3);
    }

    private function referenceHmac(string $reference): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException(
                'Application HMAC key is missing'
            );
        }

        // Exact fingerprint used by Blocks 24–25.
        return hash_hmac(
            'sha256',
            json_encode(
                ['cod-bank-reference-v1', $reference],
                JSON_THROW_ON_ERROR
            ),
            $key
        );
    }

    /** @return array<string,mixed> */
    private function result(
        string $eventKey,
        bool $duplicate
    ): array {
        return [
            'accepted' => true,
            'event_key' => $eventKey,
            'duplicate_event' => $duplicate,
            'gateway_message_authenticated' => true,
            'bank_origin_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
            'refund_executed' => false,
        ];
    }
}
