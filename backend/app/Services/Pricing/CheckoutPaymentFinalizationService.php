<?php

namespace App\Services\Pricing;

use App\Services\Loyalty\LoyaltyTransactionService;
use App\Services\Promotions\PromotionReservationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Internal OMS boundary. Only pass receipt IDs recorded by a VERIFIED provider adapter.
 * No public route, no provider signature verification, no shipping or refunds here.
 */
final class CheckoutPaymentFinalizationService
{
    public function __construct(
        private readonly CheckoutPaymentCaptureReceiptService $receipts,
        private readonly OrderFinancialSnapshotService $snapshots,
        private readonly PromotionReservationService $promotions,
        private readonly LoyaltyTransactionService $loyalty,
        private readonly CheckoutPaymentAttemptService $attempts,
        private readonly CheckoutPaymentReviewCaseService $reviewCases,
    ) {
    }

    /** @return array<string,mixed> */
    public function finalize(int $receiptId): array
    {
        if ($receiptId <= 0) {
            throw new InvalidArgumentException('Invalid payment receipt ID');
        }

        return DB::transaction(function () use ($receiptId): array {
            $raw = DB::table('checkout_payment_capture_receipts')
                ->where('id', $receiptId)->first(['provider_code', 'provider_capture_id']);

            if ($raw === null) {
                throw new DomainException('Verified payment receipt not found');
            }

            // The receipt service rechecks the immutable JSON and SHA-256.
            $receipt = $this->receipts->findByProviderCapture(
                (string) $raw->provider_code,
                (string) $raw->provider_capture_id
            );

            if ($receipt === null || $receipt['id'] !== $receiptId) {
                throw new RuntimeException('Payment receipt lookup mismatch');
            }

            // Always take the order lock before promotion, stock and wallet locks.
            $order = DB::table('orders')->where('id', $receipt['order_id'])
                ->lockForUpdate()->first(['id', 'payment_status']);

            if ($order === null) {
                throw new DomainException('Payment order not found');
            }

            $existing = DB::table('checkout_payment_capture_decisions')
                ->where('receipt_id', $receiptId)->first();

            if ($existing !== null) {
                if ($existing->decision === 'review') {
                    $this->reviewCases->ensureForDecision((int) $existing->id);
                }
                return $this->readDecision($existing);
            }

            // Another captured charge for the same already-paid order is NOT
            // a second valid payment. Preserve paid order, mark receipt for review.
            if ($order->payment_status === 'paid') {
                return $this->review($order, $receiptId, 'already_paid');
            }
            if ($order->payment_status === 'canceled') {
                return $this->review($order, $receiptId, 'order_canceled');
            }
            if ($order->payment_status !== 'pending') {
                return $this->review($order, $receiptId, 'order_not_pending');
            }

            $checked = $this->inspectPreparedOrder((int) $order->id, $receipt);

            if ($checked['reason'] !== null) {
                return $this->review($order, $receiptId, $checked['reason']);
            }

            // A savepoint guarantees that a rejected promotion/bonus capture
            // never leaves the order half paid. Unexpected SQL failures bubble
            // out, rolling back the entire outer transaction for a safe retry.
            try {
                DB::transaction(function () use ($order, $receipt, $checked): void {
                    DB::table('orders')->where('id', $order->id)->update([
                        'payment_status' => 'paid',
                        'updated_at' => now(),
                    ]);

                    if ($checked['promotion_reservation_id'] !== null) {
                        $promoted = $this->promotions->capture(
                            $checked['promotion_reservation_id'],
                            (string) Str::uuid(),
                            CarbonImmutable::parse($receipt['captured_at'])
                        );

                        if ($promoted['status'] !== 'captured') {
                            throw new DomainException('Promotion capture was not completed');
                        }
                    }

                    if ($checked['loyalty_reservation_id'] !== null) {
                        $captured = $this->loyalty->captureReservation(
                            $checked['loyalty_wallet_id'],
                            $checked['loyalty_reservation_id'],
                            'checkout-payment:' . $receipt['id'],
                            CarbonImmutable::parse($receipt['captured_at'])
                        );

                        if (($captured['captured_units'] ?? null) !== $checked['bonus_units']) {
                            throw new DomainException('Bonus capture amount mismatch');
                        }
                    }
                });
            } catch (DomainException | InvalidArgumentException) {
                return $this->review($order, $receiptId, 'capture_rejected');
            }

            return $this->writeDecision(
                (int) $order->id, $receiptId, 'paid', 'accepted',
                $checked['promotion_reservation_id'],
                $checked['loyalty_reservation_id']
            );
        }, 3);
    }

