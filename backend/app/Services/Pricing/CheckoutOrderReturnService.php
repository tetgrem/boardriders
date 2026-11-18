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

/** Staff-only RMA. Approval is a proposal: no payment, loyalty or stock mutation. */
final class CheckoutOrderReturnService
{
    public const VIEW_ABILITY = 'orders.returns.view';
    public const OPEN_ABILITY = 'orders.returns.open';
    public const INSPECT_ABILITY = 'orders.returns.inspect';
    public const DECIDE_ABILITY = 'orders.returns.decide';
    public const CANCEL_ABILITY = 'orders.returns.cancel_before_dispatch';

    public function __construct(
        private readonly CheckoutOrderReturnPolicy $policy,
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly CheckoutOrderCancellationService $cancellations
    ) {
    }

    public function cancelBeforeDispatch(string $orderKey, string $operationKey, string $reason = 'customer_cancel'): array
    {
        $this->actor(self::CANCEL_ABILITY);
        $orderKey = $this->uuid($orderKey);
        $operationKey = $this->uuid($operationKey);
        return DB::transaction(function () use ($orderKey, $operationKey, $reason): array {
            $order = DB::table('orders')->where('order_key', $orderKey)
                ->lockForUpdate()->first(['id']);
            if ($order === null) {
                throw new DomainException('Order not found');
            }
            if (DB::table('checkout_cod_ttn_submission_intents')->where('order_id', $order->id)->exists()
                || DB::table('checkout_order_return_cases')->where('order_id', $order->id)->exists()
                || DB::table('checkout_cod_settlement_cases')->where('order_id', $order->id)->exists()) {
                throw new DomainException('Fulfillment or settlement exists: use reviewed workflow');
            }
            return $this->cancellations->cancel($orderKey, $operationKey, $reason);
        }, 3);
    }

