<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Rechecks every reviewed per-order preflight under the same bank source. */
final class CheckoutCodBankCoverageEvaluator
{
    public function __construct(
        private readonly CheckoutCodStatementReconciliationEvaluator $statements,
        private readonly CheckoutCodPostingPreflightEvaluator $preflights,
        private readonly CheckoutCodBankCoveragePolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $kind, string $sourceKey, bool $lock = false): array
    {
        if (!in_array($kind, ['single', 'batch'], true) || !Str::isUuid($sourceKey)) {
            throw new InvalidArgumentException('Invalid COD bank coverage source');
        }
        $sourceKey = strtolower($sourceKey);
        $table = $kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts' : 'checkout_cod_bank_batches';

        $source = DB::table($table)->where('operation_key', $sourceKey)
            ->first(['id', 'recorded_by', 'reference_hmac', 'claimed_net_minor']);
        if ($source === null) {
            throw new DomainException('COD bank coverage source missing');
        }

        $orders = $kind === 'single'
            ? $this->singleOrders((int) $source->id)
            : $this->batchOrders((int) $source->id);
        if ($orders->isEmpty()) {
            throw new DomainException('Bank source has no COD order allocations');
        }
        if ($orders->pluck('order_id')->unique()->count() !== $orders->count()) {
            throw new DomainException('One COD order allocated more than once');
        }

        // Lock all order rows consistently before the bank source/reference.
        if ($lock) {
            DB::table('orders')->whereIn('id', $orders->pluck('order_id')->all())
                ->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table($table)->where('id', $source->id)->lockForUpdate()->first(['id']);
        }

        $proposal = DB::table('checkout_cod_statement_reconciliations as p')
            ->leftJoin('checkout_cod_statement_reconciliation_reviews as r',
                'r.reconciliation_id', '=', 'p.id')
            ->where('p.source_kind', $kind)->where('p.source_id', $source->id)
            ->orderByDesc('p.id')->first([
                'p.id', 'p.operation_key', 'p.statement_entry_id',
                'p.snapshot_hmac', 'p.result_code', 'p.proposed_by',
                'r.decision', 'r.reviewed_by',
            ]);
        if ($proposal === null) {
            throw new DomainException('Bank statement reconciliation missing');
        }

        $current = $this->statements->inspect(
            $kind, $sourceKey, (int) $proposal->statement_entry_id, $lock
        );
        $sourceCurrent = $proposal->result_code === 'balanced_unverified'
            && $proposal->decision === 'acknowledge_comparison'
            && $current['result_code'] === 'balanced_unverified'
            && hash_equals((string) $proposal->snapshot_hmac, (string) $current['snapshot_hmac']);

        $participants = array_merge($current['participants'], [
            (int) $source->recorded_by, (int) $proposal->proposed_by,
            (int) ($proposal->reviewed_by ?? 0),
        ]);
        $lineItems = [];
        $gross = 0;
        $fee = 0;
        $allocated = 0;
        $ready = 0;
        $risk = false;

        foreach ($orders as $row) {
            $gross += (int) $row->claimed_gross_minor;
            $fee += (int) $row->claimed_fee_minor;
            $allocated += (int) $row->allocated_minor;

            $pf = DB::table('checkout_cod_posting_preflights as p')
                ->leftJoin('checkout_cod_posting_preflight_reviews as r',
                    'r.preflight_id', '=', 'p.id')
                ->where('p.reconciliation_id', $proposal->id)
                ->where('p.order_id', $row->order_id)
                ->first([
                    'p.id', 'p.operation_key', 'p.snapshot_hmac',
                    'p.prepared_by', 'p.cod_gross_minor', 'p.fee_minor',
                    'p.bank_allocation_minor', 'r.decision',
                    'r.reviewed_by',
                ]);
            $valid = false;
            if ($pf !== null) {
                $participants[] = (int) $pf->prepared_by;
                $participants[] = (int) ($pf->reviewed_by ?? 0);

                $live = $this->preflights->evaluate(
                    (string) $proposal->operation_key, (string) $row->order_key, false
                );
                $valid = $live['can_prepare_review']
                    && $pf->decision === 'retain_unverified'
                    && hash_equals((string) $pf->snapshot_hmac, (string) $live['snapshot_hmac'])
                    && (int) $pf->cod_gross_minor === (int) $row->claimed_gross_minor
                    && (int) $pf->fee_minor === (int) $row->claimed_fee_minor
                    && (int) $pf->bank_allocation_minor === (int) $row->allocated_minor;
            }
            if ($valid) {
                $ready++;
            }
            if ((int) $row->allocated_minor !== (int) $row->claimed_net_minor
                || (int) $row->claimed_gross_minor !== (int) $row->expected_cod_minor) {
                $risk = true;
            }
            $lineItems[] = [
                'order_id' => (int) $row->order_id,
                'preflight_id' => $pf === null ? null : (int) $pf->id,
                'gross_minor' => (int) $row->claimed_gross_minor,
                'fee_minor' => (int) $row->claimed_fee_minor,
                'allocated_minor' => (int) $row->allocated_minor,
                'preflight_current' => $valid,
                'preflight_snapshot_hmac' => $pf?->snapshot_hmac,
                'preflight_review_decision' => $pf?->decision,
            ];
        }

        $participants = array_values(array_unique(array_filter(
            $participants, static fn (int $id): bool => $id > 0
        )));
        sort($participants, SORT_NUMERIC);
        $result = $this->policy->evaluate(
            (int) $current['bank_net_minor'], (int) $source->claimed_net_minor,
            $gross, $fee, $allocated, $orders->count(), $ready,
            $sourceCurrent, $risk
        );
        return array_merge($result, [
            'source_kind' => $kind,
            'source_id' => (int) $source->id,
            'source_key' => $sourceKey,
            'reconciliation_id' => (int) $proposal->id,
            'statement_entry_id' => (int) $proposal->statement_entry_id,
            'lines' => $lineItems,
            'participants' => $participants,
            'snapshot_hmac' => $this->digest([
                'cod-bank-coverage-v1', $kind, (int) $source->id,
                (int) $proposal->id, (string) $current['snapshot_hmac'],
                $result, $lineItems, $participants,
            ]),
        ]);
    }

