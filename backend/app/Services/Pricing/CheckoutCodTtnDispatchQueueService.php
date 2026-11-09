<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** PII-free paginated back-office queue, no carrier call. */
final class CheckoutCodTtnDispatchQueueService
{
    /** @return array<string,mixed> */
    public function recent(
        int $limit = 25,
        ?int $beforeId = null
    ): array {
        $user = Auth::user();
        $actor = $user?->getAuthIdentifier();

        if (
            (!is_int($actor) && !(is_string($actor) && ctype_digit($actor)))
            || (int) $actor <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Dispatch queue view permission denied'
            );
        }

        if (
            $limit < 1
            || $limit > 100
            || ($beforeId !== null && $beforeId < 1)
        ) {
            throw new InvalidArgumentException(
                'Invalid dispatch queue pagination'
            );
        }

        $query = DB::table('checkout_cod_ttn_dispatch_plans as p')
            ->leftJoin(
                'checkout_cod_ttn_dispatch_reviews as r',
                'r.plan_id',
                '=',
                'p.id'
            )
            ->orderByDesc('p.id');

        if ($beforeId !== null) {
            $query->where('p.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'p.id',
            'p.intent_id',
            'p.prepared_by',
            'p.created_at',
            'r.decision',
            'r.reviewed_by',
        ]);

        $more = $rows->count() > $limit;

        $items = $rows
            ->take($limit)
            ->map(static fn (object $row): array => [
                'plan_id' => (int) $row->id,
                'intent_id' => (int) $row->intent_id,
                'prepared_by' => (int) $row->prepared_by,
                'review_decision' => $row->decision,
                'reviewed_by' => $row->reviewed_by === null
                    ? null
                    : (int) $row->reviewed_by,
                'created_at' => (string) $row->created_at,
                'ttn_submission_allowed' => false,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['plan_id']
                : null,
            'ttn_submission_allowed' => false,
        ];
    }
}
