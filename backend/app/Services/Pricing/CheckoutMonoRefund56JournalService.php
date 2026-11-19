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
 * BOARDRIDERS #56: immutable accounting handoff after verified Mono refund.
 * This service NEVER calls Mono or changes loyalty balances / stock quantities.
 */
final class CheckoutMonoRefund56JournalService
{
    public const JOURNAL_ABILITY = 'payments.mono.refunds.journal';

    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer,
    ) {
    }

    /** @return array<string,mixed> */
    public function record(int $executionId): array
    {
        $actor = $this->actor();

        if ($executionId <= 0) {
            throw new DomainException('Invalid Mono execution ID');
        }

        return DB::transaction(function () use ($executionId, $actor): array {
            $locator = DB::table('checkout_mono_refund_executions')
                ->where('id', $executionId)
                ->first(['order_id']);

            if ($locator === null) {
                throw new DomainException('Mono execution not found');
            }

            // Same lock ordering as #54: order first, then execution.
            $order = DB::table('orders')
                ->where('id', $locator->order_id)
                ->lockForUpdate()
                ->first(['id']);

            if ($order === null) {
                throw new DomainException('Original order not found');
            }

            $execution = DB::table('checkout_mono_refund_executions')
                ->where('id', $executionId)
                ->lockForUpdate()
                ->first();

            $this->verifyExecution($execution);
            $this->verifyPlanAndCapture($execution);

            $plannedBy = DB::table('checkout_mono_rma_refund_plans')
                ->where('id', $execution->refund_plan_id)
                ->value('planned_by');

            if ($actor === (int) $execution->executed_by || $actor === (int) $plannedBy) {
                throw new DomainException(
                    'Journal reviewer must differ from refund planner and executor'
                );
            }

            if ($execution->status !== 'succeeded') {
                throw new DomainException(
                    'Provider refund is not confirmed; journal entry forbidden'
                );
            }

            $latest = $this->verifyObservations($executionId);

            if (
                $latest === null
                || $latest->source !== 'invoice_status'
                || $latest->state !== 'success'
                || (int) $latest->matched_amount_minor !== (int) $execution->amount_minor
            ) {
                throw new RuntimeException(
                    'Refund cannot be journaled without latest signed success evidence'
                );
            }

            $existing = DB::table('checkout_mono_refund_journal56')
                ->where('execution_id', $executionId)
                ->first(['id']);

            if ($existing !== null) {
                return $this->read((int) $existing->id);
            }

            $entry = [
                'execution_id' => $executionId,
                'refund_plan_id' => (int) $execution->refund_plan_id,
                'order_id' => (int) $execution->order_id,
                'capture_receipt_id' => (int) $execution->capture_receipt_id,
                'observation_id' => (int) $latest->id,
                'ext_ref' => (string) $execution->ext_ref,
                'amount_minor' => (int) $execution->amount_minor,
                'currency_code' => (string) $execution->currency_code,
                'recorded_by' => $actor,
            ];

            $journalId = (int) DB::table('checkout_mono_refund_journal56')
                ->insertGetId([
                    ...$entry,
                    'entry_hmac' => $this->signer->digest($entry),
                    'created_at' => now(),
                ]);

            // Review handoffs are immutable notifications, not stock/bonus postings.
            foreach (['loyalty_review', 'inventory_review'] as $topic) {
                $handoff = [
                    'journal_id' => $journalId,
                    'topic' => $topic,
                    'event_key' => $this->eventKey($execution, $topic),
                ];

                DB::table('checkout_mono_refund_handoffs56')->insert([
                    ...$handoff,
                    'event_hmac' => $this->signer->digest($handoff),
                    'created_at' => now(),
                ]);
            }

            return $this->read($journalId);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function show(int $journalId): array
    {
        $this->actor();

        return $this->read($journalId);
    }

    /** @return array<string,mixed> */
    private function read(int $journalId): array
    {
        if ($journalId <= 0) {
            throw new DomainException('Invalid Mono refund journal ID');
        }

        $journal = DB::table('checkout_mono_refund_journal56')
            ->where('id', $journalId)
            ->first();

        if ($journal === null) {
            throw new DomainException('Mono refund journal not found');
        }

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

        $this->assertHmac(
            (string) $journal->entry_hmac,
            $entry,
            'Mono refund journal signature mismatch'
        );

        $execution = DB::table('checkout_mono_refund_executions')
            ->where('id', $journal->execution_id)
            ->first();

        $this->verifyExecution($execution);

        if (
            $execution->status !== 'succeeded'
            || (int) $execution->refund_plan_id !== $entry['refund_plan_id']
            || (int) $execution->order_id !== $entry['order_id']
            || (int) $execution->capture_receipt_id !== $entry['capture_receipt_id']
            || (string) $execution->ext_ref !== $entry['ext_ref']
            || (int) $execution->amount_minor !== $entry['amount_minor']
            || (string) $execution->currency_code !== $entry['currency_code']
        ) {
            throw new RuntimeException('Journal is inconsistent with provider execution');
        }

        $latest = $this->verifyObservations((int) $journal->execution_id);

        if (
            $latest === null
            || (int) $latest->id !== $entry['observation_id']
            || $latest->source !== 'invoice_status'
            || $latest->state !== 'success'
            || (int) $latest->matched_amount_minor !== $entry['amount_minor']
        ) {
            throw new RuntimeException('Journal provider evidence was altered');
        }

        $handoffs = DB::table('checkout_mono_refund_handoffs56')
            ->where('journal_id', $journalId)
            ->orderBy('topic')
            ->get();

        if (
            $handoffs->count() !== 2
            || $handoffs->pluck('topic')->all() !== ['inventory_review', 'loyalty_review']
        ) {
            throw new RuntimeException('Journal review handoffs are missing or duplicated');
        }

        foreach ($handoffs as $row) {
            $handoff = [
                'journal_id' => $journalId,
                'topic' => (string) $row->topic,
                'event_key' => (string) $row->event_key,
            ];

            if (!hash_equals($this->eventKey($execution, $handoff['topic']), $handoff['event_key'])) {
                throw new RuntimeException('Journal handoff key mismatch');
            }

            $this->assertHmac(
                (string) $row->event_hmac,
                $handoff,
                'Journal handoff signature mismatch'
            );
        }

        return [
            'journal_id' => $journalId,
            'execution_id' => $entry['execution_id'],
            'refund_plan_id' => $entry['refund_plan_id'],
            'order_id' => $entry['order_id'],
            'amount_minor' => $entry['amount_minor'],
            'currency_code' => $entry['currency_code'],
            'ext_ref' => $entry['ext_ref'],
            'status' => 'provider_confirmed',
            'provider_refund_confirmed' => true,
            'card_settlement_confirmed' => false,
            'loyalty_adjusted' => false,
            'inventory_restocked' => false,
            'rma_closed' => false,
            'pending_review_topics' => ['inventory_review', 'loyalty_review'],
        ];
    }

    private function eventKey(object $execution, string $topic): string
    {
        return hash(
            'sha256',
            'mono-refund-journal56:' . (string) $execution->operation_key . ':' . $topic
        );
    }

    private function verifyPlanAndCapture(object $execution): void
    {
        $plan = DB::table('checkout_mono_rma_refund_plans')
            ->where('id', $execution->refund_plan_id)
            ->first();

        if ($plan === null) {
            throw new RuntimeException('Mono refund journal plan missing');
        }

        $payload = [
            'case_id' => (int) $plan->return_case_id,
            'order_id' => (int) $plan->order_id,
            'capture_receipt_id' => (int) $plan->capture_receipt_id,
            'operation_key' => (string) $plan->operation_key,
            'amount_minor' => (int) $plan->amount_minor,
            'currency_code' => (string) $plan->currency_code,
            'planned_by' => (int) $plan->planned_by,
        ];

        $this->assertHmac(
            (string) $plan->plan_hmac,
            $payload,
            'Mono refund journal plan signature mismatch'
        );

        if (
            (int) $plan->order_id !== (int) $execution->order_id
            || (int) $plan->capture_receipt_id !== (int) $execution->capture_receipt_id
            || (string) $plan->operation_key !== (string) $execution->operation_key
            || (int) $plan->amount_minor !== (int) $execution->amount_minor
            || (string) $plan->currency_code !== (string) $execution->currency_code
        ) {
            throw new RuntimeException('Mono refund journal plan does not match execution');
        }

        $receipt = DB::table('checkout_payment_capture_receipts')
            ->where('id', $execution->capture_receipt_id)
            ->first();

        if (
            $receipt === null
            || $receipt->provider_code !== 'mono'
            || (int) $receipt->order_id !== (int) $execution->order_id
            || (int) $receipt->amount_minor < (int) $execution->amount_minor
        ) {
            throw new RuntimeException('Mono refund journal original capture invalid');
        }
    }

    private function verifyExecution(?object $execution): void
    {
        if ($execution === null) {
            throw new DomainException('Mono refund execution not found');
        }

        $identity = [
            'refund_plan_id' => (int) $execution->refund_plan_id,
            'order_id' => (int) $execution->order_id,
            'capture_receipt_id' => (int) $execution->capture_receipt_id,
            'operation_key' => (string) $execution->operation_key,
            'ext_ref' => (string) $execution->ext_ref,
            'invoice_id' => (string) $execution->invoice_id,
            'amount_minor' => (int) $execution->amount_minor,
            'currency_code' => (string) $execution->currency_code,
            'executed_by' => (int) $execution->executed_by,
        ];

        $this->assertHmac(
            (string) $execution->request_hmac,
            $identity,
            'Mono refund execution signature mismatch'
        );
    }

    private function verifyObservations(int $executionId): ?object
    {
        $rows = DB::table('checkout_mono_refund_observations')
            ->where('execution_id', $executionId)
            ->orderBy('id')
            ->get();

        $last = null;

        foreach ($rows as $row) {
            $audit = [
                'execution_id' => $executionId,
                'observation_key' => (string) $row->observation_key,
                'source' => (string) $row->source,
                'state' => (string) $row->state,
                'matched_amount_minor' => $row->matched_amount_minor === null
                    ? null : (int) $row->matched_amount_minor,
                'evidence_sha256' => (string) $row->evidence_sha256,
            ];

            if ($row->reviewed_by !== null) {
                $audit['reviewed_by'] = (int) $row->reviewed_by;
            }

            $this->assertHmac(
                (string) $row->evidence_hmac,
                $audit,
                'Mono refund observation signature mismatch'
            );

            $last = $row;
        }

        return $last;
    }

    /** @param array<string,mixed> $signed */
    private function assertHmac(string $stored, array $signed, string $message): void
    {
        if (!hash_equals($stored, $this->signer->digest($signed))) {
            throw new RuntimeException($message);
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
            || !Gate::forUser($user)->allows(self::JOURNAL_ABILITY)
        ) {
            throw new AuthorizationException('Mono refund journal access denied');
        }

        return (int) $id;
    }
}
