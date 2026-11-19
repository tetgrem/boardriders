<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * BOARDRIDERS #54 + #55.
 *
 * A refund POST is issued at most once for each persisted execution.
 * Unknown provider outcomes never trigger automatic resubmission.
 * A provider-confirmed refund is not proof of final card settlement.
 */
final class CheckoutMonoRefund54Service
{
    public const SEND_ABILITY = 'payments.mono.refunds.send';
    public const RECONCILE_ABILITY = 'payments.mono.refunds.reconcile';
    public const RECOVER_ABILITY = 'payments.mono.refunds.recover';

    public function __construct(
        private readonly CheckoutMonoGateway53 $mono,
        private readonly CheckoutMonoRefund54Gateway $refundGateway,
        private readonly CheckoutMonoRefund54Evidence $evidence,
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly CheckoutPaymentCaptureReceiptService $receipts,
        private readonly CheckoutPaymentAttemptService $attempts,
    ) {
    }

    /** @return array<string,mixed> */
    public function send(int $planId): array
    {
        $actor = $this->actor(self::SEND_ABILITY);

        if ($planId <= 0) {
            throw new DomainException('Invalid refund plan');
        }

        $this->sendingEnabled();

        $claimed = DB::transaction(function () use ($planId, $actor): array {
            $plan = DB::table('checkout_mono_rma_refund_plans')
                ->where('id', $planId)
                ->first();

            if ($plan === null) {
                throw new DomainException('Approved refund plan not found');
            }

            $order = DB::table('orders')
                ->where('id', $plan->order_id)
                ->lockForUpdate()
                ->first(['id', 'order_key', 'payment_status']);

            if ($order === null || $order->payment_status !== 'paid') {
                throw new DomainException('Refund requires paid order');
            }

            $this->verifyPlan($plan);

            if ($actor === (int) $plan->planned_by) {
                throw new DomainException(
                    'Refund executor must be independent of planner'
                );
            }

            $existing = DB::table('checkout_mono_refund_executions')
                ->where('refund_plan_id', $planId)
                ->first();

            if ($existing !== null) {
                $this->verifyExecution($existing);

                return [
                    'new' => false,
                    'id' => (int) $existing->id,
                ];
            }

            // An unresolved execution reserves the full amount indefinitely.
            if (DB::table('checkout_mono_refund_executions')
                ->where('capture_receipt_id', $plan->capture_receipt_id)
                ->where('status', '!=', 'succeeded')
                ->exists()) {
                throw new DomainException('Previous Mono refund is unresolved');
            }

            $receipt = $this->verifiedReceipt($plan);

            // Application-side budget check. #55 also enforces this in PostgreSQL.
            $reservedMinor = (int) DB::table('checkout_mono_refund_executions')
                ->where('capture_receipt_id', $plan->capture_receipt_id)
                ->sum('amount_minor');

            $availableMinor = (int) $receipt['amount_minor'] - $reservedMinor;

            if ($availableMinor < 0 || (int) $plan->amount_minor > $availableMinor) {
                throw new DomainException(
                    'Mono refund exceeds remaining captured amount'
                );
            }

            $launch = DB::table('checkout_mono_invoice_launches')
                ->where('order_id', $order->id)
                ->first();

            if (
                $launch === null
                || $launch->status !== 'ready'
                || $launch->provider_invoice_id !== $receipt['provider_payment_id']
                || (int) $launch->amount_minor !== (int) $receipt['amount_minor']
            ) {
                throw new DomainException(
                    'Original Mono invoice does not match capture'
                );
            }

            $extRef = str_replace('-', '', (string) $plan->operation_key);

            $identity = [
                'refund_plan_id' => (int) $plan->id,
                'order_id' => (int) $plan->order_id,
                'capture_receipt_id' => (int) $plan->capture_receipt_id,
                'operation_key' => (string) $plan->operation_key,
                'ext_ref' => $extRef,
                'invoice_id' => (string) $launch->provider_invoice_id,
                'amount_minor' => (int) $plan->amount_minor,
                'currency_code' => 'UAH',
                'executed_by' => $actor,
            ];

            $id = (int) DB::table('checkout_mono_refund_executions')
                ->insertGetId([
                    ...$identity,
                    'status' => 'sending',
                    'request_hmac' => $this->signer->digest($identity),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'new' => true,
                'id' => $id,
            ];
        }, 3);

        $id = $claimed['id'];

        if (!$claimed['new']) {
            // An existing operation MUST NOT issue a second provider POST.
            return $this->read($id);
        }

        $execution = $this->raw($id);
        $orderKey = $this->orderKey($execution);

        $capture = DB::table('checkout_payment_capture_receipts')
            ->where('id', $execution->capture_receipt_id)
            ->first(['amount_minor']);

        if ($capture === null) {
            return $this->observe(
                $id,
                'submission',
                'blocked',
                null,
                hash('sha256', 'missing-original-capture'),
                'needs_review'
            );
        }

        // Check the provider before dispatching the ONE permitted POST.
        try {
            $status = $this->mono->status((string) $execution->invoice_id);

            $checked = $this->evidence->inspect(
                $status,
                (string) $execution->invoice_id,
                $orderKey,
                (int) $capture->amount_minor,
                (string) $execution->ext_ref,
                (int) $execution->amount_minor,
                $this->knownRefunds($execution),
            );
        } catch (DomainException $e) {
            return $this->observe(
                $id,
                'submission',
                'blocked',
                null,
                hash('sha256', 'preflight-mismatch'),
                'needs_review'
            );
        } catch (Throwable $e) {
            report($e);

            return $this->observe(
                $id,
                'submission',
                'uncertain',
                null,
                hash('sha256', 'preflight-unavailable'),
                'awaiting_evidence'
            );
        }

        if ($checked['state'] !== 'absent') {
            // Provider already knows this ext_ref: NO additional POST.
            return $this->observe(
                $id,
                'invoice_status',
                $checked['state'],
                $checked['matched_amount_minor'],
                $checked['evidence_sha256'],
                $this->targetStatus($checked['state'])
            );
        }

        if (($status['status'] ?? null) !== 'success') {
            return $this->observe(
                $id,
                'submission',
                'blocked',
                null,
                hash('sha256', 'original-invoice-not-chargeable'),
                'needs_review'
            );
        }

        try {
            $reply = $this->refundGateway->cancelOnce(
                (string) $execution->invoice_id,
                (string) $execution->ext_ref,
                (int) $execution->amount_minor,
                (int) $capture->amount_minor,
            );

            return $this->observe(
                $id,
                'submission',
                'submitted',
                null,
                hash('sha256', 'mono-cancel-reply:' . $reply),
                $reply === 'failure' ? 'needs_review' : 'awaiting_evidence'
            );
        } catch (Throwable $e) {
            // Includes timeouts and non-2xx responses. Never retry automatically.
            report($e);

            return $this->observe(
                $id,
                'submission',
                'uncertain',
                null,
                hash('sha256', 'mono-cancel-outcome-unknown'),
                'awaiting_evidence'
            );
        }
    }

    /** @return array<string,mixed> */
    public function reconcile(int $executionId): array
    {
        $this->actor(self::RECONCILE_ABILITY);

        $execution = $this->raw($executionId);
        $this->verifyExecution($execution);

        // Do not race an in-flight monetary POST.
        // A stuck 'sending' is handled by separately authorized recoverStale().
        if (in_array($execution->status, ['succeeded', 'sending'], true)) {
            return $this->read($executionId);
        }

        $capture = DB::table('checkout_payment_capture_receipts')
            ->where('id', $execution->capture_receipt_id)
            ->first(['amount_minor']);

        if ($capture === null) {
            throw new DomainException('Original capture missing');
        }

        try {
            $status = $this->mono->status((string) $execution->invoice_id);

            $checked = $this->evidence->inspect(
                $status,
                (string) $execution->invoice_id,
                $this->orderKey($execution),
                (int) $capture->amount_minor,
                (string) $execution->ext_ref,
                (int) $execution->amount_minor,
                $this->knownRefunds($execution),
            );
        } catch (DomainException $e) {
            return $this->observe(
                $executionId,
                'invoice_status',
                'unexpected',
                null,
                hash('sha256', 'untrusted-refund-evidence'),
                'needs_review'
            );
        } catch (Throwable $e) {
            report($e);

            // A failed GET is not evidence of provider refund state.
            return $this->read($executionId);
        }

        return $this->observe(
            $executionId,
            'invoice_status',
            $checked['state'],
            $checked['matched_amount_minor'],
            $checked['evidence_sha256'],
            $this->targetStatus($checked['state'])
        );
    }

    /** @return array<int,array<string,int|string>> */
    public function staleExecutions(): array
    {
        $this->actor(self::RECOVER_ABILITY);

        return DB::table('checkout_mono_refund_executions')
            ->where('status', 'sending')
            ->where('created_at', '<=', CarbonImmutable::now('UTC')->subMinutes(15))
            ->orderBy('id')
            ->limit(100)
            ->get([
                'id',
                'refund_plan_id',
                'amount_minor',
                'currency_code',
                'created_at',
            ])
            ->map(static fn (object $row): array => [
                'execution_id' => (int) $row->id,
                'refund_plan_id' => (int) $row->refund_plan_id,
                'amount_minor' => (int) $row->amount_minor,
                'currency_code' => (string) $row->currency_code,
                'created_at' => (string) $row->created_at,
            ])
            ->all();
    }

    /**
     * Investigate a stuck sending record by GET only. Never re-send POST /cancel.
     *
     * @return array<string,mixed>
     */
    public function recoverStale(int $executionId): array
    {
        $actor = $this->actor(self::RECOVER_ABILITY);

        $execution = $this->raw($executionId);
        $this->verifyExecution($execution);

        if ($execution->status !== 'sending') {
            throw new DomainException(
                'Only stuck sending refunds can be recovered'
            );
        }

        if (CarbonImmutable::parse((string) $execution->created_at, 'UTC')
            ->greaterThan(CarbonImmutable::now('UTC')->subMinutes(15))) {
            throw new DomainException(
                'Refund is not old enough for manual recovery'
            );
        }

        $capture = DB::table('checkout_payment_capture_receipts')
            ->where('id', $execution->capture_receipt_id)
            ->first(['amount_minor']);

        if ($capture === null) {
            return $this->observe(
                $executionId,
                'invoice_status',
                'unexpected',
                null,
                hash('sha256', 'mono55-missing-capture'),
                'needs_review',
                $actor
            );
        }

        try {
            $status = $this->mono->status((string) $execution->invoice_id);

            $checked = $this->evidence->inspect(
                $status,
                (string) $execution->invoice_id,
                $this->orderKey($execution),
                (int) $capture->amount_minor,
                (string) $execution->ext_ref,
                (int) $execution->amount_minor,
                $this->knownRefunds($execution),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->observe(
                $executionId,
                'invoice_status',
                'unexpected',
                null,
                hash('sha256', 'mono55-unverifiable-provider-state'),
                'needs_review',
                $actor
            );
        }

        // Absence in GET does NOT prove that the original POST was never sent.
        $target = $checked['state'] === 'absent'
            ? 'needs_review'
            : $this->targetStatus($checked['state']);

        return $this->observe(
            $executionId,
            'invoice_status',
            $checked['state'],
            $checked['matched_amount_minor'],
            $checked['evidence_sha256'],
            $target,
            $actor
        );
    }

    /** @return array<string,mixed> */
    public function show(int $executionId): array
    {
        $this->actor(self::RECONCILE_ABILITY);

        return $this->read($executionId);
    }

    private function targetStatus(string $state): string
    {
        return match ($state) {
            'success' => 'succeeded',
            'failure', 'unexpected' => 'needs_review',
            default => 'awaiting_evidence',
        };
    }

    /**
     * Atomic provider observation and legal state transition.
     * For #55 manual observations only, reviewed_by is covered by HMAC.
     * For #54 observations, HMAC payload remains unchanged.
     *
     * @return array<string,mixed>
     */
    private function observe(
        int $executionId,
        string $source,
        string $state,
        ?int $matched,
        string $evidenceSha,
        string $targetStatus,
        ?int $reviewedBy = null,
    ): array {
        return DB::transaction(function () use (
            $executionId,
            $source,
            $state,
            $matched,
            $evidenceSha,
            $targetStatus,
            $reviewedBy
        ): array {
            $unlocked = DB::table('checkout_mono_refund_executions')
                ->where('id', $executionId)
                ->first(['order_id']);

            if ($unlocked === null) {
                throw new DomainException('Refund execution not found');
            }

            DB::table('orders')
                ->where('id', $unlocked->order_id)
                ->lockForUpdate()
                ->first(['id']);

            $execution = DB::table('checkout_mono_refund_executions')
                ->where('id', $executionId)
                ->lockForUpdate()
                ->first();

            $this->verifyExecution($execution);

            if ($execution->status === 'succeeded') {
                return $this->read($executionId);
            }

            $last = DB::table('checkout_mono_refund_observations')
                ->where('execution_id', $executionId)
                ->orderByDesc('id')
                ->first();

            if (
                $last !== null
                && $last->source === $source
                && $last->state === $state
                && $last->evidence_sha256 === $evidenceSha
                && (
                $last->matched_amount_minor === null
                    ? null
                    : (int) $last->matched_amount_minor
                ) === $matched
                && (
                $last->reviewed_by === null
                    ? null
                    : (int) $last->reviewed_by
                ) === $reviewedBy
            ) {
                return $this->read($executionId);
            }

            $audit = [
                'execution_id' => $executionId,
                'observation_key' => (string) Str::uuid(),
                'source' => $source,
                'state' => $state,
                'matched_amount_minor' => $matched,
                'evidence_sha256' => $evidenceSha,
            ];

            // Include reviewed_by in signature only for manually reviewed rows.
            if ($reviewedBy !== null) {
                $audit['reviewed_by'] = $reviewedBy;
            }

            // CRITICAL: ALL audit fields MUST be part of INSERT (not just
            // reviewed_by, evidence_hmac and created_at).
            DB::table('checkout_mono_refund_observations')->insert([
                ...$audit,
                'evidence_hmac' => $this->signer->digest($audit),
                'created_at' => now(),
            ]);

            // Once flagged for investigation, non-success evidence cannot
            // silently clear the manual review requirement.
            if (
                $execution->status === 'needs_review'
                && $targetStatus !== 'succeeded'
            ) {
                $targetStatus = 'needs_review';
            }

            if ($execution->status !== $targetStatus) {
                DB::table('checkout_mono_refund_executions')
                    ->where('id', $executionId)
                    ->update([
                        'status' => $targetStatus,
                        'updated_at' => now(),
                    ]);
            }

            return $this->read($executionId);
        }, 3);
    }

    /** @return array<string,mixed> */
    private function read(int $executionId): array
    {
        $execution = $this->raw($executionId);
        $this->verifyExecution($execution);

        $events = DB::table('checkout_mono_refund_observations')
            ->where('execution_id', $executionId)
            ->orderBy('id')
            ->get();

        $lastState = null;

        foreach ($events as $event) {
            $audit = [
                'execution_id' => $executionId,
                'observation_key' => (string) $event->observation_key,
                'source' => (string) $event->source,
                'state' => (string) $event->state,
                'matched_amount_minor' =>
                    $event->matched_amount_minor === null
                        ? null
                        : (int) $event->matched_amount_minor,
                'evidence_sha256' => (string) $event->evidence_sha256,
            ];

            // The same conditional HMAC shape as observe().
            if ($event->reviewed_by !== null) {
                $audit['reviewed_by'] = (int) $event->reviewed_by;
            }

            if (!hash_equals(
                (string) $event->evidence_hmac,
                $this->signer->digest($audit)
            )) {
                throw new RuntimeException(
                    'Mono refund audit observation tampered'
                );
            }

            $lastState = $audit['state'];
        }

        if (
            $execution->status === 'succeeded'
            && $lastState !== 'success'
        ) {
            throw new RuntimeException(
                'Mono refund marked succeeded without latest success evidence'
            );
        }

        return [
            'execution_id' => $executionId,
            'refund_plan_id' => (int) $execution->refund_plan_id,
            'order_id' => (int) $execution->order_id,
            'invoice_id' => (string) $execution->invoice_id,
            'ext_ref' => (string) $execution->ext_ref,
            'amount_minor' => (int) $execution->amount_minor,
            'currency_code' => 'UAH',
            'status' => (string) $execution->status,
            'last_evidence_state' => $lastState,
            'provider_refund_confirmed' => $execution->status === 'succeeded',
            'card_settlement_confirmed' => false,
            'loyalty_refunded' => false,
            'inventory_restocked' => false,
            'observation_count' => $events->count(),
        ];
    }

    private function raw(int $id): object
    {
        if ($id <= 0) {
            throw new DomainException('Invalid refund execution ID');
        }

        $row = DB::table('checkout_mono_refund_executions')
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw new DomainException('Refund execution not found');
        }

        return $row;
    }

    private function orderKey(object $execution): string
    {
        $key = DB::table('orders')
            ->where('id', $execution->order_id)
            ->value('order_key');

        if (!is_string($key)) {
            throw new DomainException('Refund order key missing');
        }

        return $key;
    }

    /** @return array<string,int> */
    private function knownRefunds(object $execution): array
    {
        return DB::table('checkout_mono_refund_executions')
            ->where('capture_receipt_id', $execution->capture_receipt_id)
            ->pluck('amount_minor', 'ext_ref')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    private function verifyPlan(object $plan): void
    {
        $payload = [
            'case_id' => (int) $plan->return_case_id,
            'order_id' => (int) $plan->order_id,
            'capture_receipt_id' => (int) $plan->capture_receipt_id,
            'operation_key' => (string) $plan->operation_key,
            'amount_minor' => (int) $plan->amount_minor,
            'currency_code' => (string) $plan->currency_code,
            'planned_by' => (int) $plan->planned_by,
        ];

        if (!hash_equals(
            (string) $plan->plan_hmac,
            $this->signer->digest($payload)
        )) {
            throw new RuntimeException('Signed refund plan does not match record');
        }
    }

    /** @return array<string,mixed> */
    private function verifiedReceipt(object $plan): array
    {
        $row = DB::table('checkout_payment_capture_receipts')
            ->where('id', $plan->capture_receipt_id)
            ->first();

        if (
            $row === null
            || $row->provider_code !== 'mono'
            || (int) $row->order_id !== (int) $plan->order_id
            || (int) $plan->amount_minor > (int) $row->amount_minor
        ) {
            throw new DomainException('Mono refund capture is invalid');
        }

        $decision = DB::table('checkout_payment_capture_decisions')
            ->where('receipt_id', $row->id)
            ->where('decision', 'paid')
            ->first();

        $receipt = $this->receipts->findByProviderCapture(
            'mono',
            (string) $row->provider_capture_id
        );

        $attempt = $this->attempts->findForOrder((int) $plan->order_id);

        if (
            $decision === null
            || $receipt === null
            || $attempt === null
            || $receipt['id'] !== (int) $row->id
            || $receipt['provider_payment_id'] !== $attempt['provider_payment_id']
            || $attempt['provider_code'] !== 'mono'
            || $attempt['provider_payment_id'] !== $row->provider_capture_id
            || $receipt['amount_minor'] !== (int) $row->amount_minor
            || $receipt['currency_code'] !== 'UAH'
        ) {
            throw new DomainException(
                'Mono refund has no verified original payment'
            );
        }

        return $receipt;
    }

    private function verifyExecution(object $execution): void
    {
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

        if (!hash_equals(
            (string) $execution->request_hmac,
            $this->signer->digest($identity)
        )) {
            throw new RuntimeException(
                'Mono refund execution identity tampered'
            );
        }
    }

    private function sendingEnabled(): void
    {
        if (
            config('services.mono_acquiring.enabled') !== true
            || config('services.mono_acquiring.refunds.enabled') !== true
            || config(
                'services.mono_acquiring.refunds.non_fiscalized_merchant_confirmed'
            ) !== true
        ) {
            throw new DomainException(
                'Mono refund execution disabled until '
                . 'non-fiscalized merchant is verified'
            );
        }
    }

    private function actor(string $ability): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            $user === null
            || !is_numeric($id)
            || (int) $id <= 0
            || !Gate::forUser($user)->allows($ability)
        ) {
            throw new AuthorizationException('Mono refund access denied');
        }

        return (int) $id;
    }
}
