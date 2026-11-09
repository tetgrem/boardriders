<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Paginated, PII-free settlement work queue. */
final class CheckoutCodSettlementQueueService
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
                'Settlement queue permission denied'
            );
        }

        if (
            $limit < 1
            || $limit > 100
            || ($beforeId !== null && $beforeId <= 0)
        ) {
            throw new InvalidArgumentException(
                'Invalid settlement queue pagination'
            );
        }

        $query = DB::table('checkout_cod_settlement_cases as c')
            ->orderByDesc('c.id');

        if ($beforeId !== null) {
            $query->where('c.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'c.id',
            'c.order_id',
            'c.expected_cod_minor',
            'c.expected_fee_minor',
            'c.currency_code',
        ]);

        $more = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $row): array => [
                'case_id' => (int) $row->id,
                'order_id' => (int) $row->order_id,
                'expected_cod_minor' => (int) $row->expected_cod_minor,
                'expected_fee_minor' =>
                    $row->expected_fee_minor === null
                        ? null
                        : (int) $row->expected_fee_minor,
                'currency_code' => (string) $row->currency_code,
                'funds_received_verified' => false,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['case_id']
                : null,
            'cod_paid' => false,
            'automatic_settlement_allowed' => false,
        ];
    }
}
