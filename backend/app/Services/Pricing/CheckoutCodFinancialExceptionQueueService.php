<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Cursor pagination of open holds, without personal or bank references. */
final class CheckoutCodFinancialExceptionQueueService
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
                'Financial exception queue denied'
            );
        }

        if (
            $limit < 1
            || $limit > 100
            || ($beforeId !== null && $beforeId < 1)
        ) {
            throw new InvalidArgumentException(
                'Invalid exception queue pagination'
            );
        }

        $query = DB::table('checkout_cod_financial_exceptions as e')
            ->leftJoin(
                'checkout_cod_financial_exception_reviews as r',
                'r.exception_id',
                '=',
                'e.id'
            )
            ->where(function ($query): void {
                $query->whereNull('r.id')
                    ->orWhere(
                        'r.decision',
                        'hold_for_investigation'
                    );
            })
            ->orderByDesc('e.id');

        if ($beforeId !== null) {
            $query->where('e.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'e.id',
            'e.source_kind',
            'e.source_id',
            'e.reason_code',
            'e.exception_key',
            'r.decision',
        ]);

        $hasMore = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $row): array => [
                'exception_id' => (int) $row->id,
                'exception_key' => (string) $row->exception_key,
                'source_kind' => (string) $row->source_kind,
                'source_id' => (int) $row->source_id,
                'reason_code' => (string) $row->reason_code,
                'status' => $row->decision === null
                    ? 'pending_review_hold'
                    : 'investigation_hold',
                'cod_paid' => false,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== []
                ? $items[count($items) - 1]['exception_id']
                : null,
            'automatic_settlement_allowed' => false,
        ];
    }
}
