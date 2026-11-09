<?php

namespace App\Services\Pricing;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pins historical COD expectations, without declaring a payment received.
 */
final class CheckoutCodSettlementCaseService
{
    public const OPEN_ABILITY = 'cod.settlement.open';

    /** @return array<string,mixed> */
    public function open(
        string $orderKey,
        string $caseKey
    ): array {
        $actor = $this->actor();

        if (!Str::isUuid($orderKey) || !Str::isUuid($caseKey)) {
            throw new InvalidArgumentException(
                'Invalid COD settlement UUID'
            );
        }

        $orderKey = strtolower($orderKey);
        $caseKey = strtolower($caseKey);

        return DB::transaction(function () use (
            $actor,
            $orderKey,
            $caseKey
        ): array {
            $order = DB::table('orders')
                ->where('order_key', $orderKey)
                ->lockForUpdate()
                ->first(['id', 'payment_status']);

            if ($order === null) {
                throw new DomainException('COD order not found');
            }

            $old = DB::table('checkout_cod_settlement_cases')
                ->where('order_id', $order->id)
                ->first();

            if ($old !== null) {
                $expected = $this->digest([
                    'cod-settlement-case-v1',
                    $orderKey,
                    $caseKey,
                    $actor,
                    (int) $old->preflight_id,
                    (int) $old->expected_cod_minor,
                    $old->expected_fee_minor === null
                        ? null
                        : (int) $old->expected_fee_minor,
                    (string) $old->financial_snapshot_sha256,
                ]);

                if (
                    (string) $old->case_key !== $caseKey
                    || (int) $old->opened_by !== $actor
                    || !hash_equals(
                        (string) $old->request_hmac,
                        $expected
                    )
                ) {
                    throw new DomainException(
                        'COD settlement case already exists'
                    );
                }

                return $this->present($old);
            }

            if ($order->payment_status !== 'pending') {
                throw new DomainException(
                    'Only pending COD orders can begin financial reconciliation'
                );
            }

            $source = DB::table('checkout_cod_financial_preflights as f')
                ->join(
                    'checkout_cod_requests as req',
                    'req.order_id',
                    '=',
                    'f.order_id'
                )
                ->join(
                    'checkout_payment_choices as choice',
                    'choice.order_id',
                    '=',
                    'f.order_id'
                )
                ->where('f.order_id', $order->id)
                ->first([
                    'f.id',
                    'f.cod_amount_minor',
                    'f.estimated_cod_fee_minor',
                    'f.financial_snapshot_sha256',
                    'f.local_result',
                    'req.goods_due_minor',
                    'req.currency_code',
                    'req.snapshot_sha256 as request_sha256',
                    'choice.payment_method',
                ]);

            if (
                $source === null
                || $source->local_result !== 'within_published_limit'
                || $source->payment_method !== 'cash_on_delivery'
                || $source->currency_code !== 'UAH'
                || (int) $source->cod_amount_minor
                !== (int) $source->goods_due_minor
                || !hash_equals(
                    (string) $source->financial_snapshot_sha256,
                    (string) $source->request_sha256
                )
            ) {
                throw new DomainException(
                    'COD financial evidence is incomplete'
                );
            }

            $fee = $source->estimated_cod_fee_minor === null
                ? null
                : (int) $source->estimated_cod_fee_minor;

            $hmac = $this->digest([
                'cod-settlement-case-v1',
                $orderKey,
                $caseKey,
                $actor,
                (int) $source->id,
                (int) $source->cod_amount_minor,
                $fee,
                (string) $source->financial_snapshot_sha256,
            ]);

            $id = DB::table('checkout_cod_settlement_cases')
                ->insertGetId([
                    'case_key' => $caseKey,
                    'order_id' => $order->id,
                    'preflight_id' => $source->id,
                    'expected_cod_minor' =>
                        $source->cod_amount_minor,
                    'expected_fee_minor' => $fee,
                    'currency_code' => 'UAH',
                    'financial_snapshot_sha256' =>
                        $source->financial_snapshot_sha256,
                    'opened_by' => $actor,
                    'request_hmac' => $hmac,
                    'created_at' => now(),
                ]);

            return $this->present(
                DB::table('checkout_cod_settlement_cases')
                    ->where('id', $id)
                    ->first()
            );
        }, 3);
    }

    private function actor(): int
    {
        $user = Auth::user();
        $id = $user?->getAuthIdentifier();

        if (
            (!is_int($id) && !(is_string($id) && ctype_digit($id)))
            || (int) $id <= 0
            || !Gate::forUser($user)->allows(self::OPEN_ABILITY)
        ) {
            throw new AuthorizationException(
                'Settlement case permission denied'
            );
        }

        return (int) $id;
    }

    private function digest(array $data): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new DomainException('APP_KEY missing');
        }

        return hash_hmac(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR),
            $key
        );
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'case_key' => (string) $row->case_key,
            'order_id' => (int) $row->order_id,
            'preflight_id' => (int) $row->preflight_id,
            'expected_cod_minor' => (int) $row->expected_cod_minor,
            'expected_fee_minor' => $row->expected_fee_minor === null
                ? null
                : (int) $row->expected_fee_minor,
            'currency_code' => (string) $row->currency_code,
            'external_source_verified' => false,
            'funds_received_verified' => false,
            'cod_paid' => false,
        ];
    }
}
