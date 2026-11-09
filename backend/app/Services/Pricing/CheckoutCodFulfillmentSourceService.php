<?php

namespace App\Services\Pricing;

use Illuminate\Support\Facades\DB;

/** Read-only binding of a reviewed physical pack to an active COD order. */
final class CheckoutCodFulfillmentSourceService
{
    public function __construct(
        private readonly CheckoutCodWarehousePackReadinessService $warehouse
    ) {
    }

    /** @return array<string,mixed> */
    public function inspect(string $orderKey): array
    {
        $source = $this->warehouse->inspect($orderKey);

        $result = [
            'order_id' => (int) $source['order_id'],
            'order_key' => (string) $source['order_key'],
            'intent_id' => $source['intent_id'],
            'pack_check_id' => null,
            'source_hmac' => null,
            'expected_units' => null,
            'pack_current' => false,
        ];

        if (($source['source_ready'] ?? false) !== true) {
            return $result;
        }

        $check = DB::table('checkout_cod_warehouse_pack_checks as c')
            ->join(
                'checkout_cod_warehouse_pack_reviews as r',
                'r.pack_check_id',
                '=',
                'c.id'
            )
            ->where('c.order_id', $source['order_id'])
            ->where('c.dispatch_plan_id', $source['plan_id'])
            ->where('r.decision', 'accepted')
            ->first([
                'c.id',
                'c.source_hmac',
                'c.counted_units',
            ]);

        if (
            $check === null
            || !hash_equals(
                (string) $source['source_hmac'],
                (string) $check->source_hmac
            )
            || (int) $source['total_units'] !== (int) $check->counted_units
        ) {
            return $result;
        }

        return array_merge($result, [
            'pack_check_id' => (int) $check->id,
            'source_hmac' => (string) $check->source_hmac,
            'expected_units' => (int) $check->counted_units,
            'pack_current' => true,
        ]);
    }
}
