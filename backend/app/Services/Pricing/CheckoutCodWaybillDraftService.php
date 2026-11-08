<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * PII-minimized, immutable INTERNAL waybill draft. This is NOT an API payload.
 * InternetDocument/save MUST NOT be called from this service.
 */
final class CheckoutCodWaybillDraftService
{
    private const BLOCKERS = [
        'sender_identity_not_verified_by_carrier',
        'recipient_counterparty_reference_missing',
        'recipient_contact_reference_missing',
        'merchant_cod_agreement_not_verified',
        'payout_contract_not_verified',
        'carrier_cod_acceptance_not_verified',
    ];

    public function __construct(
        private readonly CheckoutCodReadinessReportService $readiness,
        private readonly NovaPoshtaSenderProfileService $senders,
        private readonly NovaPoshtaSenderProfilePolicy $policy,
    ) {
    }

    /** @return array<string, mixed> */
    public function prepare(
        string $orderKey,
        string $draftKey,
        string $senderProfileCode,
        string $payerType,
        string $paymentMethod,
        string $cargoDescription,
    ): array {
        if (!Str::isUuid($orderKey) || !Str::isUuid($draftKey)) {
            throw new InvalidArgumentException('Invalid waybill draft UUID');
        }
        $orderKey = strtolower($orderKey);
        $draftKey = strtolower($draftKey);
        $profileCode = $this->policy->normalizeCode($senderProfileCode);
        $payerType = $this->policy->documentPayer($payerType);
        $paymentMethod = $this->policy->documentPaymentMethod($paymentMethod);
        $description = $this->policy->description($cargoDescription);

        return DB::transaction(function () use (
            $orderKey, $draftKey, $profileCode, $payerType, $paymentMethod, $description
        ): array {
            $order = DB::table('orders')->where('order_key', $orderKey)
                ->lockForUpdate()->first(['id', 'payment_status']);
            if ($order === null || $order->payment_status !== 'pending') {
                throw new DomainException('Waybill draft requires pending COD order');
            }
            $orderId = (int) $order->id;

            // Existing record is a historical artifact pinned to its sender revision.
            $existing = DB::table('checkout_cod_waybill_drafts')
                ->where('order_id', $orderId)->first();
            if ($existing !== null) {
                $oldCode = DB::table('nova_poshta_sender_profiles')
                    ->where('id', $existing->sender_profile_id)->value('profile_code');
                if ((string) $existing->draft_key !== $draftKey
                    || $oldCode !== $profileCode
                    || (string) $existing->payer_type !== $payerType
                    || (string) $existing->payment_method !== $paymentMethod
                    || (string) $existing->cargo_description !== $description
                ) {
                    throw new DomainException('COD waybill draft cannot be replaced');
                }
                return $this->present($existing);
            }

            $sender = $this->senders->latest($profileCode);
            if ($sender === null) {
                throw new DomainException('Sender profile is not configured');
            }

            $report = $this->readiness->inspect($orderKey);
            if (($report['local_checks_complete'] ?? null) !== true) {
                throw new DomainException('COD local readiness checks are incomplete');
            }

            $source = DB::table('checkout_cod_financial_preflights as f')
                ->join('checkout_cod_measured_rates as rt', 'rt.id', '=', 'f.measured_rate_id')
                ->join('checkout_cod_package_assessments as a', 'a.id', '=', 'rt.assessment_id')
                ->join('checkout_cod_parcel_manifests as m', 'm.id', '=', 'rt.manifest_id')
                ->join('checkout_cod_recipients as r', 'r.id', '=', 'm.recipient_id')
                ->join('checkout_cod_delivery_selections as s', 's.id', '=', 'r.cod_delivery_selection_id')
                ->join('checkout_order_preparations as p', 'p.order_id', '=', 'f.order_id')
                ->where('f.order_id', $orderId)
                ->first([
                    'f.id as preflight_id', 'f.local_result',
                    'f.cod_amount_minor', 'f.declared_value_minor',
                    'f.financial_snapshot_sha256',
                    'rt.id as rate_id', 'rt.service_type', 'rt.gross_grams',
                    'rt.sender_city_ref as rate_sender_city_ref',
                    'rt.cod_fee_minor', 'rt.snapshot_sha256 as rate_sha',
                    'a.id as assessment_id', 'm.id as manifest_id',
                    'r.id as recipient_id', 'r.city_ref as recipient_city_ref',
                    's.id as selection_id', 's.destination_ref',
                    's.carrier_code', 's.delivery_type',
                    'p.id as preparation_id', 'p.expires_at',
                ]);

            if ($source === null
                || $source->local_result !== 'within_published_limit'
                || $source->cod_fee_minor === null
                || $source->carrier_code !== 'nova_poshta'
                || strtolower((string) $source->rate_sender_city_ref) !== $sender['city_ref']
                || !hash_equals(
                    (string) $source->financial_snapshot_sha256,
                    (string) $source->rate_sha
                )
                || CarbonImmutable::parse($source->expires_at)
                    ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ) {
                throw new DomainException('COD waybill source is not locally complete');
            }

            // Lock the actual stock reservation rows before persisting.
            $holds = DB::table('checkout_order_stock_reservations as s')
                ->join('inventory_reservations as r', 'r.id', '=', 's.inventory_reservation_id')
                ->where('s.checkout_preparation_id', $source->preparation_id)
                ->orderBy('r.id')->lockForUpdate()
                ->get([
                    's.quantity as line_quantity', 'r.order_id',
                    'r.quantity as reserved_quantity', 'r.shipped_qty',
                    'r.released_qty', 'r.expires_at',
                ]);
            if ($holds->isEmpty()) {
                throw new DomainException('Missing COD stock reservations');
            }
            foreach ($holds as $hold) {
                if ((int) $hold->order_id !== $orderId
                    || (int) $hold->line_quantity < 1
                    || (int) $hold->line_quantity !== (int) $hold->reserved_quantity
                    || (int) $hold->shipped_qty !== 0
                    || (int) $hold->released_qty !== 0
                    || $hold->expires_at === null
                    || CarbonImmutable::parse($hold->expires_at)
                        ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                ) {
                    throw new DomainException('COD stock is no longer reserved');
                }
            }

            if (DB::table('checkout_cod_waybill_drafts')->where('draft_key', $draftKey)->exists()) {
                throw new DomainException('Waybill draft key belongs to another order');
            }

            $signature = hash_hmac('sha256', json_encode([
                'cod-waybill-draft-v1', $orderKey, $sender['id'],
                (int) $source->preflight_id, (int) $source->rate_id,
                $payerType, $paymentMethod, $description,
            ], JSON_THROW_ON_ERROR), (string) config('app.key'));

            $id = DB::table('checkout_cod_waybill_drafts')->insertGetId([
                'draft_key' => $draftKey,
                'order_id' => $orderId,
                'sender_profile_id' => $sender['id'],
                'financial_preflight_id' => $source->preflight_id,
                'measured_rate_id' => $source->rate_id,
                'manifest_id' => $source->manifest_id,
                'assessment_id' => $source->assessment_id,
                'recipient_id' => $source->recipient_id,
                'selection_id' => $source->selection_id,
                'service_type' => $source->service_type,
                'payer_type' => $payerType,
                'payment_method' => $paymentMethod,
                'cargo_description' => $description,
                'sender_city_ref' => $sender['city_ref'],
                'recipient_city_ref' => $source->recipient_city_ref,
                'destination_ref' => $source->destination_ref,
                'gross_grams' => $source->gross_grams,
                'cod_amount_minor' => $source->cod_amount_minor,
                'declared_value_minor' => $source->declared_value_minor,
                'financial_snapshot_sha256' => $source->financial_snapshot_sha256,
                'request_hmac' => $signature,
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('checkout_cod_waybill_drafts')->where('id', $id)->first()
            );
        }, 3);
    }

    /** Historical internal view only. Never use this as dispatch authorization. */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }
        $row = DB::table('checkout_cod_waybill_drafts')
            ->where('order_id', $orderId)->first();
        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'draft_key' => (string) $row->draft_key,
            'order_id' => (int) $row->order_id,
            'sender_profile_id' => (int) $row->sender_profile_id,
            'financial_preflight_id' => (int) $row->financial_preflight_id,
            'measured_rate_id' => (int) $row->measured_rate_id,
            'manifest_id' => (int) $row->manifest_id,
            'assessment_id' => (int) $row->assessment_id,
            'recipient_id' => (int) $row->recipient_id,
            'selection_id' => (int) $row->selection_id,
            'service_type' => (string) $row->service_type,
            'payer_type' => (string) $row->payer_type,
            'payment_method' => (string) $row->payment_method,
            'cargo_description' => (string) $row->cargo_description,
            'sender_city_ref' => (string) $row->sender_city_ref,
            'recipient_city_ref' => (string) $row->recipient_city_ref,
            'destination_ref' => (string) $row->destination_ref,
            'gross_grams' => (int) $row->gross_grams,
            'cod_amount_minor' => (int) $row->cod_amount_minor,
            'declared_value_minor' => (int) $row->declared_value_minor,
            'financial_snapshot_sha256' => (string) $row->financial_snapshot_sha256,
            'status' => (int) $row->declared_value_minor === (int) $row->cod_amount_minor
                ? 'internal_draft_external_verification_required'
                : 'internal_draft_requires_declared_value_rerate',
            'missing_external_evidence' => array_merge(
                self::BLOCKERS,
                (int) $row->declared_value_minor !== (int) $row->cod_amount_minor
                    ? ['declared_value_rate_recalculation_required'] : []
            ),
            'actual_ttn_number' => null,
            'is_internet_document_save_payload' => false,
            'api_submit_allowed' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
