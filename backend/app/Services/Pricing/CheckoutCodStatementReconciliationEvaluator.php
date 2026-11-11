<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Re-evaluates the live bank/claim/hold picture on every decision. */
final class CheckoutCodStatementReconciliationEvaluator
{
    public function __construct(
        private readonly CheckoutCodStatementReconciliationPolicy $policy
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $kind, string $sourceKey, int $entryId, bool $lock = false): array
    {
        if (!in_array($kind, ['single', 'batch'], true) || $entryId <= 0) {
            throw new InvalidArgumentException('Invalid statement reconciliation source');
        }

        $table = $kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts'
            : 'checkout_cod_bank_batches';
        $query = DB::table($table)->where('operation_key', $sourceKey);
        if ($lock) {
            $query->lockForUpdate();
        }
        $source = $query->first(['id', 'operation_key', 'recorded_by', 'reference_hmac', 'claimed_net_minor']);
        if ($source === null) {
            throw new DomainException('COD bank claim missing');
        }

        // Shares the lock with the PrivatBank importer (Block 30).
        if ($lock) {
            DB::table('checkout_cod_bank_reference_claims')
                ->where('reference_hmac', $source->reference_hmac)
                ->lockForUpdate()->first(['source_id']);
        }

        $entry = DB::table('checkout_cod_privat_statement_entries as e')
            ->join('checkout_cod_privat_import_runs as r', 'r.id', '=', 'e.first_run_id')
            ->where('e.id', $entryId)
            ->first([
                'e.id', 'e.reference_hmac', 'e.account_alias', 'e.direction',
                'e.amount_minor', 'e.row_hmac', 'r.imported_by',
            ]);
        if ($entry === null || $entry->reference_hmac !== $source->reference_hmac) {
            throw new DomainException('Bank statement line does not belong to COD claim');
        }

        $alias = (string) config('services.privatbank_autoclient.account_alias', '');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,47}$/D', $alias)
            || $entry->account_alias !== $alias) {
            throw new DomainException('Statement account alias does not match configured bank account');
        }

        $participants = [(int) $source->recorded_by, (int) $entry->imported_by];
        $gross = 0;
        $fee = 0;
        $novaNet = 0;
        $count = 0;
        $reviewed = false;
        $linkedHold = false;

        if ($kind === 'single') {
            $match = DB::table('checkout_cod_settlement_bank_matches as m')
                ->join('checkout_cod_settlement_observations as o', 'o.id', '=', 'm.observation_id')
                ->leftJoin('checkout_cod_settlement_bank_reviews as mr', 'mr.match_id', '=', 'm.id')
                ->leftJoin('checkout_cod_settlement_reviews as nr', 'nr.observation_id', '=', 'o.id')
                ->where('m.bank_receipt_id', $source->id)
                ->first([
                    'm.match_result', 'm.proposed_by', 'mr.reviewed_by as match_reviewed_by',
                    'mr.decision as match_decision', 'o.id as observation_id', 'o.recorded_by',
                    'o.claimed_gross_minor', 'o.claimed_fee_minor', 'o.claimed_net_minor',
                    'o.comparison_result', 'nr.decision as novapay_decision',
                    'nr.reviewed_by as novapay_reviewed_by',
                ]);
            if ($match !== null) {
                $gross = (int) $match->claimed_gross_minor;
                $fee = (int) $match->claimed_fee_minor;
                $novaNet = (int) $match->claimed_net_minor;
                $count = 1;
                foreach (['proposed_by', 'match_reviewed_by', 'recorded_by', 'novapay_reviewed_by'] as $field) {
                    if ($match->{$field} !== null) {
                        $participants[] = (int) $match->{$field};
                    }
                }
                $reviewed = $match->match_result === 'amounts_match_unverified'
                    && $match->match_decision === 'retain_external_confirmation'
                    && $match->novapay_decision === 'retain_unverified'
                    && $match->comparison_result === 'consistent_with_estimate';
                $linkedHold = $this->hasHold('novapay', (int) $match->observation_id);
            }
        } else {
            $batchReview = DB::table('checkout_cod_bank_batch_reviews')
                ->where('batch_id', $source->id)->first(['decision', 'reviewed_by', 'allocated_minor']);
            if ($batchReview !== null) {
                $participants[] = (int) $batchReview->reviewed_by;
            }
            $allocations = DB::table('checkout_cod_bank_batch_allocations as a')
                ->join('checkout_cod_settlement_observations as o', 'o.id', '=', 'a.observation_id')
                ->leftJoin('checkout_cod_settlement_reviews as nr', 'nr.observation_id', '=', 'o.id')
                ->where('a.batch_id', $source->id)
                ->get([
                    'a.amount_minor', 'a.allocated_by', 'o.id as observation_id',
                    'o.recorded_by', 'o.claimed_gross_minor', 'o.claimed_fee_minor',
                    'o.claimed_net_minor', 'o.comparison_result',
                    'nr.decision as novapay_decision', 'nr.reviewed_by as novapay_reviewed_by',
                ]);
            $count = $allocations->count();
            $allocated = 0;
            $allValid = $count > 0;
            foreach ($allocations as $a) {
                $gross += (int) $a->claimed_gross_minor;
                $fee += (int) $a->claimed_fee_minor;
                $novaNet += (int) $a->claimed_net_minor;
                $allocated += (int) $a->amount_minor;
                $participants[] = (int) $a->allocated_by;
                $participants[] = (int) $a->recorded_by;
                if ($a->novapay_reviewed_by !== null) {
                    $participants[] = (int) $a->novapay_reviewed_by;
                }
                $allValid = $allValid
                    && $a->novapay_decision === 'retain_unverified'
                    && $a->comparison_result === 'consistent_with_estimate'
                    && (int) $a->amount_minor === (int) $a->claimed_net_minor;
                $linkedHold = $linkedHold || $this->hasHold('novapay', (int) $a->observation_id);
            }
            $reviewed = $batchReview?->decision === 'allocation_balanced_unverified'
                && (int) ($batchReview?->allocated_minor ?? -1) === $allocated
                && $allocated === (int) $source->claimed_net_minor
                && $allValid;
        }

        $statementRows = DB::table('checkout_cod_privat_statement_entries')
            ->where('reference_hmac', $source->reference_hmac)
            ->orderBy('id')
            ->get(['id', 'account_alias', 'direction', 'amount_minor']);
        $risk = $statementRows->count() !== 1
            || $entry->direction !== 'credit'
            || $linkedHold
            || $this->hasHold($kind, (int) $source->id)
            || $statementRows->contains(fn (object $r): bool =>
                (int) $r->id !== (int) $entry->id || $r->account_alias !== $alias);

        $gateway = DB::table('checkout_cod_gateway_reports')
            ->where('reference_hmac', $source->reference_hmac)
            ->orderBy('id')
            ->get(['id', 'event_kind', 'net_minor']);
        $gatewayCredits = $gateway->where('event_kind', 'credit_reported');
        $risk = $risk || $gateway->where('event_kind', 'reversal_reported')->isNotEmpty()
            || $gatewayCredits->count() > 1
            || $gatewayCredits->contains(fn (object $r): bool =>
                (int) $r->net_minor !== (int) $source->claimed_net_minor);

        $signed = DB::table('checkout_cod_bank_evidence_events')
            ->where('reference_hmac', $source->reference_hmac)
            ->orderBy('id')
            ->get(['id', 'event_kind', 'amount_minor']);
        $signedCredits = $signed->where('event_kind', 'credit');
        $risk = $risk || $signed->where('event_kind', 'reversal')->isNotEmpty()
            || $signedCredits->count() > 1
            || $signedCredits->contains(fn (object $r): bool =>
                (int) $r->amount_minor !== (int) $source->claimed_net_minor);

        $result = $this->policy->compare(
            (int) $source->claimed_net_minor, (int) $entry->amount_minor,
            $gross, $fee, $novaNet, $count, $reviewed, $risk
        );
        $participants = array_values(array_unique($participants));
        sort($participants, SORT_NUMERIC);

        return array_merge($result, [
            'source_kind' => $kind,
            'source_id' => (int) $source->id,
            'source_key' => (string) $source->operation_key,
            'entry_id' => (int) $entry->id,
            'entry_row_hmac' => (string) $entry->row_hmac,
            'participants' => $participants,
            'snapshot_hmac' => $this->digest([
                'cod-statement-reconciliation-snapshot-v1', $kind, (int) $source->id,
                (int) $entry->id, (string) $entry->row_hmac, $result,
                $reviewed, $risk, $participants,
                $statementRows->map(static fn (object $r): array => [
                    (int) $r->id, (string) $r->account_alias,
                    (string) $r->direction, (int) $r->amount_minor,
                ])->all(),
                $gateway->map(static fn (object $r): array => [
                    (int) $r->id, (string) $r->event_kind, (int) $r->net_minor,
                ])->all(),
                $signed->map(static fn (object $r): array => [
                    (int) $r->id, (string) $r->event_kind, (int) $r->amount_minor,
                ])->all(),
            ]),
        ]);
    }

    private function hasHold(string $kind, int $id): bool
    {
        $row = DB::selectOne(
            'SELECT CASE WHEN boardriders_cod_financial_has_hold(CAST(? AS TEXT), CAST(? AS BIGINT)) '
            . 'THEN 1 ELSE 0 END AS held', [$kind, $id]
        );
        return (int) $row->held === 1;
    }

    private function digest(array $parts): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), $key);
    }
}
