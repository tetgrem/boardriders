<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CartPreviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'in:uk,ru'],
            'items' => ['required', 'array', 'max:200'],
            'items.*.variant_id' => [
                'required', 'integer', 'min:1', 'distinct',
            ],
            'items.*.quantity' => [
                'required', 'integer', 'between:1,99',
            ],
        ]);

        $items = collect($validated['items']);

        if ($items->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $locale = $validated['locale'];
        $ids = $items->pluck('variant_id')->all();

        // Актуальні залишки з усіх складів.
        $stock = DB::table('inventory_stocks')
            ->select('variant_id')
            ->selectRaw(
                'SUM(GREATEST(on_hand_qty - reserved_qty, 0)) AS available_qty'
            )
            ->groupBy('variant_id');

        // Отримуємо всі SKU кошика одним запитом.
        $rows = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')

            ->join('product_translations as uk', function ($join) {
                $join->on('uk.product_id', '=', 'p.id')
                    ->where('uk.locale', '=', 'uk');
            })

            ->leftJoin(
                'product_translations as tr',
                function ($join) use ($locale) {
                    $join->on('tr.product_id', '=', 'p.id')
                        ->where('tr.locale', '=', $locale);
                }
            )

            ->leftJoin(
                'brands as b',
                'b.id',
                '=',
                'p.brand_id'
            )

            ->leftJoin('product_images as img', function ($join) {
                $join->on('img.product_id', '=', 'p.id')
                    ->where('img.is_primary', '=', true);
            })

            ->join(
                'variant_prices as vp',
                'vp.variant_id',
                '=',
                'v.id'
            )

            ->join('price_lists as pl', function ($join) {
                $join->on('pl.id', '=', 'vp.price_list_id')
                    ->where('pl.code', '=', 'UA_RETAIL')
                    ->where('pl.is_active', '=', true);
            })

            ->leftJoinSub($stock, 'st', function ($join) {
                $join->on('st.variant_id', '=', 'v.id');
            })

            ->whereIn('v.id', $ids)
            ->where('v.is_active', true)
            ->whereIn('p.status', ['active', 'published'])
            ->whereNull('p.deleted_at')

            ->select([
                'v.id as variant_id',
                'v.sku',
                'p.id as product_id',
                'b.name as brand_name',
                'img.path as image_path',
                'img.alt_uk',
                'img.alt_ru',
                'vp.price_minor',
                'pl.currency_code',
                'st.available_qty',
            ])

            ->selectRaw(
                'COALESCE(tr.name, uk.name) AS product_name'
            )

            ->selectRaw(
                'COALESCE(tr.slug, uk.slug) AS product_slug'
            )

            ->get()
            ->keyBy('variant_id');

        // Отримуємо розміри взуття для SKU.
        $sizes = DB::table('variant_attribute_values as vav')

            ->join(
                'attributes as a',
                'a.id',
                '=',
                'vav.attribute_id'
            )

            ->join('attribute_options as opt', function ($join) {
                $join->on('opt.id', '=', 'vav.option_id')
                    ->on('opt.attribute_id', '=', 'vav.attribute_id');
            })

            ->leftJoin(
                'attribute_option_translations as uk',
                function ($join) {
                    $join->on(
                        'uk.attribute_option_id',
                        '=',
                        'opt.id'
                    )->where('uk.locale', '=', 'uk');
                }
            )

            ->leftJoin(
                'attribute_option_translations as tr',
                function ($join) use ($locale) {
                    $join->on(
                        'tr.attribute_option_id',
                        '=',
                        'opt.id'
                    )->where('tr.locale', '=', $locale);
                }
            )

            ->whereIn('vav.variant_id', $ids)
            ->where('a.code', 'shoe_size_eu')
            ->where('a.is_variant', true)
            ->where('a.is_active', true)
            ->where('opt.is_active', true)

            ->select('vav.variant_id')

            ->selectRaw(
                'COALESCE(tr.name, uk.name, opt.code) AS size_name'
            )

            ->get()
            ->keyBy('variant_id');

        // Формуємо відповідь у порядку товарів кошика.
        $data = $items->map(
            function (array $item) use ($rows, $sizes, $locale) {
                $id = (int) $item['variant_id'];
                $row = $rows->get($id);

                // Товар міг бути видалений або деактивований.
                if ($row === null) {
                    return [
                        'variant_id' => $id,
                        'available' => false,
                    ];
                }

                $qty = max(0, (int) $row->available_qty);

                $alt = $locale === 'ru'
                    ? ($row->alt_ru ?: $row->alt_uk)
                    : $row->alt_uk;

                return [
                    'variant_id' => $id,
                    'available' => true,

                    'product_id' => (int) $row->product_id,

                    'name' => $row->product_name,
                    'slug' => $row->product_slug,
                    'brand' => $row->brand_name,

                    'sku' => $row->sku,

                    'size' => $sizes->get($id)?->size_name,

                    'image' => $row->image_path === null
                        ? null
                        : [
                            'url' => url(
                                Storage::disk('public')->url(
                                    $row->image_path
                                )
                            ),
                            'alt' => $alt ?: $row->product_name,
                        ],

                    'price' => [
                        'amount_minor' => (int) $row->price_minor,
                        'currency' => $row->currency_code,
                    ],

                    'stock' => [
                        'available' => $qty,
                        'in_stock' => $qty > 0,
                    ],
                ];
            }
        );

        return response()->json([
            'data' => $data,
        ]);
    }
}
