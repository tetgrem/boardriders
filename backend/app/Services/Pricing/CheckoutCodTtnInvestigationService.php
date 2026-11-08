<?php

namespace App\Services\Pricing;

use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Append-only administrative casework. Never releases the #14 reconciliation hold. */
final class CheckoutCodTtnInvestigationService
{
    public const OPEN_ABILITY = 'nova-poshta.ttn-investigation.open';
    public const MANAGE_ABILITY = 'nova-poshta.ttn-investigation.manage';
    public const ARCHIVE_ABILITY = 'nova-poshta.ttn-investigation.archive';

    public function __construct(private readonly CheckoutCodTtnInvestigationPolicy $policy)
    {
    }

    /** @return array<string,mixed> */
    public function open(string $orderKey, string $caseKey, DateTimeInterface $deadlineAt): array
    {
        $actor = $this->actor(self::OPEN_ABILITY);
        $orderKey = $this->policy->uuid($orderKey);
        $caseKey = $this->policy->uuid($caseKey);
        $deadline = $this->policy->deadline($deadlineAt, false);
        $requestHmac = $this->digest(['ttn-case-open-v1', $orderKey, $caseKey, $actor, $deadline]);

        return DB::transaction(function () use ($actor, $orderKey, $caseKey, $deadline, $deadlineAt, $requestHmac): array {
            $order = DB::table('orders')->where('order_key', $orderKey)->first(['id']);
            if ($order === null) {
                throw new DomainException('TTN investigation order not found');
            }
            $intent = DB::table('checkout_cod_ttn_submission_intents')
                ->where('order_id', $order->id)->lockForUpdate()->first(['id', 'context_hmac']);
            if ($intent === null) {
                throw new DomainException('TTN investigation needs an intent');
            }
            $existing = DB::table('checkout_cod_ttn_investigation_cases')
                ->where('intent_id', $intent->id)->first();
            if ($existing !== null) {
                if ((string) $existing->case_key !== $caseKey
                    || (int) $existing->opened_by !== $actor
                    || !hash_equals((string) $existing->request_hmac, $requestHmac)) {
                    throw new DomainException('TTN investigation already exists for order');
                }
                return $this->presentCase($existing);
            }
            if (DB::table('checkout_cod_ttn_investigation_cases')->where('case_key', $caseKey)->exists()) {
                throw new DomainException('Investigation UUID already belongs to another intent');
            }
            $this->policy->deadline($deadlineAt);
            $cancelled = DB::table('checkout_cod_ttn_submission_safety_events')
                ->where('intent_id', $intent->id)->where('event_type', 'cancel_before_submission')->exists();
            $triggerId = DB::table('checkout_cod_ttn_submission_safety_events')
                ->where('intent_id', $intent->id)->where('event_type', 'reconciliation_required')
                ->orderByDesc('id')->value('id');
            if ($triggerId === null || $cancelled) {
                throw new DomainException('TTN investigation requires an unresolved safety flag');
            }
            $id = DB::table('checkout_cod_ttn_investigation_cases')->insertGetId([
                'case_key' => $caseKey,
                'intent_id' => $intent->id,
                'trigger_event_id' => $triggerId,
                'intent_context_hmac' => (string) $intent->context_hmac,
                'deadline_at' => $deadline,
                'opened_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);
            return $this->presentCase(DB::table('checkout_cod_ttn_investigation_cases')->where('id', $id)->first());
        }, 3);
    }

    /** @return array<string,mixed> */
    public function record(
        string $caseKey,
        string $operationKey,
        string $eventType,
        string $reasonCode,
        ?int $assigneeId = null,
        ?DateTimeInterface $deadlineAt = null,
    ): array {
        $actor = $this->actor($eventType === 'archive' ? self::ARCHIVE_ABILITY : self::MANAGE_ABILITY);
        $caseKey = $this->policy->uuid($caseKey);
        $operationKey = $this->policy->uuid($operationKey);
        $deadline = $this->policy->event($eventType, $reasonCode, $assigneeId, $deadlineAt, false);
        $requestHmac = $this->digest([
            'ttn-case-event-v1', $caseKey, $operationKey, $actor,
            $eventType, $reasonCode, $assigneeId, $deadline,
        ]);

        return DB::transaction(function () use (
            $actor, $caseKey, $operationKey, $eventType, $reasonCode,
            $assigneeId, $deadline, $deadlineAt, $requestHmac
        ): array {
            $case = DB::table('checkout_cod_ttn_investigation_cases')
                ->where('case_key', $caseKey)->lockForUpdate()->first(['id']);
            if ($case === null) {
                throw new DomainException('TTN investigation case not found');
            }
            $existing = DB::table('checkout_cod_ttn_investigation_events')
                ->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ((int) $existing->case_id !== (int) $case->id
                    || (int) $existing->recorded_by !== $actor
                    || !hash_equals((string) $existing->request_hmac, $requestHmac)) {
                    throw new DomainException('Investigation operation UUID reused with different request');
                }
                return $this->presentEvent($existing);
            }
            if (DB::table('checkout_cod_ttn_investigation_events')
                ->where('case_id', $case->id)->where('event_type', 'archive')->exists()) {
                throw new DomainException('Archived investigation cannot receive new events');
            }
            $this->policy->event($eventType, $reasonCode, $assigneeId, $deadlineAt);
            $id = DB::table('checkout_cod_ttn_investigation_events')->insertGetId([
                'operation_key' => $operationKey,
                'case_id' => $case->id,
                'event_type' => $eventType,
                'reason_code' => $reasonCode,
                'assignee_id' => $assigneeId,
                'deadline_at' => $deadline,
                'recorded_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);
            return $this->presentEvent(DB::table('checkout_cod_ttn_investigation_events')->where('id', $id)->first());
        }, 3);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('TTN investigation permission denied');
        }
        return (int) $id;
    }

    private function digest(array $payload): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new DomainException('Application key is missing');
        }
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);
    }

    private function presentCase(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'case_key' => (string) $row->case_key,
            'intent_id' => (int) $row->intent_id,
            'trigger_event_id' => (int) $row->trigger_event_id,
            'opened_by' => (int) $row->opened_by,
            'deadline_at' => (string) $row->deadline_at,
            'recorded' => true,
            'reconciliation_hold_released' => false,
            'ttn_submission_allowed' => false,
        ];
    }

    private function presentEvent(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'operation_key' => (string) $row->operation_key,
            'case_id' => (int) $row->case_id,
            'event_type' => (string) $row->event_type,
            'reason_code' => (string) $row->reason_code,
            'assignee_id' => $row->assignee_id === null ? null : (int) $row->assignee_id,
            'recorded_by' => (int) $row->recorded_by,
            'reconciliation_hold_released' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
        ];
    }
}
