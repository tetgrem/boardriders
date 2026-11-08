<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append-only safety controls. 'reconciliation_required' is an OPERATOR REPORT,
 * not evidence that our integration ever contacted the carrier.
 * No event can release a hold or authorize InternetDocument/save.
 */
final class CheckoutCodTtnSubmissionSafetyEventService
{
    public const CONTROL_ABILITY = 'nova-poshta.ttn-safety.control';
    public const RECONCILE_ABILITY = 'nova-poshta.ttn-safety.flag-reconciliation';

    public function __construct(private readonly CheckoutCodTtnSubmissionSafetyPolicy $policy)
    {
    }

    /** @return array<string, mixed> */
    public function record(
        string $intentKey,
        string $eventKey,
        string $eventType,
        string $reasonCode,
    ): array {
        $this->policy->validate($eventType, $reasonCode);
        $actor = $this->actor($eventType === 'reconciliation_required'
            ? self::RECONCILE_ABILITY : self::CONTROL_ABILITY);
        if (!Str::isUuid($intentKey) || !Str::isUuid($eventKey)) {
            throw new InvalidArgumentException('Invalid TTN safety event UUID');
        }
        $intentKey = strtolower($intentKey);
        $eventKey = strtolower($eventKey);

        return DB::transaction(function () use (
            $intentKey, $eventKey, $eventType, $reasonCode, $actor
        ): array {
            $intent = DB::table('checkout_cod_ttn_submission_intents')
                ->where('intent_key', $intentKey)->lockForUpdate()->first(['id']);
            if ($intent === null) {
                throw new DomainException('TTN intent not found');
            }
            $hash = $this->digest([
                'cod-ttn-safety-event-v1', $intentKey, $eventKey,
                $eventType, $reasonCode, $actor,
            ]);
            $existing = DB::table('checkout_cod_ttn_submission_safety_events')
                ->where('event_key', $eventKey)->first();
            if ($existing !== null) {
                if ((int) $existing->intent_id !== (int) $intent->id
                    || !hash_equals((string) $existing->request_hmac, $hash)
                ) {
                    throw new DomainException('Safety event UUID reused with changed data');
                }
                return $this->present($existing);
            }
            if (DB::table('checkout_cod_ttn_submission_safety_events')
                ->where('intent_id', $intent->id)
                ->where('event_type', 'cancel_before_submission')->exists()
            ) {
                throw new DomainException('Cancelled TTN intent is terminal');
            }
            if ($eventType === 'cancel_before_submission' &&
                DB::table('checkout_cod_ttn_submission_safety_events')
                    ->where('intent_id', $intent->id)
                    ->where('event_type', 'reconciliation_required')->exists()
            ) {
                throw new DomainException('Reconciliation must precede cancellation');
            }

            $id = DB::table('checkout_cod_ttn_submission_safety_events')->insertGetId([
                'event_key' => $eventKey,
                'intent_id' => $intent->id,
                'event_type' => $eventType,
                'reason_code' => $reasonCode,
                'recorded_by' => $actor,
                'request_hmac' => $hash,
                'created_at' => now(),
            ]);
            return $this->present(DB::table('checkout_cod_ttn_submission_safety_events')
                ->where('id', $id)->first());
        }, 3);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        if ($user === null || !method_exists($user, 'getAuthIdentifier')) {
            throw new AuthorizationException('Authenticated TTN safety operator required');
        }
        $id = $user->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException('TTN safety operation permission denied');
        }
        return (int) $id;
    }

    private function digest(array $payload): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('Application key is missing');
        }
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $key);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'event_key' => (string) $row->event_key,
            'intent_id' => (int) $row->intent_id,
            'event_type' => (string) $row->event_type,
            'reason_code' => (string) $row->reason_code,
            'recorded_by' => (int) $row->recorded_by,
            'event_is_operator_report_only' => true,
            'provider_call_made_by_this_module' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
