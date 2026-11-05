<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Immutable triage queue for captured payments that require manual review.
 * Does NOT create refunds, authorize payment, or change order status.
 */
final class CheckoutPaymentReviewCaseService
{
    /** @return array<string,mixed> */
    public function ensureForDecision(int $decisionId): array
    {
        if ($decisionId <= 0) {
            throw new InvalidArgumentException('Invalid payment decision ID');
        }

        return DB::transaction(function () use ($decisionId): array {
            // Serializes two workers opening the same case.
            $decision = DB::table('checkout_payment_capture_decisions')
                ->where('id', $decisionId)
                ->lockForUpdate()
                ->first();

            if ($decision === null) {
                throw new DomainException('Payment decision not found');
            }
            if ($decision->decision !== 'review') {
                throw new DomainException('Only review decisions can open a review case');
            }

            $receipt = DB::table('checkout_payment_capture_receipts')
                ->where('id', $decision->receipt_id)
                ->first(['id', 'order_id', 'amount_minor', 'currency_code']);

            if (
                $receipt === null
                || (int) $receipt->order_id !== (int) $decision->order_id
            ) {
                throw new DomainException('Payment decision and receipt belong to different orders');
            }

            $existing = DB::table('checkout_payment_review_cases')
                ->where('decision_id', $decisionId)
                ->first();

            if ($existing !== null) {
                return $this->present($existing, $decision, $receipt);
            }

            [$type, $action] = $this->classify((string) $decision->reason_code);

            // A missing snapshot is a legitimate review scenario.
            $expected = DB::table('order_financial_snapshots')
                ->where('order_id', $decision->order_id)
                ->first(['goods_payable_minor', 'currency_code']);

            $id = (int) DB::table('checkout_payment_review_cases')->insertGetId([
                'decision_id' => $decisionId,
                'case_type' => $type,
                'suggested_action' => $action,
                'captured_amount_minor' => (int) $receipt->amount_minor,
                'captured_currency_code' => (string) $receipt->currency_code,
                'expected_amount_minor' => $expected === null
                    ? null : (int) $expected->goods_payable_minor,
                'expected_currency_code' => $expected?->currency_code,
            ]);

            $case = DB::table('checkout_payment_review_cases')
                ->where('id', $id)
                ->first();

            return $this->present($case, $decision, $receipt);
        }, 3);
    }

    /** @return array<string,mixed>|null */
    public function findForDecision(int $decisionId): ?array
    {
        if ($decisionId <= 0) {
            throw new InvalidArgumentException('Invalid payment decision ID');
        }

        $case = DB::table('checkout_payment_review_cases')
            ->where('decision_id', $decisionId)
            ->first();

        if ($case === null) {
            return null;
        }

        $decision = DB::table('checkout_payment_capture_decisions')
            ->where('id', $decisionId)->first();
        $receipt = DB::table('checkout_payment_capture_receipts')
            ->where('id', $decision->receipt_id)->first();

        return $this->present($case, $decision, $receipt);
    }

    /** @return array{string,string} */
    private function classify(string $reason): array
    {
        return match ($reason) {
            'already_paid', 'order_canceled' =>
            ['possible_extra_capture', 'assess_refund'],
            'amount_mismatch', 'currency_mismatch' =>
            ['payment_mismatch', 'reconcile_amount'],
            'stock_mismatch', 'promotion_mismatch',
            'bonus_mismatch', 'capture_rejected',
            'capture_outside_window' =>
            ['reservation_conflict', 'reconcile_fulfillment'],
            'preparation_missing', 'snapshot_missing',
            'order_not_pending' =>
            ['missing_checkout_context', 'investigate'],
            default => ['other', 'investigate'],
        };
    }

    /** @return array<string,mixed> */
    private function present(object $case, object $decision, object $receipt): array
    {
        return [
            'id' => (int) $case->id,
            'decision_id' => (int) $case->decision_id,
            'receipt_id' => (int) $receipt->id,
            'order_id' => (int) $decision->order_id,
            'reason_code' => (string) $decision->reason_code,
            'case_type' => (string) $case->case_type,
            'suggested_action' => (string) $case->suggested_action,
            'captured_amount_minor' => (int) $case->captured_amount_minor,
            'captured_currency_code' => (string) $case->captured_currency_code,
            'expected_amount_minor' => $case->expected_amount_minor === null
                ? null : (int) $case->expected_amount_minor,
            'expected_currency_code' => $case->expected_currency_code,
            'created_at' => (string) $case->created_at,
        ];
    }
}
