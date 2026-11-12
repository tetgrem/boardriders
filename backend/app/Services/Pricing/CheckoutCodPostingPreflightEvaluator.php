<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CheckoutCodPostingPreflightEvaluator
{
    public function __construct(
        private readonly CheckoutCodStatementReconciliationEvaluator $reconciliations,
        private readonly CheckoutCodPostingPreflightPolicy $policy
    ) {
    }

    /** @return array<string, mixed> */
    public function evaluate(
        string $reconciliationKey,
        string $orderKey,
        bool $lock = false
    ): array {
        if (
            !Str::isUuid($reconciliationKey)
            || !Str::isUuid($orderKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid COD posting preflight UUID'
            );
        }

        $reconciliationKey = strtolower($reconciliationKey);
        $orderKey = strtolower($orderKey);

        $orderQuery = DB::table('orders')
            ->where('order_key', $orderKey);

        if ($lock) {
            $orderQuery->lockForUpdate();
        }

        $order = $orderQuery->first([
            'id',
            'order_key',
            'payment_status',
        ]);

        if ($order === null) {
            throw new DomainException('COD order not found');
        }

        $proposal = DB::table(
            'checkout_cod_statement_reconciliations as p'
        )
            ->leftJoin(
                'checkout_cod_statement_reconciliation_reviews as r',
                'r.reconciliation_id',
                '=',
                'p.id'
            )
            ->where('p.operation_key', $reconciliationKey)
            ->first([
                'p.id',
                'p.source_kind',
                'p.source_id',
                'p.statement_entry_id',
                'p.snapshot_hmac',
                'p.result_code',
                'p.proposed_by',
                'r.decision',
                'r.reviewed_by',
            ]);

        if ($proposal === null) {
            throw new DomainException(
                'Reviewed COD reconciliation missing'
            );
        }

        $sourceTable = $proposal->source_kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts'
            : 'checkout_cod_bank_batches';

        $source = DB::table($sourceTable)
            ->where('id', $proposal->source_id)
            ->first([
                'id',
                'operation_key',
            ]);

        if ($source === null) {
            throw new DomainException(
                'COD reconciliation source missing'
            );
        }

        $current = $this->reconciliations->inspect(
            (string) $proposal->source_kind,
            (string) $source->operation_key,
            (int) $proposal->statement_entry_id,
            $lock
        );

        $latestId = (int) DB::table(
            'checkout_cod_statement_reconciliations'
        )
            ->where('source_kind', $proposal->source_kind)
            ->where('source_id', $proposal->source_id)
            ->max('id');

        $reviewed = (int) $proposal->id === $latestId
            && $proposal->result_code === 'balanced_unverified'
            && $proposal->decision === 'acknowledge_comparison'
            && $current['result_code'] === 'balanced_unverified'
            && hash_equals(
                (string) $proposal->snapshot_hmac,
                (string) $current['snapshot_hmac']
            );

        $rows = $proposal->source_kind === 'single'
            ? $this->singleOrder(
                (int) $proposal->source_id,
                (int) $order->id
            )
            : $this->batchOrder(
                (int) $proposal->source_id,
                (int) $order->id
            );

        if ($rows->count() !== 1) {
            throw new DomainException(
                'COD order must have exactly one matching allocation'
            );
        }

        $allocation = $rows->first();

        if (
            $allocation->currency_code !== 'UAH'
            || (
                $proposal->source_kind === 'batch'
                && (int) $allocation->allocated_minor
                !== (int) $allocation->claimed_net_minor
            )
        ) {
            throw new DomainException(
                'COD posting allocation is inconsistent'
            );
        }

        $paymentChoice = DB::table('checkout_payment_choices')
            ->where('order_id', $order->id)
            ->value('payment_method');

        if ($paymentChoice !== 'cash_on_delivery') {
            throw new DomainException(
                'Only cash-on-delivery orders are supported'
            );
        }

        $result = $this->policy->evaluate(
            (int) $allocation->expected_cod_minor,
            (int) $allocation->claimed_gross_minor,
            (int) $allocation->claimed_fee_minor,
            (int) $allocation->claimed_net_minor,
            $reviewed,
            $order->payment_status === 'pending'
        );

        $participants = array_values(array_unique(array_merge(
            $current['participants'],
            [
                (int) $proposal->proposed_by,
                (int) ($proposal->reviewed_by ?? 0),
            ]
        )));

        sort($participants, SORT_NUMERIC);

        $snapshotHmac = $this->hmac([
            'cod-posting-preflight-snapshot-v1',
            (int) $proposal->id,
            (int) $order->id,
            (string) $order->payment_status,
            (int) $allocation->case_id,
            (int) $allocation->observation_id,
            (string) $current['snapshot_hmac'],
            (string) $proposal->snapshot_hmac,
            (string) ($proposal->decision ?? ''),
            $result,
            $participants,
        ]);

        return array_merge($result, [
            'order_id' => (int) $order->id,
            'order_key' => (string) $order->order_key,
            'reconciliation_id' => (int) $proposal->id,
            'reconciliation_key' => $reconciliationKey,
            'source_kind' => (string) $proposal->source_kind,
            'source_id' => (int) $proposal->source_id,
            'statement_entry_id' =>
                (int) $proposal->statement_entry_id,
            'case_id' => (int) $allocation->case_id,
            'observation_id' =>
                (int) $allocation->observation_id,
            'participants' => $participants,
            'snapshot_hmac' => $snapshotHmac,
        ]);
    }

    private function singleOrder(
        int $sourceId,
        int $orderId
    ): \Illuminate\Support\Collection {
        return DB::table(
            'checkout_cod_settlement_bank_receipts as b'
        )
            ->join(
                'checkout_cod_settlement_cases as c',
                'c.id',
                '=',
                'b.case_id'
            )
            ->join(
                'checkout_cod_settlement_bank_matches as m',
                'm.bank_receipt_id',
                '=',
                'b.id'
            )
            ->join(
                'checkout_cod_settlement_observations as o',
                'o.id',
                '=',
                'm.observation_id'
            )
            ->where('b.id', $sourceId)
            ->where('c.order_id', $orderId)
            ->get([
                'c.id as case_id',
                'c.currency_code',
                'c.expected_cod_minor',
                'o.id as observation_id',
                'o.claimed_gross_minor',
                'o.claimed_fee_minor',
                'o.claimed_net_minor',
            ]);
    }

    private function batchOrder(
        int $sourceId,
        int $orderId
    ): \Illuminate\Support\Collection {
        return DB::table(
            'checkout_cod_bank_batch_allocations as a'
        )
            ->join(
                'checkout_cod_settlement_cases as c',
                'c.id',
                '=',
                'a.case_id'
            )
            ->join(
                'checkout_cod_settlement_observations as o',
                'o.id',
                '=',
                'a.observation_id'
            )
            ->where('a.batch_id', $sourceId)
            ->where('c.order_id', $orderId)
            ->get([
                'c.id as case_id',
                'c.currency_code',
                'c.expected_cod_minor',
                'o.id as observation_id',
                'o.claimed_gross_minor',
                'o.claimed_fee_minor',
                'o.claimed_net_minor',
                'a.amount_minor as allocated_minor',
            ]);
    }

    private function hmac(array $payload): string
    {
        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR),
            $secret
        );
    }
}
