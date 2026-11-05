<?php

namespace App\Services\Pricing;

use App\Services\Loyalty\LoyaltyRedemptionQuoteService;
use App\Services\Loyalty\LoyaltyRedemptionEligibilityService;
use App\Services\Promotions\PromotionService;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Єдиний серверний preview ціни, промокоду і бонусів.
 *
 * Без створення замовлення, резервів або бухгалтерських записів.
 *
 * Важливо:
 * - priceListCode визначає довірений серверний шар;
 * - verifiedUserId береться з автентифікації;
 * - eligibility товарів визначається тільки на сервері;
 * - ціни та залишки читаються з бази даних;
 * - при підтвердженні замовлення розрахунок повторюється.
 */
final class CheckoutPricingPreviewService
{
    public function __construct(
        private readonly PromotionService $promotions,
        private readonly LoyaltyRedemptionQuoteService $loyalty,
        private readonly LoyaltyRedemptionEligibilityService $eligibility,
        private readonly CheckoutAllocationService $allocations,
    ) {
    }

    /**
     * @param array<int, array{variant_id:int,quantity:int}> $items
     * @return array<string,mixed>
     */
    public function preview(
        array $items,
        string $marketCode,
        string $priceListCode,
        ?string $promotionCode = null,
        ?int $verifiedUserId = null,
        ?int $requestedBonusUnits = null,
    ): array {

        // ========================================
        // 1. ПЕРЕВІРКА ВХІДНИХ ДАНИХ
        // ========================================

        if ($items === [] || count($items) > 200) {
            throw new InvalidArgumentException(
                'Invalid number of cart items'
            );
        }

        if (
            $verifiedUserId !== null
            && $verifiedUserId <= 0
        ) {
            throw new InvalidArgumentException(
                'Invalid verified user'
            );
        }

        if (
            $requestedBonusUnits !== null
            && $requestedBonusUnits < 0
        ) {
            throw new InvalidArgumentException(
                'Invalid requested bonus units'
            );
        }

        $quantityById = [];

        foreach ($items as $item) {
            if (
                !is_array($item)
                || !isset(
                    $item['variant_id'],
                    $item['quantity']
                )
                || !is_int($item['variant_id'])
                || $item['variant_id'] <= 0
                || !is_int($item['quantity'])
                || $item['quantity'] < 1
                || $item['quantity'] > 99
                || isset($quantityById[$item['variant_id']])
            ) {
                throw new InvalidArgumentException(
                    'Invalid or duplicate cart item'
                );
            }

            $quantityById[$item['variant_id']] =
                $item['quantity'];
        }

        // ========================================
        // 2. ПІДГОТОВКА BONUS ELIGIBILITY
        // ========================================

        // Дозволені SKU визначаються тільки
        // серверними правилами.
        //
        // Для гостя списання бонусів недоступне.
        //
        // Ніяких approvedLoyaltyVariantIds
        // із браузера більше не приймаємо.

        $approved = [];
        $eligibility = null;

        // ========================================
        // 3. ПЕРЕВІРКА РИНКУ ТА ПРАЙС-ЛИСТА
        // ========================================

        $market = DB::table('markets')
            ->where('code', $marketCode)
            ->where('is_active', true)
            ->first([
                'id',
                'country_code',
                'currency_code',
            ]);

        $priceList = DB::table('price_lists as pl')
            ->join(
                'currencies as c',
                'c.code',
                '=',
                'pl.currency_code'
            )
            ->where('pl.code', $priceListCode)
            ->where('pl.type', 'retail')
            ->where('pl.is_active', true)
            ->where('c.is_active', true)
            ->first([
                'pl.id',
                'pl.country_code',
                'pl.currency_code',
            ]);

        if (
            $market === null
            || $priceList === null
            || $market->country_code
            !== $priceList->country_code
            || $market->currency_code
            !== $priceList->currency_code
        ) {
            throw new DomainException(
                'Market and retail price list are not compatible'
            );
        }

        // ========================================
        // 4. АВТОМАТИЧНА ПЕРЕВІРКА
        //    ДОПУСКУ ТОВАРІВ ДО БОНУСІВ
        // ========================================

        // Список товарів, на які дозволено
        // витрачати бонуси, отримуємо з PostgreSQL.
        //
        // Правила можуть стосуватися:
        // - всього ринку;
        // - бренду;
        // - категорії;
        // - товару;
        // - конкретного SKU.
        //
        // Якщо дозволяючого правила немає,
        // бонуси для товару недоступні.

        if ($verifiedUserId !== null) {
            $eligibility = $this->eligibility->resolve(
                (int) $market->id,
                array_keys($quantityById)
            );

            $approved = array_fill_keys(
                $eligibility['approved_variant_ids'],
                true
            );
        }

        // ========================================
        // 5. АКТУАЛЬНІ ЗАЛИШКИ
        // ========================================

        $stock = DB::table('inventory_stocks')
            ->select('variant_id')
            ->selectRaw(
                'SUM(GREATEST(on_hand_qty - reserved_qty, 0)) AS available_qty'
            )
            ->groupBy('variant_id');

        // ========================================
        // 6. АКТУАЛЬНІ ЦІНИ SKU
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
                $stock,
                's',
                function ($join) {
                    $join->on(
                        's.variant_id',
                        '=',
                        'v.id'
                    );
                }
            )

