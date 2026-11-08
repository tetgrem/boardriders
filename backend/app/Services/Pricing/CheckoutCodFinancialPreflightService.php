<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Trusted server-side screening. A recorded result is NOT payout authorization,
 * a final price, an invoice, a TTN, or permission to ship.
 */
final class CheckoutCodFinancialPreflightService
{
    public function __construct(
        private readonly CheckoutCodDeliverySelectionService $selections,
        private readonly OrderFinancialSnapshotService $snapshots,
        private readonly NovaPoshtaCodSettlementPolicy $policy,
    ) {
    }

    /** @return array<string, mixed> */
    public function record(
        string $orderKey,
        string $operationKey,
        int $declaredValueMinor,
        string $payoutChannel,
    ): array {
        if (!Str::isUuid($orderKey) || !Str::isUuid($operationKey)) {
            throw new InvalidArgumentException('Invalid financial preflight UUID');
        }

        $orderKey = strtolower($orderKey);
        $operationKey = strtolower($operationKey);

        return DB::transaction(function () use (
            $orderKey, $operationKey, $declaredValueMinor, $payoutChannel
        ): array {
            $context = $this->context($orderKey);
            $decision = $this->policy->evaluate(
                $context['cod_amount_minor'],
                $declaredValueMinor,
                $payoutChannel,
                $context['estimated_cod_fee_minor'],
            );

            $requestHash = hash('sha256', json_encode([
                'cod-financial-preflight-v1',
                $orderKey,
                $context,
                $declaredValueMinor,
                $payoutChannel,
            ], JSON_THROW_ON_ERROR));

            $existing = DB::table('checkout_cod_financial_preflights')
                ->where('order_id', $context['order_id'])->first();

            if ($existing !== null) {
                if (
                    (string) $existing->operation_key !== $operationKey
                    || !hash_equals((string) $existing->request_sha256, $requestHash)
                ) {
                    throw new DomainException('COD financial preflight is immutable');
                }

                return $this->present($existing);
            }

            if (DB::table('checkout_cod_financial_preflights')
                ->where('operation_key', $operationKey)->exists()
            ) {
                throw new DomainException('COD financial preflight key already used');
            }

            $id = DB::table('checkout_cod_financial_preflights')->insertGetId([
                'operation_key' => $operationKey,
                'order_id' => $context['order_id'],
                'measured_rate_id' => $context['measured_rate_id'],
                'assessment_id' => $context['assessment_id'],
                'cod_delivery_selection_id' => $context['selection_id'],
                'financial_snapshot_id' => $context['financial_snapshot_id'],
                'policy_version' => $decision['policy_version'],
                'payout_channel' => $decision['payout_channel'],
                'payout_limit_minor' => $decision['payout_limit_minor'],
                'cod_amount_minor' => $decision['cod_amount_minor'],
                'declared_value_minor' => $decision['declared_value_minor'],
                'delivery_cost_minor' => $context['delivery_cost_minor'],
                'estimated_cod_fee_minor' => $decision['estimated_cod_fee_minor'],
                'local_result' => $decision['local_result'],
                'financial_snapshot_sha256' => $context['financial_snapshot_sha256'],
                'request_sha256' => $requestHash,
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('checkout_cod_financial_preflights')->where('id', $id)->first()
            );
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('checkout_cod_financial_preflights')
            ->where('order_id', $orderId)->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed> */
    private function context(string $orderKey): array
    {
        $order = DB::table('orders')->where('order_key', $orderKey)
            ->lockForUpdate()->first(['id', 'payment_status']);

        if ($order === null || $order->payment_status !== 'pending') {
            throw new DomainException('COD order must be pending');
        }

        $orderId = (int) $order->id;
        $selection = $this->selections->findForOrder($orderId);
        $snapshot = $this->snapshots->findForOrder($orderId);
        $preparation = DB::table('checkout_order_preparations')
            ->where('order_id', $orderId)
            ->first(['id', 'expires_at', 'bonus_units_reserved']);
        $rate = DB::table('checkout_cod_measured_rates')
            ->where('order_id', $orderId)->first([
                'id', 'order_id', 'assessment_id', 'goods_due_minor',
                'snapshot_sha256', 'delivery_cost_minor', 'cod_fee_minor',
            ]);

        if ($selection === null || $snapshot === null || $preparation === null
            || $rate === null
            || ($selection['status'] ?? null) !== 'awaiting_carrier_verification'
            || ($selection['carrier_code'] ?? null) !== 'nova_poshta'
            || !in_array($selection['delivery_type'] ?? null, ['branch', 'parcel_locker'], true)
            || ($snapshot['currency_code'] ?? null) !== 'UAH'
            || (int) $preparation->bonus_units_reserved !== 0
            || CarbonImmutable::parse($preparation->expires_at)
                ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            || (int) $rate->order_id !== $orderId
            || (int) $rate->goods_due_minor !== (int) $snapshot['goods_payable_minor']
            || (int) $rate->goods_due_minor !== (int) $selection['goods_due_minor']
            || !hash_equals((string) $snapshot['sha256'], (string) $rate->snapshot_sha256)
            || !hash_equals((string) $snapshot['sha256'], (string) $selection['snapshot_sha256'])
        ) {
            throw new DomainException('COD financial context is unavailable');
        }

        $assessment = DB::table('checkout_cod_package_assessments')
            ->where('id', $rate->assessment_id)
            ->first(['order_id', 'cod_delivery_selection_id', 'local_result']);

        if ($assessment === null
            || (int) $assessment->order_id !== $orderId
            || (int) $assessment->cod_delivery_selection_id !== (int) $selection['id']
            || $assessment->local_result !== 'within_standard_limits'
        ) {
            throw new DomainException('COD package assessment is not acceptable');
        }

        // The previous rate may be historical; the stock hold must STILL exist.
        $reservations = DB::table('checkout_order_stock_reservations as s')
            ->join('inventory_reservations as r', 'r.id', '=', 's.inventory_reservation_id')
            ->where('s.checkout_preparation_id', $preparation->id)
            ->orderBy('s.inventory_reservation_id')
            ->get([
                's.quantity as line_quantity', 'r.order_id',
                'r.quantity as reserved_quantity', 'r.shipped_qty',
                'r.released_qty', 'r.expires_at',
            ]);

        if ($reservations->isEmpty()) {
            throw new DomainException('COD stock reservation is missing');
        }

        foreach ($reservations as $r) {
            if ((int) $r->order_id !== $orderId
                || (int) $r->line_quantity < 1
                || (int) $r->line_quantity !== (int) $r->reserved_quantity
                || (int) $r->shipped_qty !== 0
                || (int) $r->released_qty !== 0
                || $r->expires_at === null
                || CarbonImmutable::parse($r->expires_at)
                    ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ) {
                throw new DomainException('COD stock reservation is no longer active');
            }
        }

        return [
            'order_id' => $orderId,
            'measured_rate_id' => (int) $rate->id,
            'assessment_id' => (int) $rate->assessment_id,
            'selection_id' => (int) $selection['id'],
            'financial_snapshot_id' => (int) $snapshot['id'],
            'financial_snapshot_sha256' => (string) $snapshot['sha256'],
            'cod_amount_minor' => (int) $rate->goods_due_minor,
            'delivery_cost_minor' => (int) $rate->delivery_cost_minor,
            'estimated_cod_fee_minor' => $rate->cod_fee_minor === null
                ? null : (int) $rate->cod_fee_minor,
        ];
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'operation_key' => (string) $row->operation_key,
            'measured_rate_id' => (int) $row->measured_rate_id,
            'assessment_id' => (int) $row->assessment_id,
            'cod_delivery_selection_id' => (int) $row->cod_delivery_selection_id,
            'financial_snapshot_id' => (int) $row->financial_snapshot_id,
            'policy_version' => (string) $row->policy_version,
            'payout_channel' => (string) $row->payout_channel,
            'payout_limit_minor' => (int) $row->payout_limit_minor,
            'cod_amount_minor' => (int) $row->cod_amount_minor,
            'declared_value_minor' => (int) $row->declared_value_minor,
            'declared_value_differs_from_cod' =>
                (int) $row->declared_value_minor !== (int) $row->cod_amount_minor,
            'delivery_cost_minor' => (int) $row->delivery_cost_minor,
            'estimated_cod_fee_minor' => $row->estimated_cod_fee_minor === null
                ? null : (int) $row->estimated_cod_fee_minor,
            'local_result' => (string) $row->local_result,
            'financial_snapshot_sha256' => (string) $row->financial_snapshot_sha256,
            'declared_value_carrier_verified' => false,
            'payout_channel_contract_verified' => false,
            'cod_eligibility_confirmed' => false,
            'fee_finalized' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
