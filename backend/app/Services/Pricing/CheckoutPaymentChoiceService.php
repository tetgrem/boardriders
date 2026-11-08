<?php

namespace App\Services\Pricing;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Internal checkout payment method selection.
 * Does not initiate a charge, capture funds or mark COD as paid.
 * Merchant authorization and browser wallet support belong to the API/UI layer.
 */
final class CheckoutPaymentChoiceService
{
    /** @return array<string,mixed> */
    public function choose(
        string $orderKey,
        string $paymentMethod,
        ?string $providerCode = null,
    ): array {
        $orderKey = strtolower(trim($orderKey));
        $paymentMethod = strtolower(trim($paymentMethod));
        $providerCode = $providerCode === null
            ? null : strtolower(trim($providerCode));

        if (!Str::isUuid($orderKey)) {
            throw new InvalidArgumentException('Invalid order UUID');
        }

        $isOnline = in_array($paymentMethod, [
            'card', 'apple_pay', 'google_pay',
        ], true);

        if (
            !(
                $isOnline
                && in_array($providerCode, ['mono', 'liqpay'], true)
            )
            && !(
                $paymentMethod === 'cash_on_delivery'
                && $providerCode === null
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid payment method/provider combination'
            );
        }

        return DB::transaction(function () use (
            $orderKey, $paymentMethod, $providerCode, $isOnline
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null) {
                throw new DomainException('Order not found');
            }

            $existing = DB::table('checkout_payment_choices')
                ->where('order_id', $order->id)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->payment_method !== $paymentMethod
                    || $existing->provider_code !== $providerCode
                ) {
                    throw new DomainException(
                        'Order already has another payment choice'
                    );
                }

                return $this->present($existing, $orderKey);
            }

            $preparation = DB::table('checkout_order_preparations')
                ->where('order_id', $order->id)
                ->first(['expires_at']);

            if (
                $order->payment_status !== 'pending'
                || $preparation === null
                || CarbonImmutable::parse($preparation->expires_at)
                    ->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                || DB::table('checkout_payment_attempts')
                    ->where('order_id', $order->id)->exists()
                || DB::table('checkout_payment_capture_receipts')
                    ->where('order_id', $order->id)->exists()
            ) {
                throw new DomainException(
                    'Cannot select payment method for this checkout'
                );
            }

            $id = (int) DB::table('checkout_payment_choices')
                ->insertGetId([
                    'order_id' => $order->id,
                    'payment_method' => $paymentMethod,
                    'provider_code' => $providerCode,
                ]);

            $row = DB::table('checkout_payment_choices')
                ->where('id', $id)
                ->first();

            if ($row === null) {
                throw new DomainException(
                    'Payment choice was not saved'
                );
            }

            return $this->present($row, $orderKey);
        }, 3);
    }

    /** @return array<string,mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('Invalid order ID');
        }

        $row = DB::table('checkout_payment_choices')
            ->where('order_id', $orderId)
            ->first();

        if ($row === null) {
            return null;
        }

        $orderKey = DB::table('orders')
            ->where('id', $orderId)->value('order_key');

        if (!is_string($orderKey)) {
            throw new DomainException(
                'Order for payment choice not found'
            );
        }

        return $this->present($row, $orderKey);
    }

    /** @return array<string,mixed> */
    private function present(object $row, string $orderKey): array
    {
        return [
            'id' => (int) $row->id,
            'order_id' => (int) $row->order_id,
            'order_key' => $orderKey,
            'payment_method' => (string) $row->payment_method,
            'provider_code' => $row->provider_code === null
                ? null : (string) $row->provider_code,
            'requires_online_capture' =>
                $row->payment_method !== 'cash_on_delivery',
            'status' => 'selected',
            'created_at' => (string) $row->created_at,
        ];
    }
}
