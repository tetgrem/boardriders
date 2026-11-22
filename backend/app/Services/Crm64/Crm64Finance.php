<?php

declare(strict_types=1);

namespace App\Services\Crm64;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use App\Services\Pricing\CheckoutPaymentCaptureReceiptService;
use App\Services\Pricing\OrderFinancialSnapshotService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A verified financial projection. No account/card details, no money-changing actions. */
final class Crm64Finance
{
    public function __construct(
        private readonly CheckoutPaymentCaptureReceiptService $receipts,
        private readonly OrderFinancialSnapshotService $snapshots,
        private readonly CheckoutOrderReturnSigner $signer,
    ) {}

    public function forOrder(int $orderId): array
    {
        $snapshot = $this->snapshots->findForOrder($orderId);
        $captured = [];
        $totalCaptured = 0;
        foreach (DB::table('checkout_payment_capture_receipts')->where('order_id', $orderId)->orderBy('id')->get() as $r) {
            $verified = $this->receipts->findByProviderCapture(
                (string) $r->provider_code, (string) $r->provider_capture_id
            );
            if ($verified === null || $verified['id'] !== (int) $r->id
                || $verified['order_id'] !== $orderId) {
                throw new RuntimeException('CRM capture identity mismatch');
            }
            $accepted = DB::table('checkout_payment_capture_decisions')
                ->where('receipt_id', $r->id)->where('order_id', $orderId)
                ->where('decision', 'paid')->exists();
            if ($accepted) $totalCaptured = $this->sum($totalCaptured, $verified['amount_minor']);
            $captured[] = [
                'receipt_id' => $verified['id'],
                'provider_code' => $verified['provider_code'],
                'amount_minor' => $verified['amount_minor'],
                'currency_code' => $verified['currency_code'],
                'accepted_as_paid' => $accepted,
                'captured_at' => $verified['captured_at'],
            ];
        }

        $executions = [];
        $confirmed = 0;
        $journaled = 0;
        $reserved = 0;
        foreach (DB::table('checkout_mono_refund_executions')->where('order_id', $orderId)->orderBy('id')->get() as $e) {
            $identity = [
                'refund_plan_id' => (int) $e->refund_plan_id,
                'order_id' => (int) $e->order_id,
                'capture_receipt_id' => (int) $e->capture_receipt_id,
                'operation_key' => (string) $e->operation_key,
                'ext_ref' => (string) $e->ext_ref,
                'invoice_id' => (string) $e->invoice_id,
                'amount_minor' => (int) $e->amount_minor,
                'currency_code' => (string) $e->currency_code,
                'executed_by' => (int) $e->executed_by,
            ];
            $this->signed((string) $e->request_hmac, $identity);
            $plan = DB::table('checkout_mono_rma_refund_plans')->where('id', $e->refund_plan_id)->first();
            if ($plan === null || (int) $plan->order_id !== $orderId
                || (int) $plan->capture_receipt_id !== (int) $e->capture_receipt_id
                || (int) $plan->amount_minor !== (int) $e->amount_minor) {
                throw new RuntimeException('CRM refund plan/execution mismatch');
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
            $reserved = $this->sum($reserved, (int) $e->amount_minor);
            $observation = DB::table('checkout_mono_refund_observations')
                ->where('execution_id', $e->id)->orderByDesc('id')->first();
            if ($observation !== null) {
                $audit = [
                    'execution_id' => (int) $observation->execution_id,
                    'observation_key' => (string) $observation->observation_key,
                    'source' => (string) $observation->source,
                    'state' => (string) $observation->state,
                    'matched_amount_minor' => $observation->matched_amount_minor === null
                        ? null : (int) $observation->matched_amount_minor,
                    'evidence_sha256' => (string) $observation->evidence_sha256,
                ];
                if ($observation->reviewed_by !== null) $audit['reviewed_by'] = (int) $observation->reviewed_by;
                $this->signed((string) $observation->evidence_hmac, $audit);
            }
            $success = $e->status === 'succeeded';
            if ($success && ($observation === null || $observation->source !== 'invoice_status'
                || $observation->state !== 'success'
                || (int) $observation->matched_amount_minor !== (int) $e->amount_minor)) {
                throw new RuntimeException('CRM provider refund has no matching success evidence');
            }
            if ($success) $confirmed = $this->sum($confirmed, (int) $e->amount_minor);
            $journal = DB::table('checkout_mono_refund_journal56')->where('execution_id', $e->id)->first();
            if ($journal !== null) {
                $entry = [
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
                $this->signed((string) $journal->entry_hmac, $entry);
                if (!$success || (int) $journal->observation_id !== (int) $observation->id
                    || (int) $journal->amount_minor !== (int) $e->amount_minor
                    || (int) $journal->order_id !== $orderId) {
                    throw new RuntimeException('CRM journal/provider evidence mismatch');
                }
                $journaled = $this->sum($journaled, (int) $journal->amount_minor);
            }
            $executions[] = [
                'execution_id' => (int) $e->id,
                'capture_receipt_id' => (int) $e->capture_receipt_id,
                'amount_minor' => (int) $e->amount_minor,
                'status' => (string) $e->status,
                'provider_refund_confirmed' => $success,
                'journal_id' => $journal === null ? null : (int) $journal->id,
                'card_settlement_confirmed' => false,
            ];
        }

        return [
            'financial_snapshot' => $snapshot === null ? null : [
                'snapshot_id' => $snapshot['id'],
                'goods_payable_minor' => $snapshot['goods_payable_minor'],
                'currency_code' => $snapshot['currency_code'],
                'sha256' => $snapshot['sha256'],
                'lines' => array_map(static fn (array $line): array => [
                    'variant_id' => (int) $line['variant_id'],
                    'quantity' => (int) $line['quantity'],
                    'goods_payable_minor' => (int) $line['goods_payable_minor'],
                ], $snapshot['snapshot']['lines'] ?? []),
            ],
            'captures' => $captured,
            'accepted_capture_total_minor' => $totalCaptured,
            'refund_reserved_minor' => $reserved,
            'provider_confirmed_refund_minor' => $confirmed,
            'journaled_refund_minor' => $journaled,
            'refunds' => $executions,
            'card_settlement_confirmed' => false,
        ];
    }

    private function signed(string $hmac, array $identity): void
    {
        if (!hash_equals($hmac, $this->signer->digest($identity))) {
            throw new RuntimeException('CRM financial source signature mismatch');
        }
    }

    private function sum(int $a, int $b): int
    {
        if ($b < 0 || $a > PHP_INT_MAX - $b) throw new RuntimeException('CRM amount overflow');
        return $a + $b;
    }
}
