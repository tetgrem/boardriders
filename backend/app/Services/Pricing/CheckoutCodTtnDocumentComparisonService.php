<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Historical post-incident comparison, not proof of TTN ownership.
 * No checkout expiration restriction: investigation must work after an expired order.
 * Never writes to orders, releases holds or calls InternetDocument/save.
 */
final class CheckoutCodTtnDocumentComparisonService
{
    public const COMPARE_ABILITY = 'nova-poshta.ttn-reconciliation.compare-document';

    public function __construct(private readonly NovaPoshtaTtnDocumentProbe $probe)
    {
    }

    /** @return array<string,mixed> */
    public function compare(string $scanOperationKey, string $operationKey): array
    {
        $actor = $this->actor();
        if (!Str::isUuid($scanOperationKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid document comparison UUID');
        }
        $scanKey = strtolower($scanOperationKey);
        $key = strtolower($operationKey);
        $context = $this->context($scanKey);
        if ($actor === $context['scanned_by'] || $actor === $context['reviewed_by']) {
            throw new DomainException('Independent document comparison operator required');
        }

        // Historical, exact-key retry: no carrier requests.
        $existing = DB::table('checkout_cod_ttn_document_comparisons')
            ->where('operation_key', $key)->first();
        if ($existing !== null) {
            $this->checkRetry($existing, $context, $actor, $key);
            return $this->present($existing);
        }
        if (DB::table('checkout_cod_ttn_document_comparisons')
            ->where('scan_id', $context['scan_id'])->exists()) {
            throw new DomainException('Document comparison already recorded for scan');
        }

        $result = $this->probe->inspect($context['number'], $context['expected']);
        $refHash = $result['document_ref'] === null ? null
            : hash_hmac('sha256', $result['document_ref'], $this->secret());

        return DB::transaction(function () use ($context, $key, $actor, $result, $refHash): array {
            $intent = DB::table('checkout_cod_ttn_submission_intents')
                ->where('id', $context['intent_id'])->lockForUpdate()->first(['context_hmac']);
            $current = $this->context($context['scan_key']);
            if ($intent === null || $current['identity_hmac'] !== $context['identity_hmac']
                || !hash_equals((string) $intent->context_hmac, $context['context_hmac'])) {
                throw new DomainException('Historical reconciliation sources changed');
            }
            $existing = DB::table('checkout_cod_ttn_document_comparisons')
                ->where('operation_key', $key)->first();
            if ($existing !== null) {
                $this->checkRetry($existing, $context, $actor, $key);
                return $this->present($existing);
            }
            if (DB::table('checkout_cod_ttn_document_comparisons')
                ->where('scan_id', $context['scan_id'])->exists()) {
                throw new DomainException('Comparison already recorded by another operation');
            }

            $id = DB::table('checkout_cod_ttn_document_comparisons')->insertGetId([
                'operation_key' => $key,
                'scan_id' => $context['scan_id'],
                'review_id' => $context['review_id'],
                'intent_id' => $context['intent_id'],
                'summary' => $result['summary'],
                'checks' => json_encode($result['checks'], JSON_THROW_ON_ERROR),
                'candidate_number_hmac' => $context['number_hmac'],
                'intent_context_hmac' => $context['context_hmac'],
                'document_ref_hmac' => $refHash,
                'compared_by' => $actor,
                'request_hmac' => $this->requestHmac($context, $key, $actor),
                'created_at' => now(),
            ]);
            return $this->present(DB::table('checkout_cod_ttn_document_comparisons')
                ->where('id', $id)->first());
        }, 3);
    }

    /** @return array<string,mixed> */
    private function context(string $scanKey): array
    {
        $row = DB::table('checkout_cod_ttn_reconciliation_scans as s')
            ->join('checkout_cod_ttn_submission_intents as i', 'i.id', '=', 's.intent_id')
            ->join('checkout_cod_ttn_reconciliation_reviews as r', 'r.scan_id', '=', 's.id')
            ->join('checkout_cod_waybill_drafts as d', 'd.id', '=', 'i.draft_id')
            ->join('nova_poshta_sender_profiles as sender', 'sender.id', '=', 'i.sender_profile_id')
            ->join('checkout_cod_recipient_carrier_bindings as b', 'b.id', '=', 'i.binding_id')
            ->where('s.operation_key', $scanKey)
            ->first([
                's.id as scan_id', 's.operation_key as scan_key', 's.intent_id',
                's.trigger_event_id', 's.provider_result', 's.scanned_by',
                's.candidate_number_ciphertext', 's.candidate_number_hmac',
                's.intent_context_hmac as scan_context',
                'i.context_hmac as intent_context', 'i.draft_id', 'i.binding_id',
                'r.id as review_id', 'r.reviewed_by', 'r.decision', 'r.reason_code',
                'd.sender_city_ref', 'd.recipient_city_ref', 'd.destination_ref',
                'd.service_type', 'd.gross_grams', 'd.declared_value_minor',
                'sender.counterparty_ref as sender_counterparty',
                'b.counterparty_ref_ciphertext as recipient_counterparty_ciphertext',
            ]);
        if ($row === null || in_array((int) ($row->scanned_by ?? 0), [(int) ($row->reviewed_by ?? 0)], true)
            || $row->provider_result !== 'number_observed_once'
            || $row->decision !== 'escalate_to_carrier' || $row->reason_code !== 'candidate_seen'
            || !hash_equals((string) $row->scan_context, (string) $row->intent_context)) {
            throw new DomainException('An independently escalated observed TTN scan is required');
        }
        try {
            $number = Crypt::decryptString((string) $row->candidate_number_ciphertext);
            $recipient = Crypt::decryptString((string) $row->recipient_counterparty_ciphertext);
        } catch (DecryptException) {
            throw new DomainException('Historical carrier references cannot be decrypted');
        }
        if (!preg_match('/^[0-9]{14}$/D', $number)
            || !hash_equals((string) $row->candidate_number_hmac,
                hash_hmac('sha256', $number, $this->secret()))
            || !Str::isUuid($recipient)) {
            throw new DomainException('Historical TTN candidate or counterparty reference invalid');
        }
        $expected = [
            'IntDocNumber' => $number,
            'Sender' => strtolower((string) $row->sender_counterparty),
            'Recipient' => strtolower($recipient),
            'CitySender' => strtolower((string) $row->sender_city_ref),
            'CityRecipient' => strtolower((string) $row->recipient_city_ref),
            'RecipientAddress' => strtolower((string) $row->destination_ref),
            'ServiceType' => (string) $row->service_type,
            'Weight' => (int) $row->gross_grams,
            'Cost' => (int) $row->declared_value_minor,
        ];
        $data = [
            'scan_id' => (int) $row->scan_id, 'scan_key' => $scanKey,
            'scanned_by' => (int) $row->scanned_by,
            'reviewed_by' => (int) $row->reviewed_by,
            'review_id' => (int) $row->review_id, 'intent_id' => (int) $row->intent_id,
            'context_hmac' => (string) $row->intent_context,
            'number_hmac' => (string) $row->candidate_number_hmac,
            'number' => $number, 'expected' => $expected,
        ];
        $data['identity_hmac'] = hash_hmac('sha256', json_encode([
            'ttn-comparison-sources-v1', $data['scan_id'], $data['review_id'],
            $data['intent_id'], $data['context_hmac'], $data['number_hmac'], $expected,
        ], JSON_THROW_ON_ERROR), $this->secret());
        return $data;
    }

    private function checkRetry(object $existing, array $context, int $actor, string $key): void
    {
        if ((int) $existing->scan_id !== $context['scan_id']
            || (int) $existing->review_id !== $context['review_id']
            || (int) $existing->intent_id !== $context['intent_id']
            || (int) $existing->compared_by !== $actor
            || !hash_equals((string) $existing->candidate_number_hmac, $context['number_hmac'])
            || !hash_equals((string) $existing->intent_context_hmac, $context['context_hmac'])
            || !hash_equals((string) $existing->request_hmac, $this->requestHmac($context, $key, $actor))) {
            throw new DomainException('Comparison UUID reused for another operation');
        }
    }

    private function requestHmac(array $ctx, string $key, int $actor): string
    {
        return hash_hmac('sha256', json_encode([
            'ttn-document-comparison-v1', $ctx['scan_key'], $key,
            $ctx['scan_id'], $ctx['review_id'], $ctx['intent_id'],
            $ctx['identity_hmac'], $actor,
        ], JSON_THROW_ON_ERROR), $this->secret());
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::COMPARE_ABILITY)) {
            throw new AuthorizationException('TTN document comparison permission denied');
        }
        return (int) $id;
    }

    private function secret(): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new DomainException('Application key missing');
        }
        return $secret;
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        $checks = is_string($row->checks)
            ? json_decode($row->checks, true, 512, JSON_THROW_ON_ERROR)
            : (array) $row->checks;
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'scan_id' => (int) $row->scan_id,
            'review_id' => (int) $row->review_id,
            'intent_id' => (int) $row->intent_id,
            'summary' => (string) $row->summary,
            'checks' => $checks,
            'compared_by' => (int) $row->compared_by,
            'document_ref_seen' => $row->document_ref_hmac !== null,
            'carrier_document_ownership_verified' => false,
            'waybill_linked_to_order' => false,
            'reconciliation_hold_released' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
