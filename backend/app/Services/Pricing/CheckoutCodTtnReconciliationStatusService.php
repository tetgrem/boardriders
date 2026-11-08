<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read-only history projection. No scan/review removes the block #14 hold. */
final class CheckoutCodTtnReconciliationStatusService
{
    /** @return array<string, mixed> */
    public function inspect(string $orderKey): array
    {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid order UUID');
        }
        $order = DB::table('orders')->where('order_key', strtolower($orderKey))
            ->first(['id']);
        if ($order === null) {
            throw new DomainException('Order not found');
        }
        $intent = DB::table('checkout_cod_ttn_submission_intents')
            ->where('order_id', $order->id)->first(['id']);
        $intentId = $intent === null ? null : (int) $intent->id;

        $flagged = $intentId !== null && DB::table('checkout_cod_ttn_submission_safety_events')
                ->where('intent_id', $intentId)
                ->where('event_type', 'reconciliation_required')->exists();
        $scan = !$flagged ? null : DB::table('checkout_cod_ttn_reconciliation_scans')
            ->where('intent_id', $intentId)->orderByDesc('id')->first();
        $review = $scan === null ? null : DB::table('checkout_cod_ttn_reconciliation_reviews')
            ->where('scan_id', $scan->id)->first(['decision', 'reason_code']);

        $state = match (true) {
            $intentId === null => 'not_prepared',
            !$flagged => 'no_reconciliation_flag',
            $scan === null => 'awaiting_scan',
            $review === null => 'awaiting_independent_review',
            $review->decision === 'escalate_to_carrier' => 'escalated_to_carrier',
            default => 'hold_continues',
        };
        return [
            'order_id' => (int) $order->id,
            'intent_id' => $intentId,
            'latest_scan_id' => $scan === null ? null : (int) $scan->id,
            'latest_scan_result' => $scan?->provider_result,
            'latest_review_decision' => $review?->decision,
            'status' => $state,
            'reconciliation_flag_recorded' => $flagged,
            'reconciliation_hold_released' => false,
            'provider_number_linked_to_order' => false,
            'absence_proven' => false,
            'ttn_created' => null,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
