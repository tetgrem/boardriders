<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * PII-free operational queue.
 * Evidence correlation is NOT source authentication.
 */
final class CheckoutCodOperationsService
{
    public function __construct(
        private readonly CheckoutCodOperationsPolicy $policy,
        private readonly CheckoutCodRefetchRiskService $risk,
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $postingKey): array
    {
        $this->authorize();

        if (!Str::isUuid($postingKey)) {
            throw new InvalidArgumentException(
                'Invalid COD bank posting UUID'
            );
        }

        $posting = DB::table('checkout_cod_bank_receipt_postings')
            ->where('operation_key', strtolower($postingKey))
            ->first();

        if ($posting === null) {
            throw new DomainException('COD bank posting not found');
        }

        $draft = DB::table('checkout_cod_journal_drafts')
            ->where('id', $posting->journal_draft_id)
            ->first();

        if ($draft === null) {
            throw new DomainException('COD journal draft not found');
        }

        $orders = DB::table('checkout_cod_bank_coverage_lines as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->leftJoin(
                'checkout_payment_choices as pc',
                'pc.order_id',
                '=',
                'o.id'
            )
            ->where('l.packet_id', $draft->coverage_packet_id)
            ->orderBy('o.id')
            ->get([
                'o.id as order_id',
                'o.order_key',
                'o.payment_status',
                'pc.payment_method',
                'l.gross_minor',
                'l.fee_minor',
                'l.allocated_minor',
            ]);

        $capture = DB::table(
            'checkout_cod_novapay_register_captures'
        )
            ->where('bank_posting_id', $posting->id)
            ->first();

        $registerLines = $capture === null
            ? collect()
            : DB::table('checkout_cod_novapay_register_lines')
                ->where('capture_id', $capture->id)
                ->get([
                    'order_id',
                    'gross_minor',
                    'fee_minor',
                    'net_minor',
                ]);

        $byOrder = $registerLines->keyBy('order_id');

        /*
         * TTN observations and NovaPay lines use distinct
         * HMAC namespaces. Candidate presence is useful,
         * but does not prove that both refer to the same TTN.
         */
        $ids = $orders
            ->pluck('order_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        $candidateCounts = $ids === []
            ? collect()
            : DB::table('checkout_cod_ttn_submission_intents as i')
                ->leftJoin(
                    'checkout_cod_ttn_reconciliation_scans as s',
                    's.intent_id',
                    '=',
                    'i.id'
                )
                ->whereIn('i.order_id', $ids)
                ->groupBy('i.order_id')
                ->selectRaw(
                    'i.order_id, ' .
                    'COUNT(DISTINCT s.candidate_number_hmac) AS candidate_count'
                )
                ->get()
                ->mapWithKeys(
                    static fn (object $r): array => [
                        (int) $r->order_id => (int) $r->candidate_count,
                    ]
                );

        $lines = [];

        $gross = 0;
        $fee = 0;
        $bank = 0;

        $registerGross = 0;
        $registerFee = 0;
        $registerNet = 0;

        $statusConflict = false;
        $methodConflict = false;
        $allocationConflict = false;
        $ttnMissing = false;
        $ttnAmbiguous = false;

        foreach ($orders as $order) {
            $line = $byOrder->get($order->order_id);
            $hasLine = $line !== null;

            $grossMinor = (int) $order->gross_minor;
            $feeMinor = (int) $order->fee_minor;
            $netMinor = (int) $order->allocated_minor;

            $gross += $grossMinor;
            $fee += $feeMinor;
            $bank += $netMinor;

            $candidateCount = (int) (
                $candidateCounts->get($order->order_id) ?? 0
            );

            $ttnMissing = $ttnMissing || $candidateCount === 0;
            $ttnAmbiguous = $ttnAmbiguous || $candidateCount > 1;

            $pending = (string) $order->payment_status === 'pending';
            $isCod = (string) $order->payment_method
                === 'cash_on_delivery';

            $statusConflict = $statusConflict || !$pending;
            $methodConflict = $methodConflict || !$isCod;

            $matches = $hasLine
                && (int) $line->gross_minor === $grossMinor
                && (int) $line->fee_minor === $feeMinor
                && (int) $line->net_minor === $netMinor;

            $allocationConflict = $allocationConflict
                || ($capture !== null && !$matches);

            $lines[] = [
                'order_key' => (string) $order->order_key,
                'payment_status' => (string) $order->payment_status,
                'payment_method' => $order->payment_method,
                'gross_minor' => $grossMinor,
                'fee_minor' => $feeMinor,
                'net_minor' => $netMinor,
                'has_register_line' => $hasLine,
                'register_amounts_match' => $matches,
                'ttn_candidate_count' => $candidateCount,
                'ttn_matches_register_verified' => false,
                'shipment_ownership_verified' => false,
            ];
        }

        foreach ($registerLines as $line) {
            $registerGross += (int) $line->gross_minor;
            $registerFee += (int) $line->fee_minor;
            $registerNet += (int) $line->net_minor;
        }

        $registerBalanced = $capture !== null
            && $registerLines->count() === $orders->count()
            && $registerLines->count() === (int) $capture->orders_count
            && $registerGross === (int) $capture->gross_minor
            && $registerFee === (int) $capture->fee_minor
            && $registerNet === (int) $capture->bank_minor
            && !$allocationConflict;

        $bankBalanced = $orders->count() > 0
            && $orders->count() === (int) $draft->orders_count
            && $gross === (int) $draft->gross_minor
            && $fee === (int) $draft->fee_minor
            && $bank === (int) $draft->bank_minor
            && $bank === (int) $posting->amount_minor;

        $reversals = DB::table('checkout_cod_bank_evidence_events')
                ->where('reference_hmac', $posting->reference_hmac)
                ->where('event_kind', 'reversal')
                ->exists()
            || DB::table('checkout_cod_gateway_reports')
                ->where('reference_hmac', $posting->reference_hmac)
                ->where('event_kind', 'reversal_reported')
                ->exists();

        $hold = (int) DB::selectOne(
                'SELECT CASE WHEN ' .
                'boardriders_cod_financial_has_hold(' .
                'CAST(? AS TEXT), CAST(? AS BIGINT)) ' .
                'THEN 1 ELSE 0 END AS held',
                [
                    (string) $posting->source_kind,
                    (int) $posting->source_id,
                ]
            )->held === 1;

        $risk = $this->risk->inspect(
            (string) $posting->source_kind,
            (int) $posting->source_id
        );

        $facts = [
            'register_present' => $capture !== null,
            'register_balanced' => $registerBalanced,
            'bank_balanced' => $bankBalanced,
            'orders_balanced' =>
                !$allocationConflict && $orders->count() > 0,
            'financial_hold' => $hold,
            'refetch_anomaly' =>
                (bool) $risk['blocks_financial_approval'],
            'reversal_reported' => $reversals,
            'payment_status_conflict' => $statusConflict,
            'payment_method_conflict' => $methodConflict,
            'ttn_candidate_missing' => $ttnMissing,
            'ttn_candidate_ambiguous' => $ttnAmbiguous,
        ];

        $assessment = $this->policy->evaluate($facts);

        $snapshot = $this->digest([
            'cod-operations-snapshot-v41',
            (int) $posting->id,
            (int) $draft->id,
            (int) $posting->amount_minor,
            $capture?->snapshot_hmac,
            $facts,
            $lines,
            $gross,
            $fee,
            $bank,
            $registerGross,
            $registerFee,
            $registerNet,
        ]);

        $latestReview = DB::table('checkout_cod_operations_reviews')
            ->where('bank_posting_id', $posting->id)
            ->orderByDesc('id')
            ->first();

        return [
            'posting_key' => (string) $posting->operation_key,
            'bank_posting_id' => (int) $posting->id,
            'capture_id' =>
                $capture === null ? null : (int) $capture->id,
            'source_kind' => (string) $posting->source_kind,
            'status' => $assessment['status'],
            'priority' => $assessment['priority'],
            'flags' => $assessment['flags'],
            'requires_attention' => $assessment['requires_attention'],
            'orders_count' => $orders->count(),
            'gross_minor' => $gross,
            'fee_minor' => $fee,
            'bank_minor' => $bank,
            'posted_bank_minor' => (int) $posting->amount_minor,
            'register_gross_minor' => $registerGross,
            'register_fee_minor' => $registerFee,
            'register_net_minor' => $registerNet,
            'lines' => $lines,
            'facts' => $facts,
            'snapshot_hmac' => $snapshot,
            'latest_review' => $latestReview === null
                ? null
                : [
                    'review_id' => (int) $latestReview->id,
                    'action' => (string) $latestReview->review_action,
                    'reason_code' => (string) $latestReview->reason_code,
                    'reviewed_by' => (int) $latestReview->reviewed_by,
                    'recorded_at' => (string) $latestReview->created_at,
                    'snapshot_current' => hash_equals(
                        (string) $latestReview->snapshot_hmac,
                        $snapshot
                    ),
                ],
            'novapay_origin_authenticated' => false,
            'ttn_matches_register_verified' => false,
            'shipment_ownership_verified' => false,
            'customer_payment_confirmed' => false,
            'orders_payment_status_changed' => false,
            'automatic_payment_posting_allowed' => false,
            'cod_paid' => false,
        ];
    }

    /**
     * Keyset pagination.
     * Scans no more than 500 bank postings per request.
     * No customer PII in the result.
     *
     * @return array<string,mixed>
     */
    public function queue(
        ?int $beforeId = null,
        int $limit = 20,
        string $filter = 'all'
    ): array {
        $this->authorize();

        if (
            ($beforeId !== null && $beforeId < 1)
            || $limit < 1
            || $limit > 50
            || !in_array(
                $filter,
                ['all', 'attention', 'awaiting_register'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid COD operations queue filters'
            );
        }

        $items = [];
        $scanned = 0;
        $cursor = $beforeId;

        do {
            $query = DB::table('checkout_cod_bank_receipt_postings')
                ->orderByDesc('id')
                ->limit(50);

            if ($cursor !== null) {
                $query->where('id', '<', $cursor);
            }

            $batch = $query->get(['id', 'operation_key']);

            foreach ($batch as $row) {
                $scanned++;
                $cursor = (int) $row->id;

                $state = $this->inspect(
                    (string) $row->operation_key
                );

                if (
                    ($filter === 'attention'
                        && !$state['requires_attention'])
                    || ($filter === 'awaiting_register'
                        && $state['status'] !== 'awaiting_register')
                ) {
                    if ($scanned >= 500) {
                        break;
                    }

                    continue;
                }

                unset($state['lines'], $state['facts']);
                $items[] = $state;

                if (
                    count($items) >= $limit
                    || $scanned >= 500
                ) {
                    break;
                }
            }
        } while (
            $batch->count() === 50
            && count($items) < $limit
            && $scanned < 500
        );

        $more = $cursor !== null
            && DB::table('checkout_cod_bank_receipt_postings')
                ->where('id', '<', $cursor)
                ->exists();

        return [
            'items' => $items,
            'next_before_id' => $more ? $cursor : null,
            'scanned' => $scanned,
            'scan_limit_reached' => $scanned >= 500,
            'filter' => $filter,
            'automatic_payment_posting_allowed' => false,
        ];
    }

    private function digest(array $parts): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($parts, JSON_THROW_ON_ERROR),
            $key
        );
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id)
                && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(
                CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'COD operations read denied'
            );
        }
    }
}
