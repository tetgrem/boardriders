<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One-shot refund executor. An indeterminate request is NEVER sent again.
 * 'succeeded' means confirmed by Mono's invoice/status cancelList,
 * not card settlement.
 */
final class CheckoutMonoRefund54Service
{
    public const SEND_ABILITY = 'payments.mono.refunds.send';
    public const RECONCILE_ABILITY = 'payments.mono.refunds.reconcile';

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

        $claimed = DB::transaction(function () use (
            $planId,
            $actor
        ): array {
            $plan = DB::table('checkout_mono_rma_refund_plans')
                ->where('id', $planId)
                ->first();

            if ($plan === null) {
                throw new DomainException(
                    'Approved refund plan not found'
                );
            }

            $order = DB::table('orders')
                ->where('id', $plan->order_id)
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
                    'Refund requires paid order'
                );
            }

            $this->verifyPlan($plan);

            if ($actor === (int) $plan->planned_by) {
                throw new DomainException(
                    'Refund executor must be independent of planner'
                );
            }

            $existing = DB::table(
                'checkout_mono_refund_executions'
            )
                ->where('refund_plan_id', $planId)
                ->first();

            if ($existing !== null) {
                $this->verifyExecution($existing);

                return [
                    'new' => false,
                    'id' => (int) $existing->id,
                ];
            }

            // Do not dispatch another refund for this capture
            // while an earlier cancellation is unresolved.
            if (
                DB::table('checkout_mono_refund_executions')
                    ->where(
                        'capture_receipt_id',
                        $plan->capture_receipt_id
                    )
                    ->where('status', '!=', 'succeeded')
                    ->exists()
            ) {
                throw new DomainException(
                    'Previous Mono refund is unresolved'
                );
            }

            $receipt = $this->verifiedReceipt($plan);

            $launch = DB::table(
                'checkout_mono_invoice_launches'
            )
                ->where('order_id', $order->id)
                ->first();

            if (
                $launch === null
                || $launch->status !== 'ready'
                || $launch->provider_invoice_id
                !== $receipt['provider_payment_id']
                || (int) $launch->amount_minor
                !== $receipt['amount_minor']
            ) {
                throw new DomainException(
                    'Original Mono invoice does not match capture'
                );
            }

            $extRef = str_replace(
                '-',
                '',
                (string) $plan->operation_key
            );

            $identity = [
                'refund_plan_id' => (int) $plan->id,
                'order_id' => (int) $plan->order_id,
                'capture_receipt_id' =>
                    (int) $plan->capture_receipt_id,
                'operation_key' =>
                    (string) $plan->operation_key,
                'ext_ref' => $extRef,
                'invoice_id' =>
                    (string) $launch->provider_invoice_id,
                'amount_minor' => (int) $plan->amount_minor,
                'currency_code' => 'UAH',
                'executed_by' => $actor,
            ];

