<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Historical case projection. A matching snapshot NEVER authorizes fulfillment. */
final class CheckoutCodTtnDocumentComparisonStatusService
{
    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid checkout UUID');
        }
        $order = DB::table('orders')->where('order_key', strtolower($orderKey))->first(['id']);
        if ($order === null) {
            throw new DomainException('Order not found');
        }
        $intent = DB::table('checkout_cod_ttn_submission_intents')
            ->where('order_id', $order->id)->first(['id']);
        $flagged = $intent !== null && DB::table('checkout_cod_ttn_submission_safety_events')
            ->where('intent_id', $intent->id)
            ->where('event_type', 'reconciliation_required')->exists();
        $scan = $intent === null ? null : DB::table('checkout_cod_ttn_reconciliation_scans')
            ->where('intent_id', $intent->id)->orderByDesc('id')->first(['id']);
        $comparison = $scan === null ? null : DB::table('checkout_cod_ttn_document_comparisons')
            ->where('scan_id', $scan->id)->first(['id', 'summary']);
        $state = match (true) {
            $intent === null => 'no_intent',
            !$flagged => 'no_reconciliation_flag',
            $scan === null => 'awaiting_scan',
            $comparison === null => 'awaiting_comparison',
            $comparison->summary === 'snapshot_consistent' => 'consistent_snapshot_only',
            $comparison->summary === 'snapshot_mismatch' => 'snapshot_mismatch',
            default => 'inconclusive',
        };
        return [
            'order_id' => (int) $order->id,
            'intent_id' => $intent === null ? null : (int) $intent->id,
            'latest_scan_id' => $scan === null ? null : (int) $scan->id,
            'comparison_id' => $comparison === null ? null : (int) $comparison->id,
            'status' => $state,
            'manual_reconciliation_required' => $flagged,
            'waybill_linked_to_order' => false,
            'reconciliation_hold_released' => false,
            'automatic_retry_allowed' => false,
            'ttn_submission_allowed' => false,
            'ready_for_dispatch' => false,
        ];
    }
}
