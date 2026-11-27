<?php

declare(strict_types=1);

namespace App\Services\Pricing73;

use App\Services\Pricing\CheckoutOrderReturnSigner;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CampaignSelection731
{
    public function __construct(
        private readonly CheckoutOrderReturnSigner $signer
    ) {}

    public function collect(array $data): array
    {
        $list = DB::table('price_lists as pl')
            ->join('currencies as cu', 'cu.code', '=', 'pl.currency_code')
            ->where('pl.id', $data['price_list_id'])
            ->where('pl.type', 'retail')
            ->where('pl.is_active', true)
            ->where('cu.is_active', true)
            ->first([
                'pl.id',
                'pl.country_code',
                'pl.currency_code',
                'cu.minor_units',
            ]);

        if ($list === null) {
            throw new DomainException('Прайс-лист недоступен');
        }

        $scope = $data['scope_type'];

        $ids = array_values(array_unique(
            array_map('intval', $data['scope_ids'])
        ));

        $excluded = array_values(array_unique(
            array_map('intval', $data['excluded_variant_ids'])
        ));

        if ($scope === 'all' && $ids !== []) {
            throw new DomainException(
                'Для всего каталога не нужно выбирать отдельные объекты'
            );
        }

        if ($scope !== 'all' && $ids === []) {
            throw new DomainException(
                'Выберите хотя бы один объект акции'
            );
        }

        $tables = [
            'brand' => 'brands',
            'category' => 'categories',
            'product' => 'products',
            'variant' => 'product_variants',
        ];

        if ($scope !== 'all') {
            if (!isset($tables[$scope])
                || DB::table($tables[$scope])
                    ->whereIn('id', $ids)
                    ->count() !== count($ids)) {
                throw new DomainException(
                    'Один из объектов акции не существует'
                );
            }
        }

        if (DB::table('product_variants')
                ->whereIn('id', $excluded)
                ->count() !== count($excluded)) {
            throw new DomainException(
                'Исключаемый SKU не существует'
            );
        }

        $stock = DB::table('inventory_stocks as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('w.kind', 'owned')
            ->where('w.is_active', true)
            ->where('w.country_code', $list->country_code)
            ->groupBy('s.variant_id')
            ->select('s.variant_id')
            ->selectRaw(
                'SUM(s.on_hand_qty - s.reserved_qty) AS available_qty'
            );

        $query = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('variant_prices as vp', function ($join) use ($list): void {
                $join->on('vp.variant_id', '=', 'v.id')
                    ->where('vp.price_list_id', '=', $list->id);
            })
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_translations as tr', function ($join): void {
                $join->on('tr.product_id', '=', 'p.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->leftJoinSub($stock, 'stock', function ($join): void {
                $join->on('stock.variant_id', '=', 'v.id');
            })
            ->where('v.is_active', true)
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->where('vp.price_minor', '>', 0)
            ->select([
                'v.id',
                'v.sku',
                'v.product_id',
                'vp.price_minor',
                'vp.revision',
                'tr.name as product_name',
                'b.name as brand_name',
            ])
            ->selectRaw(
                'COALESCE(stock.available_qty, 0) AS available_qty'
            );

        switch ($scope) {
            case 'brand':
                $query->whereIn('p.brand_id', $ids)
                    ->where('b.is_active', true);
                break;

            case 'product':
                $query->whereIn('p.id', $ids);
                break;

            case 'variant':
                $query->whereIn('v.id', $ids);
                break;

            case 'category':
                $placeholders = implode(
                    ',',
                    array_fill(0, count($ids), '?')
                );

                $query->whereRaw(
                    <<<SQL
                        p.id IN (
                            WITH RECURSIVE category_tree AS (
                                SELECT id
                                FROM categories
                                WHERE id IN ({$placeholders})
                                  AND is_active = TRUE

                                UNION

                                SELECT c.id
                                FROM categories c
                                JOIN category_tree t
                                    ON c.parent_id = t.id
                                WHERE c.is_active = TRUE
                            )
                            SELECT pc.product_id
                            FROM product_categories pc
                            JOIN category_tree t
                                ON t.id = pc.category_id
                        )
                    SQL,
                    $ids
                );
                break;
        }

        if ($excluded !== []) {
            $query->whereNotIn('v.id', $excluded);
        }

        // Remaining stock is evaluated per SKU, not per product.
        if (isset($data['max_stock'])) {
            $query->whereRaw(
                'COALESCE(stock.available_qty, 0) BETWEEN 1 AND ?',
                [(int) $data['max_stock']]
            );
        }

        if (isset($data['unsold_days'])) {
            $days = (int) $data['unsold_days'];

            $query->whereRaw(
                'COALESCE(stock.available_qty, 0) > 0'
            );

            // Only actual inventory receipts are considered.
            // The SKU must have reached this market before the cutoff.
            $query->whereRaw(<<<'SQL'
                EXISTS (
                    SELECT 1
                    FROM inventory_movements m
                    JOIN inventory_stocks s
                        ON s.id = m.inventory_stock_id
                    JOIN warehouses w
                        ON w.id = s.warehouse_id
                    WHERE s.variant_id = v.id
                      AND w.kind = 'owned'
                      AND w.country_code = ?
                      AND m.type = 'receipt'
                      AND m.created_at <=
                          CURRENT_TIMESTAMP - (? * INTERVAL '1 day')
                )
            SQL, [$list->country_code, $days]);

            $query->whereRaw(<<<'SQL'
                NOT EXISTS (
                    SELECT 1
                    FROM inventory_movements m
                    JOIN inventory_stocks s
                        ON s.id = m.inventory_stock_id
                    JOIN warehouses w
                        ON w.id = s.warehouse_id
                    WHERE s.variant_id = v.id
                      AND w.kind = 'owned'
                      AND w.country_code = ?
                      AND m.type = 'sale'
                      AND m.created_at >
                          CURRENT_TIMESTAMP - (? * INTERVAL '1 day')
                )
            SQL, [$list->country_code, $days]);
        }

        $rows = $query->orderBy('v.id')
            ->limit(50001)
            ->get();

        if ($rows->count() > 50000) {
            throw new DomainException(
                'В одной акции допускается до 50000 SKU'
            );
        }

        $targets = [];
        $sample = [];
        $fingerprint = [];

        foreach ($rows as $row) {
            $base = (int) $row->price_minor;

            $price = $this->discountedPrice(
                $base,
                $data
            );

            if ($price >= $base) {
                continue;
            }

            $id = (int) $row->id;

            $targets[] = $id;

            $fingerprint[] = [
                'variant_id' => $id,
                'base_price_minor' => $base,
                'revision' => (int) $row->revision,
                'campaign_price_minor' => $price,
            ];

            if (count($sample) < 100) {
                $sample[] = [
                    'id' => $id,
                    'sku' => $row->sku,
                    'product_name' => $row->product_name,
                    'brand_name' => $row->brand_name,
                    'available_qty' => (int) $row->available_qty,
                    'base_price_minor' => $base,
                    'campaign_price_minor' => $price,
                ];
            }
        }

        $token = $this->signer->digest([
            'domain' => 'boardriders-campaign731-preview-v1',
            'selection' => $data,
            'variants' => $fingerprint,
        ]);

        return [
            'variant_ids' => $targets,
            'count' => count($targets),
            'sample' => $sample,
            'currency_code' => $list->currency_code,
            'minor_units' => (int) $list->minor_units,
            'preview_token' => $token,
        ];
    }

    private function discountedPrice(
        int $base,
        array $data
    ): int {
        $value = (int) $data['discount_value'];

        $discount = $data['discount_type'] === 'percent'
            ? intdiv($base, 10000) * $value
            + intdiv(($base % 10000) * $value, 10000)
            : $value;

        if (isset($data['max_discount_minor'])) {
            $discount = min(
                $discount,
                (int) $data['max_discount_minor']
            );
        }

        return max(1, $base - min($base, $discount));
    }
}
