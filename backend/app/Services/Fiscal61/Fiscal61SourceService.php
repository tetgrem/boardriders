<?php

declare(strict_types=1);

namespace App\Services\Fiscal61;

use App\Services\Pricing\CheckoutPaymentCaptureReceiptService;
use App\Services\Pricing\CheckoutOrderReturnSigner;
use App\Services\Pricing\OrderFinancialSnapshotService;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Fiscal61SourceService
{
    public function __construct(
        private readonly CheckoutPaymentCaptureReceiptService $receipts,
        private readonly OrderFinancialSnapshotService $snapshots,
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly Fiscal61PayloadBuilder $builder,
    ) {
    }

    public function sale(int $captureId): array
    {
        $receipt = DB::table('checkout_payment_capture_receipts')
            ->where('id', $captureId)->first();

        if (
            $receipt === null
            || $receipt->provider_code !== 'mono'
            || $receipt->currency_code !== 'UAH'
        ) {
            throw new DomainException('Verified Mono capture required');
        }

        $original = $this->receipts->findByProviderCapture(
            'mono',
            (string) $receipt->provider_capture_id
        );

        $order = DB::table('orders')
            ->where('id', $receipt->order_id)
            ->first(['id', 'order_key', 'payment_status']);

        $decision = DB::table('checkout_payment_capture_decisions')
            ->where('receipt_id', $captureId)
            ->where('order_id', $receipt->order_id)
            ->where('decision', 'paid')
            ->first();

        $snapshot = $this->snapshots->findForOrder(
            (int) $receipt->order_id
        );

        if (
            $order === null
            || $order->payment_status !== 'paid'
            || $original === null
            || $original['id'] !== $captureId
            || $original['order_id'] !== (int) $order->id
            || $decision === null
            || $snapshot === null
            || $snapshot['currency_code'] !== 'UAH'
            || $snapshot['goods_payable_minor']
            !== (int) $receipt->amount_minor
        ) {
            throw new DomainException(
                'Capture has no accepted paid financial snapshot'
            );
        }

        $payload = $this->builder->sale(
            $snapshot['snapshot'],
            (string) $order->order_key
        );

        return [
            'order_id' => (int) $order->id,
            'capture_receipt_id' => $captureId,
            'source_id' => $captureId,
            'kind' => 'sale',
            'amount_minor' => (int) $receipt->amount_minor,
            'payload' => $payload,
            'source_sha256' => hash(
                'sha256',
                $original['sha256'] . ':'
                . $snapshot['sha256'] . ':'
                . $decision->id
            ),
        ];
    }

    public function refund(
        int $journalId,
        array $saleSource,
        array $salePayload
    ): array {
        $journal = DB::table('checkout_mono_refund_journal56')
            ->where('id', $journalId)
            ->first();

        if (
            $journal === null
            || (int) $journal->capture_receipt_id
            !== $saleSource['capture_receipt_id']
            || (int) $journal->order_id
            !== $saleSource['order_id']
        ) {
            throw new DomainException(
                'Refund journal does not match original sale'
            );
        }

        $journalData = [
            'execution_id' => (int) $journal->execution_id,
            'refund_plan_id' => (int) $journal->refund_plan_id,
            'order_id' => (int) $journal->order_id,
            'capture_receipt_id' => (int) $journal->capture_receipt_id,
            'observation_id' => (int) $journal->observation_id,
            'ext_ref' => (string) $journal->ext_ref,
            'amount_minor' => (int) $journal->amount_minor,
            'currency_code' => (string) $journal->currency_code,
            'recorded_by' => (int) $journal->recorded_by,
        ];

        $this->signed(
            (string) $journal->entry_hmac,
            $journalData
        );

        $execution = DB::table('checkout_mono_refund_executions')
            ->where('id', $journal->execution_id)
            ->first();

        $plan = DB::table('checkout_mono_rma_refund_plans')
            ->where('id', $journal->refund_plan_id)
            ->first();

        $obs = DB::table('checkout_mono_refund_observations')
            ->where('id', $journal->observation_id)
            ->first();

        if (
            $execution === null
            || $plan === null
            || $obs === null
            || $execution->status !== 'succeeded'
            || (int) $execution->refund_plan_id !== (int) $plan->id
            || (int) $plan->return_case_id <= 0
            || $journal->currency_code !== 'UAH'
            || (int) $execution->amount_minor
            !== (int) $journal->amount_minor
            || (int) $obs->execution_id !== (int) $execution->id
            || $obs->state !== 'success'
            || $obs->source !== 'invoice_status'
            || (int) $obs->matched_amount_minor
            !== (int) $journal->amount_minor
        ) {
            throw new DomainException(
                'Verified and journaled Mono refund required'
            );
        }

        $this->signed((string) $plan->plan_hmac, [
            'case_id' => (int) $plan->return_case_id,
            'order_id' => (int) $plan->order_id,
            'capture_receipt_id' => (int) $plan->capture_receipt_id,
            'operation_key' => (string) $plan->operation_key,
            'amount_minor' => (int) $plan->amount_minor,
            'currency_code' => (string) $plan->currency_code,
            'planned_by' => (int) $plan->planned_by,
        ]);

        $this->signed((string) $execution->request_hmac, [
            'refund_plan_id' => (int) $execution->refund_plan_id,
            'order_id' => (int) $execution->order_id,
            'capture_receipt_id' => (int) $execution->capture_receipt_id,
            'operation_key' => (string) $execution->operation_key,
            'ext_ref' => (string) $execution->ext_ref,
            'invoice_id' => (string) $execution->invoice_id,
            'amount_minor' => (int) $execution->amount_minor,
            'currency_code' => (string) $execution->currency_code,
            'executed_by' => (int) $execution->executed_by,
        ]);

        $observationData = [
            'execution_id' => (int) $obs->execution_id,
            'observation_key' => (string) $obs->observation_key,
            'source' => (string) $obs->source,
            'state' => (string) $obs->state,
            'matched_amount_minor' => (int) $obs->matched_amount_minor,
            'evidence_sha256' => (string) $obs->evidence_sha256,
        ];

        if ($obs->reviewed_by !== null) {
            $observationData['reviewed_by'] =
                (int) $obs->reviewed_by;
        }

        $this->signed(
            (string) $obs->evidence_hmac,
            $observationData
        );

        $latestObservationId = DB::table(
            'checkout_mono_refund_observations'
        )
            ->where('execution_id', $execution->id)
            ->max('id');

        if (
            (int) $latestObservationId !== (int) $obs->id
            || (int) $plan->capture_receipt_id
            !== $saleSource['capture_receipt_id']
            || (int) $plan->order_id !== $saleSource['order_id']
            || (int) $plan->amount_minor
            !== (int) $journal->amount_minor
            || (int) $execution->capture_receipt_id
            !== $saleSource['capture_receipt_id']
            || (string) $execution->ext_ref
            !== (string) $journal->ext_ref
        ) {
            throw new RuntimeException(
                'Refund evidence differs from signed original source'
            );
        }

        $case = DB::table('checkout_order_return_cases')
            ->where('id', $plan->return_case_id)
            ->first();

        $inspection = DB::table(
            'checkout_order_return_inspections'
        )
            ->where('case_id', $plan->return_case_id)
            ->first();

        $decision = DB::table(
            'checkout_order_return_decisions'
        )
            ->where('case_id', $plan->return_case_id)
            ->first();

        if (
            $case === null
            || $inspection === null
            || $decision === null
            || (int) $case->order_id !== $saleSource['order_id']
            || $decision->decision !== 'approved'
            || (int) $decision->inspection_id
            !== (int) $inspection->id
            || (int) $decision->cash_candidate_minor
            !== (int) $journal->amount_minor
        ) {
            throw new DomainException(
                'Approved RMA does not agree with refund journal'
            );
        }

        $json = (string) $inspection->items_json;

        if (!hash_equals(
            (string) $inspection->items_sha256,
            hash('sha256', $json)
        )) {
            throw new RuntimeException(
                'Return inspection checksum mismatch'
            );
        }

        $items = json_decode(
            $json,
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        $this->signed(
            (string) $inspection->inspection_hmac,
            [
                'case_id' => (int) $case->id,
                'operation_key' =>
                    (string) $inspection->operation_key,
                'receipt_reference_sha256' =>
                    (string) $inspection->receipt_reference_sha256,
                'warehouse_id' => (int) $inspection->warehouse_id,
                'items' => $items,
                'inspected_by' => (int) $inspection->inspected_by,
            ]
        );

        $decisionData = [
            'case_id' => (int) $case->id,
            'inspection_id' => (int) $inspection->id,
            'operation_key' => (string) $decision->operation_key,
            'decision' => 'approved',
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
            || $this->signer->json(
                json_decode(
                    (string) $decision->decision_json,
                    true,
                    32,
                    JSON_THROW_ON_ERROR
                )
            ) !== $this->signer->json($decisionData)
        ) {
            throw new RuntimeException(
                'RMA decision payload invalid'
            );
        }

        $this->signed(
            (string) $decision->decision_hmac,
            $decisionData
        );

        $returnUnits = DB::table(
            'checkout_order_return_units'
        )
            ->where('case_id', $case->id)
            ->orderBy('variant_id')
            ->orderBy('unit_index')
            ->get()
            ->all();

        $payload = $this->builder->refund(
            $salePayload,
            $returnUnits,
            $items,
            (int) $journal->amount_minor
        );

        return [
            'kind' => 'refund',
            'source_id' => $journalId,
            'order_id' => $saleSource['order_id'],
            'capture_receipt_id' =>
                $saleSource['capture_receipt_id'],
            'amount_minor' => (int) $journal->amount_minor,
            'payload' => $payload,
            'source_sha256' => hash(
                'sha256',
                $journal->entry_hmac . ':'
                . $inspection->inspection_hmac . ':'
                . $decision->decision_hmac
            ),
        ];
    }

    private function signed(string $signature, array $data): void
    {
        if (!hash_equals(
            $signature,
            $this->signer->digest($data)
        )) {
            throw new RuntimeException(
                'Fiscal61 financial source HMAC mismatch'
            );
        }
    }
}
