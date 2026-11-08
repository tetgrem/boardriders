<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read-only carrier lookup followed by an append-only local observation.
 * An observed number is NOT a verified match to this order.
 * Existing observation UUID retries never call the carrier again.
 */
final class CheckoutCodTtnReconciliationScanService
{
    public const SCAN_ABILITY = 'nova-poshta.ttn-reconciliation.scan';

    public function __construct(
        private readonly NovaPoshtaTtnReconciliationPolicy $policy,
        private readonly NovaPoshtaTtnNumberProbe $probe,
    ) {
    }

    /** @return array<string, mixed> */
    public function inspect(string $intentKey, string $operationKey, string $candidateNumber): array
    {
        $actor = $this->actor();
        if (!Str::isUuid($intentKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid reconciliation UUID');
        }
        $intentKey = strtolower($intentKey);
        $operationKey = strtolower($operationKey);
        $number = $this->policy->number($candidateNumber);
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new DomainException('Application key missing');
        }

        $intent = DB::table('checkout_cod_ttn_submission_intents')
            ->where('intent_key', $intentKey)->first(['id', 'context_hmac']);
        if ($intent === null) {
            throw new DomainException('TTN intent not found');
        }
        $intentId = (int) $intent->id;
        $numberHmac = hash_hmac('sha256', $number, $secret);

        // A historical retry remains possible after further safety events.
        $existing = DB::table('checkout_cod_ttn_reconciliation_scans')
            ->where('operation_key', $operationKey)->first();
        if ($existing !== null) {
            $this->matchExisting($existing, $intentId, $operationKey,
                $actor, $numberHmac, $intentKey, $secret);
            return $this->present($existing);
        }

        $eventId = $this->currentTriggerEvent($intentId);
        // Never hold row locks across carrier HTTP. A concurrent read may repeat,
        // but the unique operation key ensures only one observation is persisted.
        $lookup = $this->probe->inspect($number);

        return DB::transaction(function () use (
            $intentId, $intentKey, $operationKey, $actor,
            $number, $numberHmac, $secret, $eventId, $lookup
        ): array {
            $intent = DB::table('checkout_cod_ttn_submission_intents')
                ->where('id', $intentId)->lockForUpdate()->first(['id', 'context_hmac']);
            if ($intent === null) {
                throw new DomainException('TTN intent disappeared');
            }
            $existing = DB::table('checkout_cod_ttn_reconciliation_scans')
                ->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                $this->matchExisting($existing, $intentId, $operationKey,
                    $actor, $numberHmac, $intentKey, $secret);
                return $this->present($existing);
            }
            if ($this->currentTriggerEvent($intentId) !== $eventId) {
                throw new DomainException('Reconciliation trigger changed during lookup');
            }
            $requestHmac = $this->requestHmac($intentKey, $operationKey,
                $actor, $eventId, (string) $intent->context_hmac, $numberHmac, $secret);

            $id = DB::table('checkout_cod_ttn_reconciliation_scans')->insertGetId([
                'operation_key' => $operationKey,
                'intent_id' => $intentId,
                'trigger_event_id' => $eventId,
                'candidate_number_ciphertext' => Crypt::encryptString($number),
                'candidate_number_hmac' => $numberHmac,
                'intent_context_hmac' => (string) $intent->context_hmac,
                'provider_result' => $lookup['result'],
                'rows_examined' => $lookup['rows_examined'],
                'scanned_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);
            return $this->present(DB::table('checkout_cod_ttn_reconciliation_scans')
                ->where('id', $id)->first());
        }, 3);
    }

    private function currentTriggerEvent(int $intentId): int
    {
        $cancelled = DB::table('checkout_cod_ttn_submission_safety_events')
            ->where('intent_id', $intentId)
            ->where('event_type', 'cancel_before_submission')->exists();
        if ($cancelled) {
            throw new DomainException('Cancelled intent cannot be reconciled');
        }
        $id = DB::table('checkout_cod_ttn_submission_safety_events')
            ->where('intent_id', $intentId)
            ->where('event_type', 'reconciliation_required')
            ->orderByDesc('id')->value('id');
        if ($id === null) {
            throw new DomainException('No reported uncertain TTN outcome to reconcile');
        }
        return (int) $id;
    }

    private function requestHmac(
        string $intentKey, string $operationKey, int $actor, int $eventId,
        string $contextHmac, string $numberHmac, string $secret,
    ): string {
        return hash_hmac('sha256', json_encode([
            'cod-ttn-reconciliation-scan-v1', $intentKey, $operationKey,
            $actor, $eventId, $contextHmac, $numberHmac,
        ], JSON_THROW_ON_ERROR), $secret);
    }

    private function matchExisting(
        object $row, int $intentId, string $operationKey, int $actor,
        string $numberHmac, string $intentKey, string $secret,
    ): void {
        $expected = $this->requestHmac($intentKey, $operationKey, $actor,
            (int) $row->trigger_event_id, (string) $row->intent_context_hmac,
            $numberHmac, $secret);
        if ((int) $row->intent_id !== $intentId
            || (int) $row->scanned_by !== $actor
            || !hash_equals((string) $row->candidate_number_hmac, $numberHmac)
            || !hash_equals((string) $row->request_hmac, $expected)
        ) {
            throw new DomainException('Reconciliation UUID reused for different request');
        }
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(self::SCAN_ABILITY)
        ) {
            throw new AuthorizationException('TTN reconciliation scan permission denied');
        }
        return (int) $id;
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'intent_id' => (int) $row->intent_id,
            'trigger_event_id' => (int) $row->trigger_event_id,
            'provider_result' => (string) $row->provider_result,
            'rows_examined' => (int) $row->rows_examined,
            'scanned_by' => (int) $row->scanned_by,
            'number_observed_in_one_response' => $row->provider_result === 'number_observed_once',
            'waybill_linked_to_order' => false,
            'absence_proven' => false,
            'manual_reconciliation_required' => true,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
