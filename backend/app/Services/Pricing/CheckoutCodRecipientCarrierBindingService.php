<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stores merchant-supplied Nova Poshta recipient refs, not verified identities.
 * No HTTP calls or TTN creation. Public output never returns the raw refs.
 */
final class CheckoutCodRecipientCarrierBindingService
{
    public function __construct(
        private readonly CheckoutCodReadinessReportService $readiness,
    ) {
    }

    /** @return array<string, mixed> */
    public function bind(
        string $orderKey,
        string $bindingKey,
        string $counterpartyRef,
        string $contactRef,
    ): array {
        foreach ([$orderKey, $bindingKey, $counterpartyRef, $contactRef] as $value) {
            if (!Str::isUuid($value)) {
                throw new InvalidArgumentException('Invalid COD recipient carrier reference');
            }
        }

        $orderKey = strtolower($orderKey);
        $bindingKey = strtolower($bindingKey);
        $counterpartyRef = strtolower($counterpartyRef);
        $contactRef = strtolower($contactRef);

        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new DomainException('Application encryption key is unavailable');
        }

        return DB::transaction(function () use (
            $orderKey, $bindingKey, $counterpartyRef, $contactRef, $secret
        ): array {
            $order = DB::table('orders')->where('order_key', $orderKey)
                ->lockForUpdate()->first(['id', 'payment_status']);
            if ($order === null || $order->payment_status !== 'pending') {
                throw new DomainException('COD order must be pending');
            }
            $orderId = (int) $order->id;

            $draft = DB::table('checkout_cod_waybill_drafts')
                ->where('order_id', $orderId)
                ->first(['id', 'draft_key', 'recipient_id']);
            if ($draft === null) {
                throw new DomainException('COD waybill draft is required');
            }

            $report = $this->readiness->inspect($orderKey);
            if (($report['local_checks_complete'] ?? null) !== true) {
                throw new DomainException('COD local checks are not current');
            }

            $recipientExists = DB::table('checkout_cod_recipients')
                ->where('id', $draft->recipient_id)
                ->where('order_id', $orderId)->exists();
            if (!$recipientExists) {
                throw new DomainException('COD recipient does not match the draft');
            }

            $requestHmac = hash_hmac('sha256', json_encode([
                'cod-recipient-carrier-binding-v1', $orderKey,
                (string) $draft->draft_key, $counterpartyRef, $contactRef,
            ], JSON_THROW_ON_ERROR), $secret);

            $existing = DB::table('checkout_cod_recipient_carrier_bindings')
                ->where('order_id', $orderId)->first();
            if ($existing !== null) {
                if (
                    (string) $existing->binding_key !== $bindingKey
                    || (int) $existing->draft_id !== (int) $draft->id
                    || (int) $existing->recipient_id !== (int) $draft->recipient_id
                    || !hash_equals((string) $existing->request_hmac, $requestHmac)
                ) {
                    throw new DomainException('COD recipient carrier binding is immutable');
                }
                return $this->present($existing);
            }

            if (DB::table('checkout_cod_recipient_carrier_bindings')
                ->where('binding_key', $bindingKey)->exists()
            ) {
                throw new DomainException('COD recipient carrier binding key is already used');
            }

            $id = DB::table('checkout_cod_recipient_carrier_bindings')->insertGetId([
                'binding_key' => $bindingKey,
                'order_id' => $orderId,
                'draft_id' => (int) $draft->id,
                'recipient_id' => (int) $draft->recipient_id,
                'counterparty_ref_ciphertext' => Crypt::encryptString($counterpartyRef),
                'contact_ref_ciphertext' => Crypt::encryptString($contactRef),
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('checkout_cod_recipient_carrier_bindings')->where('id', $id)->first()
            );
        }, 3);
    }

    /** Historical, non-sensitive readback. Never returns decrypted references. */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }
        $row = DB::table('checkout_cod_recipient_carrier_bindings')
            ->where('order_id', $orderId)->first();
        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'binding_key' => (string) $row->binding_key,
            'order_id' => (int) $row->order_id,
            'draft_id' => (int) $row->draft_id,
            'recipient_id' => (int) $row->recipient_id,
            'references_recorded' => true,
            'references_verified_with_carrier' => false,
            'recipient_identity_verified' => false,
            'cod_eligibility_confirmed' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
