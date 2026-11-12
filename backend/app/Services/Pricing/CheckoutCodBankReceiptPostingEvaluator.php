<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Checks every upstream record again. This does not make a customer payment. */
final class CheckoutCodBankReceiptPostingEvaluator
{
    public function __construct(
        private readonly CheckoutCodJournalDraftEvaluator $journal,
        private readonly CheckoutCodRefetchRiskService $risk,
        private readonly CheckoutCodBankReceiptPostingPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $journalKey, string $refetchKey): array
    {
        if (!Str::isUuid($journalKey) || !Str::isUuid($refetchKey)) {
            throw new InvalidArgumentException('Invalid COD bank receipt provenance UUID');
        }
        $journalKey = strtolower($journalKey);
        $refetchKey = strtolower($refetchKey);

        $draft = DB::table('checkout_cod_journal_drafts as d')
            ->join('checkout_cod_journal_draft_reviews as r', 'r.draft_id', '=', 'd.id')
            ->join('checkout_cod_bank_coverage_packets as c', 'c.id', '=', 'd.coverage_packet_id')
            ->where('d.operation_key', $journalKey)
            ->first([
                'd.id', 'd.source_kind', 'd.source_id', 'd.snapshot_hmac',
                'd.prepared_by', 'd.bank_minor', 'd.gross_minor', 'd.fee_minor',
                'd.entry_count', 'd.orders_count', 'r.reviewed_by',
                'r.decision', 'c.operation_key as packet_key',
            ]);
        if ($draft === null || $draft->decision !== 'retain_unposted') {
            throw new DomainException('Independently reviewed unposted COD journal required');
        }
        $sourceTable = $draft->source_kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts'
            : ($draft->source_kind === 'batch' ? 'checkout_cod_bank_batches' : null);
        if ($sourceTable === null) {
            throw new DomainException('Invalid COD bank source kind');
        }
        $source = DB::table($sourceTable)->where('id', $draft->source_id)
            ->first(['id', 'recorded_by', 'reference_hmac', 'claimed_net_minor']);
        if ($source === null || (int) $source->claimed_net_minor !== (int) $draft->bank_minor) {
            throw new DomainException('Bank claim and journal amount differ');
        }

        $f = DB::table('checkout_cod_privat_refetches as f')
            ->join('checkout_cod_privat_statement_entries as e', 'e.id', '=', 'f.statement_entry_id')
            ->join('checkout_cod_privat_import_runs as i', 'i.id', '=', 'e.first_run_id')
            ->where('f.operation_key', $refetchKey)
            ->first([
                'f.id', 'f.source_kind', 'f.source_id', 'f.statement_entry_id',
                'f.reference_hmac', 'f.transaction_hmac', 'f.row_hmac',
                'f.booked_on', 'f.amount_minor', 'f.result_code', 'f.rechecked_by',
                'f.created_at', 'e.direction', 'e.account_alias',
                'e.reference_hmac as statement_reference_hmac',
                'e.transaction_hmac as statement_transaction_hmac',
                'e.row_hmac as statement_row_hmac', 'e.amount_minor as statement_amount_minor',
                'e.booked_on as statement_booked_on', 'i.imported_by',
            ]);
        if ($f === null || $f->source_kind !== $draft->source_kind
            || (int) $f->source_id !== (int) $draft->source_id
            || $f->result_code !== 'consistent_unverified'
            || (int) $f->amount_minor !== (int) $source->claimed_net_minor
            || $f->reference_hmac !== $source->reference_hmac
            || $f->reference_hmac !== $f->statement_reference_hmac
            || $f->transaction_hmac !== $f->statement_transaction_hmac
            || $f->row_hmac !== $f->statement_row_hmac
            || (int) $f->amount_minor !== (int) $f->statement_amount_minor
            || $f->booked_on !== $f->statement_booked_on
            || $f->direction !== 'credit') {
            throw new DomainException('Fresh consistent bank API evidence required');
        }
        $latestId = (int) DB::table('checkout_cod_privat_refetches')
            ->where('source_kind', $draft->source_kind)
            ->where('source_id', $draft->source_id)->max('id');
        if ((int) $f->id !== $latestId
            || Carbon::parse($f->created_at)->lessThan(now()->subMinutes(15))
            || Carbon::parse($f->created_at)->greaterThan(now()->addMinute())) {
            throw new DomainException('Bank API evidence expired or superseded');
        }
        $risk = $this->risk->inspect((string) $draft->source_kind, (int) $draft->source_id);
        if ($risk['blocks_financial_approval']) {
            throw new DomainException('Historical bank refetch anomaly prevents posting');
        }
        $localEntries = DB::table('checkout_cod_privat_statement_entries')
            ->where('reference_hmac', $source->reference_hmac)->count();
        if ($localEntries !== 1) {
            throw new DomainException('Ambiguous imported bank credit');
        }
        $activeHold = DB::selectOne(
            'SELECT CASE WHEN boardriders_cod_financial_has_hold(CAST(? AS TEXT), CAST(? AS BIGINT)) '
            . 'THEN 1 ELSE 0 END AS held',
            [(string) $draft->source_kind, (int) $draft->source_id]
        );
        if ((int) $activeHold->held === 1) {
            throw new DomainException('COD financial investigation hold active');
        }
        $current = $this->journal->inspect((string) $draft->packet_key);
        if (!$current['can_prepare']
            || !hash_equals((string) $draft->snapshot_hmac, (string) $current['snapshot_hmac'])
            || $current['bank_minor'] !== (int) $draft->bank_minor
            || $current['gross_minor'] !== (int) $draft->gross_minor
            || $current['fee_minor'] !== (int) $draft->fee_minor
            || $current['entries_count'] !== (int) $draft->entry_count
            || $current['orders_count'] !== (int) $draft->orders_count) {
            throw new DomainException('Reviewed COD journal has become stale');
        }

        $entryRows = DB::table('checkout_cod_journal_draft_entries')
            ->where('draft_id', $draft->id)
            ->orderBy('order_id')->orderBy('entry_kind')
            ->get(['order_id', 'coverage_line_id', 'entry_kind', 'amount_minor',
                'debit_account', 'credit_account']);
        $expected = collect($current['entries'])->map(static fn (array $x): array => [
            (int) $x['order_id'], (int) $x['coverage_line_id'], (string) $x['entry_kind'],
            (int) $x['amount_minor'], (string) $x['debit_account'], (string) $x['credit_account'],
        ])->sortBy(static fn (array $x): string => sprintf('%020d:%s', $x[0], $x[2]))->values()->all();
        $actual = $entryRows->map(static fn (object $x): array => [
            (int) $x->order_id, (int) $x->coverage_line_id, (string) $x->entry_kind,
            (int) $x->amount_minor, (string) $x->debit_account, (string) $x->credit_account,
        ])->all();
        if ($actual !== $expected) {
            throw new DomainException('COD journal draft entries changed');
        }
        $orders = DB::table('checkout_cod_bank_coverage_lines as l')
            ->join('orders as o', 'o.id', '=', 'l.order_id')
            ->where('l.packet_id', $current['packet_id'])
            ->get(['o.id', 'o.payment_status']);
        if ($orders->count() !== (int) $draft->orders_count
            || $orders->contains(static fn (object $o): bool => $o->payment_status !== 'pending')) {
            throw new DomainException('A COD order is not in pending payment state');
        }
        $alias = (string) config('services.privatbank_autoclient.account_alias', '');
        if ($alias !== $f->account_alias || !preg_match('/^[a-z][a-z0-9_-]{0,47}$/D', $alias)) {
            throw new DomainException('Authenticated bank account alias differs');
        }
        $lines = $this->policy->lines(
            (int) $draft->gross_minor, (int) $draft->fee_minor, (int) $draft->bank_minor
        );
        $participants = array_values(array_unique(array_filter(array_merge(
            $current['participants'], [
                (int) $source->recorded_by, (int) $draft->prepared_by,
                (int) $draft->reviewed_by, (int) $f->rechecked_by, (int) $f->imported_by,
            ]
        ), static fn (int $id): bool => $id > 0)));
        sort($participants, SORT_NUMERIC);
        $snapshot = $this->hmac([
            'cod-bank-receipt-post-snapshot-v1', (int) $draft->id,
            (string) $draft->snapshot_hmac, (string) $current['snapshot_hmac'],
            (int) $f->id, (string) $f->row_hmac,
            (string) $source->reference_hmac, (int) $source->claimed_net_minor,
            (string) $f->transaction_hmac, (string) $f->booked_on,
            $alias, $expected, $lines, $participants,
        ]);
        return [
            'journal_draft_id' => (int) $draft->id,
            'source_kind' => (string) $draft->source_kind,
            'source_id' => (int) $draft->source_id,
            'statement_entry_id' => (int) $f->statement_entry_id,
            'refetch_id' => (int) $f->id,
            'reference_hmac' => (string) $f->reference_hmac,
            'transaction_hmac' => (string) $f->transaction_hmac,
            'row_hmac' => (string) $f->row_hmac,
            'booked_on' => (string) $f->booked_on,
            'account_alias' => $alias,
            'amount_minor' => (int) $draft->bank_minor,
            'lines' => $lines,
            'participants' => $participants,
            'snapshot_hmac' => $snapshot,
        ];
    }

    private function hmac(array $parts): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), $key);
    }
}
