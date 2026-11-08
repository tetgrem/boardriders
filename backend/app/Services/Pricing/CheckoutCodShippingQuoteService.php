<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class CheckoutCodShippingQuoteService
{
    public function __construct(
        private readonly CheckoutCodDeliverySelectionService $selections,
        private readonly CheckoutCodRecipientService $recipients,
        private readonly OrderFinancialSnapshotService $snapshots,
        private readonly NovaPoshtaDeliveryQuoteService $providerQuotes,
    ) {
    }

    /** @return array<string, mixed> */
    public function quote(
        string $orderKey,
        string $quoteKey,
        int $weightGrams,
        int $seatsAmount = 1,
    ): array {
        if (!Str::isUuid($orderKey) || !Str::isUuid($quoteKey)) {
            throw new InvalidArgumentException('Invalid COD quote UUID');
        }

        if (
            $weightGrams < 1
            || $weightGrams > 10000000
            || $seatsAmount < 1
            || $seatsAmount > 20
        ) {
            throw new InvalidArgumentException(
                'Invalid COD parcel dimensions'
            );
        }

        $orderKey = strtolower($orderKey);
        $quoteKey = strtolower($quoteKey);

        $context = DB::transaction(
            fn (): array => $this->context($orderKey),
            3
        );

        $fingerprint = $this->fingerprint(
            $context,
            $weightGrams,
            $seatsAmount
        );

        $existing = DB::table('checkout_cod_shipping_quotes')
            ->where('order_id', $context['order_id'])
            ->first();

        if ($existing !== null) {
            return $this->checkExisting(
                $existing,
                $quoteKey,
                $fingerprint,
                $orderKey
            );
        }

        // External HTTP call takes place WITHOUT holding order row locks.
        $quote = $this->providerQuotes->estimate(
            $context['city_ref'],
            $context['warehouse_ref'],
            $context['delivery_type'],
            $weightGrams,
            $context['goods_due_minor'],
            $context['goods_due_minor'],
            $seatsAmount,
        );

        if (
            ($quote['estimated_only'] ?? null) !== true
            || ($quote['delivery_fee_finalized'] ?? null) !== false
            || ($quote['cod_eligibility_confirmed'] ?? null) !== false
            || ($quote['currency_code'] ?? null) !== 'UAH'
            || ($quote['city_ref'] ?? null) !== $context['city_ref']
            || ($quote['warehouse_ref'] ?? null) !== $context['warehouse_ref']
            || ($quote['warehouse_type'] ?? null) !==
            $context['delivery_type']
            || !is_int($quote['delivery_cost_minor'] ?? null)
            || $quote['delivery_cost_minor'] < 0
            || $quote['delivery_cost_minor'] > 100000000
            || !is_bool($quote['cod_fee_available'] ?? null)
            || (
                $quote['cod_fee_available'] !==
                is_int($quote['cod_transfer_fee_minor'] ?? null)
            )
            || (
                is_int($quote['cod_transfer_fee_minor'] ?? null)
                && (
                    $quote['cod_transfer_fee_minor'] < 0
                    || $quote['cod_transfer_fee_minor'] > 100000000
                )
            )
        ) {
            throw new RuntimeException('Invalid advisory delivery quote');
        }

        return DB::transaction(function () use (
            $orderKey,
            $quoteKey,
            $weightGrams,
            $seatsAmount,
            $context,
            $fingerprint,
            $quote,
        ): array {
            // Recheck order after provider request.
            $current = $this->context($orderKey);

            if ($current !== $context) {
                throw new DomainException(
                    'COD checkout changed during quote'
                );
            }

            $existing = DB::table('checkout_cod_shipping_quotes')
                ->where('order_id', $current['order_id'])
                ->first();

            if ($existing !== null) {
                return $this->checkExisting(
                    $existing,
                    $quoteKey,
                    $fingerprint,
                    $orderKey
                );
            }

            if (
                DB::table('checkout_cod_shipping_quotes')
                    ->where('quote_key', $quoteKey)
                    ->exists()
            ) {
                throw new DomainException('COD quote key already used');
            }

            $id = DB::table('checkout_cod_shipping_quotes')->insertGetId([
                'quote_key' => $quoteKey,
                'order_id' => $current['order_id'],
                'recipient_id' => $current['recipient_id'],
                'cod_delivery_selection_id' => $current['selection_id'],
                'request_hmac' => $fingerprint,
                'weight_grams' => $weightGrams,
                'seats_amount' => $seatsAmount,
                'goods_due_minor' => $current['goods_due_minor'],
                'currency_code' => 'UAH',
                'snapshot_sha256' => $current['snapshot_sha256'],
                'delivery_cost_minor' => $quote['delivery_cost_minor'],
                'cod_transfer_fee_minor' => $quote['cod_transfer_fee_minor'],
                'cod_fee_available' => $quote['cod_fee_available'],
                'created_at' => now(),
            ]);

            return $this->present(
                DB::table('checkout_cod_shipping_quotes')
                    ->where('id', $id)
                    ->first(),
                $orderKey
            );
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('checkout_cod_shipping_quotes as q')
            ->join('orders as o', 'o.id', '=', 'q.order_id')
            ->where('q.order_id', $orderId)
            ->first(['q.*', 'o.order_key']);

        return $row === null
            ? null
            : $this->present($row, (string) $row->order_key);
    }

    /** @return array<string, mixed> */
    private function context(string $orderKey): array
    {
        $order = DB::table('orders')
            ->where('order_key', $orderKey)
            ->lockForUpdate()
            ->first(['id', 'payment_status']);

        if ($order === null || $order->payment_status !== 'pending') {
            throw new DomainException('COD order is not pending');
        }

        $orderId = (int) $order->id;
        $selection = $this->selections->findForOrder($orderId);
        $recipient = $this->recipients->findForOrder($orderId);
        $snapshot = $this->snapshots->findForOrder($orderId);

        if (
            $selection === null
            || $recipient === null
            || $snapshot === null
            || ($selection['status'] ?? null) !==
            'awaiting_carrier_verification'
            || ($selection['carrier_code'] ?? null) !== 'nova_poshta'
            || !in_array(
                $selection['delivery_type'] ?? null,
                ['branch', 'parcel_locker'],
                true
            )
            || $recipient['cod_delivery_selection_id'] !==
            (int) $selection['id']
            || $snapshot['currency_code'] !== 'UAH'
            || $snapshot['goods_payable_minor'] !==
            (int) $selection['goods_due_minor']
            || !hash_equals(
                $snapshot['sha256'],
                (string) $selection['snapshot_sha256']
            )
            || $snapshot['goods_payable_minor'] < 100
            || $snapshot['goods_payable_minor'] > 100000000
            || CarbonImmutable::parse(
                (string) $recipient['checkout_expires_at']
            )->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
        ) {
            throw new DomainException(
                'COD recipient or financial context unavailable'
            );
        }

        $preparation = DB::table('checkout_order_preparations')
            ->where('order_id', $orderId)
            ->first(['id', 'bonus_units_reserved']);

        if (
            $preparation === null
            || (int) $preparation->bonus_units_reserved > 0
        ) {
            throw new DomainException(
                'COD bonus collection is not supported'
            );
        }

        $reservations = DB::table('checkout_order_stock_reservations as c')
            ->join(
                'inventory_reservations as r',
                'r.id',
                '=',
                'c.inventory_reservation_id'
            )
            ->where('c.checkout_preparation_id', $preparation->id)
            ->get([
                'c.quantity as line_quantity',
                'r.quantity as reserved_quantity',
                'r.shipped_qty',
                'r.released_qty',
                'r.expires_at',
            ]);

        if ($reservations->isEmpty()) {
            throw new DomainException(
                'COD inventory reservation is missing'
            );
        }

        foreach ($reservations as $r) {
            if (
                (int) $r->line_quantity !== (int) $r->reserved_quantity
                || (int) $r->shipped_qty !== 0
                || (int) $r->released_qty !== 0
                || $r->expires_at === null
                || CarbonImmutable::parse(
                    (string) $r->expires_at
                )->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            ) {
                throw new DomainException(
                    'COD inventory reservation is inactive'
                );
            }
        }

        return [
            'order_id' => $orderId,
            'selection_id' => (int) $selection['id'],
            'recipient_id' => (int) $recipient['id'],
            'city_ref' => strtolower($recipient['city_ref']),
            'warehouse_ref' => strtolower($selection['destination_ref']),
            'delivery_type' => $selection['delivery_type'],
            'goods_due_minor' =>
                (int) $snapshot['goods_payable_minor'],
            'snapshot_sha256' => $snapshot['sha256'],
        ];
    }

    /** @param array<string, mixed> $context */
    private function fingerprint(
        array $context,
        int $weightGrams,
        int $seatsAmount
    ): string {
        $key = config('app.key');

        if (!is_string($key) || $key === '') {
            throw new DomainException(
                'Application encryption key is missing'
            );
        }

        return hash_hmac(
            'sha256',
            json_encode([
                $context,
                $weightGrams,
                $seatsAmount,
            ], JSON_THROW_ON_ERROR),
            $key
        );
    }

    /** @return array<string, mixed> */
    private function checkExisting(
        object $row,
        string $quoteKey,
        string $fingerprint,
        string $orderKey,
    ): array {
        if (
            $row->quote_key !== $quoteKey
            || !hash_equals(
                (string) $row->request_hmac,
                $fingerprint
            )
        ) {
            throw new DomainException(
                'COD shipping quote is immutable'
            );
        }

        return $this->present($row, $orderKey);
    }

    /** @return array<string, mixed> */
    private function present(object $row, string $orderKey): array
    {
        return [
            'id' => (int) $row->id,
            'quote_key' => (string) $row->quote_key,
            'order_id' => (int) $row->order_id,
            'order_key' => $orderKey,
            'recipient_id' => (int) $row->recipient_id,
            'cod_delivery_selection_id' =>
                (int) $row->cod_delivery_selection_id,
            'weight_grams' => (int) $row->weight_grams,
            'seats_amount' => (int) $row->seats_amount,
            'goods_due_minor' => (int) $row->goods_due_minor,
            'currency_code' => (string) $row->currency_code,
            'snapshot_sha256' => (string) $row->snapshot_sha256,
            'delivery_cost_minor' => (int) $row->delivery_cost_minor,
            'cod_transfer_fee_minor' =>
                $row->cod_transfer_fee_minor === null
                    ? null
                    : (int) $row->cod_transfer_fee_minor,
            'cod_fee_available' => (bool) $row->cod_fee_available,
            'status' => (bool) $row->cod_fee_available
                ? 'estimated'
                : 'awaiting_cod_fee',
            'estimated_only' => true,
            'cod_eligibility_confirmed' => false,
            'delivery_fee_finalized' => false,
            'ready_for_dispatch' => false,
            'created_at' => (string) $row->created_at,
        ];
    }
}