            ->whereIn(
                'v.id',
                array_keys($quantityById)
            )

            ->where('v.is_active', true)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')

            ->select(
                'v.id',
                'vp.price_minor',
                'vp.revision',
                's.available_qty'
            )

            ->get()
            ->keyBy('id');

        // ========================================
        // 7. ФОРМУВАННЯ ПОЗИЦІЙ КОШИКА
        // ========================================

        $lines = [];
        $revisions = [];

        foreach ($items as $item) {
            $id = $item['variant_id'];

            $variant = $variants->get($id);

            if ($variant === null) {
                throw new DomainException(
                    'Cart contains unavailable product'
                );
            }

            if (
                (int) $variant->available_qty
                < $item['quantity']
            ) {
                throw new DomainException(
                    'Insufficient cart stock'
                );
            }

            $price = (int) $variant->price_minor;

            if ($price < 0) {
                throw new DomainException(
                    'Invalid variant price'
                );
            }

            $lines[] = [
                'variant_id' => $id,

                'quantity' => $item['quantity'],

                'unit_price_minor' => $price,

                // Поточний PromotionService застосовує
                // промокод до всього кошика.
                'promotion_eligible' => true,

                // Бонуси — тільки за серверними
                // правилами допуску товарів.
                'loyalty_eligible' =>
                    isset($approved[$id]),
            ];

            $revisions[$id] =
                (int) $variant->revision;
        }

        // ========================================
        // 8. ПОЧАТКОВА ВАРТІСТЬ КОШИКА
        // ========================================

        $base = $this->allocations->allocate(
            $lines,
            0,
            0
        );

        $subtotal = $base['subtotal_minor'];

        // ========================================
        // 9. ПЕРЕВІРКА ПРОМОКОДУ
        // ========================================

        $promotion = null;
        $promotionDiscount = 0;

        if ($promotionCode !== null) {
            $promotionCode = trim($promotionCode);

            if (
                !preg_match(
                    '/^[a-zA-Z0-9][a-zA-Z0-9_-]{2,39}$/D',
                    $promotionCode
                )
            ) {
                throw new InvalidArgumentException(
                    'Invalid promotion code'
                );
            }

            $promotion = $this->promotions->preview(
                $promotionCode,
                $items,
                $verifiedUserId,
                $priceListCode
            );

            // PromotionService повторно читає
            // ціни та залишки з PostgreSQL.
            //
            // Не змішуємо розрахунки, якщо
            // сума або валюта вже змінилися.

            if (
                ($promotion['currency'] ?? null)
                !== $priceList->currency_code

                || ($promotion['subtotal_minor'] ?? null)
                !== $subtotal
            ) {
                throw new DomainException(
                    'Cart changed while calculating promotion; retry preview'
                );
            }

            if (
                ($promotion['valid'] ?? false)
                === true
            ) {
                $promotionDiscount =
                    (int) $promotion['discount_minor'];
            }
        }

