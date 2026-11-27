<?php

declare(strict_types=1);

namespace App\Services\Pricing73;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Pricing73Read
{
    public function __construct(
        private readonly Pricing73Audit $audit
    ) {}

    public function overview(): array
    {
        $this->audit->actor();

        return [
            'price_lists' => DB::table('price_lists as pl')
                ->join('currencies as c', 'c.code', '=', 'pl.currency_code')
                ->where('pl.type', 'retail')
                ->where('pl.is_active', true)
                ->where('c.is_active', true)
                ->orderBy('pl.code')
                ->get([
                    'pl.id',
                    'pl.code',
                    'pl.name',
                    'pl.currency_code',
                    'pl.country_code',
                    'c.minor_units',
                ]),

            'markets' => DB::table('markets as m')
                ->join('currencies as c', 'c.code', '=', 'm.currency_code')
                ->where('m.is_active', true)
                ->where('c.is_active', true)
                ->orderBy('m.code')
                ->get([
                    'm.id',
                    'm.code',
                    'm.name',
                    'm.country_code',
                    'm.currency_code',
                    'c.minor_units',
                ]),

            'summary' => [
                'prices' => DB::table('variant_prices')->count(),
                'promotions' => DB::table('promotions')->count(),
                'enabled_promotions' => DB::table('promotions')
                    ->where('is_active', true)->count(),
                'wallets' => DB::table('loyalty_wallets')->count(),
            ],
        ];
    }

    public function prices(array $filters): array
    {
        $this->audit->actor();

        $listId = (int) $filters['price_list_id'];

        if (!DB::table('price_lists')
            ->where('id', $listId)
            ->where('type', 'retail')
            ->exists()) {
            abort(404, 'Price list not found');
        }

        $query = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('product_translations as tr', function ($join): void {
                $join->on('tr.product_id', '=', 'p.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->leftJoin('variant_prices as vp', function ($join) use ($listId): void {
                $join->on('vp.variant_id', '=', 'v.id')
                    ->where('vp.price_list_id', '=', $listId);
            })
            ->whereNull('p.deleted_at')
            ->select([
                'v.id',
                'v.sku',
                'v.barcode',
                'v.product_id',
                'v.is_active',
                'p.status as product_status',
                'tr.name as product_name',
                'vp.price_minor',
                'vp.compare_at_minor',
            ])
            ->selectRaw('COALESCE(vp.revision, 0) AS revision');

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $pattern = $this->pattern($q);

            $query->where(function (Builder $sub) use ($pattern): void {
                $sub->where('v.sku', 'ilike', $pattern)
                    ->orWhere('v.barcode', 'ilike', $pattern)
                    ->orWhere('tr.name', 'ilike', $pattern);
            });
        }

        if (($filters['price_status'] ?? '') === 'missing') {
            $query->whereNull('vp.id');
        }

        return $this->page($query, 'v.id', $filters);
    }

