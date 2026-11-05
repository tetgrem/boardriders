<?php

namespace App\Services\Promotions;

use Illuminate\Support\Facades\DB;

class PromotionService
{
    /**
     * Перевіряє промокод за актуальними цінами
     * та залишками товарів.
     *
     * Не резервує товар і не списує
     * використання промокоду.
     *
     * @param array<int, array{variant_id:int, quantity:int}> $items
     * @return array<string, mixed>
     */
    public function preview(
        string $code,
        array $items,
        ?int $customerId = null,
        string $priceListCode = 'UA_RETAIL'
    ): array {

        // ========================================
        // 1. ПРАЙС-ЛИСТ
        // ========================================

        $priceList = DB::table('price_lists as pl')
            ->join(
                'currencies as cu',
                'cu.code',
                '=',
                'pl.currency_code'
            )
            ->where('pl.code', $priceListCode)
            ->where('pl.is_active', true)
            ->where('cu.is_active', true)
            ->first([
                'pl.id',
                'pl.currency_code',
            ]);

        if ($priceList === null) {
            return $this->invalid(
                'price_list_unavailable'
            );
        }

        $currency = $priceList->currency_code;

        $ids = array_column(
            $items,
            'variant_id'
        );

        // ========================================
        // 2. АКТУАЛЬНІ ЗАЛИШКИ
        // ========================================

        $stocks = DB::table('inventory_stocks')
            ->select('variant_id')
            ->selectRaw(
                'SUM(GREATEST(on_hand_qty - reserved_qty, 0)) AS available_qty'
            )
            ->groupBy('variant_id');

        // ========================================
        // 3. АКТУАЛЬНІ ЦІНИ SKU
        // ========================================

        $variants = DB::table('product_variants as v')

            ->join(
                'products as p',
                'p.id',
                '=',
                'v.product_id'
            )

            ->join(
                'variant_prices as vp',
                function ($join) use ($priceList) {
                    $join
                        ->on(
                            'vp.variant_id',
                            '=',
                            'v.id'
                        )
                        ->where(
                            'vp.price_list_id',
                            '=',
                            $priceList->id
                        );
                }
            )

            ->leftJoinSub(
                $stocks,
                'stock',
                function ($join) {
                    $join->on(
                        'stock.variant_id',
                        '=',
                        'v.id'
                    );
                }
            )

            ->whereIn('v.id', $ids)
            ->where('v.is_active', true)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')

            ->select(
                'v.id',
                'vp.price_minor',
                'stock.available_qty'
            )

            ->get()
            ->keyBy('id');

        // ========================================
        // 4. ПІДРАХУНОК СУМИ КОШИКА
        // ========================================

        $subtotal = 0;

        foreach ($items as $item) {

            $variant = $variants->get(
                $item['variant_id']
            );

            $quantity = (int) $item['quantity'];

            if ($variant === null) {
                return $this->invalid(
                    'cart_unavailable',
                    0,
                    $currency
                );
            }

            if (
                (int) $variant->available_qty < $quantity
            ) {
                return $this->invalid(
                    'insufficient_stock',
                    0,
                    $currency
                );
            }

            $unitPrice = (int) $variant->price_minor;

            // Захист від переповнення цілих чисел.
            if (
                $unitPrice > intdiv(
                    PHP_INT_MAX - $subtotal,
                    $quantity
                )
            ) {
                return $this->invalid(
                    'amount_too_large',
                    0,
                    $currency
                );
            }

            $subtotal += $unitPrice * $quantity;
        }

        if ($subtotal === 0) {
            return $this->invalid(
                'empty_subtotal',
                0,
                $currency
            );
        }

        // ========================================
        // 5. ПОШУК ПРОМОКОДУ
        // ========================================

        $normalizedCode = strtoupper(
            trim($code)
        );

        $now = now();

        $promotion = DB::table('promotion_codes as pc')

            ->join(
                'promotions as p',
                'p.id',
                '=',
                'pc.promotion_id'
            )

            ->where('pc.code', $normalizedCode)

            ->where('pc.is_active', true)
            ->where('p.is_active', true)

            ->where(
                'p.price_list_id',
                $priceList->id
            )

            // Перевірка дати початку.
            ->where(function ($query) use ($now) {
                $query
                    ->whereNull('p.starts_at')
                    ->orWhere(
                        'p.starts_at',
                        '<=',
                        $now
                    );
            })

            // Перевірка дати завершення.
            ->where(function ($query) use ($now) {
                $query
                    ->whereNull('p.ends_at')
                    ->orWhere(
                        'p.ends_at',
                        '>',
                        $now
                    );
            })

            ->first([
                'pc.id as code_id',
                'pc.usage_limit as code_usage_limit',

                'p.id as promotion_id',
                'p.type',
                'p.value',
                'p.min_subtotal_minor',
                'p.max_discount_minor',
                'p.usage_limit',
                'p.per_customer_limit',
            ]);

        if ($promotion === null) {
            return $this->invalid(
                'code_unavailable',
                $subtotal,
                $currency
            );
        }

        // ========================================
        // 6. МІНІМАЛЬНА СУМА ЗАМОВЛЕННЯ
        // ========================================

        if (
            $subtotal <
            (int) $promotion->min_subtotal_minor
        ) {
            return $this->invalid(
                'minimum_not_met',
                $subtotal,
                $currency
            );
        }

        // ========================================
        // 7. ЗАГАЛЬНИЙ ЛІМІТ АКЦІЇ
        // ========================================

        if ($promotion->usage_limit !== null) {

            $used = DB::table(
                'promotion_redemptions as r'
            )
                ->join(
                    'promotion_codes as pc',
                    'pc.id',
                    '=',
                    'r.promotion_code_id'
                )

                ->where(
                    'pc.promotion_id',
                    $promotion->promotion_id
                )

                ->count();

            if (
                $used >=
                (int) $promotion->usage_limit
            ) {
                return $this->invalid(
                    'promotion_limit_reached',
                    $subtotal,
                    $currency
                );
            }
        }

        // ========================================
        // 8. ЛІМІТ КОНКРЕТНОГО КОДУ
        // ========================================

        if (
            $promotion->code_usage_limit !== null
        ) {

            $used = DB::table(
                'promotion_redemptions'
            )
                ->where(
                    'promotion_code_id',
                    $promotion->code_id
                )
                ->count();

            if (
                $used >=
                (int) $promotion->code_usage_limit
            ) {
                return $this->invalid(
                    'code_limit_reached',
                    $subtotal,
                    $currency
                );
            }
        }

        // ========================================
        // 9. ЛІМІТ НА ОДНОГО ПОКУПЦЯ
        // ========================================

        if (
            $promotion->per_customer_limit !== null
        ) {

            // Поки немає авторизації —
            // не дозволяємо обійти персональний ліміт.
            if ($customerId === null) {
                return $this->invalid(
                    'login_required',
                    $subtotal,
                    $currency
                );
            }

            $used = DB::table(
                'promotion_redemptions as r'
            )
                ->join(
                    'promotion_codes as pc',
                    'pc.id',
                    '=',
                    'r.promotion_code_id'
                )

                ->where(
                    'pc.promotion_id',
                    $promotion->promotion_id
                )

                ->where(
                    'r.user_id',
                    $customerId
                )

                ->count();

            if (
                $used >=
                (int) $promotion->per_customer_limit
            ) {
                return $this->invalid(
                    'customer_limit_reached',
                    $subtotal,
                    $currency
                );
            }
        }

        // ========================================
        // 10. РОЗРАХУНОК ЗНИЖКИ
        // ========================================

        if ($promotion->type === 'percent') {

            // Наприклад:
            // 1000 базисних пунктів = 10%.
            $basisPoints = (int) $promotion->value;

            // Розрахунок без float.
            // Округлення до найближчої копійки.
            $discount =
                intdiv($subtotal, 10000) * $basisPoints
                +
                intdiv(
                    ($subtotal % 10000) * $basisPoints
                    + 5000,
                    10000
                );

        } else {

            // Фіксована знижка в копійках.
            $discount = (int) $promotion->value;
        }

        // Знижка не може перевищувати
        // вартість кошика.
        $discount = min(
            $subtotal,
            $discount
        );

        // ========================================
        // 11. МАКСИМАЛЬНА ЗНИЖКА
        // ========================================

        if (
            $promotion->max_discount_minor !== null
        ) {
            $discount = min(
                $discount,
                (int) $promotion->max_discount_minor
            );
        }

        if ($discount <= 0) {
            return $this->invalid(
                'no_discount',
                $subtotal,
                $currency
            );
        }

        // ========================================
        // 12. РЕЗУЛЬТАТ
        // ========================================

        return [
            'valid' => true,
            'reason' => null,

            'code' => $normalizedCode,
            'currency' => $currency,

            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,

            'total_minor' => $subtotal - $discount,
        ];
    }

    // ========================================
    // ВІДПОВІДЬ ПРИ ПОМИЛЦІ
    // ========================================

    /**
     * @return array<string, mixed>
     */
    private function invalid(
        string $reason,
        int $subtotal = 0,
        ?string $currency = null
    ): array {
        return [
            'valid' => false,
            'reason' => $reason,

            'code' => null,
            'currency' => $currency,

            'subtotal_minor' => $subtotal,
            'discount_minor' => 0,

            'total_minor' => $subtotal,
        ];
    }
}