        // ========================================
        // 10. РОЗПОДІЛ ПРОМОЗНИЖКИ
        // ========================================

        $afterPromotion = $this->allocations->allocate(
            $lines,
            $promotionDiscount,
            0
        );

        // Сума лише тих товарів, на які
        // дозволено витрачати бонуси,
        // ПІСЛЯ застосування промокоду.

        $eligibleMinor =
            $afterPromotion[
            'eligible_goods_after_promotions_minor'
            ];

        // ========================================
        // 11. БОНУСНИЙ РОЗРАХУНОК
        // ========================================

        $loyalty = [
            'reason' => 'login_required',
            'requested_units' => $requestedBonusUnits,

            'applied_units' => 0,
            'discount_minor' => 0,

            'max_redeemable_units' => 0,
            'max_discount_minor' => 0,
        ];

        if ($verifiedUserId !== null) {

            $loyalty =
                $this->loyalty->quoteForVerifiedUser(
                    $verifiedUserId,
                    $marketCode,
                    $priceList->currency_code,
                    $eligibleMinor,
                    $requestedBonusUnits
                );

            // ------------------------------------
            // 11.1. ПЕРЕВІРКА СУМ ТА РИНКУ
            // ------------------------------------

            if (
                $loyalty === null

                || $loyalty['market_code']
                !== $marketCode

                || $loyalty['currency_code']
                !== $priceList->currency_code

                || $loyalty['eligible_goods_minor']
                !== $eligibleMinor
            ) {
                throw new DomainException(
                    'Loyalty quote does not match current cart'
                );
            }

            // ------------------------------------
            // 11.2. ЗВІРКА ВЕРСІЙ ПРАВИЛ
            // ------------------------------------

            // Eligibility та курс списання
            // повинні походити з однієї
            // версії бонусної політики.
            //
            // Якщо версія змінилася між
            // двома запитами до PostgreSQL,
            // не змішуємо результати.

            if (
                $eligibility['policy_id']
                !== (
                    $loyalty['policy']['id']
                    ?? null
                )

                || $eligibility['rule_version']
                !== (
                    $loyalty['policy']['rule_version']
                    ?? null
                )
            ) {
                throw new DomainException(
                    'Loyalty policy changed during preview; retry calculation'
                );
            }
        }

        // ========================================
        // 12. ОСТАТОЧНИЙ РОЗПОДІЛ
        // ========================================

        $loyaltyDiscount = (int) (
            $loyalty['discount_minor'] ?? 0
        );

        $final = $this->allocations->allocate(
            $lines,
            $promotionDiscount,
            $loyaltyDiscount
        );

        // ========================================
        // 13. РЕЗУЛЬТАТ PREVIEW
        // ========================================

        return [
            'market_code' => $marketCode,

            'price_list_code' => $priceListCode,

            'currency_code' =>
                $priceList->currency_code,

            'subtotal_minor' =>
                $final['subtotal_minor'],

            'promotion_discount_minor' =>
                $promotionDiscount,

            'loyalty_discount_minor' =>
                $loyaltyDiscount,

            'goods_payable_minor' =>
                $final['goods_payable_minor'],

            'eligible_goods_after_promotions_minor' =>
                $eligibleMinor,

            'lines' => $final['lines'],

            'price_revisions' => $revisions,

            'promotion' => $promotion,

            'loyalty' => $loyalty,

            // Snapshot серверного рішення про те,
            // які товари допущено до бонусів.
            'loyalty_eligibility' => $eligibility,

            // Цей метод не створює замовлення,
            // не резервує та не списує бонуси.
            'informational_only' => true,
        ];
    }
}
