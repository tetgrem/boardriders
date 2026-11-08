<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Side-effect-free INTERNAL diagnostic, not a complete TTN API request.
 * Never decrypts sender or recipient PII; never invokes InternetDocument/save.
 */
final class CheckoutCodWaybillPayloadPreviewService
{
    public function __construct(
        private readonly CheckoutCodWaybillDraftService $drafts,
        private readonly CheckoutCodRecipientCarrierBindingService $bindings,
        private readonly CheckoutCodReadinessReportService $readiness,
        private readonly NovaPoshtaWaybillPayloadPreviewFormatter $formatter,
    ) {
    }

    /** @return array<string, mixed> */
    public function preview(string $orderKey): array
    {
        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid order key');
        }
        $orderKey = strtolower($orderKey);

        return DB::transaction(function () use ($orderKey): array {
            $order = DB::table('orders')->where('order_key', $orderKey)
                ->first(['id', 'payment_status']);
            if ($order === null) {
                throw new DomainException('COD order not found');
            }
            $orderId = (int) $order->id;
            $draft = $this->drafts->findForOrder($orderId);
            if ($draft === null) {
                throw new DomainException('COD waybill draft not found');
            }

            $rate = DB::table('checkout_cod_measured_rates')
                ->where('id', $draft['measured_rate_id'])
                ->where('order_id', $orderId)
                ->first(['id', 'goods_due_minor', 'gross_grams', 'service_type', 'snapshot_sha256']);
            $preflight = DB::table('checkout_cod_financial_preflights')
                ->where('id', $draft['financial_preflight_id'])
                ->where('order_id', $orderId)
                ->first(['id', 'measured_rate_id', 'cod_amount_minor',
                    'declared_value_minor', 'financial_snapshot_sha256']);

            if ($rate === null || $preflight === null
                || (int) $preflight->measured_rate_id !== (int) $rate->id
                || (int) $preflight->cod_amount_minor !== $draft['cod_amount_minor']
                || (int) $preflight->declared_value_minor !== $draft['declared_value_minor']
                || (int) $rate->gross_grams !== $draft['gross_grams']
                || (string) $rate->service_type !== $draft['service_type']
                || !hash_equals((string) $rate->snapshot_sha256,
                    (string) $draft['financial_snapshot_sha256'])
                || !hash_equals((string) $preflight->financial_snapshot_sha256,
                    (string) $draft['financial_snapshot_sha256'])
            ) {
                throw new DomainException('COD waybill draft financial binding mismatch');
            }

            $senderExists = DB::table('nova_poshta_sender_profiles')
                ->where('id', $draft['sender_profile_id'])->exists();
            $recipientExists = DB::table('checkout_cod_recipients')
                ->where('id', $draft['recipient_id'])
                ->where('order_id', $orderId)->exists();
            $binding = $this->bindings->findForOrder($orderId);
            $hasBinding = $binding !== null
                && $binding['draft_id'] === $draft['id']
                && $binding['recipient_id'] === $draft['recipient_id'];
            $report = $this->readiness->inspect($orderKey);
            $formatted = $this->formatter->format($draft);

            $blockers = [];
            if (($report['local_checks_complete'] ?? null) !== true
                || $order->payment_status !== 'pending'
            ) {
                $blockers[] = 'checkout_not_currently_ready';
            }
            if (!$senderExists) {
                $blockers[] = 'sender_profile_missing';
            }
            if (!$recipientExists) {
                $blockers[] = 'checkout_recipient_missing';
            }
            if (!$hasBinding) {
                $blockers[] = 'recipient_carrier_references_missing';
            }
            if ((int) $rate->goods_due_minor !== $draft['declared_value_minor']) {
                $blockers[] = 'declared_value_rerate_required';
            }

            $blockers = array_merge($blockers, [
                'sender_carrier_identity_not_verified',
                'recipient_carrier_identity_not_verified',
                'merchant_cod_agreement_not_verified',
                'payout_contract_not_verified',
                'carrier_cod_acceptance_not_verified',
            ]);

            return [
                'order_id' => $orderId,
                'draft_id' => $draft['id'],
                'draft_key' => $draft['draft_key'],
                'recipient_references_recorded' => $hasBinding,
                'local_checks_complete_now' =>
                    ($report['local_checks_complete'] ?? null) === true,
                'preview' => $formatted,
                'blockers' => $blockers,
                'status' => 'blocked',
                'recipient_identity_verified' => false,
                'merchant_cod_agreement_verified' => false,
                'ttn_created' => false,
                'actual_ttn_number' => null,
                'api_submit_allowed' => false,
                'ready_for_dispatch' => false,
            ];
        }, 3);
    }
}
