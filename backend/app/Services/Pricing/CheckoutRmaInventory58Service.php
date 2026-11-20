<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\Inventory\InventoryService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * BOARDRIDERS #58.
 *
 * Physically received RMA units:
 * - resellable -> sellable inventory receipt;
 * - damaged -> quarantine record, without increasing sellable stock;
 * - not_received -> no stock mutation.
 *
 * No monetary provider calls.
 * No restocking without original shipped quantity.
 */
final class CheckoutRmaInventory58Service
{
    public const POST_ABILITY = 'orders.returns.inventory.post';

    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly CheckoutOrderReturnPolicy $policy,
        private readonly InventoryService $inventory,
    ) {
    }

    /** @return array<string,mixed> */
    public function post(int $journalId): array
    {
        $actor = $this->actor();

        if (
            config(
                'services.mono_acquiring.inventory_returns.posting_enabled'
            ) !== true
        ) {
            throw new DomainException(
                'RMA inventory posting is disabled'
            );
        }

        if ($journalId <= 0) {
            throw new DomainException(
                'Invalid Mono refund journal ID'
            );
        }

        return DB::transaction(function () use (
            $journalId,
            $actor
        ): array {
            $journal = DB::table(
                'checkout_mono_refund_journal56'
            )
                ->where('id', $journalId)
                ->first();

            if ($journal === null) {
                throw new DomainException(
                    'Confirmed refund journal is missing'
                );
            }

            // Lock order is consistent with the other checkout services.
            $order = DB::table('orders')
                ->where('id', $journal->order_id)
                ->lockForUpdate()
                ->first([
                    'id',
                    'order_key',
                    'payment_status',
                ]);

            if (
                $order === null
                || $order->payment_status !== 'paid'
            ) {
                throw new DomainException(
                    'Original paid order is required'
                );
            }

            $existing = DB::table(
                'checkout_rma_stock_batches58'
            )
                ->where('journal_id', $journalId)
                ->first();

            if ($existing !== null) {
                return $this->read((int) $existing->id);
            }

            $source = $this->verifiedSource($journal, $order);

            $case = $source['case'];
            $inspection = $source['inspection'];
            $decision = $source['decision'];
            $warehouseId = (int) $inspection->warehouse_id;

            if (in_array($actor, [
                (int) $case->opened_by,
                (int) $inspection->inspected_by,
                (int) $decision->decided_by,
                (int) $source['execution']->executed_by,
                (int) $source['refund_plan']->planned_by,
                (int) $journal->recorded_by,
            ], true)) {
                throw new DomainException(
                    'Inventory poster must be independent '
                    . 'of RMA and finance staff'
                );
            }

            $accepted = $source['accepted'];

            if ($accepted === []) {
                throw new DomainException(
                    'No physically received RMA units'
                );
            }

            // Compare total returned quantity with actual shipped quantity.
            $newByVariant = [];

            foreach ($accepted as $unit) {
                $variant = (int) $unit['variant_id'];

                $newByVariant[$variant] =
                    ($newByVariant[$variant] ?? 0) + 1;
            }

            ksort($newByVariant, SORT_NUMERIC);

            foreach ($newByVariant as $variantId => $quantity) {
                $shipped = (int) DB::table(
                    'inventory_reservations as r'
                )
                    ->join(
                        'inventory_stocks as s',
                        's.id',
                        '=',
                        'r.inventory_stock_id'
                    )
                    ->where('r.order_id', $order->id)
                    ->where('s.variant_id', $variantId)
                    ->sum('r.shipped_qty');

                $previouslyReceived = (int) DB::table(
                    'checkout_rma_stock_units58'
                )
                    ->where('order_id', $order->id)
                    ->where('variant_id', $variantId)
                    ->count();

                // Quarantined items are counted too.
                // They cannot be physically received a second time.
                if (
                    $shipped < $previouslyReceived + $quantity
                ) {
                    throw new DomainException(
                        'RMA restock exceeds physically shipped quantity'
                    );
                }
            }

            $sellable = count(array_filter(
                $accepted,
                fn (array $unit): bool =>
                    $unit['condition'] === 'resellable'
            ));

            $quarantine = count($accepted) - $sellable;

            $batch = [
                'journal_id' => $journalId,
                'case_id' => (int) $case->id,
                'order_id' => (int) $order->id,
                'inspection_id' => (int) $inspection->id,
                'decision_id' => (int) $decision->id,
                'warehouse_id' => $warehouseId,
                'posted_by' => $actor,
                'accepted_units' => count($accepted),
                'sellable_units' => $sellable,
                'quarantine_units' => $quarantine,
            ];

            $batchId = (int) DB::table(
                'checkout_rma_stock_batches58'
            )->insertGetId([
                ...$batch,
                'batch_hmac' => $this->signer->digest($batch),
                'created_at' => now(),
            ]);

            foreach ($accepted as $unit) {
                $resellable =
                    $unit['condition'] === 'resellable';

                $receiptKey = $resellable
                    ? $this->receiptKey((int) $unit['id'])
                    : null;

                $entry = [
                    'batch_id' => $batchId,
                    'order_id' => (int) $order->id,
                    'return_unit_id' => (int) $unit['id'],
                    'variant_id' => (int) $unit['variant_id'],
                    'warehouse_id' => $warehouseId,
                    'condition' => $unit['condition'],
                    'disposition' => $resellable
                        ? 'sellable'
                        : 'quarantine',
                    'receipt_key' => $receiptKey,
                    'posted_by' => $actor,
                ];

                // The physical receipt and audit record are atomic.
                if ($resellable) {
                    $this->inventory->receiveOnce(
                        $warehouseId,
                        (int) $unit['variant_id'],
                        1,
                        'RMA58:'
                        . (string) $case->return_key
                        . ':UNIT:'
                        . (int) $unit['id'],
                        $receiptKey,
                        'RMA58 sellable unit'
                    );
                }

                DB::table(
                    'checkout_rma_stock_units58'
                )->insert([
                    ...$entry,
                    'entry_hmac' =>
                        $this->signer->digest($entry),
                    'created_at' => now(),
                ]);
            }

            return $this->read($batchId);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function show(int $batchId): array
    {
        $this->actor();

        return $this->read($batchId);
    }

    /** @return array<string,mixed> */
    private function read(int $batchId): array
    {
        $batch = DB::table(
            'checkout_rma_stock_batches58'
        )
            ->where('id', $batchId)
            ->first();

        if ($batch === null) {
            throw new DomainException(
                'RMA stock posting not found'
            );
        }

        $signed = [
            'journal_id' => (int) $batch->journal_id,
            'case_id' => (int) $batch->case_id,
            'order_id' => (int) $batch->order_id,
            'inspection_id' => (int) $batch->inspection_id,
            'decision_id' => (int) $batch->decision_id,
            'warehouse_id' => (int) $batch->warehouse_id,
            'posted_by' => (int) $batch->posted_by,
            'accepted_units' => (int) $batch->accepted_units,
            'sellable_units' => (int) $batch->sellable_units,
            'quarantine_units' => (int) $batch->quarantine_units,
        ];

        $this->assertSigned(
            (string) $batch->batch_hmac,
            $signed
        );

        $rows = DB::table('checkout_rma_stock_units58')
            ->where('batch_id', $batchId)
            ->orderBy('return_unit_id')
            ->get();

        if ($rows->count() !== (int) $batch->accepted_units) {
            throw new RuntimeException(
                'RMA stock unit count does not match batch'
            );
        }

        $items = [];
        $sellable = 0;
        $quarantine = 0;

        foreach ($rows as $row) {
            $data = [
                'batch_id' => (int) $row->batch_id,
                'order_id' => (int) $row->order_id,
                'return_unit_id' =>
                    (int) $row->return_unit_id,
                'variant_id' => (int) $row->variant_id,
                'warehouse_id' => (int) $row->warehouse_id,
                'condition' => (string) $row->condition,
                'disposition' => (string) $row->disposition,
                'receipt_key' =>
                    $row->receipt_key === null
                        ? null
                        : (string) $row->receipt_key,
                'posted_by' => (int) $row->posted_by,
            ];

            $this->assertSigned(
                (string) $row->entry_hmac,
                $data
            );

            if (
                $data['order_id'] !== $signed['order_id']
                || $data['warehouse_id']
                !== $signed['warehouse_id']
                || $data['posted_by']
                !== $signed['posted_by']
            ) {
                throw new RuntimeException(
                    'RMA stock unit inconsistent with batch'
                );
            }

            if ($data['disposition'] === 'sellable') {
                if (
                    $data['condition'] !== 'resellable'
                    || $data['receipt_key']
                    !== $this->receiptKey(
                        $data['return_unit_id']
                    )
                ) {
                    throw new RuntimeException(
                        'Incorrect RMA stock receipt identity'
                    );
                }

                $sellable++;

                $receipt = DB::table('inventory_receipts as r')
                    ->join(
                        'inventory_stocks as s',
                        's.id',
                        '=',
                        'r.inventory_stock_id'
                    )
                    ->where(
                        'r.receipt_key',
                        $data['receipt_key']
                    )
                    ->where(
                        's.warehouse_id',
                        $data['warehouse_id']
                    )
                    ->where(
                        's.variant_id',
                        $data['variant_id']
                    )
                    ->first(['r.quantity']);

                if (
                    $receipt === null
                    || (int) $receipt->quantity !== 1
                ) {
                    throw new RuntimeException(
                        'RMA physical stock receipt missing'
                    );
                }
            } elseif (
                $data['disposition'] === 'quarantine'
                && $data['condition'] === 'damaged'
                && $data['receipt_key'] === null
            ) {
                $quarantine++;
            } else {
                throw new RuntimeException(
                    'Invalid RMA stock unit disposition'
                );
            }

            $items[] = [
                'return_unit_id' => $data['return_unit_id'],
                'variant_id' => $data['variant_id'],
                'condition' => $data['condition'],
                'disposition' => $data['disposition'],
            ];
        }

        if (
            $sellable !== $signed['sellable_units']
            || $quarantine !== $signed['quarantine_units']
        ) {
            throw new RuntimeException(
                'RMA stock disposition totals mismatch'
            );
        }

        return [
            'batch_id' => $batchId,
            'journal_id' => $signed['journal_id'],
            'case_id' => $signed['case_id'],
            'status' => 'posted',
            'accepted_units' => $signed['accepted_units'],
            'sellable_units' => $sellable,
            'quarantine_units' => $quarantine,
            'inventory_restocked' => $sellable > 0,
            'rma_closed' => false,
            'items' => $items,
        ];
    }

    /**
     * @return array{
     *   case:object,
     *   inspection:object,
     *   decision:object,
     *   execution:object,
     *   refund_plan:object,
     *   accepted:array<int,array<string,mixed>>
     * }
     */
    private function verifiedSource(
        object $journal,
        object $order
    ): array {
        $execution = DB::table(
            'checkout_mono_refund_executions'
        )
            ->where('id', $journal->execution_id)
            ->first();

        $refundPlan = DB::table(
            'checkout_mono_rma_refund_plans'
        )
            ->where('id', $journal->refund_plan_id)
            ->first();

        if (
            $execution === null
            || $refundPlan === null
            || $execution->status !== 'succeeded'
            || (int) $execution->order_id
            !== (int) $order->id
            || (int) $execution->refund_plan_id
            !== (int) $refundPlan->id
            || (int) $journal->amount_minor
            !== (int) $execution->amount_minor
            || (string) $journal->ext_ref
            !== (string) $execution->ext_ref
        ) {
            throw new DomainException(
                'Mono refund confirmation inconsistent with journal'
            );
        }

        $entry = [
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
        ];

        $this->assertSigned(
            (string) $journal->entry_hmac,
            $entry
        );

        $executionIdentity = [
            'refund_plan_id' =>
                (int) $execution->refund_plan_id,
            'order_id' =>
                (int) $execution->order_id,
            'capture_receipt_id' =>
                (int) $execution->capture_receipt_id,
            'operation_key' =>
                (string) $execution->operation_key,
            'ext_ref' =>
                (string) $execution->ext_ref,
            'invoice_id' =>
                (string) $execution->invoice_id,
            'amount_minor' =>
                (int) $execution->amount_minor,
            'currency_code' =>
                (string) $execution->currency_code,
            'executed_by' =>
                (int) $execution->executed_by,
        ];

        $this->assertSigned(
            (string) $execution->request_hmac,
            $executionIdentity
        );

        $planIdentity = [
            'case_id' =>
                (int) $refundPlan->return_case_id,
            'order_id' =>
                (int) $refundPlan->order_id,
            'capture_receipt_id' =>
                (int) $refundPlan->capture_receipt_id,
            'operation_key' =>
                (string) $refundPlan->operation_key,
            'amount_minor' =>
                (int) $refundPlan->amount_minor,
            'currency_code' =>
                (string) $refundPlan->currency_code,
            'planned_by' =>
                (int) $refundPlan->planned_by,
        ];

        $this->assertSigned(
            (string) $refundPlan->plan_hmac,
            $planIdentity
        );

        if (
            (int) $refundPlan->order_id
            !== (int) $order->id
            || (int) $refundPlan->capture_receipt_id
            !== (int) $execution->capture_receipt_id
            || (int) $refundPlan->amount_minor
            !== (int) $execution->amount_minor
        ) {
            throw new RuntimeException(
                'Refund plan and execution do not reconcile'
            );
        }

        if (
            (int) $journal->capture_receipt_id
            !== (int) $execution->capture_receipt_id
        ) {
            throw new RuntimeException(
                'Original Mono capture differs'
            );
        }

        $latestObservationId = DB::table(
            'checkout_mono_refund_observations'
        )
            ->where('execution_id', $execution->id)
            ->orderByDesc('id')
            ->value('id');

        if (
            (int) $latestObservationId
            !== (int) $journal->observation_id
        ) {
            throw new RuntimeException(
                'Provider observation changed since journal'
            );
        }

        $observation = DB::table(
            'checkout_mono_refund_observations'
        )
            ->where('id', $journal->observation_id)
            ->first();

        if (
            $observation === null
            || (int) $observation->execution_id
            !== (int) $execution->id
            || $observation->state !== 'success'
            || $observation->source !== 'invoice_status'
            || (int) $observation->matched_amount_minor
            !== (int) $execution->amount_minor
        ) {
            throw new DomainException(
                'Exact signed Mono success observation required'
            );
        }

        $obs = [
            'execution_id' => (int) $execution->id,
            'observation_key' =>
                (string) $observation->observation_key,
            'source' => (string) $observation->source,
            'state' => (string) $observation->state,
            'matched_amount_minor' =>
                (int) $observation->matched_amount_minor,
            'evidence_sha256' =>
                (string) $observation->evidence_sha256,
        ];

        if ($observation->reviewed_by !== null) {
            $obs['reviewed_by'] =
                (int) $observation->reviewed_by;
        }

        $this->assertSigned(
            (string) $observation->evidence_hmac,
            $obs
        );

        $case = DB::table(
            'checkout_order_return_cases'
        )
            ->where('id', $refundPlan->return_case_id)
            ->first();

        $inspection = DB::table(
            'checkout_order_return_inspections'
        )
            ->where('case_id', $refundPlan->return_case_id)
            ->first();

        $decision = DB::table(
            'checkout_order_return_decisions'
        )
            ->where('case_id', $refundPlan->return_case_id)
            ->first();

        if (
            $case === null
            || $inspection === null
            || $decision === null
            || $decision->decision !== 'approved'
            || (int) $case->order_id !== (int) $order->id
            || (int) $decision->inspection_id
            !== (int) $inspection->id
        ) {
            throw new DomainException(
                'Approved warehouse-inspected RMA is required'
            );
        }

        $marketId = DB::table(
            'checkout_order_preparations'
        )
            ->where('order_id', $order->id)
            ->value('market_id');

        $country = DB::table('markets')
            ->where('id', $marketId)
            ->value('country_code');

        $warehouse = DB::table('warehouses')
            ->where('id', $inspection->warehouse_id)
            ->first();

        if (
            $country === null
            || $warehouse === null
            || $warehouse->country_code !== $country
            || $warehouse->kind !== 'owned'
            || !$warehouse->is_active
        ) {
            throw new DomainException(
                'Receiving warehouse must be active, '
                . 'owned and in original market'
            );
        }

        $json = (string) $inspection->items_json;

        if (!hash_equals(
            (string) $inspection->items_sha256,
            hash('sha256', $json)
        )) {
            throw new RuntimeException(
                'RMA inspection SHA mismatch'
            );
        }

        $items = json_decode(
            $json,
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($items)
            || !array_is_list($items)
        ) {
            throw new RuntimeException(
                'RMA inspection JSON invalid'
            );
        }

        $inspect = [
            'case_id' => (int) $case->id,
            'operation_key' =>
                (string) $inspection->operation_key,
            'receipt_reference_sha256' =>
                (string) $inspection->receipt_reference_sha256,
            'warehouse_id' =>
                (int) $inspection->warehouse_id,
            'items' => $items,
            'inspected_by' =>
                (int) $inspection->inspected_by,
        ];

        $this->assertSigned(
            (string) $inspection->inspection_hmac,
            $inspect
        );

        $units = DB::table(
            'checkout_order_return_units'
        )
            ->where('case_id', $case->id)
            ->orderBy('variant_id')
            ->orderBy('unit_index')
            ->get();

        $unitData = $units->map(
            static fn (object $u): array => [
                'id' => (int) $u->id,
                'variant_id' => (int) $u->variant_id,
                'unit_index' => (int) $u->unit_index,
                'gross_minor' => (int) $u->gross_minor,
                'promotion_minor' =>
                    (int) $u->promotion_minor,
                'loyalty_minor' =>
                    (int) $u->loyalty_minor,
                'cash_minor' =>
                    (int) $u->cash_minor,
            ]
        )->all();

        if (
            count($items) !== count($unitData)
            || $items === []
        ) {
            throw new DomainException(
                'Inspection does not classify every returned unit'
            );
        }

        $byId = [];

        foreach ($unitData as $unit) {
            $byId[$unit['id']] = $unit;
        }

        $conditions = [];
        $accepted = [];
        $seen = [];

        foreach ($items as $item) {
            $id = $item['return_unit_id'] ?? null;
            $condition = $item['condition'] ?? null;

            if (
                !is_int($id)
                || !isset($byId[$id])
                || isset($seen[$id])
                || !in_array(
                    $condition,
                    ['resellable', 'damaged', 'not_received'],
                    true
                )
            ) {
                throw new DomainException(
                    'Invalid or duplicate RMA inspection unit'
                );
            }

            $seen[$id] = true;
            $unit = $byId[$id];

            $conditions[] = [
                'variant_id' => $unit['variant_id'],
                'unit_index' => $unit['unit_index'],
                'condition' => $condition,
            ];

            if ($condition !== 'not_received') {
                $accepted[] = [
                    ...$unit,
                    'condition' => $condition,
                ];
            }
        }

        $assessed = $this->policy->inspect(
            $unitData,
            $conditions
        );

        $expected = [
            'case_id' => (int) $case->id,
            'inspection_id' => (int) $inspection->id,
            'operation_key' =>
                (string) $decision->operation_key,
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
                hash(
                    'sha256',
                    (string) $decision->decision_json
                )
            )
            || !hash_equals(
                (string) $decision->decision_json,
                $this->signer->json($expected)
            )
        ) {
            throw new RuntimeException(
                'RMA decision JSON mismatch'
            );
        }

        $this->assertSigned(
            (string) $decision->decision_hmac,
            $expected
        );

        if (
            (int) $decision->accepted_qty
            !== $assessed['totals']['units']
            || (int) $decision->cash_candidate_minor
            !== $assessed['totals']['cash_minor']
            || (int) $decision->loyalty_candidate_minor
            !== $assessed['totals']['loyalty_minor']
            || (int) $decision->promotion_allocated_minor
            !== $assessed['totals']['promotion_minor']
        ) {
            throw new RuntimeException(
                'RMA accepted units do not reconcile to decision'
            );
        }

        if (
            $accepted === []
            || (int) $decision->cash_candidate_minor
            !== (int) $journal->amount_minor
        ) {
            throw new DomainException(
                'RMA financial candidate does not match Mono refund'
            );
        }

        return [
            'case' => $case,
            'inspection' => $inspection,
            'decision' => $decision,
            'execution' => $execution,
            'refund_plan' => $refundPlan,
            'accepted' => $accepted,
        ];
    }

    /**
     * Deterministic UUID.
     * The same physical return unit always has the same receipt key.
     */
    private function receiptKey(int $returnUnitId): string
    {
        $hex = substr(
            hash(
                'sha256',
                'boardriders-rma58:unit:' . $returnUnitId
            ),
            0,
            32
        );

        $hex[12] = '5';
        $hex[16] = dechex(
            (hexdec($hex[16]) & 3) | 8
        );

        return substr($hex, 0, 8)
            . '-' . substr($hex, 8, 4)
            . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4)
            . '-' . substr($hex, 20, 12);
    }

    /** @param array<string,mixed> $payload */
    private function assertSigned(
        string $hmac,
        array $payload
    ): void {
        if (!hash_equals(
            $hmac,
            $this->signer->digest($payload)
        )) {
            throw new RuntimeException(
                'RMA or financial audit signature mismatch'
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
            || !Gate::forUser($user)->allows(
                self::POST_ABILITY
            )
        ) {
            throw new AuthorizationException(
                'RMA stock posting denied'
            );
        }

        return (int) $id;
    }
}
