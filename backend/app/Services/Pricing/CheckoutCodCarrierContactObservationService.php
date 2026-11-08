<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Internal, read-only API observations recorded as append-only evidence.
 * Ref matching is NOT proof of recipient identity, payout agreement or COD eligibility.
 * No InternetDocument/save call is possible from this service.
 */
final class CheckoutCodCarrierContactObservationService
{
    public function __construct(
        private readonly CheckoutCodReadinessReportService $readiness,
        private readonly NovaPoshtaCounterpartyContactProbe $probe,
    ) {
    }

    /** @return array<string, mixed> */
    public function observe(string $orderKey, string $operationKey): array
    {
        if (!Str::isUuid($orderKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid COD contact observation UUID');
        }

        $orderKey = strtolower($orderKey);
        $operationKey = strtolower($operationKey);

        // First read is short. No database lock is kept during provider HTTP.
        $before = DB::transaction(fn (): array => $this->context($orderKey, false), 3);

        $existing = DB::table('checkout_cod_carrier_contact_observations')
            ->where('operation_key', $operationKey)->first();
        if ($existing !== null) {
            return $this->retry($existing, $before, $operationKey);
        }

        $sender = $this->probe->inspect(
            $before['refs']['sender_counterparty'],
            $before['refs']['sender_contact']
        );
        $recipient = $this->probe->inspect(
            $before['refs']['recipient_counterparty'],
            $before['refs']['recipient_contact']
        );

        return DB::transaction(function () use (
            $orderKey, $operationKey, $before, $sender, $recipient
        ): array {
            // Revalidate all inputs and reservations after external requests.
            $current = $this->context($orderKey, true);
            if (!hash_equals($before['context_hmac'], $current['context_hmac'])
                || $before['ids'] !== $current['ids']
            ) {
                throw new DomainException('COD contact context changed during carrier lookup');
            }

            $existing = DB::table('checkout_cod_carrier_contact_observations')
                ->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                return $this->retry($existing, $current, $operationKey);
            }

            $id = DB::table('checkout_cod_carrier_contact_observations')->insertGetId([
                'operation_key' => $operationKey,
                'order_id' => $current['ids']['order_id'],
                'draft_id' => $current['ids']['draft_id'],
                'binding_id' => $current['ids']['binding_id'],
                'sender_profile_id' => $current['ids']['sender_profile_id'],
                'sender_result' => $sender['result'],
                'recipient_result' => $recipient['result'],
                'sender_contact_seen' => $sender['contact_seen'],
                'recipient_contact_seen' => $recipient['contact_seen'],
                'context_hmac' => $current['context_hmac'],
                'created_at' => now(),
            ]);

            return $this->present(DB::table('checkout_cod_carrier_contact_observations')
                ->where('id', $id)->first());
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function latestForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('checkout_cod_carrier_contact_observations')
            ->where('order_id', $orderId)->orderByDesc('id')->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    private function context(string $orderKey, bool $lockOrder): array
    {
        $query = DB::table('orders')->where('order_key', $orderKey);
        if ($lockOrder) {
            $query->lockForUpdate();
        }
        $order = $query->first(['id', 'payment_status']);

        if ($order === null || $order->payment_status !== 'pending') {
            throw new DomainException('COD order is not pending');
        }
        $orderId = (int) $order->id;

        if (($this->readiness->inspect($orderKey)['local_checks_complete'] ?? null) !== true) {
            throw new DomainException('COD local readiness is incomplete');
        }

        $draft = DB::table('checkout_cod_waybill_drafts')
            ->where('order_id', $orderId)
            ->first(['id', 'draft_key', 'order_id', 'recipient_id', 'sender_profile_id',
                'financial_snapshot_sha256']);
        if ($draft === null) {
            throw new DomainException('COD waybill draft is required');
        }

        $binding = DB::table('checkout_cod_recipient_carrier_bindings')
            ->where('order_id', $orderId)
            ->first(['id', 'draft_id', 'recipient_id', 'request_hmac',
                'counterparty_ref_ciphertext', 'contact_ref_ciphertext']);
        $sender = DB::table('nova_poshta_sender_profiles')
            ->where('id', $draft->sender_profile_id)
            ->first(['id', 'counterparty_ref', 'contact_ref', 'request_hmac']);

        if ($binding === null || $sender === null
            || (int) $binding->draft_id !== (int) $draft->id
            || (int) $binding->recipient_id !== (int) $draft->recipient_id
        ) {
            throw new DomainException('COD contact references do not match waybill draft');
        }

        try {
            $recipientCounterparty = Crypt::decryptString((string) $binding->counterparty_ref_ciphertext);
            $recipientContact = Crypt::decryptString((string) $binding->contact_ref_ciphertext);
        } catch (DecryptException) {
            throw new DomainException('Stored COD carrier references cannot be decrypted');
        }

        $refs = [
            'sender_counterparty' => strtolower((string) $sender->counterparty_ref),
            'sender_contact' => strtolower((string) $sender->contact_ref),
            'recipient_counterparty' => strtolower($recipientCounterparty),
            'recipient_contact' => strtolower($recipientContact),
        ];
        foreach ($refs as $ref) {
            if (!Str::isUuid($ref)) {
                throw new DomainException('Stored COD carrier reference is invalid');
            }
        }

        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new DomainException('Application key is missing');
        }

        $ids = [
            'order_id' => $orderId,
            'draft_id' => (int) $draft->id,
            'binding_id' => (int) $binding->id,
            'sender_profile_id' => (int) $sender->id,
        ];
        $digest = hash_hmac('sha256', json_encode([
            'cod-contact-observation-v1', $orderKey, $ids,
            (string) $draft->draft_key,
            (string) $draft->financial_snapshot_sha256,
            (string) $binding->request_hmac,
            (string) $sender->request_hmac,
            $refs,
        ], JSON_THROW_ON_ERROR), $secret);

        return ['ids' => $ids, 'refs' => $refs, 'context_hmac' => $digest];
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    private function retry(object $existing, array $ctx, string $operationKey): array
    {
        if ((string) $existing->operation_key !== $operationKey
            || (int) $existing->order_id !== $ctx['ids']['order_id']
            || (int) $existing->draft_id !== $ctx['ids']['draft_id']
            || (int) $existing->binding_id !== $ctx['ids']['binding_id']
            || (int) $existing->sender_profile_id !== $ctx['ids']['sender_profile_id']
            || !hash_equals((string) $existing->context_hmac, $ctx['context_hmac'])
        ) {
            throw new DomainException('COD contact observation UUID reused for different context');
        }
        return $this->present($existing);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'order_id' => (int) $row->order_id,
            'draft_id' => (int) $row->draft_id,
            'binding_id' => (int) $row->binding_id,
            'sender_profile_id' => (int) $row->sender_profile_id,
            'sender_result' => (string) $row->sender_result,
            'recipient_result' => (string) $row->recipient_result,
            'sender_contact_seen' => (bool) $row->sender_contact_seen,
            'recipient_contact_seen' => (bool) $row->recipient_contact_seen,
            'observation_scope' => 'single_api_response_per_counterparty',
            'person_identity_verified' => false,
            'merchant_cod_agreement_verified' => false,
            'payout_contract_verified' => false,
            'cod_eligibility_confirmed' => false,
            'ttn_created' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
