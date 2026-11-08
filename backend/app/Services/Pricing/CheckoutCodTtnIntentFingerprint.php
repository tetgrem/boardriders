<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recomputable HMAC of the exact local evidence used to stage a TTN intent.
 * Contains IDs and financial fingerprints, never receiver PII or API credentials.
 */
final class CheckoutCodTtnIntentFingerprint
{
    /** @param array<string, mixed> $report @return array<string, mixed> */
    public function capture(string $orderKey, array $report): array
    {
        if (!Str::isUuid($orderKey)
            || ($report['order_key'] ?? null) !== strtolower($orderKey)
            || ($report['all_local_pre_submission_checks_current'] ?? null) !== true
        ) {
            throw new DomainException('TTN intent requires complete local preflight');
        }
        $orderId = (int) ($report['order_id'] ?? 0);
        $draftId = (int) ($report['draft_id'] ?? 0);
        if ($orderId <= 0 || $draftId <= 0) {
            throw new DomainException('TTN preflight has no valid order/draft');
        }

        $draft = DB::table('checkout_cod_waybill_drafts')
            ->where('id', $draftId)->where('order_id', $orderId)
            ->first([
                'id', 'sender_profile_id', 'financial_snapshot_sha256',
                'cod_amount_minor', 'declared_value_minor',
            ]);
        $binding = DB::table('checkout_cod_recipient_carrier_bindings')
            ->where('order_id', $orderId)->where('draft_id', $draftId)
            ->first(['id']);
        $observation = DB::table('checkout_cod_carrier_contact_observations')
            ->where('order_id', $orderId)->orderByDesc('id')
            ->first([
                'id', 'draft_id', 'binding_id', 'sender_profile_id',
                'sender_contact_seen', 'recipient_contact_seen',
            ]);

        if ($draft === null || $binding === null || $observation === null
            || (int) $observation->draft_id !== $draftId
            || (int) $observation->binding_id !== (int) $binding->id
            || (int) $observation->sender_profile_id !== (int) $draft->sender_profile_id
            || !$observation->sender_contact_seen || !$observation->recipient_contact_seen
        ) {
            throw new DomainException('TTN intent carrier references are not current');
        }

        $ids = [
            'order_id' => $orderId,
            'draft_id' => $draftId,
            'binding_id' => (int) $binding->id,
            'observation_id' => (int) $observation->id,
            'sender_profile_id' => (int) $draft->sender_profile_id,
        ];
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new DomainException('Application key is missing');
        }

        $payload = [
            'cod-ttn-intent-context-v1', strtolower($orderKey), $ids,
            (string) $draft->financial_snapshot_sha256,
            (int) $draft->cod_amount_minor, (int) $draft->declared_value_minor,
            $report['profile_code'] ?? null,
            $report['contract_states'] ?? null,
            $report['external_material_states'] ?? null,
        ];
        return [
            'ids' => $ids,
            'context_hmac' => hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret),
        ];
    }
}
