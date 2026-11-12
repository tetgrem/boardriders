<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read-only CRM view; no posting, personal data or raw bank references. */
final class CheckoutCodJournalDraftStatusService
{
    public function __construct(
        private readonly CheckoutCodJournalDraftEvaluator $evaluator
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $packetKey): array
    {
        $this->authorize();

        if (!Str::isUuid($packetKey)) {
            throw new InvalidArgumentException(
                'Invalid COD journal packet UUID'
            );
        }

        $packetKey = strtolower($packetKey);
        $current = $this->evaluator->inspect($packetKey);

        $draft = DB::table('checkout_cod_journal_drafts as d')
            ->leftJoin(
                'checkout_cod_journal_draft_reviews as r',
                'r.draft_id',
                '=',
                'd.id'
            )
            ->where('d.coverage_packet_id', $current['packet_id'])
            ->first([
                'd.id',
                'd.operation_key',
                'd.snapshot_hmac',
                'd.orders_count',
                'd.entry_count',
                'd.gross_minor',
                'd.fee_minor',
                'd.bank_minor',
                'r.decision',
            ]);

        $fresh = $draft !== null
            && $current['can_prepare']
            && hash_equals(
                (string) $draft->snapshot_hmac,
                $current['snapshot_hmac']
            );

        return [
            'coverage_packet_id' => $current['packet_id'],
            'draft_id' => $draft === null ? null : (int) $draft->id,
            'status' => match (true) {
                $draft === null => 'awaiting_unposted_journal_draft',
                $draft->decision === 'escalate_investigation'
                => 'journal_draft_investigation',
                !$fresh => 'journal_draft_stale',
                $draft->decision === 'retain_unposted'
                => 'reviewed_draft_external_evidence_required',
                default => 'awaiting_independent_journal_review',
            },
            'snapshot_current' => $fresh,
            'review_decision' => $draft?->decision,
            'orders_count' => $current['orders_count'],
            'entry_count' => $current['entries_count'],
            'gross_minor' => $current['gross_minor'],
            'fee_minor' => $current['fee_minor'],
            'bank_minor' => $current['bank_minor'],
            'funds_received_verified' => false,
            'payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function recent(
        int $limit = 25,
        ?int $beforeId = null
    ): array {
        $this->authorize();

        if ($limit < 1
            || $limit > 50
            || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException(
                'Invalid journal draft queue cursor'
            );
        }

        $query = DB::table('checkout_cod_journal_drafts as d')
            ->leftJoin(
                'checkout_cod_journal_draft_reviews as r',
                'r.draft_id',
                '=',
                'd.id'
            )
            ->orderByDesc('d.id');

        if ($beforeId !== null) {
            $query->where('d.id', '<', $beforeId);
        }

        $rows = $query->limit($limit + 1)->get([
            'd.id',
            'd.coverage_packet_id',
            'd.orders_count',
            'd.entry_count',
            'd.gross_minor',
            'd.fee_minor',
            'd.bank_minor',
            'r.decision',
        ]);

        $more = $rows->count() > $limit;

        $items = $rows->take($limit)
            ->map(static fn (object $r): array => [
                'draft_id' => (int) $r->id,
                'coverage_packet_id' => (int) $r->coverage_packet_id,
                'orders_count' => (int) $r->orders_count,
                'entry_count' => (int) $r->entry_count,
                'gross_minor' => (int) $r->gross_minor,
                'fee_minor' => (int) $r->fee_minor,
                'bank_minor' => (int) $r->bank_minor,
                'review_decision' => $r->decision,
                'payment_posting_allowed' => false,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['draft_id']
                : null,
            'automatic_payment_posting_allowed' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if ((!is_int($id)
                && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )) {
            throw new AuthorizationException(
                'COD journal draft view permission denied'
            );
        }
    }
}
