<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Persist independent comparison and review. Never posts a payment. */
final class CheckoutCodStatementReconciliationService
{
    public const PROPOSE_ABILITY = 'cod.statement-reconciliation.propose';
    public const REVIEW_ABILITY = 'cod.statement-reconciliation.review';

    public function __construct(
        private readonly CheckoutCodStatementReconciliationEvaluator $evaluator
    ) {
    }

    /** @return array<string,mixed> */
    public function propose(string $kind, string $sourceKey, int $entryId, string $operationKey): array
    {
        $actor = $this->actor(self::PROPOSE_ABILITY);
        if (!in_array($kind, ['single', 'batch'], true)
            || !Str::isUuid($sourceKey) || !Str::isUuid($operationKey) || $entryId <= 0) {
            throw new InvalidArgumentException('Invalid COD reconciliation proposal');
        }
        $sourceKey = strtolower($sourceKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use ($kind, $sourceKey, $entryId, $operationKey, $actor): array {
            $current = $this->evaluator->inspect($kind, $sourceKey, $entryId, true);
            if (in_array($actor, $current['participants'], true)) {
                throw new DomainException('Independent reconciliation proposer required');
            }
            $requestHmac = $this->hmac([
                'cod-statement-reconcile-propose-v1', $kind, $sourceKey,
                $entryId, $operationKey, $actor,
            ]);
            $old = DB::table('checkout_cod_statement_reconciliations')
                ->where('operation_key', $operationKey)->first();
            if ($old !== null) {
                if ((int) $old->source_id !== $current['source_id']
                    || (int) $old->statement_entry_id !== $entryId
                    || $old->source_kind !== $kind
                    || !hash_equals((string) $old->request_hmac, $requestHmac)) {
                    throw new DomainException('Reconciliation UUID reused');
                }
                return $this->present($old, true);
            }
            $latest = DB::table('checkout_cod_statement_reconciliations')
                ->where('source_kind', $kind)->where('source_id', $current['source_id'])
                ->orderByDesc('id')->first();
            if ($latest !== null) {
                if (hash_equals((string) $latest->snapshot_hmac, $current['snapshot_hmac'])) {
                    throw new DomainException('An identical reconciliation snapshot already exists');
                }
                // Changed bank/claim/hold state may be re-proposed, append-only.
                // The old proposal/review remains in the audit history.
            }
            $id = DB::table('checkout_cod_statement_reconciliations')->insertGetId([
                'operation_key' => $operationKey,
                'source_kind' => $kind,
                'source_id' => $current['source_id'],
                'statement_entry_id' => $entryId,
                'claimed_net_minor' => $current['claimed_net_minor'],
                'bank_net_minor' => $current['bank_net_minor'],
                'novapay_gross_minor' => $current['novapay_gross_minor'],
                'novapay_fee_minor' => $current['novapay_fee_minor'],
                'novapay_net_minor' => $current['novapay_net_minor'],
                'allocation_count' => $current['allocation_count'],
                'difference_minor' => $current['difference_minor'],
                'result_code' => $current['result_code'],
                'snapshot_hmac' => $current['snapshot_hmac'],
                'proposed_by' => $actor,
                'request_hmac' => $requestHmac,
                'created_at' => now(),
            ]);
            return $this->present(
                DB::table('checkout_cod_statement_reconciliations')->where('id', $id)->first(),
                false
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(string $proposalKey, string $reviewKey, string $decision): array
    {
        $actor = $this->actor(self::REVIEW_ABILITY);
        if (!Str::isUuid($proposalKey) || !Str::isUuid($reviewKey)
            || !in_array($decision, ['acknowledge_comparison', 'escalate_investigation'], true)) {
            throw new InvalidArgumentException('Invalid COD reconciliation review');
        }
        $proposalKey = strtolower($proposalKey);
        $reviewKey = strtolower($reviewKey);
        $reason = $decision === 'acknowledge_comparison'
            ? 'external_payment_verification_required'
            : 'bank_reconciliation_discrepancy';

        return DB::transaction(function () use ($proposalKey, $reviewKey, $decision, $reason, $actor): array {
            $proposal = DB::table('checkout_cod_statement_reconciliations')
                ->where('operation_key', $proposalKey)->lockForUpdate()->first();
            if ($proposal === null) {
                throw new DomainException('COD reconciliation proposal not found');
            }
            $sourceTable = $proposal->source_kind === 'single'
                ? 'checkout_cod_settlement_bank_receipts' : 'checkout_cod_bank_batches';
            $sourceKey = DB::table($sourceTable)->where('id', $proposal->source_id)
                ->value('operation_key');
            if ($sourceKey === null) {
                throw new DomainException('COD reconciliation source missing');
            }
            $current = $this->evaluator->inspect(
                (string) $proposal->source_kind, (string) $sourceKey,
                (int) $proposal->statement_entry_id, true
            );
            if ((int) $proposal->proposed_by === $actor
                || in_array($actor, $current['participants'], true)) {
                throw new DomainException('Independent reconciliation reviewer required');
            }
            $signature = $this->hmac([
                'cod-statement-reconcile-review-v1', $proposalKey,
                $reviewKey, $actor, $decision, $reason,
                (string) $proposal->request_hmac,
            ]);
            $old = DB::table('checkout_cod_statement_reconciliation_reviews')
                ->where('reconciliation_id', $proposal->id)->first();
            if ($old !== null) {
                if ((string) $old->review_key !== $reviewKey
                    || !hash_equals((string) $old->request_hmac, $signature)) {
                    throw new DomainException('COD reconciliation already reviewed');
                }
                return $this->presentReview($old, true);
            }
            $latestId = (int) DB::table('checkout_cod_statement_reconciliations')
                ->where('source_kind', $proposal->source_kind)
                ->where('source_id', $proposal->source_id)->max('id');
            $fresh = (int) $proposal->id === $latestId
                && hash_equals((string) $proposal->snapshot_hmac, $current['snapshot_hmac']);
            if ($decision === 'acknowledge_comparison'
                && (!$fresh || $proposal->result_code !== 'balanced_unverified'
                    || $current['result_code'] !== 'balanced_unverified')) {
                throw new DomainException('Only current balanced comparisons can be acknowledged');
            }
            $id = DB::table('checkout_cod_statement_reconciliation_reviews')->insertGetId([
                'review_key' => $reviewKey,
                'reconciliation_id' => $proposal->id,
                'decision' => $decision,
                'reason_code' => $reason,
                'reviewed_by' => $actor,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);
            return $this->presentReview(
                DB::table('checkout_cod_statement_reconciliation_reviews')->where('id', $id)->first(),
                false
            );
        }, 3);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('COD statement reconciliation permission denied');
        }
        return (int) $id;
    }

    private function hmac(array $data): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), $key);
    }

    /** @return array<string,mixed> */
    private function present(object $r, bool $replay): array
    {
        return [
            'reconciliation_id' => (int) $r->id,
            'operation_key' => (string) $r->operation_key,
            'source_kind' => (string) $r->source_kind,
            'source_id' => (int) $r->source_id,
            'statement_entry_id' => (int) $r->statement_entry_id,
            'result_code' => (string) $r->result_code,
            'claimed_net_minor' => (int) $r->claimed_net_minor,
            'bank_net_minor' => (int) $r->bank_net_minor,
            'novapay_net_minor' => (int) $r->novapay_net_minor,
            'novapay_gross_minor' => (int) $r->novapay_gross_minor,
            'novapay_fee_minor' => (int) $r->novapay_fee_minor,
            'difference_minor' => (int) $r->difference_minor,
            'allocation_count' => (int) $r->allocation_count,
            'idempotent_replay' => $replay,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function presentReview(object $r, bool $replay): array
    {
        return [
            'review_id' => (int) $r->id,
            'review_key' => (string) $r->review_key,
            'reconciliation_id' => (int) $r->reconciliation_id,
            'decision' => (string) $r->decision,
            'reason_code' => (string) $r->reason_code,
            'idempotent_replay' => $replay,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
