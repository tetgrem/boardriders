<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Cursor-paginated internal pack-check queue, excluding recipient PII. */
final class CheckoutCodWarehousePackQueueService
{
    /** @return array<string,mixed> */
    public function recent(
        int $limit = 25,
        ?int $beforeId = null
    ): array {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'Warehouse queue permission denied'
            );
        }

        if (
            $limit < 1
            || $limit > 100
            || ($beforeId !== null && $beforeId < 1)
        ) {
            throw new InvalidArgumentException(
                'Invalid warehouse queue pagination'
            );
        }

        $query = DB::table('checkout_cod_warehouse_pack_checks as c')
            ->leftJoin(
                'checkout_cod_warehouse_pack_reviews as r',
                'r.pack_check_id',
                '=',
                'c.id'
            )
            ->orderByDesc('c.id');

        if ($beforeId !== null) {
            $query->where('c.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'c.id',
            'c.order_id',
            'c.container_type',
            'c.counted_units',
            'c.checked_by',
            'r.decision',
            'r.reviewed_by',
        ]);

        $hasMore = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $r): array => [
                'pack_check_id' => (int) $r->id,
                'order_id' => (int) $r->order_id,
                'container_type' => (string) $r->container_type,
                'counted_units' => (int) $r->counted_units,
                'checked_by' => (int) $r->checked_by,
                'review_decision' => $r->decision,
                'reviewed_by' => $r->reviewed_by === null
                    ? null
                    : (int) $r->reviewed_by,
                'carrier_label_available' => false,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== []
                ? $items[count($items) - 1]['pack_check_id']
                : null,
            'ttn_submission_allowed' => false,
        ];
    }
}
