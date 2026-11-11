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
 * Read-only investigation status and paginated worklist.
 * No bank or personal references.
 */
final class CheckoutCodFinancialResolutionStatusService
{
    /** @return array<string,mixed> */
    public function inspect(string $exceptionKey): array
    {
        $this->authorize();

        if (!Str::isUuid($exceptionKey)) {
            throw new InvalidArgumentException(
                'Invalid financial exception UUID'
            );
        }

        $row = DB::table('checkout_cod_financial_exceptions as e')
            ->leftJoin(
                'checkout_cod_financial_exception_reviews as r',
                'r.exception_id',
                '=',
                'e.id'
            )
            ->where(
                'e.exception_key',
                strtolower($exceptionKey)
            )
            ->first([
                'e.id',
                'e.exception_key',
                'e.source_kind',
                'e.reason_code',
                'r.decision AS initial_decision',
            ]);

        if ($row === null) {
            throw new DomainException(
                'Financial exception not found'
            );
        }

        return $this->present($row);
    }

    /** @return array<string,mixed> */
    public function recent(
        int $limit = 25,
        ?int $beforeId = null
    ): array {
        $this->authorize();

        if (
            $limit < 1
            || $limit > 50
            || ($beforeId !== null && $beforeId < 1)
        ) {
            throw new InvalidArgumentException(
                'Invalid financial resolution pagination'
            );
        }

        $query = DB::table('checkout_cod_financial_exceptions as e')
            ->join(
                'checkout_cod_financial_exception_reviews as r',
                'r.exception_id',
                '=',
                'e.id'
            )
            ->where('r.decision', 'hold_for_investigation')
            ->orderByDesc('e.id');

        if ($beforeId !== null) {
            $query->where('e.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'e.id',
            'e.exception_key',
            'e.source_kind',
            'e.reason_code',
            'r.decision AS initial_decision',
        ]);

        $hasMore = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(
                fn (object $r): array => $this->present($r)
            )
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $hasMore && $items !== []
                ? $items[count($items) - 1]['exception_id']
                : null,
            'funds_received_verified' => false,
            'automatic_settlement_allowed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function present(object $r): array
    {
        $count = DB::table('checkout_cod_exception_evidence')
            ->where('exception_id', $r->id)
            ->count();

        $proposal = DB::table('checkout_cod_exception_proposals as p')
            ->leftJoin(
                'checkout_cod_exception_proposal_reviews as d',
                'd.proposal_id',
                '=',
                'p.id'
            )
            ->where('p.exception_id', $r->id)
            ->orderByDesc('p.id')
            ->first([
                'p.id',
                'p.recommendation_kind',
                'p.delta_minor',
                'd.decision',
            ]);

        $status = match (true) {
            $r->initial_decision === 'dismiss_report' =>
            'incident_dismissed',

            $r->initial_decision === null =>
            'awaiting_initial_review',

            $proposal === null && $count < 2 =>
            'collecting_evidence',

            $proposal === null =>
            'evidence_ready_for_proposal',

            $proposal->decision === 'return_for_rework' =>
            'proposal_returned_for_rework',

            $proposal->decision === 'ready_for_external_verification' =>
            'recommendation_awaiting_external_verification',

            default =>
            'proposal_awaiting_independent_review',
        };

        return [
            'exception_id' => (int) $r->id,
            'exception_key' => (string) $r->exception_key,
            'source_kind' => (string) $r->source_kind,
            'reason_code' => (string) $r->reason_code,
            'status' => $status,
            'evidence_count' => $count,
            'proposal_id' => $proposal === null
                ? null
                : (int) $proposal->id,
            'recommendation_kind' =>
                $proposal?->recommendation_kind,
            'recommended_delta_minor' => $proposal === null
                ? null
                : (int) $proposal->delta_minor,
            'financial_hold' =>
                $r->initial_decision !== 'dismiss_report',
            'bank_source_authenticated' => false,
            'funds_received_verified' => false,
            'refund_executed' => false,
        ];
    }

    private function authorize(): void
    {
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
                'Financial resolution reader denied'
            );
        }
    }
}
