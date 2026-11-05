<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    /**
     * Публічний каталог SKU.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => [
                'sometimes',
                'integer',
                'between:1,60',
            ],
            'in_stock' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 24);
        $onlyAvailable = $request->boolean('in_stock');

        $query = $this->catalogQuery();

        if ($onlyAvailable) {
            $query->whereRaw(
                'COALESCE(s.available_qty, 0) > 0'
            );
        }

        $page = $query
            ->orderBy('v.id')
            ->cursorPaginate($perPage);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn ($item) => $this->formatVariant($item))
                ->values(),

            'meta' => [
                'per_page' => $perPage,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'previous_cursor' =>
                    $page->previousCursor()?->encode(),
            ],
        ]);
    }

    /**
     * Один SKU.
     */
    public function show(string $sku): JsonResponse
    {
        $variant = $this->catalogQuery()
            ->where('v.sku', $sku)
            ->first();

        if ($variant === null) {
            return response()->json([
                'message' => 'Товар не знайдено.',
            ], 404);
        }

        return response()->json([
            'data' => $this->formatVariant($variant),
        ]);
    }

    /**
     * Базовий запит каталогу.
     *
     * Закупівельні ціни постачальників
     * у публічний API не потрапляють.
     */
    private function catalogQuery(): Builder
    {
        $stockTotals = DB::table('inventory_stocks')
            ->select('variant_id')
            ->selectRaw(
                'SUM(on_hand_qty - reserved_qty) AS available_qty'
            )
            ->groupBy('variant_id');

        return DB::table('product_variants as v')
            ->join(
                'products as product',
                'product.id',
                '=',
                'v.product_id'
            )
            ->join(
                'variant_prices as price',
                'price.variant_id',
                '=',
                'v.id'
            )
            ->join(
                'price_lists as list',
                'list.id',
                '=',
                'price.price_list_id'
            )
            ->join(
                'currencies as currency',
                'currency.code',
                '=',
                'list.currency_code'
            )
            ->leftJoinSub(
                $stockTotals,
                's',
                function ($join) {
                    $join->on(
                        's.variant_id',
                        '=',
                        'v.id'
                    );
                }
            )

            // Только публично разрешённые товары.
            ->whereIn(
                'product.status',
                ['active', 'published']
            )

            // Пока используем украинский прайс-лист.
            ->where('list.code', 'UA_RETAIL')
            ->where('list.is_active', true)
            ->where('currency.is_active', true)

            ->select([
                'v.id',
                'v.product_id',
                'v.sku',
                'price.price_minor',
                'price.compare_at_minor',
                'list.currency_code',
            ])
            ->selectRaw(
                'COALESCE(s.available_qty, 0) AS available_qty'
            );
    }

    private function formatVariant(object $item): array
    {
        return [
            'id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'sku' => $item->sku,

            'price' => [
                'amount_minor' => (int) $item->price_minor,
                'compare_at_minor' =>
                    $item->compare_at_minor === null
                        ? null
                        : (int) $item->compare_at_minor,

                'currency' => $item->currency_code,
            ],

            'stock' => [
                'available' => max(
                    0,
                    (int) $item->available_qty
                ),
                'in_stock' =>
                    (int) $item->available_qty > 0,
            ],
        ];
    }
}