    /** @param array<string,mixed> $receipt
     *  @return array{reason:?string,promotion_reservation_id:?int,loyalty_wallet_id:?int,loyalty_reservation_id:?int,bonus_units:int}
     */
    private function inspectPreparedOrder(int $orderId, array $receipt): array
    {
        $result = [
            'reason' => null,
            'promotion_reservation_id' => null,
            'loyalty_wallet_id' => null,
            'loyalty_reservation_id' => null,
            'bonus_units' => 0,
        ];

        $orderKey = DB::table('orders')->where('id', $orderId)->value('order_key');

        $preparation = DB::table('checkout_order_preparations')
            ->where('order_id', $orderId)->first();

        if ($preparation === null) {
            $result['reason'] = 'preparation_missing';
            return $result;
        }

        $financial = $this->snapshots->findForOrder($orderId);
        if ($financial === null || $financial['id'] !== (int) $preparation->financial_snapshot_id) {
            $result['reason'] = 'snapshot_missing';
            return $result;
        }

        $snapshot = $financial['snapshot'];
        if ($receipt['amount_minor'] !== $financial['goods_payable_minor']) {
            $result['reason'] = 'amount_mismatch';
            return $result;
        }
        if ($receipt['currency_code'] !== $financial['currency_code']) {
            $result['reason'] = 'currency_mismatch';
            return $result;
        }

        $paidAt = CarbonImmutable::parse($receipt['captured_at'])->utc();
        if (
            $paidAt->greaterThan(CarbonImmutable::now('UTC'))
            || $paidAt->lessThan(CarbonImmutable::parse($preparation->created_at))
            || $paidAt->greaterThanOrEqualTo(CarbonImmutable::parse($preparation->expires_at))
        ) {
            $result['reason'] = 'capture_outside_window';
            return $result;
        }

        // Fail closed: a prepared checkout may be paid ONLY by the provider
        // payment intent that was bound to the immutable financial snapshot.
        // Unknown or mismatched captures remain recorded and go to review.
        $attempt = $this->attempts->findForOrder($orderId);
        if ($attempt === null) {
            $result['reason'] = 'payment_attempt_missing';
            return $result;
        }
        if (($receipt['provider_payment_id'] ?? null) === null) {
            $result['reason'] = 'provider_payment_id_missing';
            return $result;
        }
        if (
            $attempt['order_id'] !== $orderId
            || $attempt['order_key'] !== $orderKey
            || $attempt['financial_snapshot_id'] !== $financial['id']
            || !hash_equals($attempt['financial_snapshot_sha256'], $financial['sha256'])
            || $attempt['provider_code'] !== $receipt['provider_code']
            || $attempt['provider_payment_id'] !== $receipt['provider_payment_id']
            || $attempt['amount_minor'] !== $receipt['amount_minor']
            || $attempt['currency_code'] !== $receipt['currency_code']
        ) {
            $result['reason'] = 'payment_attempt_mismatch';
            return $result;
        }
        if (
            $paidAt->lessThan(CarbonImmutable::parse($attempt['created_at']))
            || $paidAt->greaterThanOrEqualTo(CarbonImmutable::parse($attempt['expires_at']))
        ) {
            $result['reason'] = 'capture_outside_attempt_window';
            return $result;
        }

        $promo = DB::table('promotion_reservations as r')
            ->join('promotion_codes as c', 'c.id', '=', 'r.promotion_code_id')
            ->where('r.order_id', $orderId)
            ->first([
                'r.id', 'r.promotion_id', 'r.user_id', 'r.currency_code',
                'r.discount_minor', 'r.created_at', 'r.expires_at', 'c.code',
            ]);

        $expectedPromo = $snapshot['promotion_discount_minor'] ?? null;
        $expectedCode = $snapshot['promotion_code'] ?? null;

        if ($promo === null) {
            if ($expectedPromo !== 0 || $expectedCode !== null) {
                $result['reason'] = 'promotion_mismatch';
                return $result;
            }
        } else {
            // Promotion -> inventory -> wallet is the same lock order as cancel.
            DB::table('promotions')->where('id', $promo->promotion_id)
                ->lockForUpdate()->first(['id']);

            if (
                (int) $promo->discount_minor !== $expectedPromo
                || $promo->code !== $expectedCode
                || $promo->currency_code !== $financial['currency_code']
                || ($promo->user_id === null ? null : (int) $promo->user_id)
                    !== ($preparation->user_id === null ? null : (int) $preparation->user_id)
                || $paidAt->lessThan(CarbonImmutable::parse($promo->created_at))
                || $paidAt->greaterThanOrEqualTo(CarbonImmutable::parse($promo->expires_at))
                || DB::table('promotion_reservation_events')
                    ->where('reservation_id', $promo->id)->exists()
            ) {
                $result['reason'] = 'promotion_mismatch';
                return $result;
            }

            $result['promotion_reservation_id'] = (int) $promo->id;
        }

        $lines = $snapshot['lines'] ?? null;
        if (!is_array($lines) || $lines === [] || count($lines) > 200) {
            $result['reason'] = 'stock_mismatch';
            return $result;
        }

        $expected = [];
        foreach ($lines as $line) {
            if (
                !is_array($line)
                || !is_int($line['variant_id'] ?? null)
                || !is_int($line['quantity'] ?? null)
                || $line['quantity'] <= 0
                || isset($expected[$line['variant_id']])
            ) {
                $result['reason'] = 'stock_mismatch';
                return $result;
            }
            $expected[$line['variant_id']] = $line['quantity'];
        }

        $links = DB::table('checkout_order_stock_reservations')
            ->where('checkout_preparation_id', $preparation->id)
            ->orderBy('variant_id')->get();

        if (
            $links->count() !== count($expected)
            || DB::table('inventory_reservations')
                ->where('order_id', $orderId)->count() !== $links->count()
        ) {
            $result['reason'] = 'stock_mismatch';
            return $result;
        }

        foreach ($links as $link) {
            // Inventory lock order: reservation -> stock.
            $reservation = DB::table('inventory_reservations')
                ->where('id', $link->inventory_reservation_id)
                ->lockForUpdate()->first();

            if ($reservation === null) {
                $result['reason'] = 'stock_mismatch';
                return $result;
            }

            $stock = DB::table('inventory_stocks')
                ->where('id', $reservation->inventory_stock_id)
                ->lockForUpdate()->first();

            $quantity = (int) $link->quantity;
            if (
                $stock === null
                || (int) $reservation->order_id !== $orderId
                || ($expected[(int) $link->variant_id] ?? null) !== $quantity
                || (int) $reservation->quantity !== $quantity
                || (int) $reservation->shipped_qty !== 0
                || (int) $reservation->released_qty !== 0
                || (int) $stock->warehouse_id !== (int) $link->warehouse_id
                || (int) $stock->variant_id !== (int) $link->variant_id
                || (int) $stock->reserved_qty < $quantity
                || (int) $stock->on_hand_qty < $quantity
                || $paidAt->lessThan(CarbonImmutable::parse($reservation->created_at))
                || $reservation->expires_at === null
                || $paidAt->greaterThanOrEqualTo(CarbonImmutable::parse($reservation->expires_at))
            ) {
                $result['reason'] = 'stock_mismatch';
                return $result;
            }
        }

        $bonusUnits = $snapshot['loyalty_units_spent'] ?? null;
        if (
            !is_int($bonusUnits) || $bonusUnits < 0
            || (int) $preparation->bonus_units_reserved !== $bonusUnits
        ) {
            $result['reason'] = 'bonus_mismatch';
            return $result;
        }

        if ($bonusUnits > 0) {
            $walletId = (int) $preparation->loyalty_wallet_id;
            $reservationId = (int) $preparation->loyalty_reservation_id;
            $bonus = DB::table('loyalty_reservations')
                ->where('id', $reservationId)->first();

            if (
                $walletId <= 0 || $reservationId <= 0
                || $bonus === null
                || (int) $bonus->wallet_id !== $walletId
                || (int) $bonus->units_total !== $bonusUnits
                || $bonus->order_reference !== 'checkout-order:' . $orderKey
                || $paidAt->lessThan(CarbonImmutable::parse($bonus->created_at))
                || $paidAt->greaterThanOrEqualTo(CarbonImmutable::parse($bonus->expires_at))
                || (int) DB::table('loyalty_ledger_entries')
                    ->where('reservation_id', $reservationId)
                    ->sum('reserved_delta') !== $bonusUnits
            ) {
                $result['reason'] = 'bonus_mismatch';
                return $result;
            }

            $result['loyalty_wallet_id'] = $walletId;
            $result['loyalty_reservation_id'] = $reservationId;
            $result['bonus_units'] = $bonusUnits;
        } elseif (
            $preparation->loyalty_wallet_id !== null
            || $preparation->loyalty_reservation_id !== null
        ) {
            $result['reason'] = 'bonus_mismatch';
            return $result;
        }

        return $result;
    }

