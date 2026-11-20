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
 * BOARDRIDERS #60: immutable, read-only financial reconciliation per MONO capture.
 *
 * No HTTP calls to Mono, no refund POSTs, and no changes to ledger/stock/bonuses.
 * Provider-confirmed cancel is NOT evidence that the buyer's card was credited.
 * All amounts are integer UAH minor units.
 */
final class CheckoutMonoRefundReconciliation60Service
{
    public const RUN_ABILITY = 'payments.mono.refunds.reconciliation.run';
    public const VIEW_ABILITY = 'payments.mono.refunds.reconciliation.view';

    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly CheckoutPaymentCaptureReceiptService $receipts,
        private readonly CheckoutPaymentAttemptService $attempts,
    ) {
    }

    /** @return array<string,mixed> */
    public function reconcile(int $captureReceiptId): array
    {
        $actor = $this->actor(self::RUN_ABILITY);

        if (config('services.mono_acquiring.refund_reconciliation.enabled') !== true) {
            throw new DomainException('Mono refund reconciliation is disabled');
        }

        if ($captureReceiptId <= 0) {
            throw new DomainException('Invalid capture receipt ID');
        }

        return DB::transaction(function () use ($actor, $captureReceiptId): array {
            $locator = DB::table('checkout_payment_capture_receipts')
                ->where('id', $captureReceiptId)->first(['order_id']);

            if ($locator === null) {
                throw new DomainException('Mono capture receipt not found');
            }

            // The same order-first lock order as the payment/refund services.
            $order = DB::table('orders')
                ->where('id', $locator->order_id)
                ->lockForUpdate()
                ->first(['id', 'order_key', 'payment_status']);

            if ($order === null || $order->payment_status !== 'paid') {
                throw new DomainException('Only paid orders can be reconciled');
            }

            $capture = $this->verifiedCapture($captureReceiptId, $order);
            $plans = DB::table('checkout_mono_rma_refund_plans')
                ->where('capture_receipt_id', $captureReceiptId)
                ->orderBy('id')->get();
            $executions = DB::table('checkout_mono_refund_executions')
                ->where('capture_receipt_id', $captureReceiptId)
                ->orderBy('id')->get();
            $intents = DB::table('checkout_payment_refund_intents')
                ->where('receipt_id', $captureReceiptId)
                ->orderBy('id')->get(['id', 'amount_minor']);

            $byPlan = [];
            $byExtRef = [];
            foreach ($executions as $execution) {
                $pid = (int) $execution->refund_plan_id;
                $ref = (string) $execution->ext_ref;
                if (isset($byPlan[$pid]) || isset($byExtRef[$ref])) {
                    throw new RuntimeException('Duplicated Mono refund execution identity');
                }
                $this->verifyExecution($execution);
                $byPlan[$pid] = $execution;
                $byExtRef[$ref] = true;
            }

            $planned = 0;
            $executed = 0;
            $confirmed = 0;
            $journaled = 0;
            $unresolved = 0;
            $unjournaled = 0;
            $manualReview = false;
            $lines = [];
            $evidence = [
                'capture' => [
                    'receipt_id' => $captureReceiptId,
                    'order_id' => (int) $order->id,
                    'capture_sha256' => $capture['sha256'],
                    'amount_minor' => $capture['amount_minor'],
                    'currency_code' => 'UAH',
                ],
                'plans' => [],
                'intents' => [],
            ];

            foreach ($intents as $intent) {
                $minor = (int) $intent->amount_minor;
                if ($minor <= 0) {
                    throw new RuntimeException('Invalid foreign refund intent amount');
                }
                $evidence['intents'][] = [
                    'id' => (int) $intent->id,
                    'amount_minor' => $minor,
                ];
            }

            $otherIntentsMinor = $this->addAmounts(
                array_map(static fn (array $row): int => $row['amount_minor'], $evidence['intents'])
            );

            foreach ($plans as $plan) {
                $this->verifyPlan($plan);
                if ((int) $plan->order_id !== (int) $order->id) {
                    throw new RuntimeException('Refund plan for another order');
                }
                $amount = (int) $plan->amount_minor;
                if ($amount <= 0) {
                    throw new RuntimeException('Invalid refund plan amount');
                }
                $planned = $this->addAmounts([$planned, $amount]);

                $execution = $byPlan[(int) $plan->id] ?? null;
                $status = 'not_started';
                $observationId = null;
                $journalId = null;
                $observationFingerprint = null;
                $journalFingerprint = null;
                $executionFingerprint = null;

                if ($execution !== null) {
                    unset($byPlan[(int) $plan->id]);
                    if (
                        (int) $execution->capture_receipt_id !== $captureReceiptId
                        || (int) $execution->order_id !== (int) $order->id
                        || (int) $execution->amount_minor !== $amount
                        || (string) $execution->operation_key !== (string) $plan->operation_key
                        || (string) $execution->currency_code !== 'UAH'
                    ) {
                        throw new RuntimeException('Execution does not match its signed plan');
                    }
                    $executed = $this->addAmounts([$executed, $amount]);
                    $executionFingerprint = (string) $execution->request_hmac;
                    $status = (string) $execution->status;

                    $observation = DB::table('checkout_mono_refund_observations')
                        ->where('execution_id', $execution->id)
                        ->orderByDesc('id')->first();

                    if ($observation !== null) {
                        $this->verifyObservation($observation, (int) $execution->id);
                        $observationId = (int) $observation->id;
                        $observationFingerprint = (string) $observation->evidence_hmac;
                    }

                    if ($status === 'succeeded') {
                        if (
                            $observation === null
                            || (string) $observation->source !== 'invoice_status'
                            || (string) $observation->state !== 'success'
                            || (int) $observation->matched_amount_minor !== $amount
                        ) {
                            $manualReview = true;
                            $status = 'needs_review';
                        } else {
                            $confirmed = $this->addAmounts([$confirmed, $amount]);
                            $journal = DB::table('checkout_mono_refund_journal56')
                                ->where('execution_id', $execution->id)->first();
                            if ($journal !== null) {
                                $this->verifyJournal($journal, $execution, $observation);
                                $journalId = (int) $journal->id;
                                $journalFingerprint = (string) $journal->entry_hmac;
                                $journaled = $this->addAmounts([$journaled, $amount]);
                                $status = 'journaled';
                            } else {
                                $unjournaled++;
                                $status = 'confirmed_unjournaled';
                            }
                        }
                    } elseif ($status === 'needs_review') {
                        $manualReview = true;
                    } elseif (!in_array($status, ['sending', 'awaiting_evidence'], true)) {
                        $manualReview = true;
                        $status = 'needs_review';
                    }

                    if (in_array($status, ['sending', 'awaiting_evidence', 'needs_review'], true)) {
                        $unresolved++;
                    }
                }

                $line = [
                    'refund_plan_id' => (int) $plan->id,
                    'execution_id' => $execution === null ? null : (int) $execution->id,
                    'journal_id' => $journalId,
                    'observation_id' => $observationId,
                    'amount_minor' => $amount,
                    'line_status' => $status,
                ];
                $lines[] = $line;
                $evidence['plans'][] = [
                    ...$line,
                    'operation_key' => (string) $plan->operation_key,
                    'plan_hmac' => (string) $plan->plan_hmac,
                    'execution_hmac' => $executionFingerprint,
                    'observation_hmac' => $observationFingerprint,
                    'journal_hmac' => $journalFingerprint,
                ];
            }

            if ($byPlan !== []) {
                throw new RuntimeException('Orphan Mono execution without a matching capture plan');
            }

            $captureMinor = (int) $capture['amount_minor'];
            $committed = $this->addAmounts([$planned, $otherIntentsMinor]);
            $available = max(0, $captureMinor - $committed);

            if (
                $committed > $captureMinor
                || $executed > $planned
                || $confirmed > $executed
                || $journaled > $confirmed
            ) {
                $manualReview = true;
            }

            $hasNotStarted = count(array_filter(
                    $lines,
                    static fn (array $line): bool => $line['line_status'] === 'not_started'
                )) > 0;
            $resultCode = match (true) {
                $manualReview => 'needs_review',
                $unresolved > 0 => 'awaiting_provider_evidence',
                $unjournaled > 0 => 'awaiting_journal',
                $hasNotStarted || $otherIntentsMinor > 0 => 'awaiting_execution',
                count($lines) === 0 => 'no_refunds',
                default => 'provider_reconciled',
            };

            // Neither Mono success nor this reconciliation certifies arrival on the card.
            $evidence['totals'] = [
                'captured_minor' => $captureMinor,
                'planned_minor' => $planned,
                'other_intents_minor' => $otherIntentsMinor,
                'executed_minor' => $executed,
                'confirmed_minor' => $confirmed,
                'journaled_minor' => $journaled,
                'available_to_plan_minor' => $available,
                'plan_count' => count($lines),
                'unresolved_count' => $unresolved,
                'unjournaled_count' => $unjournaled,
                'result_code' => $resultCode,
            ];
            $evidenceHash = hash('sha256', $this->signer->json($evidence));

            $existing = DB::table('checkout_mono_refund_reconciliations60')
                ->where('capture_receipt_id', $captureReceiptId)
                ->where('evidence_sha256', $evidenceHash)
                ->first(['id']);

            if ($existing !== null) {
                return $this->read((int) $existing->id);
            }

            $audit = [
                'order_id' => (int) $order->id,
                'capture_receipt_id' => $captureReceiptId,
                'evidence_sha256' => $evidenceHash,
                'result_code' => $resultCode,
                ...$evidence['totals'],
                'reviewed_by' => $actor,
            ];

            $reconciliationId = (int) DB::table('checkout_mono_refund_reconciliations60')
                ->insertGetId([
                    ...$audit,
                    'reconciliation_hmac' => $this->signer->digest($audit),
                    'created_at' => now(),
                ]);

            foreach ($lines as $line) {
                $signedLine = ['reconciliation_id' => $reconciliationId, ...$line];
                DB::table('checkout_mono_refund_reconciliation_lines60')->insert([
                    ...$signedLine,
                    'line_hmac' => $this->signer->digest($signedLine),
                    'created_at' => now(),
                ]);
            }

            return $this->read($reconciliationId);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function show(int $reconciliationId): array
    {
        $this->actor(self::VIEW_ABILITY);
        return $this->read($reconciliationId);
    }

    /** @return array<string,mixed> */
    private function read(int $reconciliationId): array
    {
        $row = DB::table('checkout_mono_refund_reconciliations60')
            ->where('id', $reconciliationId)->first();

        if ($row === null) {
            throw new DomainException('Mono reconciliation not found');
        }

        $audit = [
            'order_id' => (int) $row->order_id,
            'capture_receipt_id' => (int) $row->capture_receipt_id,
            'evidence_sha256' => (string) $row->evidence_sha256,
            'result_code' => (string) $row->result_code,
            'captured_minor' => (int) $row->captured_minor,
            'planned_minor' => (int) $row->planned_minor,
            'other_intents_minor' => (int) $row->other_intents_minor,
            'executed_minor' => (int) $row->executed_minor,
            'confirmed_minor' => (int) $row->confirmed_minor,
            'journaled_minor' => (int) $row->journaled_minor,
            'available_to_plan_minor' => (int) $row->available_to_plan_minor,
            'plan_count' => (int) $row->plan_count,
            'unresolved_count' => (int) $row->unresolved_count,
            'unjournaled_count' => (int) $row->unjournaled_count,
            'reviewed_by' => (int) $row->reviewed_by,
        ];

        $this->checkSignature((string) $row->reconciliation_hmac, $audit);

        $lines = DB::table('checkout_mono_refund_reconciliation_lines60')
            ->where('reconciliation_id', $reconciliationId)
            ->orderBy('refund_plan_id')->get();
        $resultLines = [];
        $planned = 0;
        $executed = 0;
        $confirmed = 0;
        $journaled = 0;
        $unresolved = 0;
        $unjournaled = 0;

        foreach ($lines as $line) {
            $data = [
                'reconciliation_id' => (int) $line->reconciliation_id,
                'refund_plan_id' => (int) $line->refund_plan_id,
                'execution_id' => $line->execution_id === null ? null : (int) $line->execution_id,
                'journal_id' => $line->journal_id === null ? null : (int) $line->journal_id,
                'observation_id' => $line->observation_id === null ? null : (int) $line->observation_id,
                'amount_minor' => (int) $line->amount_minor,
                'line_status' => (string) $line->line_status,
            ];
            $this->checkSignature((string) $line->line_hmac, $data);
            $planned = $this->addAmounts([$planned, $data['amount_minor']]);
            if ($data['execution_id'] !== null) {
                $executed = $this->addAmounts([$executed, $data['amount_minor']]);
            }
            if (in_array($data['line_status'], ['journaled', 'confirmed_unjournaled'], true)) {
                $confirmed = $this->addAmounts([$confirmed, $data['amount_minor']]);
            }
            if ($data['line_status'] === 'journaled') {
                $journaled = $this->addAmounts([$journaled, $data['amount_minor']]);
            }
            if (in_array($data['line_status'], ['sending', 'awaiting_evidence', 'needs_review'], true)) {
                $unresolved++;
            }
            if ($data['line_status'] === 'confirmed_unjournaled') {
                $unjournaled++;
            }
            unset($data['reconciliation_id']);
            $resultLines[] = $data;
        }

        if (
            $lines->count() !== $audit['plan_count']
            || $planned !== $audit['planned_minor']
            || $executed !== $audit['executed_minor']
            || $confirmed !== $audit['confirmed_minor']
            || $journaled !== $audit['journaled_minor']
            || $unresolved !== $audit['unresolved_count']
            || $unjournaled !== $audit['unjournaled_count']
        ) {
            throw new RuntimeException('Mono reconciliation audit totals inconsistent');
        }

        return [
            'reconciliation_id' => $reconciliationId,
            ...$audit,
            'lines' => $resultLines,
            'provider_refunds_reconciled' => $audit['result_code'] === 'provider_reconciled',
            'card_settlement_confirmed' => false,
            'does_not_send_money' => true,
        ];
    }

    private function verifiedCapture(int $receiptId, object $order): array
    {
        $receipt = DB::table('checkout_payment_capture_receipts')
            ->where('id', $receiptId)->first();
        if (
            $receipt === null
            || (int) $receipt->order_id !== (int) $order->id
            || $receipt->provider_code !== 'mono'
            || $receipt->currency_code !== 'UAH'
            || (int) $receipt->amount_minor <= 0
        ) {
            throw new DomainException('Original verified Mono capture required');
        }

        $decision = DB::table('checkout_payment_capture_decisions')
            ->where('receipt_id', $receiptId)
            ->where('order_id', $order->id)
            ->where('decision', 'paid')
            ->first(['id']);
        $original = $this->receipts->findByProviderCapture(
            'mono', (string) $receipt->provider_capture_id
        );
        $attempt = $this->attempts->findForOrder((int) $order->id);
        $launch = DB::table('checkout_mono_invoice_launches')
            ->where('order_id', $order->id)->first();

        if (
            $decision === null || $original === null || $attempt === null || $launch === null
            || $original['id'] !== $receiptId
            || $attempt['provider_code'] !== 'mono'
            || $attempt['provider_payment_id'] !== $receipt->provider_capture_id
            || $original['provider_payment_id'] !== $attempt['provider_payment_id']
            || $launch->status !== 'ready'
            || $launch->provider_invoice_id !== $original['provider_payment_id']
            || (int) $launch->amount_minor !== (int) $receipt->amount_minor
        ) {
            throw new DomainException('Mono capture is not reconciled with checkout');
        }

        return $original;
    }

    private function verifyPlan(object $p): void
    {
        $this->checkSignature((string) $p->plan_hmac, [
            'case_id' => (int) $p->return_case_id,
            'order_id' => (int) $p->order_id,
            'capture_receipt_id' => (int) $p->capture_receipt_id,
            'operation_key' => (string) $p->operation_key,
            'amount_minor' => (int) $p->amount_minor,
            'currency_code' => (string) $p->currency_code,
            'planned_by' => (int) $p->planned_by,
        ]);
        if ($p->currency_code !== 'UAH') {
            throw new RuntimeException('Foreign currency Mono refund plan');
        }
    }

    private function verifyExecution(object $e): void
    {
        $this->checkSignature((string) $e->request_hmac, [
            'refund_plan_id' => (int) $e->refund_plan_id,
            'order_id' => (int) $e->order_id,
            'capture_receipt_id' => (int) $e->capture_receipt_id,
            'operation_key' => (string) $e->operation_key,
            'ext_ref' => (string) $e->ext_ref,
            'invoice_id' => (string) $e->invoice_id,
            'amount_minor' => (int) $e->amount_minor,
            'currency_code' => (string) $e->currency_code,
            'executed_by' => (int) $e->executed_by,
        ]);
    }

    private function verifyObservation(object $o, int $executionId): void
    {
        if ((int) $o->execution_id !== $executionId) {
            throw new RuntimeException('Mono observation belongs to another execution');
        }
        $data = [
            'execution_id' => $executionId,
            'observation_key' => (string) $o->observation_key,
            'source' => (string) $o->source,
            'state' => (string) $o->state,
            'matched_amount_minor' => $o->matched_amount_minor === null
                ? null : (int) $o->matched_amount_minor,
            'evidence_sha256' => (string) $o->evidence_sha256,
        ];
        if ($o->reviewed_by !== null) {
            $data['reviewed_by'] = (int) $o->reviewed_by;
        }
        $this->checkSignature((string) $o->evidence_hmac, $data);
    }

    private function verifyJournal(object $j, object $e, object $observation): void
    {
        if (
            (int) $j->execution_id !== (int) $e->id
            || (int) $j->refund_plan_id !== (int) $e->refund_plan_id
            || (int) $j->order_id !== (int) $e->order_id
            || (int) $j->capture_receipt_id !== (int) $e->capture_receipt_id
            || (int) $j->observation_id !== (int) $observation->id
            || (int) $j->amount_minor !== (int) $e->amount_minor
            || (string) $j->ext_ref !== (string) $e->ext_ref
            || (string) $j->currency_code !== (string) $e->currency_code
        ) {
            throw new RuntimeException('Mono refund journal mismatches provider evidence');
        }
        $this->checkSignature((string) $j->entry_hmac, [
            'execution_id' => (int) $j->execution_id,
            'refund_plan_id' => (int) $j->refund_plan_id,
            'order_id' => (int) $j->order_id,
            'capture_receipt_id' => (int) $j->capture_receipt_id,
            'observation_id' => (int) $j->observation_id,
            'ext_ref' => (string) $j->ext_ref,
            'amount_minor' => (int) $j->amount_minor,
            'currency_code' => (string) $j->currency_code,
            'recorded_by' => (int) $j->recorded_by,
        ]);
    }

    /** @param list<int> $amounts */
    private function addAmounts(array $amounts): int
    {
        $sum = 0;
        foreach ($amounts as $amount) {
            if ($amount < 0 || $amount > PHP_INT_MAX - $sum) {
                throw new RuntimeException('Invalid or overflowing refund amount');
            }
            $sum += $amount;
        }
        return $sum;
    }

    /** @param array<string,mixed> $data */
    private function checkSignature(string $stored, array $data): void
    {
        if (!hash_equals($stored, $this->signer->digest($data))) {
            throw new RuntimeException('Mono60 source audit signature mismatch');
        }
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();
        if (
            $user === null || !is_numeric($id) || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException('Mono60 reconciliation access denied');
        }
        return (int) $id;
    }
}
