<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Projection of existing read-only carrier observations. Never changes OMS or payments. */
final class CheckoutCodTtnLifecycleService
{
    public const VIEW_ABILITY = 'cod.operations.ttn_lifecycle.view';
    public const SYNC_ABILITY = 'cod.operations.ttn_lifecycle.sync';

    public function __construct(
        private readonly CheckoutCodTtnLifecyclePolicy $policy,
        private readonly CheckoutCodTtnLifecycleSigner $signer
    ) {
    }

    public function sync(string $trackingKey, int $limit = 100): array
    {
        $this->authorize(self::SYNC_ABILITY);
        $case = $this->case($trackingKey);
        return $this->synchronizeCase($case, $limit);
    }

    /** Scheduler ingests existing trusted polling rows; it never polls carrier itself. */
    public function scanDue(int $limit = 50): array
    {
        if (!app()->runningInConsole()) {
            throw new AuthorizationException('COD lifecycle scan is console-only');
        }
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Lifecycle scan limit must be 1..100');
        }
        $cases = DB::table('checkout_cod_ttn_tracking_cases as c')
            ->whereExists(static function ($q): void {
                $q->selectRaw('1')
                    ->from('checkout_cod_ttn_tracking_observations as o')
                    ->whereColumn('o.tracking_case_id', 'c.id')
                    ->whereNotExists(static function ($inner): void {
                        $inner->selectRaw('1')
                            ->from('checkout_cod_ttn_lifecycle_events as e')
                            ->whereColumn('e.observation_id', 'o.id');
                    });
            })
            ->orderBy('c.id')->limit($limit)
            ->get(['c.id', 'c.tracking_key', 'c.intent_id']);

