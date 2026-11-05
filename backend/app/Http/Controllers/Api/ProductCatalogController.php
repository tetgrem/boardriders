<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {

        $params = $request->validate([
            'locale' => 'sometimes|in:uk,ru',
            'per_page' => 'sometimes|integer|between:1,60',
            'category_id' => 'sometimes|integer|min:1',
            'in_stock' => 'sometimes|boolean',
            'brand' => [
                'sometimes',
                'string',
                'max:120',
                'regex:/^[a-z0-9][a-z0-9-]*$/',
            ],
            'sort' => 'sometimes|in:newest,price_asc,price_desc',
        ]);

        $locale = $params['locale'] ?? 'uk';
        $perPage = (int) ($params['per_page'] ?? 24);
        $query = $this->baseQuery($locale);

        if (isset($params['brand'])) {
            $query->where('b.slug', $params['brand']);
        }

        if (isset($params['category_id'])) {
            $categoryId = (int) $params['category_id'];

            $query->whereExists(function ($subquery) use ($categoryId) {
                $subquery->selectRaw('1')
                    ->from('product_categories as pc_filter')
                    ->join(
                        'categories as c_filter',
                        'c_filter.id',
                        '=',
                        'pc_filter.category_id'
                    )
                    ->whereColumn('pc_filter.product_id', 'p.id')
                    ->where('pc_filter.category_id', $categoryId)
                    ->where('c_filter.is_active', true);
            });
        }

        if ($request->boolean('in_stock')) {
            $query->where('summary.available_qty', '>', 0);
        }

        $sort = $params['sort'] ?? 'newest';

        switch ($sort) {
            case 'price_asc':
                $query
                    ->orderBy('summary.min_price_minor', 'asc')
                    ->orderBy('p.id', 'asc');
                break;

            case 'price_desc':
                $query
                    ->orderBy('summary.min_price_minor', 'desc')
                    ->orderBy('p.id', 'desc');
                break;

            default:
                $query->orderBy('p.id', 'desc');
                break;
        }

        $page = $query->cursorPaginate($perPage);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (object $row) => $this->productData($row, $locale))
                ->values(),
            'meta' => [
                'locale' => $locale,
                'per_page' => $perPage,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'previous_cursor' => $page->previousCursor()?->encode(),
            ],
        ]);

    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $params = $request->validate([
            'locale' => 'sometimes|in:uk,ru',
        ]);

        $locale = $params['locale'] ?? 'uk';

        $product = $this->baseQuery($locale)
            ->whereRaw(
                'COALESCE(localized.slug, uk.slug) = ?',
                [$slug]
            )
            ->first();

        if ($product === null) {
            return response()->json([
                'message' => 'Товар не знайдено.',
            ], 404);
        }

        $data = $this->productData($product, $locale);

        $data['images'] = DB::table('product_images')
            ->where('product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(
                fn (object $image) => $this->imageData(
                    $image,
                    $locale,
                    $product->name
                )
            )
            ->values()
            ->all();

        $data['description'] = $product->description;
        $data['seo'] = [
            'title' => $product->seo_title,
            'description' => $product->seo_description,
        ];

        $data['variants'] = $this->variantsQuery()
            ->where('v.product_id', $product->id)
            ->orderBy('v.sort_order')
            ->orderBy('v.id')
            ->get()
            ->map(fn (object $v) => [
                'id' => (int) $v->id,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'price' => [
                    'amount_minor' => (int) $v->price_minor,
                    'compare_at_minor' =>
                        $v->compare_at_minor === null
                            ? null
                            : (int) $v->compare_at_minor,
                    'currency' => $v->currency_code,
                ],
                'stock' => [
                    'available' => max(
                        0,
                        (int) $v->available_qty
                    ),
                    'in_stock' => (int) $v->available_qty > 0,
                ],
            ])
            ->values();

