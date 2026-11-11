<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read-only, redacted proposal/review queue and live staleness detection. */
final class CheckoutCodStatementReconciliationStatusService
{
    public function __construct(
        private readonly CheckoutCodStatementReconciliationEvaluator $evaluator
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $kind, string $sourceKey): array
    {
        $this->authorize();
        if (!in_array($kind, ['single', 'batch'], true) || !Str::isUuid($sourceKey)) {
            throw new InvalidArgumentException('Invalid COD statement status source');
        }
        $table = $kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts' : 'checkout_cod_bank_batches';
        $source = DB::table($table)->where('operation_key', strtolower($sourceKey))
            ->first(['id']);
        if ($source === null) {
            throw new DomainException('COD statement source missing');
        }
        $proposal = DB::table('checkout_cod_statement_reconciliations as p')
            ->leftJoin('checkout_cod_statement_reconciliation_reviews as r',
                'r.reconciliation_id', '=', 'p.id')
            ->where('p.source_kind', $kind)->where('p.source_id', $source->id)
            ->orderByDesc('p.id')
            ->first(['p.id', 'p.operation_key', 'p.statement_entry_id', 'p.result_code',
                'p.claimed_net_minor', 'p.bank_net_minor', 'p.novapay_net_minor',
                'p.novapay_gross_minor', 'p.novapay_fee_minor', 'p.difference_minor',
                'p.allocation_count', 'p.snapshot_hmac', 'r.decision']);
        if ($proposal === null) {
            return [
                'source_kind' => $kind, 'source_id' => (int) $source->id,
                'status' => 'awaiting_statement_reconciliation',
                'reconciliation_id' => null, 'result_code' => null,
                'funds_received_verified' => false,
                'cod_paid' => false,
            ];
        }
        return $this->present($kind, strtolower($sourceKey), $proposal);
    }

    /** @return array<string,mixed> */
    public function recent(int $limit = 25, ?int $beforeId = null): array
    {
        $this->authorize();
        if ($limit < 1 || $limit > 50 || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException('Invalid COD reconciliation queue cursor');
        }
        $q = DB::table('checkout_cod_statement_reconciliations as p')
            ->leftJoin('checkout_cod_statement_reconciliation_reviews as r',
                'r.reconciliation_id', '=', 'p.id')
            ->orderByDesc('p.id');
        if ($beforeId !== null) {
            $q->where('p.id', '<', $beforeId);
        }
        $rows = $q->limit($limit + 1)->get([
            'p.id', 'p.operation_key', 'p.source_kind', 'p.source_id',
            'p.result_code', 'p.claimed_net_minor', 'p.bank_net_minor',
            'p.difference_minor', 'r.decision',
        ]);
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->map(static fn (object $r): array => [
            'reconciliation_id' => (int) $r->id,
            'operation_key' => (string) $r->operation_key,
            'source_kind' => (string) $r->source_kind,
            'source_id' => (int) $r->source_id,
            'result_code' => (string) $r->result_code,
            'claimed_net_minor' => (int) $r->claimed_net_minor,
            'bank_net_minor' => (int) $r->bank_net_minor,
            'difference_minor' => (int) $r->difference_minor,
            'review_decision' => $r->decision,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ])->values()->all();
        return [
            'items' => $items,
            'next_before_id' => $more && $items !== []
                ? $items[count($items) - 1]['reconciliation_id'] : null,
            'automatic_payment_posting_allowed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function present(string $kind, string $sourceKey, object $p): array
    {
        $fresh = false;
        $currentCode = null;
        try {
            $current = $this->evaluator->inspect($kind, $sourceKey, (int) $p->statement_entry_id);
            $fresh = hash_equals((string) $p->snapshot_hmac, (string) $current['snapshot_hmac']);
            $currentCode = $current['result_code'];
        } catch (DomainException $e) {
            // Source/bank alias changed: never portray an old approval as current.
        }
        $status = match (true) {
            !$fresh => 'stale_requires_new_investigation',
            $p->decision === 'escalate_investigation' => 'investigation_escalated',
            $p->decision === 'acknowledge_comparison' => 'comparison_reviewed_payment_unverified',
            $p->result_code !== 'balanced_unverified' => 'discrepancy_awaiting_review',
            default => 'balanced_comparison_awaiting_independent_review',
        };
        return [
            'reconciliation_id' => (int) $p->id,
            'operation_key' => (string) $p->operation_key,
            'status' => $status,
            'result_code' => (string) $p->result_code,
            'current_result_code' => $currentCode,
            'snapshot_current' => $fresh,
            'review_decision' => $p->decision,
            'claimed_net_minor' => (int) $p->claimed_net_minor,
            'bank_net_minor' => (int) $p->bank_net_minor,
            'novapay_net_minor' => (int) $p->novapay_net_minor,
            'novapay_gross_minor' => (int) $p->novapay_gross_minor,
            'novapay_fee_minor' => (int) $p->novapay_fee_minor,
            'difference_minor' => (int) $p->difference_minor,
            'allocation_count' => (int) $p->allocation_count,
            'funds_received_verified' => false,
            'cod_paid' => false,
            'automatic_payment_posting_allowed' => false,
        ];
    }

    private function authorize(): void
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY)) {
            throw new AuthorizationException('COD reconciliation read permission denied');
        }
    }
}