    public function promotions(array $filters): array
    {
        $this->audit->actor();

        $query = DB::table('promotions as p')
            ->join('price_lists as pl', 'pl.id', '=', 'p.price_list_id')
            ->select([
                'p.*',
                'pl.code as price_list_code',
                'pl.currency_code',
            ])
            ->selectRaw('
                (
                    SELECT COUNT(*)
                    FROM promotion_redemptions r
                    JOIN promotion_codes c
                        ON c.id = r.promotion_code_id
                    WHERE c.promotion_id = p.id
                ) AS used_count
            ');

        if (isset($filters['price_list_id'])) {
            $query->where(
                'p.price_list_id',
                (int) $filters['price_list_id']
            );
        }

        if (!empty($filters['q'])) {
            $pattern = $this->pattern($filters['q']);

            $query->where(function (Builder $sub) use ($pattern): void {
                $sub->where('p.name', 'ilike', $pattern)
                    ->orWhereExists(function (Builder $code) use ($pattern): void {
                        $code->selectRaw('1')
                            ->from('promotion_codes as pc')
                            ->whereColumn('pc.promotion_id', 'p.id')
                            ->where('pc.code', 'ilike', $pattern);
                    });
            });
        }

        return $this->page($query, 'p.id', $filters);
    }

    public function promotion(int $id): array
    {
        $this->audit->actor();

        $promotion = DB::table('promotions as p')
            ->join('price_lists as pl', 'pl.id', '=', 'p.price_list_id')
            ->where('p.id', $id)
            ->first([
                'p.*',
                'pl.code as price_list_code',
                'pl.currency_code',
            ]);

        if ($promotion === null) {
            abort(404, 'Promotion not found');
        }

        $codes = DB::table('promotion_codes as c')
            ->where('c.promotion_id', $id)
            ->select('c.*')
            ->selectRaw('
                (
                    SELECT COUNT(*)
                    FROM promotion_redemptions r
                    WHERE r.promotion_code_id = c.id
                ) AS used_count
            ')
            ->selectRaw('
                (
                    SELECT COUNT(*)
                    FROM promotion_reservations r
                    WHERE r.promotion_code_id = c.id
                    AND r.expires_at > CURRENT_TIMESTAMP
                    AND NOT EXISTS (
                        SELECT 1
                        FROM promotion_reservation_events e
                        WHERE e.reservation_id = r.id
                    )
                ) AS pending_count
            ')
            ->orderBy('c.id')
            ->limit(501)
            ->get();

        if ($codes->count() > 500) {
            abort(409, 'Promotion code list exceeds display limit');
        }

        return [
            'promotion' => $promotion,
            'codes' => $codes,
        ];
    }

    public function policies(int $marketId): array
    {
        $this->audit->actor();

        if (!DB::table('markets')
            ->where('id', $marketId)->exists()) {
            abort(404, 'Market not found');
        }

        $rows = DB::table('loyalty_redemption_policies')
            ->where('market_id', $marketId)
            ->orderByDesc('effective_from')
            ->limit(30)
            ->get();

        $active = DB::table('loyalty_redemption_policies')
            ->where('market_id', $marketId)
            ->whereRaw('effective_from <= CURRENT_TIMESTAMP')
            ->orderByDesc('effective_from')
            ->value('id');

        $latest = $rows->first();

        $rules = $latest === null
            ? []
            : DB::table('loyalty_redemption_eligibility_rules')
                ->where('policy_id', $latest->id)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get();

        return [
            'items' => $rows,
            'active_policy_id' => $active === null
                ? null : (int) $active,
            'latest' => $latest,
            'rules' => $rules,
        ];
    }

    public function scopes(array $filters): array
    {
        $this->audit->actor();

        $scope = $filters['scope_type'];

        $query = match ($scope) {
            'brand' => DB::table('brands as t')
                ->select(['t.id', 't.name']),

            'category' => DB::table('categories as t')
                ->leftJoin('category_translations as tr', function ($join): void {
                    $join->on('tr.category_id', '=', 't.id')
                        ->where('tr.locale', '=', 'uk');
                })
                ->select(['t.id', 'tr.name']),

            'product' => DB::table('products as t')
                ->leftJoin('product_translations as tr', function ($join): void {
                    $join->on('tr.product_id', '=', 't.id')
                        ->where('tr.locale', '=', 'uk');
                })
                ->whereNull('t.deleted_at')
                ->select(['t.id', 'tr.name']),

            'variant' => DB::table('product_variants as t')
                ->select(['t.id', 't.sku as name']),

            default => abort(422),
        };

        if (!empty($filters['q'])) {
            $column = match ($scope) {
                'brand' => 't.name',
                'variant' => 't.sku',
                default => 'tr.name',
            };

            $query->where(
                $column,
                'ilike',
                $this->pattern($filters['q'])
            );
        }

        return $this->page($query, 't.id', $filters);
    }

    public function history(array $filters): array
    {
        $this->audit->actor();

        $page = $this->page(
            DB::table('checkout_pricing_admin_events73'),
            'id',
            $filters
        );

        $page['items'] = array_map(function (object $row): array {
            $event = $this->audit->verify($row);

            return [
                'id' => $event['id'],
                'action' => $event['action'],
                'actor_id' => $event['actor_id'],
                'created_at' => $event['created_at'],
                'result' => $event['result'],
            ];
        }, $page['items']);

        return $page;
    }

    private function pattern(string $q): string
    {
        return '%' . str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                trim($q)
            ) . '%';
    }

    private function page(
        Builder $query,
        string $idColumn,
        array $filters
    ): array {
        $limit = (int) ($filters['limit'] ?? 30);

        if (isset($filters['before_id'])) {
            $query->where(
                $idColumn,
                '<',
                (int) $filters['before_id']
            );
        }

        $rows = $query
            ->orderByDesc($idColumn)
            ->limit($limit + 1)
            ->get();

        $items = $rows->take($limit)->values()->all();

        return [
            'items' => $items,
            'next_before_id' =>
                $rows->count() > $limit && $items !== []
                    ? (int) $items[count($items) - 1]->id
                    : null,
        ];
    }
}