// Отримуємо ID усіх SKU поточного товару.
        $variantIds = $data['variants']->pluck('id')->all();

        $sizeMap = collect();

        if ($variantIds !== []) {
            $sizeMap = DB::table('variant_attribute_values as vav')
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
                    'attribute_option_translations as tr_uk',
                    function ($join) {
                        $join->on(
                            'tr_uk.attribute_option_id',
                            '=',
                            'opt.id'
                        )->where('tr_uk.locale', '=', 'uk');
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
                ->whereIn('vav.variant_id', $variantIds)
                ->where('a.code', 'shoe_size_eu')
                ->where('a.is_variant', true)
                ->where('a.is_active', true)
                ->where('opt.is_active', true)
                ->select([
                    'vav.variant_id',
                    'opt.code as size_code',
                ])
                ->selectRaw(
                    'COALESCE(tr.name, tr_uk.name, opt.code) AS size_label'
                )
                ->get()
                ->keyBy('variant_id');
        }

// Кожному SKU додаємо його власний розмір.
        $data['variants'] = $data['variants']
            ->map(function (array $variant) use ($sizeMap): array {
                $size = $sizeMap->get($variant['id']);

                $variant['size_eu'] = $size === null
                    ? null
                    : [
                        'system' => 'EU',
                        'code' => $size->size_code,
                        'label' => $size->size_label,
                    ];

                return $variant;
            })
            ->values();

// Зведений список розмірів для перемикача на сторінці.
        $data['sizes_eu'] = $data['variants']
            ->filter(
                fn (array $variant) => $variant['size_eu'] !== null
            )
            ->groupBy(
                fn (array $variant) => $variant['size_eu']['code']
            )
            ->map(function ($variants, $code): array {
                $available = $variants->sum(
                    fn (array $variant) => $variant['stock']['available']
                );

                return [
                    'code' => (string) $code,
                    'label' => $variants->first()['size_eu']['label'],
                    'available' => $available,
                    'in_stock' => $available > 0,
                ];
            })
            ->sortBy(fn (array $size) => (float) $size['code'])
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
        ]);
    }

    private function baseQuery(string $locale): Builder
    {
        $primaryCategory = DB::table('product_categories')
            ->select('product_id')
            ->selectRaw('MIN(category_id) AS category_id')
            ->where('is_primary', true)
            ->groupBy('product_id');

        return DB::table('products as p')
            ->join('product_translations as uk', function ($join) {
                $join->on('uk.product_id', '=', 'p.id')
                    ->where('uk.locale', '=', 'uk');
            })
            ->leftJoin(
                'product_translations as localized',
                function ($join) use ($locale) {
                    $join->on(
                        'localized.product_id',
                        '=',
                        'p.id'
                    )->where('localized.locale', '=', $locale);
                }
            )
            ->joinSub(
                $this->productSummaryQuery(),
                'summary',
                function ($join) {
                    $join->on(
                        'summary.product_id',
                        '=',
                        'p.id'
                    );
                }
            )
            ->leftJoin('brands as b', function ($join) {
                $join->on('b.id', '=', 'p.brand_id')
                    ->where('b.is_active', '=', true);
            })
            ->leftJoinSub(
                $primaryCategory,
                'pc_main',
                function ($join) {
                    $join->on(
                        'pc_main.product_id',
                        '=',
                        'p.id'
                    );
                }
            )
            ->leftJoin('categories as c', function ($join) {
                $join->on('c.id', '=', 'pc_main.category_id')
                    ->where('c.is_active', '=', true);
            })
            ->leftJoin(
                'category_translations as ct_uk',
                function ($join) {
                    $join->on(
                        'ct_uk.category_id',
                        '=',
                        'c.id'
                    )->where('ct_uk.locale', '=', 'uk');
                }
            )
            ->leftJoin(
                'category_translations as ct_localized',
                function ($join) use ($locale) {
                    $join->on(
                        'ct_localized.category_id',
                        '=',
                        'c.id'
                    )->where(
                        'ct_localized.locale',
                        '=',
                        $locale
                    );
                }
            )
            ->leftJoin('product_images as cover', function ($join) {
                $join->on('cover.product_id', '=', 'p.id')
                    ->where('cover.is_primary', '=', true);
            })
            ->whereIn('p.status', ['active', 'published'])
            ->whereNull('p.deleted_at')
            ->select([
                'p.id',
                'b.id as brand_id',
                'b.name as brand_name',
                'b.slug as brand_slug',
                'c.id as category_id',
                'summary.min_price_minor',
                'summary.currency_code',
                'summary.available_qty',
                'cover.path as cover_path',
                'cover.alt_uk as cover_alt_uk',
                'cover.alt_ru as cover_alt_ru',
            ])
            ->selectRaw(
                'COALESCE(localized.name, uk.name) AS name'
            )
            ->selectRaw(
                'COALESCE(localized.slug, uk.slug) AS slug'
            )
            ->selectRaw(
                'COALESCE(localized.short_description, uk.short_description) AS short_description'
            )
            ->selectRaw(
                'COALESCE(localized.description, uk.description) AS description'
            )
            ->selectRaw(
                'COALESCE(localized.seo_title, uk.seo_title) AS seo_title'
            )
            ->selectRaw(
                'COALESCE(localized.seo_description, uk.seo_description) AS seo_description'
            )
            ->selectRaw(
                'COALESCE(ct_localized.name, ct_uk.name) AS category_name'
            )
            ->selectRaw(
                'COALESCE(ct_localized.slug, ct_uk.slug) AS category_slug'
            );
    }

    private function productSummaryQuery(): Builder
    {
        return $this->variantsQuery()
            ->select('v.product_id')
            ->selectRaw(
                'MIN(vp.price_minor) AS min_price_minor'
            )
            ->selectRaw(
                'MIN(pl.currency_code) AS currency_code'
            )
            ->selectRaw(
                'SUM(COALESCE(stock.available_qty, 0)) AS available_qty'
            )
            ->groupBy('v.product_id');
    }

    private function variantsQuery(): Builder
    {
        $stock = DB::table('inventory_stocks')
            ->select('variant_id')
            ->selectRaw(
                'SUM(GREATEST(on_hand_qty - reserved_qty, 0)) AS available_qty'
            )
            ->groupBy('variant_id');

        return DB::table('product_variants as v')
            ->join(
                'variant_prices as vp',
                'vp.variant_id',
                '=',
                'v.id'
            )
            ->join('price_lists as pl', function ($join) {
                $join->on(
                    'pl.id',
                    '=',
                    'vp.price_list_id'
                )
                    ->where('pl.code', '=', 'UA_RETAIL')
                    ->where('pl.is_active', '=', true);
            })
            ->join('currencies as cu', function ($join) {
                $join->on(
                    'cu.code',
                    '=',
                    'pl.currency_code'
                )->where('cu.is_active', '=', true);
            })
            ->leftJoinSub(
                $stock,
                'stock',
                function ($join) {
                    $join->on(
                        'stock.variant_id',
                        '=',
                        'v.id'
                    );
                }
            )
            ->where('v.is_active', true)
            ->select([
                'v.id',
                'v.product_id',
                'v.sku',
                'v.barcode',
                'v.sort_order',
                'vp.price_minor',
                'vp.compare_at_minor',
                'pl.currency_code',
            ])
            ->selectRaw(
                'COALESCE(stock.available_qty, 0) AS available_qty'
            );
    }

    private function productData(object $row, string $locale): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'slug' => $row->slug,
            'short_description' => $row->short_description,
            'brand' => $row->brand_id === null ? null : [
                'id' => (int) $row->brand_id,
                'name' => $row->brand_name,
                'slug' => $row->brand_slug,
            ],
            'primary_category' =>
                $row->category_id === null ? null : [
                    'id' => (int) $row->category_id,
                    'name' => $row->category_name,
                    'slug' => $row->category_slug,
                ],
            'price_from' => [
                'amount_minor' =>
                    (int) $row->min_price_minor,
                'currency' => $row->currency_code,
            ],
            'stock' => [
                'available' => max(
                    0,
                    (int) $row->available_qty
                ),
                'in_stock' =>
                    (int) $row->available_qty > 0,
            ],

            'image' => $row->cover_path === null
                ? null
                : [
                    'url' => $this->imageUrl($row->cover_path),
                    'alt' => (
                    $locale === 'ru'
                        ? ($row->cover_alt_ru ?: $row->cover_alt_uk)
                        : $row->cover_alt_uk
                    ) ?: $row->name,
                ],

        ];
    }

    private function imageUrl(string $path): string
    {
        return url(Storage::disk('public')->url($path));
    }

    private function imageData(
        object $image,
        string $locale,
        string $fallbackName
    ): array {
        $alt = $locale === 'ru'
            ? ($image->alt_ru ?: $image->alt_uk)
            : $image->alt_uk;

        return [
            'id' => (int) $image->id,
            'url' => $this->imageUrl($image->path),
            'alt' => $alt ?: $fallbackName,
            'is_primary' => (bool) $image->is_primary,
            'sort_order' => (int) $image->sort_order,
        ];
    }

    public function brands(): JsonResponse
    {
        $brands = DB::table('brands')
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'slug',
            ]);

        return response()->json([
            'data' => $brands,
        ]);
    }

}
