<?php

declare(strict_types=1);

namespace App\Services\Warehouse72;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Warehouse72Read
{
    public function __construct(
        private readonly Warehouse72Audit $audit
    ) {}

    public function overview(): array
    {
        $this->audit->actor();

        $warehouses = DB::table('warehouses')
            ->orderBy('name')
            ->limit(1001)
            ->get([
                'id',
                'code',
                'name',
                'kind',
                'country_code',
                'city',
                'address',
                'is_active',
                'admin_revision',
            ]);

        if ($warehouses->count() > 1000) {
            abort(409, 'Warehouse directory limit exceeded');
        }

        $summary = DB::table('inventory_stocks as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('w.is_active', true)
            ->where('w.kind', 'owned')
            ->selectRaw('
                COALESCE(SUM(s.on_hand_qty), 0) AS on_hand,
                COALESCE(SUM(s.reserved_qty), 0) AS reserved,
                COALESCE(SUM(s.on_hand_qty - s.reserved_qty), 0)
                    AS available
            ')
            ->first();

        return [
            'summary' => [
                'on_hand' => (int) $summary->on_hand,
                'reserved' => (int) $summary->reserved,
                'available' => (int) $summary->available,
                'active_warehouses' => $warehouses
                    ->filter(fn (object $row): bool =>
                        $row->kind === 'owned'
                        && (bool) $row->is_active
                    )->count(),
            ],
            'warehouses' => $warehouses,
        ];
    }

    public function stocks(array $filters): array
    {
        $this->audit->actor();

        $query = DB::table('inventory_stocks as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('product_variants as v', 'v.id', '=', 's.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('product_translations as tr', function ($join): void {
                $join->on('tr.product_id', '=', 'p.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->select([
                's.id',
                's.warehouse_id',
                's.variant_id',
                's.on_hand_qty',
                's.reserved_qty',
                's.revision',
                'v.sku',
                'v.barcode',
                'v.product_id',
                'v.is_active',
                'p.status as product_status',
                'tr.name as product_name',
                'w.name as warehouse_name',
                'w.kind as warehouse_kind',
            ])
            ->selectRaw(
                '(s.on_hand_qty - s.reserved_qty) AS available_qty'
            )
            ->selectRaw('
                s.reserved_qty = COALESCE((
                    SELECT SUM(r.quantity - r.shipped_qty - r.released_qty)
                    FROM inventory_reservations r
                    WHERE r.inventory_stock_id = s.id
                ), 0) AS reserve_consistent
            ');

        $this->warehouseFilter($query, $filters, 's.warehouse_id');
        $this->search($query, $filters);

        $status = $filters['stock_status'] ?? 'all';

        match ($status) {
            'available' => $query->whereRaw(
                's.on_hand_qty > s.reserved_qty'
            ),
            'reserved' => $query->where('s.reserved_qty', '>', 0),
            'low' => $query->whereRaw(
                's.on_hand_qty - s.reserved_qty BETWEEN 1 AND 5'
            ),
            'empty' => $query->whereRaw(
                's.on_hand_qty = s.reserved_qty'
            ),
            default => null,
        };

        return $this->page($query, 's.id', $filters);
    }

    public function variants(array $filters): array
    {
        $this->audit->actor();

        $warehouseId = (int) $filters['warehouse_id'];

        if (!DB::table('warehouses')
            ->where('id', $warehouseId)->exists()) {
            abort(404, 'Warehouse not found');
        }

        // A variant without any stock record is also selectable
        // for an initial receipt or stocktake.
        $query = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('product_translations as tr', function ($join): void {
                $join->on('tr.product_id', '=', 'p.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->leftJoin('inventory_stocks as s', function ($join) use ($warehouseId): void {
                $join->on('s.variant_id', '=', 'v.id')
                    ->where('s.warehouse_id', '=', $warehouseId);
            })
            ->select([
                'v.id',
                'v.sku',
                'v.barcode',
                'v.product_id',
                'v.is_active',
                'p.status as product_status',
                'tr.name as product_name',
            ])
            ->selectRaw('
                COALESCE(s.on_hand_qty, 0) AS on_hand_qty,
                COALESCE(s.reserved_qty, 0) AS reserved_qty,
                COALESCE(s.on_hand_qty - s.reserved_qty, 0) AS available_qty,
                COALESCE(s.revision, 0) AS revision
            ');

        $this->search($query, $filters);

        return $this->page($query, 'v.id', $filters);
    }

    public function movements(array $filters): array
    {
        $this->audit->actor();

        $query = DB::table('inventory_movements as m')
            ->join('inventory_stocks as s', 's.id', '=', 'm.inventory_stock_id')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('product_variants as v', 'v.id', '=', 's.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('product_translations as tr', function ($join): void {
                $join->on('tr.product_id', '=', 'p.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->select([
                'm.id',
                'm.type',
                'm.quantity_delta',
                'm.reference',
                'm.created_at',
                's.warehouse_id',
                's.variant_id',
                'v.sku',
                'v.product_id',
                'tr.name as product_name',
                'w.name as warehouse_name',
            ]);

        $this->warehouseFilter($query, $filters, 's.warehouse_id');
        $this->search($query, $filters);

        return $this->page($query, 'm.id', $filters);
    }

    public function reservations(array $filters): array
    {
        $this->audit->actor();

        $query = DB::table('inventory_reservations as r')
            ->join('inventory_stocks as s', 's.id', '=', 'r.inventory_stock_id')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('product_variants as v', 'v.id', '=', 's.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('product_translations as tr', function ($join): void {
                $join->on('tr.product_id', '=', 'p.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->whereRaw(
                'r.quantity - r.shipped_qty - r.released_qty > 0'
            )
            ->select([
                'r.id',
                'r.quantity',
                'r.shipped_qty',
                'r.released_qty',
                'r.expires_at',
                'r.created_at',
                's.warehouse_id',
                's.variant_id',
                'o.order_key',
                'v.sku',
                'v.product_id',
                'tr.name as product_name',
                'w.name as warehouse_name',
            ])
            ->selectRaw('
                (r.quantity - r.shipped_qty - r.released_qty) AS active_qty
            ');

        $this->warehouseFilter($query, $filters, 's.warehouse_id');
        $this->search($query, $filters);

        return $this->page($query, 'r.id', $filters);
    }

    public function documents(array $filters): array
    {
        $this->audit->actor();

        $query = DB::table('checkout_warehouse_documents72');

        $this->warehouseFilter($query, $filters, 'warehouse_id');

        if (!empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        if (!empty($filters['q'])) {
            $query->where(
                'reference',
                'ilike',
                $this->pattern($filters['q'])
            );
        }

        $page = $this->page($query, 'id', $filters);

        $page['items'] = array_map(function (object $row): array {
            $event = $this->audit->verify($row);

            return [
                'id' => $event['document_id'],
                'kind' => $event['kind'],
                'actor_id' => $event['actor_id'],
                'warehouse_id' => $event['warehouse_id'],
                'reference' => $event['reference'],
                'created_at' => $event['created_at'],
                'line_count' => (int) ($event['result']['line_count'] ?? 0),
            ];
        }, $page['items']);

        return $page;
    }

    public function document(int $id): array
    {
        $this->audit->actor();

        $row = DB::table('checkout_warehouse_documents72')
            ->where('id', $id)
            ->first();

        if ($row === null) {
            abort(404, 'Warehouse document not found');
        }

        $event = $this->audit->verify($row);

        unset(
            $event['request_json'],
            $event['result_json']
        );

        return $event;
    }

    private function warehouseFilter(
        Builder $query,
        array $filters,
        string $column
    ): void {
        if (isset($filters['warehouse_id'])) {
            $query->where(
                $column,
                (int) $filters['warehouse_id']
            );
        }
    }

    private function search(
        Builder $query,
        array $filters
    ): void {
        $search = trim((string) ($filters['q'] ?? ''));

        if ($search === '') {
            return;
        }

        $pattern = $this->pattern($search);

        $query->where(function (Builder $sub) use ($pattern): void {
            $sub->where('v.sku', 'ilike', $pattern)
                ->orWhere('v.barcode', 'ilike', $pattern)
                ->orWhere('tr.name', 'ilike', $pattern);
        });
    }

    private function pattern(string $value): string
    {
        return '%' . str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                trim($value)
            ) . '%';
    }

    private function page(
        Builder $query,
        string $idColumn,
        array $filters
    ): array {
        $limit = (int) ($filters['limit'] ?? config(
            'warehouse72.page_size',
            30
        ));

        if (isset($filters['before_id'])) {
            $query->where(
                $idColumn,
                '<',
                (int) $filters['before_id']
            );
        }

        $rows = $query->orderByDesc($idColumn)
            ->limit($limit + 1)
            ->get();

        $items = $rows->take($limit)
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $rows->count() > $limit
            && $items !== []
                ? (int) $items[count($items) - 1]->id
                : null,
        ];
    }
}
