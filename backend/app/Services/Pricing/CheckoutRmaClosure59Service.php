<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * BOARDRIDERS #59: closes the INTERNAL RMA workflow.
 *
 * A successful Mono provider refund does NOT prove card settlement.
 * No bank requests, bonus mutations or stock mutations are performed here.
 */
final class CheckoutRmaClosure59Service
{
    public const CLOSE_ABILITY = 'orders.returns.close';

    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly CheckoutOrderReturnPolicy $policy,
    ) {
    }

    /** @return array<string,mixed> */
    public function close(int $journalId): array
    {
        $actor = $this->actor();

        if (config('services.mono_acquiring.rma_close.enabled') !== true) {
            throw new DomainException('RMA workflow closure is disabled');
        }

        if ($journalId <= 0) {
            throw new DomainException('Invalid refund journal ID');
        }

        return DB::transaction(function () use ($journalId, $actor): array {
            $locator = DB::table('checkout_mono_refund_journal56')
                ->where('id', $journalId)
                ->first(['order_id']);

            if ($locator === null) {
                throw new DomainException('Verified refund journal not found');
            }

            // Order-first locking, as in the existing checkout services.
            $order = DB::table('orders')
                ->where('id', $locator->order_id)
                ->lockForUpdate()
                ->first();

            if ($order === null || $order->payment_status !== 'paid') {
                throw new DomainException('Paid original order required');
            }

            $source = $this->source($journalId, $order);

            $existing = DB::table('checkout_rma_closures59')
                ->where('journal_id', $journalId)
                ->first(['id']);

            if ($existing !== null) {
                return $this->read((int) $existing->id);
            }

            $blocked = [
                (int) $source['case']->opened_by,
                (int) $source['inspection']->inspected_by,
                (int) $source['decision']->decided_by,
                (int) $source['stock']->posted_by,
                (int) $source['execution']->executed_by,
                (int) $source['refund_plan']->planned_by,
                (int) $source['journal']->recorded_by,
            ];

            if (in_array($actor, $blocked, true)) {
                throw new DomainException(
                    'RMA closer must be independent of earlier operators'
                );
            }

            $this->verifyStock($source);
            $loyalty = $this->verifyLoyalty($source);

            $signed = [
                'journal_id' => $journalId,
                'case_id' => (int) $source['case']->id,
                'order_id' => (int) $order->id,
                'stock_batch_id' => (int) $source['stock']->id,
                'loyalty_postings_count' => count($loyalty['links']),
                'loyalty_digest_sha256' => $loyalty['digest'],
                'closed_by' => $actor,
                'card_settlement_confirmed' => false,
            ];

            $id = (int) DB::table('checkout_rma_closures59')
                ->insertGetId([
                    ...$signed,
                    'closure_hmac' => $this->signer->digest($signed),
                    'created_at' => now(),
                ]);

            foreach ($loyalty['links'] as $link) {
                $data = [
                    'closure_id' => $id,
                    'posting_id' => $link['posting_id'],
                ];

                DB::table('checkout_rma_closure_loyalty59')->insert([
                    ...$data,
                    'link_hmac' => $this->signer->digest($data),
                    'created_at' => now(),
                ]);
            }

            return $this->read($id);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function show(int $closureId): array
    {
        $this->actor();

        return $this->read($closureId);
    }

    /** @return array<string,mixed> */
    private function read(int $closureId): array
    {
        if ($closureId <= 0) {
            throw new DomainException('Invalid closure ID');
        }

        $closure = DB::table('checkout_rma_closures59')
            ->where('id', $closureId)
            ->first();

        if ($closure === null) {
            throw new DomainException('RMA closure not found');
        }

        $signed = [
            'journal_id' => (int) $closure->journal_id,
            'case_id' => (int) $closure->case_id,
            'order_id' => (int) $closure->order_id,
            'stock_batch_id' => (int) $closure->stock_batch_id,
            'loyalty_postings_count' =>
                (int) $closure->loyalty_postings_count,
            'loyalty_digest_sha256' =>
                (string) $closure->loyalty_digest_sha256,
            'closed_by' => (int) $closure->closed_by,
            'card_settlement_confirmed' =>
                (bool) $closure->card_settlement_confirmed,
        ];

        $this->signed(
            (string) $closure->closure_hmac,
            $signed
        );

        if ($signed['card_settlement_confirmed']) {
            throw new RuntimeException(
                'RMA closure cannot certify card settlement'
            );
        }

        $order = DB::table('orders')
            ->where('id', $signed['order_id'])
            ->first();

        if ($order === null || $order->payment_status !== 'paid') {
            throw new RuntimeException(
                'RMA closure original order missing'
            );
        }

        $source = $this->source(
            $signed['journal_id'],
            $order
        );

        if (
            (int) $source['case']->id !== $signed['case_id']
            || (int) $source['stock']->id !== $signed['stock_batch_id']
        ) {
            throw new RuntimeException('RMA closure source mismatch');
        }

        $this->verifyStock($source);
        $loyalty = $this->verifyLoyalty($source);

        if (
            count($loyalty['links']) !== $signed['loyalty_postings_count']
            || !hash_equals(
                $signed['loyalty_digest_sha256'],
                $loyalty['digest']
            )
        ) {
            throw new RuntimeException(
                'RMA closure loyalty snapshot mismatch'
            );
        }

        $rows = DB::table('checkout_rma_closure_loyalty59')
            ->where('closure_id', $closureId)
            ->orderBy('posting_id')
            ->get();

        $expected = array_column(
            $loyalty['links'],
            'posting_id'
        );

        sort($expected, SORT_NUMERIC);

        if ($rows->count() !== count($expected)) {
            throw new RuntimeException(
                'RMA closure loyalty link count mismatch'
            );
        }

        $actual = [];

        foreach ($rows as $row) {
            $data = [
                'closure_id' => (int) $row->closure_id,
                'posting_id' => (int) $row->posting_id,
            ];

            $this->signed(
                (string) $row->link_hmac,
                $data
            );

            $actual[] = $data['posting_id'];
        }

        if ($actual !== $expected) {
            throw new RuntimeException(
                'RMA closure linked wrong loyalty operations'
            );
        }

        return [
            'closure_id' => $closureId,
            'case_id' => $signed['case_id'],
            'journal_id' => $signed['journal_id'],
            'stock_batch_id' => $signed['stock_batch_id'],
            'loyalty_postings_count' =>
                $signed['loyalty_postings_count'],
            'accepted_units' => (int) $source['stock']->accepted_units,
            'sellable_units' => (int) $source['stock']->sellable_units,
            'quarantine_units' =>
                (int) $source['stock']->quarantine_units,
            'status' => 'closed_pending_card_settlement',
            'rma_closed' => true,
            'provider_refund_confirmed' => true,
            'card_settlement_confirmed' => false,
            'inventory_accounted' => true,
            'loyalty_review_complete' => true,
        ];
    }

    /** @return array<string,object|array> */
    private function source(int $journalId, object $order): array
    {
        $journal = DB::table('checkout_mono_refund_journal56')
            ->where('id', $journalId)
            ->first();

        if (
            $journal === null
            || (int) $journal->order_id !== (int) $order->id
        ) {
            throw new DomainException(
                'Refund journal belongs to another order'
            );
        }

        $execution = DB::table('checkout_mono_refund_executions')
            ->where('id', $journal->execution_id)
            ->first();

        $plan = DB::table('checkout_mono_rma_refund_plans')
            ->where('id', $journal->refund_plan_id)
            ->first();

        if (
            $execution === null
            || $plan === null
            || $execution->status !== 'succeeded'
            || (int) $execution->id !== (int) $journal->execution_id
            || (int) $execution->refund_plan_id !== (int) $plan->id
            || (int) $plan->order_id !== (int) $order->id
            || (int) $execution->order_id !== (int) $order->id
            || (int) $execution->capture_receipt_id
            !== (int) $journal->capture_receipt_id
            || (int) $plan->capture_receipt_id
            !== (int) $journal->capture_receipt_id
            || (int) $plan->amount_minor
            !== (int) $journal->amount_minor
            || (int) $execution->amount_minor
            !== (int) $journal->amount_minor
            || $execution->ext_ref !== $journal->ext_ref
            || $plan->currency_code !== $journal->currency_code
            || $execution->currency_code !== $journal->currency_code
        ) {
            throw new DomainException(
                'Refund provider or plan evidence invalid'
            );
        }

        $this->signed((string) $journal->entry_hmac, [
            'execution_id' => (int) $journal->execution_id,
            'refund_plan_id' => (int) $journal->refund_plan_id,
            'order_id' => (int) $journal->order_id,
            'capture_receipt_id' =>
                (int) $journal->capture_receipt_id,
            'observation_id' => (int) $journal->observation_id,
            'ext_ref' => (string) $journal->ext_ref,
            'amount_minor' => (int) $journal->amount_minor,
            'currency_code' => (string) $journal->currency_code,
            'recorded_by' => (int) $journal->recorded_by,
        ]);

        $this->signed((string) $execution->request_hmac, [
            'refund_plan_id' => (int) $execution->refund_plan_id,
            'order_id' => (int) $execution->order_id,
            'capture_receipt_id' =>
                (int) $execution->capture_receipt_id,
            'operation_key' => (string) $execution->operation_key,
            'ext_ref' => (string) $execution->ext_ref,
            'invoice_id' => (string) $execution->invoice_id,
            'amount_minor' => (int) $execution->amount_minor,
            'currency_code' => (string) $execution->currency_code,
            'executed_by' => (int) $execution->executed_by,
        ]);

        $this->signed((string) $plan->plan_hmac, [
            'case_id' => (int) $plan->return_case_id,
            'order_id' => (int) $plan->order_id,
            'capture_receipt_id' => (int) $plan->capture_receipt_id,
            'operation_key' => (string) $plan->operation_key,
            'amount_minor' => (int) $plan->amount_minor,
            'currency_code' => (string) $plan->currency_code,
            'planned_by' => (int) $plan->planned_by,
        ]);

        $observation = DB::table('checkout_mono_refund_observations')
            ->where('id', $journal->observation_id)
            ->first();

        $latestId = DB::table('checkout_mono_refund_observations')
            ->where('execution_id', $execution->id)
            ->max('id');

        if (
            $observation === null
            || (int) $latestId !== (int) $observation->id
            || (int) $observation->execution_id
            !== (int) $execution->id
            || $observation->source !== 'invoice_status'
            || $observation->state !== 'success'
            || (int) $observation->matched_amount_minor
            !== (int) $journal->amount_minor
        ) {
            throw new DomainException(
                'Latest exact Mono success evidence required'
            );
        }

        $obs = [
            'execution_id' => (int) $execution->id,
            'observation_key' => (string) $observation->observation_key,
            'source' => (string) $observation->source,
            'state' => (string) $observation->state,
            'matched_amount_minor' =>
                (int) $observation->matched_amount_minor,
            'evidence_sha256' => (string) $observation->evidence_sha256,
        ];

        if ($observation->reviewed_by !== null) {
            $obs['reviewed_by'] = (int) $observation->reviewed_by;
        }

        $this->signed(
            (string) $observation->evidence_hmac,
            $obs
        );

        $topics = DB::table('checkout_mono_refund_handoffs56')
            ->where('journal_id', $journalId)
            ->get();

        if ($topics->count() !== 2) {
            throw new DomainException(
                'Both immutable review handoffs required'
            );
        }

        foreach ($topics as $handoff) {
            $topic = (string) $handoff->topic;

            if (!in_array(
                $topic,
                ['inventory_review', 'loyalty_review'],
                true
            )) {
                throw new DomainException('Invalid review handoff');
            }

            $eventKey = hash(
                'sha256',
                'mono-refund-journal56:'
                . (string) $execution->operation_key
                . ':' . $topic
            );

            if (!hash_equals(
                $eventKey,
                (string) $handoff->event_key
            )) {
                throw new RuntimeException(
                    'Review handoff identifier mismatch'
                );
            }

            $this->signed(
                (string) $handoff->event_hmac,
                [
                    'journal_id' => $journalId,
                    'topic' => $topic,
                    'event_key' => $eventKey,
                ]
            );
        }

        $case = DB::table('checkout_order_return_cases')
            ->where('id', $plan->return_case_id)
            ->first();

        $inspection = DB::table('checkout_order_return_inspections')
            ->where('case_id', $plan->return_case_id)
            ->first();

        $decision = DB::table('checkout_order_return_decisions')
            ->where('case_id', $plan->return_case_id)
            ->first();

        $stock = DB::table('checkout_rma_stock_batches58')
            ->where('journal_id', $journalId)
            ->first();

        $prep = DB::table('checkout_order_preparations')
            ->where('order_id', $order->id)
            ->first();

        if (
            $case === null
            || $inspection === null
            || $decision === null
            || $prep === null
            || $stock === null
            || (int) $case->order_id !== (int) $order->id
            || $decision->decision !== 'approved'
            || (int) $decision->inspection_id
            !== (int) $inspection->id
            || (int) $stock->case_id !== (int) $case->id
            || (int) $stock->order_id !== (int) $order->id
            || (int) $stock->inspection_id !== (int) $inspection->id
            || (int) $stock->decision_id !== (int) $decision->id
        ) {
            throw new DomainException(
                'Independently inspected and stocked RMA required'
            );
        }

        $this->verifyInspectionDecision(
            $case,
            $inspection,
            $decision
        );

        if (
            (int) $decision->cash_candidate_minor
            !== (int) $journal->amount_minor
        ) {
            throw new DomainException(
                'Returned cash candidate does not match confirmed refund'
            );
        }

        return compact(
                'journal',
                'execution',
                'case',
                'inspection',
                'decision',
                'stock',
                'prep'
            ) + [
                'refund_plan' => $plan,
                'order' => $order,
            ];
    }

    private function verifyInspectionDecision(
        object $case,
        object $inspection,
        object $decision
    ): void {
        $inspectionJson = (string) $inspection->items_json;

        if (!hash_equals(
            (string) $inspection->items_sha256,
            hash('sha256', $inspectionJson)
        )) {
            throw new RuntimeException('Inspection SHA mismatch');
        }

        $items = json_decode(
            $inspectionJson,
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('Invalid RMA inspection items');
        }

        $this->signed((string) $inspection->inspection_hmac, [
            'case_id' => (int) $case->id,
            'operation_key' => (string) $inspection->operation_key,
            'receipt_reference_sha256' =>
                (string) $inspection->receipt_reference_sha256,
            'warehouse_id' => (int) $inspection->warehouse_id,
            'items' => $items,
            'inspected_by' => (int) $inspection->inspected_by,
        ]);

        $decisionData = [
            'case_id' => (int) $case->id,
            'inspection_id' => (int) $inspection->id,
            'operation_key' => (string) $decision->operation_key,
            'decision' => (string) $decision->decision,
            'accepted_qty' => (int) $decision->accepted_qty,
            'cash_candidate_minor' =>
                (int) $decision->cash_candidate_minor,
            'loyalty_candidate_minor' =>
                (int) $decision->loyalty_candidate_minor,
            'promotion_allocated_minor' =>
                (int) $decision->promotion_allocated_minor,
            'decided_by' => (int) $decision->decided_by,
            'customer_payment_authenticated' => false,
            'money_refunded' => false,
            'loyalty_refunded' => false,
            'inventory_restocked' => false,
        ];

        if (
            !hash_equals(
                (string) $decision->decision_sha256,
                hash('sha256', (string) $decision->decision_json)
            )
            || !hash_equals(
                (string) $decision->decision_json,
                $this->signer->json($decisionData)
            )
        ) {
            throw new RuntimeException(
                'Signed RMA decision JSON inconsistent'
            );
        }

        $this->signed(
            (string) $decision->decision_hmac,
            $decisionData
        );
    }

    /** @param array<string,object|array> $source */
    private function verifyStock(array $source): void
    {
        $b = $source['stock'];

        $data = [
            'journal_id' => (int) $b->journal_id,
            'case_id' => (int) $b->case_id,
            'order_id' => (int) $b->order_id,
            'inspection_id' => (int) $b->inspection_id,
            'decision_id' => (int) $b->decision_id,
            'warehouse_id' => (int) $b->warehouse_id,
            'posted_by' => (int) $b->posted_by,
            'accepted_units' => (int) $b->accepted_units,
            'sellable_units' => (int) $b->sellable_units,
            'quarantine_units' => (int) $b->quarantine_units,
        ];

        $this->signed(
            (string) $b->batch_hmac,
            $data
        );

        if (
            $data['warehouse_id'] !==
            (int) $source['inspection']->warehouse_id
            || $data['accepted_units'] !==
            (int) $source['decision']->accepted_qty
            || $data['accepted_units'] !==
            $data['sellable_units'] + $data['quarantine_units']
        ) {
            throw new RuntimeException(
                'RMA stock batch totals inconsistent'
            );
        }

        $units = DB::table('checkout_order_return_units')
            ->where('case_id', $source['case']->id)
            ->get(['id', 'variant_id']);

        $unitMap = [];

        foreach ($units as $unit) {
            $unitMap[(int) $unit->id] = (int) $unit->variant_id;
        }

        $inspectionItems = json_decode(
            (string) $source['inspection']->items_json,
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        $expected = [];

        foreach ($inspectionItems as $item) {
            $id = $item['return_unit_id'] ?? null;
            $condition = $item['condition'] ?? null;

            if (
                !is_int($id)
                || !isset($unitMap[$id])
                || isset($expected[$id])
                || !in_array(
                    $condition,
                    ['resellable', 'damaged', 'not_received'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid RMA inspected unit identity'
                );
            }

            if ($condition !== 'not_received') {
                $expected[$id] = [
                    'variant_id' => $unitMap[$id],
                    'condition' => $condition,
                ];
            }
        }

        if (count($expected) !== $data['accepted_units']) {
            throw new RuntimeException(
                'RMA stock does not match inspection'
            );
        }

        $rows = DB::table('checkout_rma_stock_units58')
            ->where('batch_id', $b->id)
            ->get();

        if ($rows->count() !== count($expected)) {
            throw new RuntimeException('RMA stock line count mismatch');
        }

        $seen = [];
        $sellable = 0;
        $quarantine = 0;

        foreach ($rows as $row) {
            $identity = [
                'batch_id' => (int) $row->batch_id,
                'order_id' => (int) $row->order_id,
                'return_unit_id' => (int) $row->return_unit_id,
                'variant_id' => (int) $row->variant_id,
                'warehouse_id' => (int) $row->warehouse_id,
                'condition' => (string) $row->condition,
                'disposition' => (string) $row->disposition,
                'receipt_key' => $row->receipt_key === null
                    ? null
                    : (string) $row->receipt_key,
                'posted_by' => (int) $row->posted_by,
            ];

            $this->signed(
                (string) $row->entry_hmac,
                $identity
            );

            $id = $identity['return_unit_id'];

            if (
                !isset($expected[$id])
                || isset($seen[$id])
                || $identity['order_id'] !== $data['order_id']
                || $identity['warehouse_id'] !== $data['warehouse_id']
                || $identity['posted_by'] !== $data['posted_by']
                || $identity['variant_id'] !==
                $expected[$id]['variant_id']
                || $identity['condition'] !==
                $expected[$id]['condition']
            ) {
                throw new RuntimeException(
                    'Incorrect posted RMA stock line'
                );
            }

            $seen[$id] = true;

            if ($identity['condition'] === 'resellable') {
                if (
                    $identity['disposition'] !== 'sellable'
                    || $identity['receipt_key'] === null
                ) {
                    throw new RuntimeException(
                        'Sellable RMA unit missing receipt reference'
                    );
                }

                $receipt = DB::table('inventory_receipts as r')
                    ->join(
                        'inventory_stocks as s',
                        's.id',
                        '=',
                        'r.inventory_stock_id'
                    )
                    ->where('r.receipt_key', $identity['receipt_key'])
                    ->where('s.warehouse_id', $data['warehouse_id'])
                    ->where('s.variant_id', $identity['variant_id'])
                    ->first(['r.quantity']);

                if (
                    $receipt === null
                    || (int) $receipt->quantity !== 1
                ) {
                    throw new RuntimeException(
                        'Original stock receipt missing'
                    );
                }

                $sellable++;
            } elseif (
                $identity['condition'] === 'damaged'
                && $identity['disposition'] === 'quarantine'
                && $identity['receipt_key'] === null
            ) {
                $quarantine++;
            } else {
                throw new RuntimeException(
                    'Invalid RMA stock disposition'
                );
            }
        }

        if (
            $sellable !== $data['sellable_units']
            || $quarantine !== $data['quarantine_units']
        ) {
            throw new RuntimeException(
                'RMA stock line totals changed'
            );
        }
    }

    /**
     * @param array<string,object|array> $source
     * @return array{
     *   links:list<array{
     *     posting_id:int,
     *     plan_id:int,
     *     operation_id:string,
     *     result_hash:string
     *   }>,
     *   digest:string
     * }
     */
    private function verifyLoyalty(array $source): array
    {
        $journalId = (int) $source['journal']->id;

        $plans = DB::table('checkout_mono_refund_loyalty_plans57')
            ->where('journal_id', $journalId)
            ->orderBy('id')
            ->get();

        $postings = DB::table(
            'checkout_mono_refund_loyalty_postings57 as x'
        )
            ->join(
                'checkout_mono_refund_loyalty_plans57 as p',
                'p.id',
                '=',
                'x.plan_id'
            )
            ->where('p.journal_id', $journalId)
            ->get(['x.*']);

        if ($plans->count() !== $postings->count()) {
            throw new DomainException(
                'All approved loyalty adjustments must be posted'
            );
        }

        $byPlan = [];

        foreach ($postings as $posting) {
            $byPlan[(int) $posting->plan_id] = $posting;
        }

        $earned = DB::table('loyalty_grants')
            ->where(
                'source_ref',
                (string) $source['order']->order_key
            )
            ->where('grant_type', 'purchase')
            ->get(['id', 'wallet_id', 'market_id']);

        $expectedEarned = [];

        foreach ($earned as $grant) {
            $expectedEarned[(int) $grant->id] = $grant;
        }

        // Only physically received returned units can require
        // restoration of spent bonuses.
        $inspectionItems = json_decode(
            (string) $source['inspection']->items_json,
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        $receivedIds = [];

        foreach ($inspectionItems as $item) {
            if ($item['condition'] !== 'not_received') {
                $receivedIds[] = (int) $item['return_unit_id'];
            }
        }

        $neededRestore = $receivedIds !== []
            && (int) DB::table('checkout_order_return_units')
                ->where('case_id', $source['case']->id)
                ->whereIn('id', $receivedIds)
                ->sum('loyalty_minor') > 0;

        $seenEarned = [];
        $seenRestored = false;
        $links = [];

        foreach ($plans as $plan) {
            $post = $byPlan[(int) $plan->id] ?? null;

            if ($post === null) {
                throw new DomainException('Unposted RMA bonus plan');
            }

            $alloc = json_decode(
                (string) $plan->allocations,
                true,
                32,
                JSON_THROW_ON_ERROR
            );

            $expiry = json_decode(
                (string) $plan->expiry_overrides,
                true,
                32,
                JSON_THROW_ON_ERROR
            );

            if (
                !is_array($alloc)
                || !array_is_list($alloc)
                || $alloc === []
                || !is_array($expiry)
            ) {
                throw new RuntimeException(
                    'Invalid signed RMA bonus allocation'
                );
            }

            $signedPlan = [
                'journal_id' => (int) $plan->journal_id,
                'wallet_id' => (int) $plan->wallet_id,
                'effect_kind' => (string) $plan->effect_kind,
                'source_id' => (int) $plan->source_id,
                'allocations' => $alloc,
                'expiry_overrides' => (object) $expiry,
                'calculation_sha256' =>
                    (string) $plan->calculation_sha256,
                'approved_by' => (int) $plan->approved_by,
            ];

            $this->signed(
                (string) $plan->plan_hmac,
                $signedPlan
            );

            $wallet = DB::table('loyalty_wallets')
                ->where('id', $plan->wallet_id)
                ->first(['market_id']);

            if (
                $wallet === null
                || (int) $wallet->market_id !==
                (int) $source['prep']->market_id
            ) {
                throw new RuntimeException(
                    'RMA bonus wallet market mismatch'
                );
            }

            if ($plan->effect_kind === 'reverse_earned') {
                $grant = $expectedEarned[
                (int) $plan->source_id
                ] ?? null;

                if (
                    $grant === null
                    || (int) $grant->wallet_id !==
                    (int) $plan->wallet_id
                ) {
                    throw new DomainException(
                        'Earned grant not attributable to this order'
                    );
                }

                if (
                    count($alloc) !== 1
                    || (int) ($alloc[0]['grant_id'] ?? 0) !==
                    (int) $plan->source_id
                    || (int) ($alloc[0]['units'] ?? 0) <= 0
                ) {
                    throw new RuntimeException(
                        'Incorrect earned bonus reversal allocation'
                    );
                }

                $seenEarned[(int) $plan->source_id] = true;
                $operationType = 'grant_reversal';
            } elseif ($plan->effect_kind === 'restore_spent') {
                if (
                    !$neededRestore
                    || $seenRestored
                    || $source['prep']->loyalty_reservation_id === null
                    || (int) $plan->source_id !==
                    (int) $source['prep']->loyalty_reservation_id
                    || (int) $plan->wallet_id !==
                    (int) $source['prep']->loyalty_wallet_id
                ) {
                    throw new DomainException(
                        'Spent bonus restoration does not match checkout'
                    );
                }

                $seenRestored = true;
                $operationType = 'redemption_refund';
            } else {
                throw new RuntimeException(
                    'Unknown signed RMA bonus effect'
                );
            }

            $signedPost = [
                'plan_id' => (int) $post->plan_id,
                'loyalty_operation_id' =>
                    (string) $post->loyalty_operation_id,
                'posted_by' => (int) $post->posted_by,
                'result_sha256' =>
                    (string) $post->result_sha256,
            ];

            $this->signed(
                (string) $post->posting_hmac,
                $signedPost
            );

            if (
                (int) $post->posted_by ===
                (int) $plan->approved_by
            ) {
                throw new RuntimeException(
                    'Loyalty dual control missing'
                );
            }

            $operationKey = sprintf(
                'mono57:j%d:%s:%d',
                $journalId,
                $plan->effect_kind,
                $plan->source_id
            );

            $operation = DB::table('loyalty_operations')
                ->where('id', $post->loyalty_operation_id)
                ->where('wallet_id', $plan->wallet_id)
                ->where('operation_key', $operationKey)
                ->where('operation_type', $operationType)
                ->first(['result']);

            if ($operation === null) {
                throw new RuntimeException(
                    'Loyalty ledger operation missing'
                );
            }

            $result = json_decode(
                (string) $operation->result,
                true,
                32,
                JSON_THROW_ON_ERROR
            );

            // PostgreSQL JSONB key order must not change this hash.
            $resultHash = hash(
                'sha256',
                json_encode(
                    $this->canonical($result),
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                )
            );

            if (!hash_equals(
                (string) $post->result_sha256,
                $resultHash
            )) {
                throw new RuntimeException(
                    'Loyalty operation result signature mismatch'
                );
            }

            $links[] = [
                'posting_id' => (int) $post->id,
                'plan_id' => (int) $plan->id,
                'operation_id' =>
                    (string) $post->loyalty_operation_id,
                'result_hash' => $resultHash,
            ];
        }

        if (
            count($seenEarned) !== count($expectedEarned)
            || ($neededRestore && !$seenRestored)
        ) {
            throw new DomainException(
                'Order has unreviewed earned or spent bonus effects'
            );
        }

        usort(
            $links,
            static fn (array $a, array $b): int =>
                $a['posting_id'] <=> $b['posting_id']
        );

        $digest = hash(
            'sha256',
            json_encode(
                $links,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            )
        );

        return [
            'links' => $links,
            'digest' => $digest,
        ];
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }

    /** @param array<string,mixed> $data */
    private function signed(string $hmac, array $data): void
    {
        if (!hash_equals(
            $hmac,
            $this->signer->digest($data)
        )) {
            throw new RuntimeException(
                'RMA59 immutable audit signature mismatch'
            );
        }
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            $user === null
            || !is_numeric($id)
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(self::CLOSE_ABILITY)
        ) {
            throw new AuthorizationException(
                'RMA workflow close access denied'
            );
        }

        return (int) $id;
    }
}