    /** @param object $order */
    private function review(object $order, int $receiptId, string $reason): array
    {
        if ($order->payment_status === 'pending') {
            DB::table('orders')->where('id', $order->id)->update([
                'payment_status' => 'review',
                'updated_at' => now(),
            ]);
        }

        return $this->writeDecision((int) $order->id, $receiptId, 'review', $reason);
    }

    /** @return array<string,mixed> */
    private function writeDecision(
        int $orderId,
        int $receiptId,
        string $decision,
        string $reason,
        ?int $promotionReservationId = null,
        ?int $loyaltyReservationId = null,
    ): array {
        $id = (int) DB::table('checkout_payment_capture_decisions')->insertGetId([
            'order_id' => $orderId,
            'receipt_id' => $receiptId,
            'decision' => $decision,
            'reason_code' => $reason,
            'promotion_reservation_id' => $promotionReservationId,
            'loyalty_reservation_id' => $loyaltyReservationId,
        ]);

        if ($decision === 'review') {
            // Same transaction as the immutable decision.
            $this->reviewCases->ensureForDecision($id);
        }

        $row = DB::table('checkout_payment_capture_decisions')->where('id', $id)->first();
        return $this->readDecision($row);
    }

    /** @return array<string,mixed> */
    private function readDecision(object $row): array
    {
        $status = DB::table('orders')->where('id', $row->order_id)->value('payment_status');

        return [
            'decision_id' => (int) $row->id,
            'receipt_id' => (int) $row->receipt_id,
            'order_id' => (int) $row->order_id,
            'decision' => (string) $row->decision,
            'reason_code' => (string) $row->reason_code,
            'order_payment_status' => (string) $status,
            'promotion_reservation_id' => $row->promotion_reservation_id === null
                ? null : (int) $row->promotion_reservation_id,
            'loyalty_reservation_id' => $row->loyalty_reservation_id === null
                ? null : (int) $row->loyalty_reservation_id,
            'created_at' => (string) $row->created_at,
        ];
    }
}
