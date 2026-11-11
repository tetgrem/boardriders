<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;

/** Stores pinned-key, signed bank feed assertions as immutable evidence, not payments. */
final class CheckoutCodBankEvidenceService
{
    public function __construct(private readonly CheckoutCodBankEvidenceVerifier $verifier)
    {
    }

    /** @return array<string,mixed> */
    public function receive(
        string $rawBody,
        string $providerId,
        string $keyId,
        string $timestamp,
        string $nonce,
        string $signatureBase64
    ): array {
        $epoch = $this->verifier->verify(
            $rawBody, $providerId, $keyId, $timestamp, $nonce, $signatureBase64
        );
        $accountAlias = (string) config(
            "services.cod_bank_evidence.providers.{$providerId}.account_alias", ''
        );
        $data = $this->verifier->payload($rawBody, $accountAlias, $epoch);
        $bodyHash = hash('sha256', $rawBody);
        $nonce = strtolower($nonce);
        $referenceHash = $this->hmac(['cod-bank-reference-v1', $data['bank_reference']]);
        $txHash = $this->hmac(['cod-bank-native-transaction-v1', $providerId, $data['bank_transaction_id']]);
        $reversesHash = $data['reverses_transaction_id'] === null ? null
            : $this->hmac(['cod-bank-native-transaction-v1', $providerId, $data['reverses_transaction_id']]);

        return DB::transaction(function () use (
            $providerId, $keyId, $epoch, $nonce, $data,
            $bodyHash, $referenceHash, $txHash, $reversesHash
        ): array {
            $newNonce = DB::table('checkout_cod_bank_evidence_nonces')->insertOrIgnore([
                'provider_id' => $providerId,
                'nonce' => $nonce,
                'body_sha256' => $bodyHash,
                'received_at' => now(),
            ]);
            if ($newNonce !== 1) {
                throw new DomainException('Bank evidence nonce replay');
            }

            $nonceId = DB::table('checkout_cod_bank_evidence_nonces')
                ->where('provider_id', $providerId)
                ->where('nonce', $nonce)
                ->value('id');

            // Serialize against future COD matches/allocations where a reference exists.
            DB::table('checkout_cod_bank_reference_claims')
                ->where('reference_hmac', $referenceHash)->lockForUpdate()->first(['id']);

            $existing = DB::table('checkout_cod_bank_evidence_events')
                ->where('provider_id', $providerId)
                ->where('event_key', $data['event_key'])->first();

            if ($existing !== null) {
                if (!hash_equals((string) $existing->body_sha256, $bodyHash)) {
                    throw new DomainException('Bank evidence event UUID conflict');
                }
                return $this->result($data['event_key'], true);
            }

            $inserted = DB::table('checkout_cod_bank_evidence_events')->insertOrIgnore([
                'provider_id' => $providerId,
                'key_id' => $keyId,
                'event_key' => $data['event_key'],
                'nonce_id' => $nonceId,
                'reference_hmac' => $referenceHash,
                'transaction_hmac' => $txHash,
                'reverses_transaction_hmac' => $reversesHash,
                'account_alias' => $data['account_alias'],
                'event_kind' => $data['event_kind'],
                'amount_minor' => $data['amount_minor'],
                'currency_code' => 'UAH',
                'occurred_at_epoch' => $data['occurred_at_epoch'],
                'signed_at_epoch' => $epoch,
                'body_sha256' => $bodyHash,
                'received_at' => now(),
            ]);

            if ($inserted !== 1) {
                $other = DB::table('checkout_cod_bank_evidence_events')
                    ->where('provider_id', $providerId)
                    ->where('event_key', $data['event_key'])->first();
                if ($other === null || !hash_equals((string) $other->body_sha256, $bodyHash)) {
                    throw new DomainException('Bank transaction or event identity conflict');
                }
                return $this->result($data['event_key'], true);
            }

            return $this->result($data['event_key'], false);
        }, 3);
    }

    private function hmac(array $data): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), $key);
    }

    /** @return array<string,mixed> */
    private function result(string $eventKey, bool $duplicate): array
    {
        return [
            'accepted' => true,
            'event_key' => $eventKey,
            'duplicate_event' => $duplicate,
            'provider_signature_verified' => true,
            'bank_origin_authenticated' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
            'refund_executed' => false,
        ];
    }
}