    public function open(
        string $orderKey,
        string $operationKey,
        string $reason,
        array $items,
        ?int $sourceLifecycleEventId = null
    ): array {
        $actor = $this->actor(self::OPEN_ABILITY);
        $orderKey = $this->uuid($orderKey);
        $operationKey = $this->uuid($operationKey);
        if (!in_array($reason, ['customer_return','merchant_return','damaged','wrong_item',
            'carrier_refusal','carrier_unclaimed','carrier_return'], true)) {
            throw new InvalidArgumentException('Invalid return reason');
        }
        if ($sourceLifecycleEventId !== null && $sourceLifecycleEventId < 1) {
            throw new InvalidArgumentException('Invalid lifecycle evidence ID');
        }
        $requested = $this->policy->normalize($items);
        return DB::transaction(function () use ($orderKey, $operationKey, $reason,
            $sourceLifecycleEventId, $requested, $actor): array {
            $order = DB::table('orders')->where('order_key', $orderKey)
                ->lockForUpdate()->first(['id', 'payment_status']);
            if ($order === null) {
                throw new DomainException('Order not found');
            }
            if (!in_array($order->payment_status, ['pending','review','paid'], true)
                || DB::table('checkout_order_cancellations')->where('order_id', $order->id)->exists()) {
                throw new DomainException('Canceled order cannot open RMA');
            }
            $financial = DB::table('order_financial_snapshots')
                ->where('order_id', $order->id)->first();
            $snapshot = $this->financialSnapshot($financial);
            $normalized = [];
            foreach ($requested as $variant => $qty) {
                $normalized[] = ['variant_id' => $variant, 'quantity' => $qty];
            }
            $request = [
                'order_key' => $orderKey, 'reason_code' => $reason,
                'items' => $normalized, 'source_lifecycle_event_id' => $sourceLifecycleEventId,
                'opened_by' => $actor, 'financial_snapshot_sha256' => (string) $financial->snapshot_sha256,
            ];
            $hmac = $this->signer->digest($request);
            $existing = DB::table('checkout_order_return_cases')
                ->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ((int) $existing->order_id !== (int) $order->id
                    || !hash_equals((string) $existing->request_hmac, $hmac)) {
                    throw new DomainException('Return operation key reused for another request');
                }
                return $this->read(
                    $this->case((string) $existing->return_key),
                    true
                );
            }
            $this->assertCarrierEvidence((int) $order->id, $reason, $sourceLifecycleEventId);
            $claimed = [];
            foreach (DB::table('checkout_order_return_units')
                         ->where('order_id', $order->id)->get(['variant_id','unit_index']) as $u) {
                $claimed[(int) $u->variant_id][(int) $u->unit_index] = true;
            }
            $quote = $this->policy->allocate($snapshot, $requested, $claimed);
            $returnKey = (string) Str::uuid();
            $caseId = DB::table('checkout_order_return_cases')->insertGetId([
                'return_key' => $returnKey,
                'operation_key' => $operationKey,
                'order_id' => $order->id,
                'reason_code' => $reason,
                'source_lifecycle_event_id' => $sourceLifecycleEventId,
                'requested_qty' => $quote['totals']['units'],
                'financial_snapshot_sha256' => $financial->snapshot_sha256,
                'request_hmac' => $hmac,
                'opened_by' => $actor,
                'created_at' => now(),
            ]);
            foreach ($quote['units'] as $u) {
                DB::table('checkout_order_return_units')->insert([
                    'case_id' => $caseId,
                    'order_id' => $order->id,
                    'variant_id' => $u['variant_id'],
                    'unit_index' => $u['unit_index'],
                    'gross_minor' => $u['gross_minor'],
                    'promotion_minor' => $u['promotion_minor'],
                    'loyalty_minor' => $u['loyalty_minor'],
                    'cash_minor' => $u['cash_minor'],
                ]);
            }
            return $this->read(
                $this->case($returnKey),
                false
            );
        }, 3);

    }

    public function inspect(string $returnKey, string $operationKey, int $warehouseId, string $receiptReference, array $conditions): array
    {
        $actor = $this->actor(self::INSPECT_ABILITY);
        $returnKey = $this->uuid($returnKey);
        $operationKey = $this->uuid($operationKey);
        if ($warehouseId < 1) {
            throw new InvalidArgumentException('Invalid receiving warehouse');
        }
        $receiptReference = trim($receiptReference);
        if (mb_strlen($receiptReference) < 4 || mb_strlen($receiptReference) > 120) {
            throw new InvalidArgumentException('Warehouse receipt reference must have 4..120 characters');
        }
        $receiptSha = hash('sha256', $receiptReference);
        return DB::transaction(function () use ($returnKey, $operationKey, $warehouseId, $receiptSha, $conditions, $actor): array {
            $case = $this->case($returnKey);
            DB::table('orders')->where('id', $case->order_id)->lockForUpdate()->first(['id']);
            $case = $this->case($returnKey);
            $this->assertCase($case);
            if ((int) $case->opened_by === $actor) {
                throw new DomainException('Case author cannot inspect own return');
            }
            $units = $this->units((int) $case->id);
            $assessed = $this->policy->inspect($units, $conditions);
            $json = $this->signer->json($assessed['items']);
            $request = [
                'case_id' => (int) $case->id, 'operation_key' => $operationKey,
                'receipt_reference_sha256' => $receiptSha, 'warehouse_id' => $warehouseId,
                'items' => $assessed['items'], 'inspected_by' => $actor,
            ];
            $hmac = $this->signer->digest($request);
            $existing = DB::table('checkout_order_return_inspections')
                ->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ((int) $existing->case_id !== (int) $case->id
                    || !hash_equals((string) $existing->inspection_hmac, $hmac)) {
                    throw new DomainException('Inspection operation key conflict');
                }
                return $this->read($case, true);
            }
            if (DB::table('checkout_order_return_inspections')->where('case_id', $case->id)->exists()) {
                throw new DomainException('Return already inspected');
            }
            DB::table('checkout_order_return_inspections')->insert([
                'case_id' => $case->id, 'operation_key' => $operationKey,
                'warehouse_id' => $warehouseId,
                'receipt_reference_sha256' => $receiptSha,
                'items_json' => $json, 'items_sha256' => hash('sha256', $json),
                'inspection_hmac' => $hmac, 'inspected_by' => $actor,
                'created_at' => now(),
            ]);
            return $this->read($case, false);
        }, 3);
    }

    public function decide(string $returnKey, string $operationKey, string $decision): array
    {
        $actor = $this->actor(self::DECIDE_ABILITY);
        $returnKey = $this->uuid($returnKey);
        $operationKey = $this->uuid($operationKey);
        if (!in_array($decision, ['approved','rejected'], true)) {
            throw new InvalidArgumentException('Invalid RMA decision');
        }
        return DB::transaction(function () use ($returnKey, $operationKey, $decision, $actor): array {
            $case = $this->case($returnKey);
            DB::table('orders')->where('id', $case->order_id)->lockForUpdate()->first(['id']);
            $case = $this->case($returnKey);
            $this->assertCase($case);
            $inspection = DB::table('checkout_order_return_inspections')->where('case_id', $case->id)->first();
            if ($inspection === null) {
                throw new DomainException('Independent warehouse inspection required');
            }
            $items = $this->inspectionItems($inspection);
            if (in_array($actor, [(int) $case->opened_by, (int) $inspection->inspected_by], true)) {
                throw new DomainException('Decision requires third independent employee');
            }
            $units = $this->units((int) $case->id);
            $conditions = $this->conditionsFromStored($units, $items);
            $assessed = $this->policy->inspect($units, $conditions);
            $totals = $assessed['totals'];
            if ($decision === 'approved' && $totals['units'] === 0) {
                throw new DomainException('Cannot approve a return with nothing received');
            }
            if ($decision === 'rejected') {
                $totals = ['units' => 0, 'cash_minor' => 0, 'loyalty_minor' => 0, 'promotion_minor' => 0];
            }
            $payload = [
                'case_id' => (int) $case->id,
                'inspection_id' => (int) $inspection->id,
                'operation_key' => $operationKey,
                'decision' => $decision,
                'accepted_qty' => (int) $totals['units'],
                'cash_candidate_minor' => (int) $totals['cash_minor'],
                'loyalty_candidate_minor' => (int) $totals['loyalty_minor'],
                'promotion_allocated_minor' => (int) $totals['promotion_minor'],
                'decided_by' => $actor,
                'customer_payment_authenticated' => false,
                'money_refunded' => false,
                'loyalty_refunded' => false,
                'inventory_restocked' => false,
            ];
            $json = $this->signer->json($payload);
            $hmac = $this->signer->digest($payload);
            $existing = DB::table('checkout_order_return_decisions')
                ->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ((int) $existing->case_id !== (int) $case->id
                    || !hash_equals((string) $existing->decision_hmac, $hmac)) {
                    throw new DomainException('Review operation key conflict');
                }
                return $this->read($case, true);
            }
            if (DB::table('checkout_order_return_decisions')->where('case_id', $case->id)->exists()) {
                throw new DomainException('Return decision already recorded');
            }
            DB::table('checkout_order_return_decisions')->insert([
                'case_id' => $case->id, 'inspection_id' => $inspection->id,
                'operation_key' => $operationKey, 'decision' => $decision,
                'accepted_qty' => $payload['accepted_qty'],
                'cash_candidate_minor' => $payload['cash_candidate_minor'],
                'loyalty_candidate_minor' => $payload['loyalty_candidate_minor'],
                'promotion_allocated_minor' => $payload['promotion_allocated_minor'],
                'decision_json' => $json, 'decision_sha256' => hash('sha256', $json),
                'decision_hmac' => $hmac, 'decided_by' => $actor,
                'created_at' => now(),
            ]);
            return $this->read($case, false);
        }, 3);
    }

    public function show(string $returnKey): array
    {
        $this->actor(self::VIEW_ABILITY);
        return $this->read($this->case($this->uuid($returnKey)), false);
    }

    public function queue(string $filter = 'all', int $limit = 20, ?int $beforeId = null): array
    {
        $this->actor(self::VIEW_ABILITY);
        if (!in_array($filter, ['all','inspection','review','execution','closed'], true)
            || $limit < 1 || $limit > 100 || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException('Invalid return queue filters');
        }
        $q = DB::table('checkout_order_return_cases as c')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->leftJoin('checkout_order_return_inspections as i', 'i.case_id', '=', 'c.id')
            ->leftJoin('checkout_order_return_decisions as d', 'd.case_id', '=', 'c.id')
            ->select(['c.id', 'c.return_key', 'o.order_key', 'c.requested_qty',
                'c.reason_code', 'c.created_at', 'i.id as inspection_id', 'd.decision']);
        if ($filter === 'inspection') {
            $q->whereNull('i.id');
        } elseif ($filter === 'review') {
            $q->whereNotNull('i.id')->whereNull('d.id');
        } elseif ($filter === 'execution') {
            $q->where('d.decision', 'approved');
        } elseif ($filter === 'closed') {
            $q->where('d.decision', 'rejected');
        }
        if ($beforeId !== null) {
            $q->where('c.id', '<', $beforeId);
        }
        $rows = $q->orderByDesc('c.id')->limit($limit + 1)->get();
        $items = $rows->take($limit)->map(static fn (object $r): array => [
            'case_id' => (int) $r->id,
            'return_key' => (string) $r->return_key,
            'order_key' => (string) $r->order_key,
            'requested_qty' => (int) $r->requested_qty,
            'reason_code' => (string) $r->reason_code,
            'workflow' => $r->decision === 'rejected' ? 'closed_rejected'
                : ($r->decision === 'approved' ? 'awaiting_separate_execution'
                    : ($r->inspection_id === null ? 'awaiting_inspection' : 'awaiting_review')),
            'money_refunded' => false, 'inventory_restocked' => false,
        ])->values()->all();
        return [
            'items' => $items,
            'next_before_id' => $rows->count() > $limit && $items !== []
                ? $items[count($items) - 1]['case_id'] : null,
        ];
    }

    private function read(object $case, bool $replayed): array
    {
        $units = $this->assertCase($case);
        $inspection = DB::table('checkout_order_return_inspections')->where('case_id', $case->id)->first();
        $decision = DB::table('checkout_order_return_decisions')->where('case_id', $case->id)->first();
        $inspectionData = null;
        if ($inspection !== null) {
            $items = $this->inspectionItems($inspection);
            $assessment = $this->policy->inspect($units, $this->conditionsFromStored($units, $items));
            $inspectionData = [
                'inspected_by' => (int) $inspection->inspected_by,
                'warehouse_id' => (int) $inspection->warehouse_id,
                'receipt_reference_sha256' => (string) $inspection->receipt_reference_sha256,
                'conditions' => $items,
                'assessed_totals' => $assessment['totals'],
            ];
        }
        $decisionData = null;
        if ($decision !== null) {
            if ($inspection === null) {
                throw new DomainException('Return decision without inspection');
            }
            $this->assertDecision($decision, $inspection, $case, $inspectionData);
            $decisionData = [
                'decision' => (string) $decision->decision,
                'accepted_qty' => (int) $decision->accepted_qty,
                'cash_candidate_minor' => (int) $decision->cash_candidate_minor,
                'loyalty_candidate_minor' => (int) $decision->loyalty_candidate_minor,
                'promotion_allocated_minor' => (int) $decision->promotion_allocated_minor,
                'decided_by' => (int) $decision->decided_by,
            ];
        }
        $workflow = $decision?->decision === 'rejected' ? 'closed_rejected'
            : ($decision?->decision === 'approved' ? 'awaiting_separate_execution'
                : ($inspection === null ? 'awaiting_inspection' : 'awaiting_review'));
        return [
            'return_key' => (string) $case->return_key,
            'order_key' => (string) $case->order_key,
            'reason_code' => (string) $case->reason_code,
            'requested_qty' => (int) $case->requested_qty,
            'units' => $units,
            'inspection' => $inspectionData,
            'decision' => $decisionData,
            'workflow' => $workflow,
            'idempotent_replay' => $replayed,
            'tracking_candidate_is_not_shipment_ownership' => true,
            'physical_receipt_is_staff_reported' => true,
            'money_refunded' => false,
            'loyalty_refunded' => false,
            'inventory_restocked' => false,
            'customer_payment_authenticated' => false,
            'separate_finance_and_warehouse_execution_required' => true,
        ];
    }

    private function assertCase(object $case): array
    {
        $units = $this->units((int) $case->id);
        if (count($units) !== (int) $case->requested_qty) {
            throw new DomainException('RMA units count mismatch');
        }
        $counts = [];
        foreach ($units as $u) {
            $counts[$u['variant_id']] = ($counts[$u['variant_id']] ?? 0) + 1;
        }
        ksort($counts, SORT_NUMERIC);
        $items = [];
        foreach ($counts as $variant => $quantity) {
            $items[] = ['variant_id' => $variant, 'quantity' => $quantity];
        }
        $expected = [
            'order_key' => (string) $case->order_key,
            'reason_code' => (string) $case->reason_code,
            'items' => $items,
            'source_lifecycle_event_id' => $case->source_lifecycle_event_id === null
                ? null : (int) $case->source_lifecycle_event_id,
            'opened_by' => (int) $case->opened_by,
            'financial_snapshot_sha256' => (string) $case->financial_snapshot_sha256,
        ];
        if (!hash_equals((string) $case->request_hmac, $this->signer->digest($expected))) {
            throw new DomainException('RMA signed request mismatch');
        }
        $financial = DB::table('order_financial_snapshots')->where('order_id', $case->order_id)->first();
        $this->financialSnapshot($financial);
        if (!hash_equals((string) $case->financial_snapshot_sha256, (string) $financial->snapshot_sha256)) {
            throw new DomainException('Return financial snapshot reference mismatch');
        }
        return $units;
    }

    private function inspectionItems(object $inspection): array
    {
        $json = (string) $inspection->items_json;
        if (!hash_equals((string) $inspection->items_sha256, hash('sha256', $json))) {
            throw new DomainException('Inspection SHA mismatch');
        }
        try {
            $items = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('Inspection JSON invalid');
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new DomainException('Inspection items invalid');
        }
        $request = [
            'case_id' => (int) $inspection->case_id,
            'operation_key' => (string) $inspection->operation_key,
            'receipt_reference_sha256' => (string) $inspection->receipt_reference_sha256,
            'warehouse_id' => (int) $inspection->warehouse_id,
            'items' => $items,
            'inspected_by' => (int) $inspection->inspected_by,
        ];
        if (!hash_equals((string) $inspection->inspection_hmac, $this->signer->digest($request))) {
            throw new DomainException('Inspection HMAC mismatch');
        }
        return $items;
    }

    private function assertDecision(object $decision, object $inspection, object $case, array $inspectionData): void
    {
        $json = (string) $decision->decision_json;
        if (!hash_equals((string) $decision->decision_sha256, hash('sha256', $json))) {
            throw new DomainException('Decision JSON SHA mismatch');
        }
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('Decision JSON invalid');
        }
        if (!is_array($payload)) {
            throw new DomainException('Decision payload invalid');
        }
        $expected = [
            'case_id' => (int) $case->id,
            'inspection_id' => (int) $inspection->id,
            'operation_key' => (string) $decision->operation_key,
            'decision' => (string) $decision->decision,
            'accepted_qty' => (int) $decision->accepted_qty,
            'cash_candidate_minor' => (int) $decision->cash_candidate_minor,
            'loyalty_candidate_minor' => (int) $decision->loyalty_candidate_minor,
            'promotion_allocated_minor' => (int) $decision->promotion_allocated_minor,
            'decided_by' => (int) $decision->decided_by,
            'customer_payment_authenticated' => false,
            'money_refunded' => false,
            'loyalty_refunded' => false,
            'inventory_restocked' => false,
        ];
        if (!hash_equals($this->signer->json($expected), $this->signer->json($payload))
            || !hash_equals((string) $decision->decision_hmac, $this->signer->digest($expected))) {
            throw new DomainException('Return decision signature mismatch');
        }
        $t = $inspectionData['assessed_totals'];
        if ($decision->decision === 'approved') {
            if ((int) $decision->accepted_qty !== $t['units']
                || (int) $decision->cash_candidate_minor !== $t['cash_minor']
                || (int) $decision->loyalty_candidate_minor !== $t['loyalty_minor']
                || (int) $decision->promotion_allocated_minor !== $t['promotion_minor']) {
                throw new DomainException('Decision amounts do not match inspection');
            }
        } elseif ((int) $decision->accepted_qty !== 0 || (int) $decision->cash_candidate_minor !== 0
            || (int) $decision->loyalty_candidate_minor !== 0
            || (int) $decision->promotion_allocated_minor !== 0) {
            throw new DomainException('Rejected RMA has financial amounts');
        }
    }

    private function conditionsFromStored(array $units, array $items): array
    {
        $map = [];
        foreach ($units as $u) {
            $map[$u['id']] = $u;
        }
        $out = [];
        foreach ($items as $item) {
            $u = $map[$item['return_unit_id'] ?? 0] ?? null;
            if ($u === null) {
                throw new DomainException('Inspection refers to unknown unit');
            }
            $out[] = ['variant_id' => $u['variant_id'],
                'unit_index' => $u['unit_index'], 'condition' => $item['condition'] ?? null];
        }
        return $out;
    }

    private function units(int $caseId): array
    {
        return DB::table('checkout_order_return_units')->where('case_id', $caseId)
            ->orderBy('variant_id')->orderBy('unit_index')->get()
            ->map(static fn (object $u): array => [
                'id' => (int) $u->id, 'variant_id' => (int) $u->variant_id,
                'unit_index' => (int) $u->unit_index,
                'gross_minor' => (int) $u->gross_minor,
                'promotion_minor' => (int) $u->promotion_minor,
                'loyalty_minor' => (int) $u->loyalty_minor,
                'cash_minor' => (int) $u->cash_minor,
            ])->all();
    }

    private function financialSnapshot(?object $row): array
    {
        if ($row === null || !hash_equals((string) $row->snapshot_sha256, hash('sha256', (string) $row->snapshot_json))) {
            throw new DomainException('Missing or invalid immutable financial snapshot');
        }
        try {
            $json = json_decode((string) $row->snapshot_json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('Financial snapshot JSON invalid');
        }
        if (!is_array($json) || ($json['goods_payable_minor'] ?? null) !== (int) $row->goods_payable_minor
            || ($json['currency_code'] ?? null) !== (string) $row->currency_code) {
            throw new DomainException('Financial snapshot metadata invalid');
        }
        return $json;
    }

    private function assertCarrierEvidence(int $orderId, string $reason, ?int $eventId): void
    {
        $byReason = ['carrier_refusal' => 'refused', 'carrier_unclaimed' => 'unclaimed',
            'carrier_return' => 'return_requested'];
        if (!isset($byReason[$reason])) {
            if ($eventId !== null) {
                throw new DomainException('Manual return cannot use carrier reason evidence');
            }
            return;
        }
        if ($eventId === null || !DB::table('checkout_cod_ttn_lifecycle_events')
                ->where('id', $eventId)->where('order_id', $orderId)
                ->where('effective_state', $byReason[$reason])->exists()) {
            throw new DomainException('Matching carrier lifecycle evidence required');
        }
    }

    private function case(string $key): object
    {
        $case = DB::table('checkout_order_return_cases as c')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->where('c.return_key', $key)
            ->first(['c.*', 'o.order_key']);
        if ($case === null) {
            throw new DomainException('Return case not found');
        }
        return $case;
    }

    private function uuid(string $key): string
    {
        if (!Str::isUuid($key)) {
            throw new InvalidArgumentException('Invalid return UUID');
        }
        return strtolower($key);
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1
            || !Gate::forUser($user)->allows($ability)) {
            throw new AuthorizationException('Return workflow permission denied');
        }
        return (int) $id;
    }
}
