<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Paginated, PII-free work queue for bank batches. */
final class CheckoutCodBankBatchQueueService
{
    /** @return array<string, mixed> */
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
                'Bank batch queue permission denied'
            );
        }

        if (
            $limit < 1
            || $limit > 100
            || ($beforeId !== null && $beforeId <= 0)
        ) {
            throw new InvalidArgumentException(
                'Invalid bank batch queue pagination'
            );
        }

        $q = DB::table('checkout_cod_bank_batches as b')
            ->leftJoin(
                'checkout_cod_bank_batch_reviews as r',
                'r.batch_id',
                '=',
                'b.id'
            )
            ->orderByDesc('b.id');

        if ($beforeId !== null) {
            $q->where('b.id', '<', $beforeId);
        }

        $rows = $q->limit($limit + 1)->get([
            'b.id',
            'b.claimed_net_minor',
            'r.decision',
        ]);

        $hasMore = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $row): array => [
                'batch_id' => (int) $row->id,
                'claimed_net_minor' => (int) $row->claimed_net_minor,
                'currency_code' => 'UAH',
                'review_decision' => $row->decision,
                'funds_received_verified' => false,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== []
                ? $items[count($items) - 1]['batch_id']
                : null,
            'cod_paid' => false,
        ];
    }
}