        $events = 0;
        foreach ($cases as $case) {
            $events += $this->synchronizeCase($case, 100)['events_created'];
        }
        return [
            'cases_scanned' => $cases->count(),
            'events_created' => $events,
            'orders_changed' => false,
            'payment_status_changed' => false,
        ];
    }

    public function status(string $trackingKey): array
    {
        $this->authorize(self::VIEW_ABILITY);
        $case = $this->case($trackingKey);
        return $this->statusFor($case);
    }

    public function history(string $trackingKey, int $limit = 50, ?int $beforeId = null): array
    {
        $this->authorize(self::VIEW_ABILITY);
        $this->validatePage($limit, $beforeId);
        $case = $this->case($trackingKey);
        $q = DB::table('checkout_cod_ttn_lifecycle_events')
            ->where('tracking_case_id', $case->id);
        if ($beforeId !== null) {
            $q->where('id', '<', $beforeId);
        }
        $rows = $q->orderByDesc('id')->limit($limit + 1)->get();
        $items = [];
        foreach ($rows->take($limit) as $row) {
            $this->assertEvent($row);
            $items[] = $this->presentEvent($row);
        }
        return [
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['event_id'] : null,
            'evidence_only' => true,
        ];
    }

    /** Operational follow-ups based on observations, not shipment ownership. */
    public function queue(string $filter = 'all', int $limit = 30, ?int $beforeId = null): array
    {
        $this->authorize(self::VIEW_ABILITY);
        $this->validatePage($limit, $beforeId);
        if (!in_array($filter, ['all', 'return', 'pickup', 'risk', 'cod'], true)) {
            throw new InvalidArgumentException('Invalid lifecycle queue filter');
        }
        $q = DB::table('checkout_cod_ttn_lifecycle_events as e')
            ->join('checkout_cod_ttn_tracking_cases as c', 'c.id', '=', 'e.tracking_case_id')
            ->join('checkout_cod_ttn_submission_intents as i', 'i.id', '=', 'c.intent_id')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereRaw('(e.id = (SELECT MAX(x.id) FROM checkout_cod_ttn_lifecycle_events x WHERE x.tracking_case_id = e.tracking_case_id))')
            ->where('e.action_code', '<>', 'monitor');
        if ($filter !== 'all') {
            $codes = match ($filter) {
                'return' => ['follow_up_return'],
                'pickup' => ['contact_recipient'],
                'risk' => ['manual_carrier_review', 'investigate_ttn'],
                'cod' => ['verify_cod_collection'],
            };
            $q->whereIn('e.action_code', $codes);
        }
        if ($beforeId !== null) {
            $q->where('e.id', '<', $beforeId);
        }
        $rows = $q->orderByDesc('e.id')->limit($limit + 1)
            ->get(['e.*', 'c.tracking_key', 'o.order_key']);
        $items = [];
        foreach ($rows->take($limit) as $row) {
            $this->assertEvent($row);
            $items[] = [
                'tracking_key' => (string) $row->tracking_key,
                'order_key' => (string) $row->order_key,
                'event_id' => (int) $row->id,
                'observed_state' => (string) $row->effective_state,
                'action_code' => (string) $row->action_code,
                'needs_review' => (bool) $row->needs_review,
                'customer_delivery_confirmed' => false,
            ];
        }
        return [
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['event_id'] : null,
        ];
    }

    private function synchronizeCase(object $case, int $limit): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Lifecycle per-case batch limit must be 1..200');
        }
        return DB::transaction(function () use ($case, $limit): array {
            // All writers for the same intent serialize on this row, including DB trigger.
            $intent = DB::table('checkout_cod_ttn_submission_intents')
                ->where('id', $case->intent_id)->lockForUpdate()->first(['id', 'order_id']);
            if ($intent === null) {
                throw new DomainException('Tracking intent missing');
            }
            $last = DB::table('checkout_cod_ttn_lifecycle_events')
                ->where('tracking_case_id', $case->id)
                ->orderByDesc('id')->first();
            if ($last !== null) {
                $this->assertEvent($last);
            }
            $previous = [
                'state' => $last?->effective_state ?? 'awaiting_evidence',
                'code' => $last?->effective_status_code,
                'at' => $last?->effective_observed_at === null
                    ? null
                    : CarbonImmutable::parse(
                        (string) $last->effective_observed_at
                    )->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'review' => $last !== null && (bool) $last->needs_review,
            ];
            $prevHmac = $last?->event_hmac;
            $after = $last?->observation_id ?? 0;
            $observations = DB::table('checkout_cod_ttn_tracking_observations')
                ->where('tracking_case_id', $case->id)
                ->where('id', '>', $after)
                ->orderBy('id')->limit($limit)->get();
            foreach ($observations as $observation) {
                $at = CarbonImmutable::parse($observation->observed_started_at)
                    ->utc()->format('Y-m-d\TH:i:s.u\Z');
                $result = $this->policy->advance(
                    $previous, (string) $observation->outcome,
                    $observation->status_code === null ? null : (string) $observation->status_code,
                    $at
                );
                $payload = [
                    'tracking_case_id' => (int) $case->id,
                    'observation_id' => (int) $observation->id,
                    'order_id' => (int) $intent->order_id,
                    'source_outcome' => (string) $observation->outcome,
                    'source_status_code' => $observation->status_code === null
                        ? null : (string) $observation->status_code,
                    'source_observed_at' => $at,
                    'decision' => $result['decision'],
                    'effective_state' => $result['state'],
                    'effective_status_code' => $result['code'],
                    'effective_observed_at' => $result['at'],
                    'needs_review' => $result['review'],
                    'action_code' => $result['action'],
                    'previous_hmac' => $prevHmac,
                ];
                $hmac = $this->signer->digest($payload);
                DB::table('checkout_cod_ttn_lifecycle_events')->insert([
                    'tracking_case_id' => (int) $case->id,
                    'observation_id' => (int) $observation->id,
                    'order_id' => (int) $intent->order_id,
                    'source_outcome' => $payload['source_outcome'],
                    'source_status_code' => $payload['source_status_code'],
                    'source_observed_at' => $observation->observed_started_at,
                    'decision' => $result['decision'],
                    'effective_state' => $result['state'],
                    'effective_status_code' => $result['code'],
                    'effective_observed_at' => $result['at'],
                    'needs_review' => $result['review'],
                    'action_code' => $result['action'],
                    'snapshot_json' => $this->signer->json($payload),
                    'previous_hmac' => $prevHmac,
                    'event_hmac' => $hmac,
                    'created_at' => CarbonImmutable::now('UTC'),
                ]);
                $previous = [
                    'state' => $result['state'],
                    'code' => $result['code'],
                    'at' => $result['at'],
                    'review' => $result['review'],
                ];
                $prevHmac = $hmac;
            }
            return [
                'tracking_key' => (string) $case->tracking_key,
                'events_created' => $observations->count(),
                'observed_state' => $previous['state'],
                'action_code' => $this->policy->action($previous['state'], $previous['review']),
                'needs_review' => $previous['review'],
                'order_status_changed' => false,
                'cod_paid' => false,
            ];
        }, 3);
    }

    private function statusFor(object $case): array
    {
        $last = DB::table('checkout_cod_ttn_lifecycle_events')
            ->where('tracking_case_id', $case->id)->orderByDesc('id')->first();
        if ($last !== null) {
            $this->assertEvent($last);
        }
        return [
            'tracking_key' => (string) $case->tracking_key,
            'order_key' => (string) $case->order_key,
            'observed_state' => (string) ($last?->effective_state ?? 'awaiting_evidence'),
            'effective_status_code' => $last?->effective_status_code,
            'latest_decision' => $last?->decision,
            'needs_review' => $last !== null && (bool) $last->needs_review,
            'action_code' => $last?->action_code ?? 'monitor',
            'last_processed_observation_id' => $last?->observation_id === null
                ? null : (int) $last->observation_id,
            'tracking_candidate_only' => true,
            'customer_delivery_confirmed' => false,
            'return_received_by_sender_confirmed' => false,
            'carrier_ownership_verified' => false,
            'cod_paid' => false,
            'order_status_changed' => false,
            'inventory_restocked' => false,
        ];
    }

    private function assertEvent(object $row): void
    {
        try {
            $payload = json_decode((string) $row->snapshot_json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('COD lifecycle snapshot JSON invalid');
        }
        if (!is_array($payload)) {
            throw new DomainException('COD lifecycle snapshot invalid');
        }
        $expected = [
            'tracking_case_id' => (int) $row->tracking_case_id,
            'observation_id' => (int) $row->observation_id,
            'order_id' => (int) $row->order_id,
            'source_outcome' => (string) $row->source_outcome,
            'source_status_code' => $row->source_status_code,
            'source_observed_at' => CarbonImmutable::parse($row->source_observed_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'decision' => (string) $row->decision,
            'effective_state' => (string) $row->effective_state,
            'effective_status_code' => $row->effective_status_code,
            'effective_observed_at' => $row->effective_observed_at === null ? null
                : CarbonImmutable::parse($row->effective_observed_at)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'needs_review' => (bool) $row->needs_review,
            'action_code' => (string) $row->action_code,
            'previous_hmac' => $row->previous_hmac,
        ];
        if (!hash_equals($this->signer->json($expected), $this->signer->json($payload))
            || !hash_equals((string) $row->event_hmac, $this->signer->digest($expected))) {
            throw new DomainException('COD lifecycle event HMAC or payload mismatch');
        }
        $predecessor = DB::table('checkout_cod_ttn_lifecycle_events')
            ->where('tracking_case_id', $row->tracking_case_id)
            ->where('id', '<', $row->id)->orderByDesc('id')->first(['event_hmac']);
        if (($predecessor?->event_hmac) !== $row->previous_hmac) {
            throw new DomainException('COD lifecycle event chain broken');
        }
    }

    private function presentEvent(object $row): array
    {
        return [
            'event_id' => (int) $row->id,
            'observation_id' => (int) $row->observation_id,
            'source_status_code' => $row->source_status_code,
            'source_outcome' => $row->source_outcome,
            'decision' => (string) $row->decision,
            'observed_state' => (string) $row->effective_state,
            'needs_review' => (bool) $row->needs_review,
            'action_code' => (string) $row->action_code,
            'created_at' => (string) $row->created_at,
        ];
    }

    private function case(string $trackingKey): object
    {
        if (!Str::isUuid($trackingKey)) {
            throw new InvalidArgumentException('Invalid COD lifecycle tracking UUID');
        }
        $case = DB::table('checkout_cod_ttn_tracking_cases as c')
            ->join('checkout_cod_ttn_submission_intents as i', 'i.id', '=', 'c.intent_id')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('c.tracking_key', strtolower($trackingKey))
            ->first(['c.id', 'c.tracking_key', 'c.intent_id', 'o.order_key']);
        if ($case === null) {
            throw new DomainException('COD tracking case missing');
        }
        return $case;
    }

    private function authorize(string $ability): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id < 1
            || !Gate::forUser($user)->allows(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY)
            || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('COD lifecycle CRM permission denied');
        }
    }

    private function validatePage(int $limit, ?int $beforeId): void
    {
        if ($limit < 1 || $limit > 100 || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException('Invalid COD lifecycle pagination');
        }
    }
}
