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

/**
 * Immutable refund reservation.
 * NOT a call to /invoice/cancel.
 * NOT proof of refund.
 */
final class CheckoutMonoRmaRefund53Service
{
    public const PLAN_ABILITY = 'payments.mono.refunds.plan';

    public function __construct(
        private readonly CheckoutOrderReturnService $returns,
        private readonly CheckoutOrderReturnSigner $signer,
        private readonly CheckoutPaymentCaptureReceiptService $receipts,
        private readonly CheckoutPaymentAttemptService $attempts,
    ) {
    }

    public function plan(
        string $returnKey,
        string $operationKey
    ): array {
        $actor = $this->actor();

        if (
            !Str::isUuid($returnKey)
            || !Str::isUuid($operationKey)
        ) {
            throw new InvalidArgumentException(
                'Invalid refund UUID'
            );
        }

        $returnKey = strtolower($returnKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $returnKey,
            $operationKey,
            $actor
        ): array {
            $case = DB::table('checkout_order_return_cases')
                ->where('return_key', $returnKey)
                ->first();

            if ($case === null) {
                throw new DomainException('RMA not found');
            }

            $order = DB::table('orders')
                ->where('id', $case->order_id)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if (
                $order === null
                || $order->payment_status !== 'paid'
            ) {
                throw new DomainException(
                    'Refund planning requires fully paid online order'
                );
            }

            // Signed RMA reader validates units, original
            // financial snapshot, inspection and decision.
            $rma = $this->returns->show($returnKey);
            $decision = $rma['decision'];

            if (
                $decision === null
                || $decision['decision'] !== 'approved'
                || $rma['workflow'] !== 'awaiting_separate_execution'
                || $decision['cash_candidate_minor'] <= 0
            ) {
                throw new DomainException(
                    'Refund requires approved nonzero cash RMA'
                );
            }

            $inspection = DB::table(
                'checkout_order_return_inspections'
            )
                ->where('case_id', $case->id)
                ->first();

            if (
                $inspection === null
                || in_array($actor, [
                    (int) $case->opened_by,
                    (int) $inspection->inspected_by,
                    (int) $decision['decided_by'],
                ], true)
            ) {
                throw new DomainException(
                    'Refund planner must be independent'
                );
            }

            $accepted = DB::table(
                'checkout_payment_capture_decisions as d'
            )
                ->join(
                    'checkout_payment_capture_receipts as r',
                    'r.id',
                    '=',
                    'd.receipt_id'
                )
                ->where('d.order_id', $order->id)
                ->where('d.decision', 'paid')
                ->where('r.provider_code', 'mono')
                ->first([
                    'r.id',
                    'r.provider_capture_id',
                    'r.provider_payment_id',
                    'r.amount_minor',
                    'r.currency_code',
                ]);

            if (
                $accepted === null
                || $accepted->currency_code !== 'UAH'
            ) {
                throw new DomainException(
                    'No accepted Mono capture available for RMA'
                );
            }

            $receipt = $this->receipts->findByProviderCapture(
                'mono',
                (string) $accepted->provider_capture_id
            );

            $attempt = $this->attempts->findForOrder(
                (int) $order->id
            );

            if (
                $receipt === null
                || $attempt === null
                || $receipt['id'] !== (int) $accepted->id
                || $receipt['order_id'] !== (int) $order->id
                || $attempt['provider_code'] !== 'mono'
                || $attempt['provider_payment_id']
                !== $receipt['provider_payment_id']
                || $attempt['provider_payment_id']
                !== $receipt['provider_capture_id']
            ) {
                throw new DomainException(
                    'Mono refund payment identity mismatch'
                );
            }

            $amount = (int) $decision['cash_candidate_minor'];

            $payload = [
                'case_id' => (int) $case->id,
                'order_id' => (int) $order->id,
                'capture_receipt_id' => (int) $receipt['id'],
                'operation_key' => $operationKey,
                'amount_minor' => $amount,
                'currency_code' => 'UAH',
                'planned_by' => $actor,
            ];

            $hmac = $this->signer->digest($payload);

            $existing = DB::table(
                'checkout_mono_rma_refund_plans'
            )
                ->where('return_case_id', $case->id)
                ->first();

            if ($existing !== null) {
                if (
                    (string) $existing->operation_key !== $operationKey
                    || !hash_equals(
                        (string) $existing->plan_hmac,
                        $hmac
                    )
                ) {
                    throw new DomainException(
                        'Return already has a different refund plan'
                    );
                }

                return $this->present($existing, true);
            }

            $keyOwner = DB::table(
                'checkout_mono_rma_refund_plans'
            )
                ->where('operation_key', $operationKey)
                ->exists();

            if ($keyOwner) {
                throw new DomainException(
                    'Refund operation key used by another return'
                );
            }

            $previous = (int) DB::table(
                'checkout_mono_rma_refund_plans'
            )
                ->where('capture_receipt_id', $receipt['id'])
                ->sum('amount_minor');

            $reviewProposed = (int) DB::table(
                'checkout_payment_refund_intents'
            )
                ->where('receipt_id', $receipt['id'])
                ->sum('amount_minor');

            if (
                $amount > $receipt['amount_minor']
                - $previous
                - $reviewProposed
            ) {
                throw new DomainException(
                    'Refund plans exceed verified captured amount'
                );
            }

            $id = (int) DB::table(
                'checkout_mono_rma_refund_plans'
            )->insertGetId([
                'return_case_id' => $case->id,
                'order_id' => $order->id,
                'capture_receipt_id' => $receipt['id'],
                'operation_key' => $operationKey,
                'amount_minor' => $amount,
                'currency_code' => 'UAH',
                'plan_hmac' => $hmac,
                'planned_by' => $actor,
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('checkout_mono_rma_refund_plans')
                    ->where('id', $id)
                    ->first(),
                false
            );
        }, 3);
    }

    private function present(
        object $row,
        bool $replayed
    ): array {
        return [
            'plan_id' => (int) $row->id,
            'return_case_id' => (int) $row->return_case_id,
            'order_id' => (int) $row->order_id,
            'capture_receipt_id' => (int) $row->capture_receipt_id,
            'amount_minor' => (int) $row->amount_minor,
            'currency_code' => 'UAH',
            'status' =>
                'awaiting_verified_provider_refund_execution',
            'money_refunded' => false,
            'loyalty_refunded' => false,
            'inventory_restocked' => false,
            'idempotent_replay' => $replayed,
        ];
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            $user === null
            || (
                (!is_int($id) || $id <= 0)
                && (
                    !is_string($id)
                    || !ctype_digit($id)
                )
            )
            || !Gate::forUser($user)->allows(self::PLAN_ABILITY)
        ) {
            throw new AuthorizationException(
                'Mono refund planning permission denied'
            );
        }

        return (int) $id;
    }
}