    private function singleOrders(int $id): \Illuminate\Support\Collection
    {
        return DB::table('checkout_cod_settlement_bank_receipts as b')
            ->join('checkout_cod_settlement_cases as c', 'c.id', '=', 'b.case_id')
            ->join('checkout_cod_settlement_bank_matches as m', 'm.bank_receipt_id', '=', 'b.id')
            ->join('checkout_cod_settlement_observations as o', 'o.id', '=', 'm.observation_id')
            ->join('orders as orders', 'orders.id', '=', 'c.order_id')
            ->where('b.id', $id)->orderBy('orders.id')->get([
                'orders.id as order_id', 'orders.order_key', 'c.expected_cod_minor',
                'o.claimed_gross_minor', 'o.claimed_fee_minor',
                'o.claimed_net_minor', 'b.claimed_net_minor as allocated_minor',
            ]);
    }

    private function batchOrders(int $id): \Illuminate\Support\Collection
    {
        return DB::table('checkout_cod_bank_batch_allocations as a')
            ->join('checkout_cod_settlement_cases as c', 'c.id', '=', 'a.case_id')
            ->join('checkout_cod_settlement_observations as o', 'o.id', '=', 'a.observation_id')
            ->join('orders as orders', 'orders.id', '=', 'c.order_id')
            ->where('a.batch_id', $id)->orderBy('orders.id')->get([
                'orders.id as order_id', 'orders.order_key', 'c.expected_cod_minor',
                'o.claimed_gross_minor', 'o.claimed_fee_minor',
                'o.claimed_net_minor', 'a.amount_minor as allocated_minor',
            ]);
    }

    private function digest(array $payload): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $key);
    }
}
