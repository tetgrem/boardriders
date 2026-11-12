<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Append-only financial investigation plans. No payment or refund is executed. */
final class CheckoutCodAdjustmentPlanService
{
    public const PREPARE_ABILITY = 'cod.adjustment-plan.prepare';
    public const REVIEW_ABILITY = 'cod.adjustment-plan.review';

    public function __construct(private readonly CheckoutCodAdjustmentPlanPolicy $policy)
    {
    }

    /** @return array<string,mixed> */
    public function prepare(
        string $resolutionKey,
        string $journalKey,
        string $orderKey,
        int $requestedRefundMinor,
        string $operationKey
    ): array {
        $actor = $this->actor(self::PREPARE_ABILITY);
        $this->uuids($resolutionKey, $journalKey, $orderKey, $operationKey);
        $resolutionKey = strtolower($resolutionKey);
        $journalKey = strtolower($journalKey);
        $orderKey = strtolower($orderKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $actor, $resolutionKey, $journalKey, $orderKey,
            $requestedRefundMinor, $operationKey
        ): array {
            $view = $this->snapshot(
                $resolutionKey, $journalKey, $orderKey, $requestedRefundMinor, true
            );
            if (in_array($actor, $view['participants'], true)) {
                throw new DomainException('Independent adjustment planner required');
            }
            $signature = $this->hmac([
                'cod-adjustment-plan-request-v1', $resolutionKey, $journalKey,
                $orderKey, $requestedRefundMinor, $operationKey, $actor,
            ]);
            $old = DB::table('checkout_cod_adjustment_plans')
                ->where('operation_key', $operationKey)->first();
            if ($old !== null) {
                if ((int) $old->resolution_proposal_id !== $view['resolution_proposal_id']
                    || (int) $old->journal_draft_id !== $view['journal_draft_id']
                    || (int) $old->order_id !== $view['order_id']
                    || !hash_equals((string) $old->request_hmac, $signature)
                    || !hash_equals((string) $old->snapshot_hmac, $view['snapshot_hmac'])) {
                    throw new DomainException('Adjustment UUID reused or evidence changed');
                }
                return $this->present($old, true);
            }
            if (DB::table('checkout_cod_adjustment_plans')
                ->where('source_kind', $view['source_kind'])
                ->where('source_id', $view['source_id'])
                ->where('order_id', $view['order_id'])->exists()) {
                throw new DomainException('Adjustment plan already exists for this source and order');
            }
            $id = DB::table('checkout_cod_adjustment_plans')->insertGetId([
                'operation_key' => $operationKey,
                'resolution_proposal_id' => $view['resolution_proposal_id'],
                'journal_draft_id' => $view['journal_draft_id'],
                'order_id' => $view['order_id'],
                'source_kind' => $view['source_kind'],
                'source_id' => $view['source_id'],
                'plan_kind' => $view['plan_kind'],
                'original_gross_minor' => $view['original_gross_minor'],
                'original_fee_minor' => $view['original_fee_minor'],
                'original_order_net_minor' => $view['original_order_net_minor'],
                'source_net_minor' => $view['source_net_minor'],
                'recommended_source_net_minor' => $view['recommended_source_net_minor'],
                'requested_refund_minor' => $view['proposed_customer_refund_minor'],
                'source_net_difference_minor' => $view['source_net_difference_minor'],
                'snapshot_hmac' => $view['snapshot_hmac'],
                'prepared_by' => $actor,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);
            return $this->present(
                DB::table('checkout_cod_adjustment_plans')->where('id', $id)->first(), false
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function review(string $planKey, string $reviewKey, string $decision): array
    {
        $actor = $this->actor(self::REVIEW_ABILITY);
        $this->uuids($planKey, $reviewKey);
        if (!in_array($decision, ['retain_unexecuted', 'escalate_investigation'], true)) {
            throw new InvalidArgumentException('Invalid COD adjustment review decision');
        }
        $planKey = strtolower($planKey);
        $reviewKey = strtolower($reviewKey);

        return DB::transaction(function () use ($actor, $planKey, $reviewKey, $decision): array {
            $plan = DB::table('checkout_cod_adjustment_plans')
                ->where('operation_key', $planKey)->lockForUpdate()->first();
            if ($plan === null) {
                throw new DomainException('COD adjustment plan missing');
            }
            $resolutionKey = DB::table('checkout_cod_exception_proposals')
                ->where('id', $plan->resolution_proposal_id)->value('operation_key');
            $journalKey = DB::table('checkout_cod_journal_drafts')
                ->where('id', $plan->journal_draft_id)->value('operation_key');
            $orderKey = DB::table('orders')
                ->where('id', $plan->order_id)->value('order_key');
            if ($resolutionKey === null || $journalKey === null || $orderKey === null) {
                throw new DomainException('COD adjustment provenance missing');
            }
            $view = $this->snapshot(
                (string) $resolutionKey, (string) $journalKey,
                (string) $orderKey, (int) $plan->requested_refund_minor, true
            );
            if ((int) $plan->prepared_by === $actor
                || in_array($actor, $view['participants'], true)) {
                throw new DomainException('Independent adjustment reviewer required');
            }
            $reason = $decision === 'retain_unexecuted'
                ? 'external_execution_evidence_required'
                : 'adjustment_investigation_required';
            $signature = $this->hmac([
                'cod-adjustment-plan-review-v1', $planKey, $reviewKey,
                $decision, $reason, $actor, (string) $plan->request_hmac,
            ]);
            $old = DB::table('checkout_cod_adjustment_plan_reviews')
                ->where('plan_id', $plan->id)->first();
            if ($old !== null) {
                if ($old->review_key !== $reviewKey
                    || !hash_equals((string) $old->request_hmac, $signature)) {
                    throw new DomainException('Adjustment plan already reviewed');
                }
                return $this->presentReview($old, true);
            }
            if ($decision === 'retain_unexecuted'
                && !hash_equals((string) $plan->snapshot_hmac, $view['snapshot_hmac'])) {
                throw new DomainException('Stale adjustment plan cannot be retained');
            }
            $id = DB::table('checkout_cod_adjustment_plan_reviews')->insertGetId([
                'review_key' => $reviewKey,
                'plan_id' => $plan->id,
                'decision' => $decision,
                'reason_code' => $reason,
                'reviewed_by' => $actor,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);
            return $this->presentReview(
                DB::table('checkout_cod_adjustment_plan_reviews')->where('id', $id)->first(), false
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    private function snapshot(
        string $resolutionKey, string $journalKey,
        string $orderKey, int $requestedRefundMinor, bool $lock
    ): array {
        $proposal = DB::table('checkout_cod_exception_proposals as p')
            ->join('checkout_cod_financial_exceptions as e', 'e.id', '=', 'p.exception_id')
            ->join('checkout_cod_financial_exception_reviews as er', 'er.exception_id', '=', 'e.id')
            ->leftJoin('checkout_cod_exception_proposal_reviews as pr', 'pr.proposal_id', '=', 'p.id')
            ->where('p.operation_key', $resolutionKey)
            ->first([
                'p.id', 'p.exception_id', 'p.recommendation_kind', 'p.source_net_minor',
                'p.recommended_net_minor', 'p.proposed_by', 'p.request_hmac',
                'e.source_kind', 'e.source_id', 'e.reason_code', 'e.reported_by',
                'er.decision as incident_decision', 'er.reviewed_by as incident_reviewer',
                'pr.decision as proposal_decision', 'pr.reviewed_by as proposal_reviewer',
            ]);
        if ($proposal === null || $proposal->incident_decision !== 'hold_for_investigation'
            || $proposal->proposal_decision !== 'ready_for_external_verification'
            || (int) DB::table('checkout_cod_exception_proposals')
                ->where('exception_id', $proposal->exception_id)->max('id') !== (int) $proposal->id) {
            throw new DomainException('Latest independently reviewed resolution required');
        }
        if (!in_array($proposal->source_kind, ['single', 'batch'], true)) {
            throw new DomainException('Adjustment requires COD bank claim');
        }
        $kind = (string) $proposal->recommendation_kind;
        $reasonValid = match ($kind) {
            'return_case_followup' => in_array(
                $proposal->reason_code, ['customer_return_reported', 'order_cancellation_reported'], true
            ),
            'amount_correction' => in_array(
                    $proposal->reason_code, ['incorrect_amount', 'wrong_source_reference'], true
                ) && $proposal->source_kind === 'single',
            'reported_reversal' => $proposal->reason_code === 'bank_reversal_reported'
                && $proposal->source_kind === 'single',
            default => false,
        };
        if (!$reasonValid) {
            throw new DomainException('Unsupported or mismatched adjustment recommendation');
        }

        $orderQuery = DB::table('orders')->where('order_key', $orderKey);
        if ($lock) {
            $orderQuery->lockForUpdate();
        }
        $order = $orderQuery->first(['id', 'payment_status']);
        if ($order === null || $order->payment_status !== 'pending') {
            throw new DomainException('Only pending COD order can be investigated');
        }
        if ($lock) {
            DB::selectOne(
                'SELECT boardriders_cod_resolution_lock_exception(CAST(? AS BIGINT)) AS source_id',
                [(int) $proposal->exception_id]
            );
        }
        $held = DB::selectOne(
            'SELECT CASE WHEN boardriders_cod_financial_has_hold(CAST(? AS TEXT), CAST(? AS BIGINT)) '
            . 'THEN 1 ELSE 0 END AS held',
            [$proposal->source_kind, $proposal->source_id]
        );
        if ((int) $held->held !== 1) {
            throw new DomainException('Active financial investigation hold required');
        }
        $sourceTable = $proposal->source_kind === 'single'
            ? 'checkout_cod_settlement_bank_receipts' : 'checkout_cod_bank_batches';
        $source = DB::table($sourceTable)->where('id', $proposal->source_id)
            ->first(['recorded_by', 'reference_hmac', 'claimed_net_minor']);
        if ($source === null || (int) $source->claimed_net_minor !== (int) $proposal->source_net_minor) {
            throw new DomainException('Resolution no longer matches original bank claim');
        }

        $draft = DB::table('checkout_cod_journal_drafts as d')
            ->join('checkout_cod_journal_draft_reviews as dr', 'dr.draft_id', '=', 'd.id')
            ->join('checkout_cod_bank_coverage_packets as c', 'c.id', '=', 'd.coverage_packet_id')
            ->where('d.operation_key', $journalKey)
            ->first([
                'd.id', 'd.source_kind', 'd.source_id', 'd.coverage_packet_id',
                'd.snapshot_hmac', 'd.prepared_by', 'dr.decision as draft_decision',
                'dr.reviewed_by as draft_reviewer', 'c.operation_key as packet_key',
                'c.compiled_by',
            ]);
        if ($draft === null || $draft->draft_decision !== 'retain_unposted'
            || $draft->source_kind !== $proposal->source_kind
            || (int) $draft->source_id !== (int) $proposal->source_id) {
            throw new DomainException('Reviewed unposted journal from the same bank source required');
        }
        $line = DB::table('checkout_cod_bank_coverage_lines')
            ->where('packet_id', $draft->coverage_packet_id)
            ->where('order_id', $order->id)
            ->first(['id', 'gross_minor', 'fee_minor', 'allocated_minor']);
        if ($line === null) {
            throw new DomainException('Order not in this COD bank coverage');
        }
        $entries = DB::table('checkout_cod_journal_draft_entries')
            ->where('draft_id', $draft->id)->where('coverage_line_id', $line->id)
            ->orderBy('entry_kind')->get(['entry_kind', 'amount_minor']);
        $amounts = $entries->pluck('amount_minor', 'entry_kind');
        $expected = (int) $line->fee_minor > 0 ? 3 : 2;
        if ($entries->count() !== $expected
            || (int) ($amounts['carrier_collection'] ?? -1) !== (int) $line->gross_minor
            || (int) ($amounts['bank_remittance'] ?? -1) !== (int) $line->allocated_minor
            || ((int) $line->fee_minor > 0
                && (int) ($amounts['carrier_fee'] ?? -1) !== (int) $line->fee_minor)) {
            throw new DomainException('Journal does not contain complete per-order draft entries');
        }
        $impact = $this->policy->project(
            $kind, (int) $line->gross_minor, (int) $line->fee_minor,
            (int) $line->allocated_minor, (int) $proposal->source_net_minor,
            (int) $proposal->recommended_net_minor, $requestedRefundMinor
        );

        $context = app(CheckoutCodJournalDraftEvaluator::class)
            ->inspect((string) $draft->packet_key);
        $evidenceAuthors = DB::table('checkout_cod_exception_evidence')
            ->where('exception_id', $proposal->exception_id)->orderBy('id')
            ->pluck('recorded_by')->map(static fn ($id): int => (int) $id)->all();
        $participants = array_values(array_unique(array_filter(array_merge(
            $context['participants'], $evidenceAuthors, [
                (int) $source->recorded_by, (int) $proposal->reported_by,
                (int) $proposal->incident_reviewer, (int) $proposal->proposed_by,
                (int) $proposal->proposal_reviewer, (int) $draft->prepared_by,
                (int) $draft->draft_reviewer, (int) $draft->compiled_by,
            ]
        ), static fn (int $id): bool => $id > 0)));
        sort($participants, SORT_NUMERIC);
        $bankRows = DB::table('checkout_cod_privat_statement_entries')
            ->where('reference_hmac', $source->reference_hmac)->orderBy('id')
            ->get(['id', 'row_hmac'])->map(static fn ($r): array => [(int) $r->id, (string) $r->row_hmac])->all();
        $gateway = DB::table('checkout_cod_gateway_reports')
            ->where('reference_hmac', $source->reference_hmac)->orderBy('id')
            ->get(['id', 'event_kind', 'net_minor'])->map(static fn ($r): array => [
                (int) $r->id, (string) $r->event_kind, (int) $r->net_minor,
            ])->all();
        $signed = DB::table('checkout_cod_bank_evidence_events')
            ->where('reference_hmac', $source->reference_hmac)->orderBy('id')
            ->get(['id', 'event_kind', 'amount_minor'])->map(static fn ($r): array => [
                (int) $r->id, (string) $r->event_kind, (int) $r->amount_minor,
            ])->all();

        return array_merge($impact, [
            'resolution_proposal_id' => (int) $proposal->id,
            'journal_draft_id' => (int) $draft->id,
            'order_id' => (int) $order->id,
            'source_kind' => (string) $proposal->source_kind,
            'source_id' => (int) $proposal->source_id,
            'plan_kind' => $kind,
            'participants' => $participants,
            'snapshot_hmac' => $this->hmac([
                'cod-adjustment-evidence-v1', (int) $proposal->id,
                (int) $proposal->exception_id, (string) $proposal->request_hmac,
                (int) $draft->id, (string) $draft->snapshot_hmac,
                (int) $order->id, (string) $order->payment_status,
                (int) $line->id, $impact, $bankRows, $gateway, $signed,
                (string) $context['snapshot_hmac'], $participants,
            ]),
        ]);
    }

    /** @return array<string,mixed> */
    public function status(string $planKey): array
    {
        $this->actor(CheckoutCodTtnInvestigationStatusService::VIEW_ABILITY);
        $this->uuids($planKey);
        $plan = DB::table('checkout_cod_adjustment_plans as p')
            ->leftJoin('checkout_cod_adjustment_plan_reviews as r', 'r.plan_id', '=', 'p.id')
            ->where('p.operation_key', strtolower($planKey))
            ->first(['p.id', 'p.operation_key', 'p.resolution_proposal_id', 'p.journal_draft_id',
                'p.order_id', 'p.plan_kind', 'p.requested_refund_minor', 'p.snapshot_hmac',
                'r.decision']);
        if ($plan === null) {
            throw new DomainException('COD adjustment plan missing');
        }
        $fresh = false;
        try {
            $resolutionKey = DB::table('checkout_cod_exception_proposals')
                ->where('id', $plan->resolution_proposal_id)->value('operation_key');
            $journalKey = DB::table('checkout_cod_journal_drafts')
                ->where('id', $plan->journal_draft_id)->value('operation_key');
            $orderKey = DB::table('orders')->where('id', $plan->order_id)->value('order_key');
            $current = $this->snapshot((string) $resolutionKey, (string) $journalKey,
                (string) $orderKey, (int) $plan->requested_refund_minor, false);
            $fresh = hash_equals((string) $plan->snapshot_hmac, $current['snapshot_hmac']);
        } catch (DomainException $ignored) {
            $fresh = false;
        }
        return [
            'plan_id' => (int) $plan->id,
            'order_id' => (int) $plan->order_id,
            'plan_kind' => (string) $plan->plan_kind,
            'snapshot_current' => $fresh,
            'review_decision' => $plan->decision,
            'status' => match (true) {
                $plan->decision === 'escalate_investigation' => 'adjustment_investigation_escalated',
                !$fresh => 'adjustment_plan_stale',
                $plan->decision === 'retain_unexecuted' => 'adjustment_reviewed_not_executed',
                default => 'adjustment_awaiting_independent_review',
            },
            'customer_refund_executed' => false,
            'financial_ledger_changed' => false,
            'payment_posting_allowed' => false,
        ];
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0 || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('COD adjustment permission denied');
        }
        return (int) $id;
    }

    private function uuids(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (!Str::isUuid($key)) {
                throw new InvalidArgumentException('Invalid COD adjustment UUID');
            }
        }
    }

    private function hmac(array $parts): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }
        return hash_hmac('sha256', json_encode($parts, JSON_THROW_ON_ERROR), $key);
    }

    /** @return array<string,mixed> */
    private function present(object $p, bool $replay): array
    {
        return [
            'plan_id' => (int) $p->id,
            'operation_key' => (string) $p->operation_key,
            'resolution_proposal_id' => (int) $p->resolution_proposal_id,
            'journal_draft_id' => (int) $p->journal_draft_id,
            'order_id' => (int) $p->order_id,
            'plan_kind' => (string) $p->plan_kind,
            'original_gross_minor' => (int) $p->original_gross_minor,
            'original_fee_minor' => (int) $p->original_fee_minor,
            'original_order_net_minor' => (int) $p->original_order_net_minor,
            'requested_refund_minor' => (int) $p->requested_refund_minor,
            'source_net_difference_minor' => (int) $p->source_net_difference_minor,
            'idempotent_replay' => $replay,
            'customer_refund_executed' => false,
            'financial_ledger_changed' => false,
            'payment_posting_allowed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function presentReview(object $r, bool $replay): array
    {
        return [
            'review_id' => (int) $r->id,
            'review_key' => (string) $r->review_key,
            'plan_id' => (int) $r->plan_id,
            'decision' => (string) $r->decision,
            'idempotent_replay' => $replay,
            'customer_refund_executed' => false,
            'financial_ledger_changed' => false,
            'payment_posting_allowed' => false,
        ];
    }
}