            $id = (int) DB::table(
                'checkout_mono_refund_executions'
            )->insertGetId([
                ...$identity,
                'status' => 'sending',
                'request_hmac' =>
                    $this->signer->digest($identity),
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
            // Existing operation: NEVER send provider POST again.
            return $this->read($id);
        }

        $execution = $this->raw($id);
        $orderKey = $this->orderKey($execution);

        $capture = DB::table(
            'checkout_payment_capture_receipts'
        )
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

        // Preflight GET. Do not send money if provider evidence
        // is inconsistent, missing or unavailable.
        try {
            $status = $this->mono->status(
                (string) $execution->invoice_id
            );

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

        // Already visible at Mono: do not send another POST.
        if ($checked['state'] !== 'absent') {
            return $this->observe(
                $id,
                'invoice_status',
                $checked['state'],
                $checked['matched_amount_minor'],
                $checked['evidence_sha256'],
                $this->targetStatus($checked['state'])
            );
        }

        // Never send a new cancellation for a reversed invoice.
        if (($status['status'] ?? null) !== 'success') {
            return $this->observe(
                $id,
                'submission',
                'blocked',
                null,
                hash(
                    'sha256',
                    'original-invoice-not-chargeable'
                ),
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
                $reply === 'failure'
                    ? 'needs_review'
                    : 'awaiting_evidence'
            );
        } catch (Throwable $e) {
            // Includes timeout, 4xx/5xx and malformed JSON.
            // Never retry automatically.
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

        if (in_array($execution->status, [
            'succeeded',
            'sending',
        ], true)) {
            // Do not race an in-flight monetary POST.
            // A crashed 'sending' case requires audited manual recovery.
            return $this->read($executionId);
        }

        $capture = DB::table(
            'checkout_payment_capture_receipts'
        )
            ->where('id', $execution->capture_receipt_id)
            ->first(['amount_minor']);

        if ($capture === null) {
            throw new DomainException(
                'Original capture missing'
            );
        }

        try {
            $status = $this->mono->status(
                (string) $execution->invoice_id
            );

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

            // Do not invent provider status when GET fails.
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

    /** @return array<string,mixed> */
    private function observe(
        int $executionId,
        string $source,
        string $state,
        ?int $matched,
        string $evidenceSha,
        string $targetStatus,
    ): array {
        return DB::transaction(function () use (
            $executionId,
            $source,
            $state,
            $matched,
            $evidenceSha,
            $targetStatus
        ): array {
            $unlocked = DB::table(
                'checkout_mono_refund_executions'
            )
                ->where('id', $executionId)
                ->first(['order_id']);

            if ($unlocked === null) {
                throw new DomainException(
                    'Refund execution not found'
                );
            }

            DB::table('orders')
                ->where('id', $unlocked->order_id)
                ->lockForUpdate()
                ->first(['id']);

            $execution = DB::table(
                'checkout_mono_refund_executions'
            )
                ->where('id', $executionId)
                ->lockForUpdate()
                ->first();

            $this->verifyExecution($execution);

            if ($execution->status === 'succeeded') {
                return $this->read($executionId);
            }

            $last = DB::table(
                'checkout_mono_refund_observations'
            )
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
            ) {
                return $this->read($executionId);
            }

            $observationKey = (string) Str::uuid();

            $audit = [
                'execution_id' => $executionId,
                'observation_key' => $observationKey,
                'source' => $source,
                'state' => $state,
                'matched_amount_minor' => $matched,
                'evidence_sha256' => $evidenceSha,
            ];

            DB::table(
                'checkout_mono_refund_observations'
            )->insert([
                ...$audit,
                'evidence_hmac' =>
                    $this->signer->digest($audit),
                'created_at' => now(),
            ]);

            // Do not downgrade manual review to pending.
            if (
                $execution->status === 'needs_review'
                && $targetStatus !== 'succeeded'
            ) {
                $targetStatus = 'needs_review';
            }

            if ($execution->status !== $targetStatus) {
                DB::table(
                    'checkout_mono_refund_executions'
                )
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

        $events = DB::table(
            'checkout_mono_refund_observations'
        )
            ->where('execution_id', $executionId)
            ->orderBy('id')
            ->get();

        $lastState = null;

        foreach ($events as $event) {
            $audit = [
                'execution_id' => $executionId,
                'observation_key' =>
                    (string) $event->observation_key,
                'source' => (string) $event->source,
                'state' => (string) $event->state,
                'matched_amount_minor' =>
                    $event->matched_amount_minor === null
                        ? null
                        : (int) $event->matched_amount_minor,
                'evidence_sha256' =>
                    (string) $event->evidence_sha256,
            ];

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
            'refund_plan_id' =>
                (int) $execution->refund_plan_id,
            'order_id' => (int) $execution->order_id,
            'invoice_id' => (string) $execution->invoice_id,
            'ext_ref' => (string) $execution->ext_ref,
            'amount_minor' => (int) $execution->amount_minor,
            'currency_code' => 'UAH',
            'status' => (string) $execution->status,
            'last_evidence_state' => $lastState,
            'provider_refund_confirmed' =>
                $execution->status === 'succeeded',
            'card_settlement_confirmed' => false,
            'loyalty_refunded' => false,
            'inventory_restocked' => false,
            'observation_count' => $events->count(),
        ];
    }

    private function raw(int $id): object
    {
        if ($id <= 0) {
            throw new DomainException(
                'Invalid refund execution ID'
            );
        }

        $row = DB::table(
            'checkout_mono_refund_executions'
        )
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw new DomainException(
                'Refund execution not found'
            );
        }

        return $row;
    }

    private function orderKey(object $execution): string
    {
        $key = DB::table('orders')
            ->where('id', $execution->order_id)
            ->value('order_key');

        if (!is_string($key)) {
            throw new DomainException(
                'Refund order key missing'
            );
        }

        return $key;
    }

    /** @return array<string,int> */
    private function knownRefunds(object $execution): array
    {
        return DB::table('checkout_mono_refund_executions')
            ->where(
                'capture_receipt_id',
                $execution->capture_receipt_id
            )
            ->pluck('amount_minor', 'ext_ref')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    private function verifyPlan(object $plan): void
    {
        $payload = [
            'case_id' => (int) $plan->return_case_id,
            'order_id' => (int) $plan->order_id,
            'capture_receipt_id' =>
                (int) $plan->capture_receipt_id,
            'operation_key' =>
                (string) $plan->operation_key,
            'amount_minor' => (int) $plan->amount_minor,
            'currency_code' =>
                (string) $plan->currency_code,
            'planned_by' => (int) $plan->planned_by,
        ];

        if (!hash_equals(
            (string) $plan->plan_hmac,
            $this->signer->digest($payload)
        )) {
            throw new RuntimeException(
                'Signed refund plan does not match record'
            );
        }
    }

    /** @return array<string,mixed> */
    private function verifiedReceipt(object $plan): array
    {
        $row = DB::table(
            'checkout_payment_capture_receipts'
        )
            ->where('id', $plan->capture_receipt_id)
            ->first();

        if (
            $row === null
            || $row->provider_code !== 'mono'
            || (int) $row->order_id !== (int) $plan->order_id
            || (int) $plan->amount_minor
            > (int) $row->amount_minor
        ) {
            throw new DomainException(
                'Mono refund capture is invalid'
            );
        }

        $decision = DB::table(
            'checkout_payment_capture_decisions'
        )
            ->where('receipt_id', $row->id)
            ->where('decision', 'paid')
            ->first();

        $receipt = $this->receipts->findByProviderCapture(
            'mono',
            (string) $row->provider_capture_id
        );

        $attempt = $this->attempts->findForOrder(
            (int) $plan->order_id
        );

        if (
            $decision === null
            || $receipt === null
            || $attempt === null
            || $receipt['id'] !== (int) $row->id
            || $receipt['provider_payment_id']
            !== $attempt['provider_payment_id']
            || $attempt['provider_code'] !== 'mono'
            || $attempt['provider_payment_id']
            !== $row->provider_capture_id
            || $receipt['amount_minor']
            !== (int) $row->amount_minor
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
            'refund_plan_id' =>
                (int) $execution->refund_plan_id,
            'order_id' => (int) $execution->order_id,
            'capture_receipt_id' =>
                (int) $execution->capture_receipt_id,
            'operation_key' =>
                (string) $execution->operation_key,
            'ext_ref' => (string) $execution->ext_ref,
            'invoice_id' => (string) $execution->invoice_id,
            'amount_minor' => (int) $execution->amount_minor,
            'currency_code' =>
                (string) $execution->currency_code,
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
            || config(
                'services.mono_acquiring.refunds.enabled'
            ) !== true
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
            throw new AuthorizationException(
                'Mono refund access denied'
            );
        }

        return (int) $id;
    }
}
