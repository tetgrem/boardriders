<?php

namespace App\Services\Pricing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Cursor-based bank matching queue with no bank references or customer PII. */
final class CheckoutCodSettlementBankQueueService
{
    /** @return array<string,mixed> */
    public function recent(int $limit = 25, ?int $beforeId = null): array
    {
        $user = Auth::user();
        $actor = $user?->getAuthIdentifier();
        if ((!is_int($actor) && !(is_string($actor) && ctype_digit($actor)))
            || (int) $actor <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )) {
            throw new AuthorizationException('COD bank queue permission denied');
        }
        if ($limit < 1 || $limit > 100 || ($beforeId !== null && $beforeId <= 0)) {
            throw new InvalidArgumentException('Invalid COD bank queue pagination');
        }
        $q = DB::table('checkout_cod_settlement_bank_matches as m')
            ->leftJoin('checkout_cod_settlement_bank_reviews as r',
                'r.match_id', '=', 'm.id')
            ->orderByDesc('m.id');
        if ($beforeId !== null) {
            $q->where('m.id', '<', $beforeId);
        }
        $rows = $q->limit($limit + 1)->get([
            'm.id', 'm.case_id', 'm.match_result', 'm.difference_minor',
            'r.decision',
        ]);
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->map(static fn (object $r): array => [
            'match_id' => (int) $r->id,
            'case_id' => (int) $r->case_id,
            'match_result' => (string) $r->match_result,
            'difference_minor' => (int) $r->difference_minor,
            'review_decision' => $r->decision,
            'funds_received_verified' => false,
        ])->values()->all();

        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['match_id']
                : null,
            'cod_paid' => false,
            'automatic_settlement_allowed' => false,
        ];
    }
}
