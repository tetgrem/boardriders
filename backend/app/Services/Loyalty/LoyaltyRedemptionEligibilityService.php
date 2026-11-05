<?php

namespace App\Services\Loyalty;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Внутрішнє визначення товарів, дозволених до бонусного списання.
 * Лише активна версія правил ринку; якщо allow немає — deny.
 * Пріоритет вище перемагає; при рівності deny перемагає allow.
 * Не приймає список дозволених товарів від клієнта.
 */
final class LoyaltyRedemptionEligibilityService
{
    /**
     * @param array<int,int> $variantIds
     * @return array{
     *     policy_id:?int,
     *     rule_version:?string,
     *     approved_variant_ids:array<int,int>,
     *     matched_rule_ids:array<int,int>
     * }
     */
    public function resolve(int $marketId, array $variantIds): array
    {
        if ($marketId <= 0 || count($variantIds) > 200) {
            throw new InvalidArgumentException('Invalid market or cart size');
        }

        $seen = [];

        foreach ($variantIds as $id) {
            if (!is_int($id) || $id <= 0 || isset($seen[$id])) {
                throw new InvalidArgumentException('Invalid or duplicate variant');
            }

            $seen[$id] = true;
        }

        $result = [
            'policy_id' => null,
            'rule_version' => null,
            'approved_variant_ids' => [],
            'matched_rule_ids' => [],
        ];

        $market = DB::table('markets')
            ->where('id', $marketId)
            ->where('is_active', true)
            ->first(['currency_code']);

        if ($market === null) {
            return $result;
        }

        $policy = DB::table('loyalty_redemption_policies')
            ->where('market_id', $marketId)
            ->where('effective_from', '<=', CarbonImmutable::now('UTC'))
            ->orderByDesc('effective_from')
            ->first(['id', 'currency_code', 'rule_version', 'is_enabled']);

        if ($policy === null) {
            return $result;
        }

        $result['policy_id'] = (int) $policy->id;
        $result['rule_version'] = $policy->rule_version;

        if (
            $variantIds === []
            || $policy->currency_code !== $market->currency_code
            || !in_array(
                $policy->is_enabled,
                [true, 1, '1', 't', 'true'],
                true
            )
        ) {
            return $result;
        }

        $rules = DB::table('loyalty_redemption_eligibility_rules')
            ->where('policy_id', $policy->id)
            ->get([
                'id',
                'scope_type',
                'decision',
                'priority',
                'brand_id',
                'category_id',
                'product_id',
                'variant_id',
            ]);

        if ($rules->isEmpty()) {
            return $result;
        }

        $variants = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->whereIn('v.id', $variantIds)
            ->where('v.is_active', true)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->get([
                'v.id as variant_id',
                'p.id as product_id',
                'p.brand_id',
                'b.is_active as brand_active',
            ])
            ->keyBy('variant_id');

        if ($variants->isEmpty()) {
            return $result;
        }

        $productIds = $variants
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();

        $categorySets = $this->activeCategoryAncestry($productIds);

        foreach ($variantIds as $id) {
            $variant = $variants->get($id);

            if ($variant === null) {
                continue;
            }

            // Не дозволяємо бонуси на товари
            // з деактивованим брендом.
            if (
                $variant->brand_id !== null
                && !in_array(
                    $variant->brand_active,
                    [true, 1, '1', 't', 'true'],
                    true
                )
            ) {
                continue;
            }

            $best = null;

            foreach ($rules as $rule) {
                $matches = match ($rule->scope_type) {
                    'all' => true,

                    'brand' => $variant->brand_id !== null
                        && (int) $rule->brand_id
                        === (int) $variant->brand_id,

                    'category' => isset(
                        $categorySets[(int) $variant->product_id]
                        [(int) $rule->category_id]
                    ),

                    'product' => (int) $rule->product_id
                        === (int) $variant->product_id,

                    'variant' => (int) $rule->variant_id
                        === (int) $variant->variant_id,

                    default => false,
                };

                if (!$matches) {
                    continue;
                }

                // Більший пріоритет перемагає.
                // При рівності deny перемагає allow.
                // При повній рівності вибираємо менший ID
                // для стабільного аудиту.
                if (
                    $best === null
                    || (int) $rule->priority > (int) $best->priority
                    || (
                        (int) $rule->priority === (int) $best->priority
                        && $rule->decision === 'deny'
                        && $best->decision !== 'deny'
                    )
                    || (
                        (int) $rule->priority === (int) $best->priority
                        && $rule->decision === $best->decision
                        && (int) $rule->id < (int) $best->id
                    )
                ) {
                    $best = $rule;
                }
            }

            if ($best !== null) {
                $result['matched_rule_ids'][$id] = (int) $best->id;

                if ($best->decision === 'allow') {
                    $result['approved_variant_ids'][] = $id;
                }
            }
        }

        return $result;
    }

    /**
     * Активні категорії товарів та їхні активні батьки.
     * Виключає цикли в дереві категорій PostgreSQL.
     *
     * @param array<int,int> $productIds
     * @return array<int,array<int,bool>>
     */
    private function activeCategoryAncestry(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($productIds), '?')
        );

        $rows = DB::select(<<<SQL
            WITH RECURSIVE ancestry AS (
                SELECT
                    pc.product_id,
                    c.id AS category_id,
                    c.parent_id,
                    ARRAY[c.id]::bigint[] AS visited
                FROM product_categories pc
                JOIN categories c ON c.id = pc.category_id
                WHERE pc.product_id IN ({$placeholders})
                    AND c.is_active = TRUE

                UNION ALL

                SELECT
                    a.product_id,
                    p.id,
                    p.parent_id,
                    a.visited || p.id
                FROM ancestry a
                JOIN categories p ON p.id = a.parent_id
                WHERE p.is_active = TRUE
                    AND NOT (p.id = ANY(a.visited))
            )
            SELECT DISTINCT product_id, category_id
            FROM ancestry
        SQL, $productIds);

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->product_id]
            [(int) $row->category_id] = true;
        }

        return $result;
    }
}
