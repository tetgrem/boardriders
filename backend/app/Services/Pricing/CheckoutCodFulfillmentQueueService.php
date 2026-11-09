<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Cursor-based CRM queue; avoids PII and carrier credentials. */
final class CheckoutCodFulfillmentQueueService
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
                'COD fulfillment queue permission denied'
            );
        }

        if (
            $limit < 1
            || $limit > 100
            || ($beforeId !== null && $beforeId <= 0)
        ) {
            throw new InvalidArgumentException(
                'Invalid fulfillment queue pagination'
            );
        }

        $query = DB::table('checkout_cod_fulfillment_staging as s')
            ->leftJoin(
                'checkout_cod_fulfillment_staging_reviews as r',
                'r.staging_id',
                '=',
                's.id'
            )
            ->orderByDesc('s.id');

        if ($beforeId !== null) {
            $query->where('s.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            's.id',
            's.order_id',
            's.expected_units',
            's.staged_by',
            'r.decision',
            'r.reviewed_by',
        ]);

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $ids = $rows->pluck('id')->all();

        $heldIds = $ids === []
            ? []
            : DB::table('checkout_cod_fulfillment_holds')
                ->whereIn('staging_id', $ids)
                ->distinct()
                ->pluck('staging_id')
                ->all();

        $heldLookup = array_fill_keys(
            array_map('intval', $heldIds),
            true
        );

        $items = $rows->map(static fn (object $row): array => [
            'staging_id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'expected_units' => (int) $row->expected_units,
            'staged_by' => (int) $row->staged_by,
            'review_decision' => $row->decision,
            'reviewed_by' => $row->reviewed_by === null
                ? null
                : (int) $row->reviewed_by,
            'has_internal_hold' =>
                isset($heldLookup[(int) $row->id]),
            'carrier_handoff_allowed' => false,
        ])->values()->all();

        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== []
                ? $items[count($items) - 1]['staging_id']
                : null,
            'carrier_handoff_allowed' => false,
        ];
    }
}
