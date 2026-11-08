<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Paginated PII-free investigation queue for the future back-office/CRM. */
final class CheckoutCodTtnInvestigationQueueService
{
    public function __construct(private readonly CheckoutCodTtnInvestigationPolicy $policy)
    {
    }

    /** @return array<string,mixed> */
    public function recent(int $limit = 25, ?int $beforeId = null): array
    {
        $user = Auth::user();
        $actor = $user?->getAuthIdentifier();
        if ((!is_int($actor) && !(is_string($actor) && ctype_digit($actor)))
            || (int) $actor <= 0
            || !Gate::forUser($user)->allows(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY)) {
            throw new AuthorizationException('TTN investigation queue permission denied');
        }
        if ($limit < 1 || $limit > 100 || ($beforeId !== null && $beforeId <= 0)) {
            throw new InvalidArgumentException('Invalid investigation pagination');
        }

        $query = DB::table('checkout_cod_ttn_investigation_cases')->orderByDesc('id');
        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }
        $rows = $query->limit($limit + 1)->get(['id', 'case_key', 'intent_id', 'opened_by', 'deadline_at']);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $ids = $rows->pluck('id')->all();
        $events = $ids === [] ? collect() : DB::table('checkout_cod_ttn_investigation_events')
            ->whereIn('case_id', $ids)->orderBy('id')
            ->get(['case_id', 'event_type', 'assignee_id', 'deadline_at'])->groupBy('case_id');
        $now = CarbonImmutable::now('UTC');
        $items = [];
        foreach ($rows as $row) {
            $timeline = [];
            foreach ($events->get($row->id, collect()) as $event) {
                $timeline[] = [
                    'event_type' => (string) $event->event_type,
                    'assignee_id' => $event->assignee_id === null ? null : (int) $event->assignee_id,
                    'deadline_at' => $event->deadline_at === null ? null : (string) $event->deadline_at,
                ];
            }
            $projection = $this->policy->project((string) $row->deadline_at, $timeline, $now);
            $items[] = array_merge([
                'case_id' => (int) $row->id,
                'case_key' => (string) $row->case_key,
                'intent_id' => (int) $row->intent_id,
                'opened_by' => (int) $row->opened_by,
            ], $projection);
        }
        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== [] ? $items[count($items) - 1]['case_id'] : null,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
        ];
    }
}
