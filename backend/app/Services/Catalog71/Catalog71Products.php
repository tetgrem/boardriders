<?php

declare(strict_types=1);

namespace App\Services\Catalog71;

use App\Models\User;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class Catalog71Products
{
    public function __construct(
        private readonly Catalog71Audit $audit
    ) {}

    public function actor(): int
    {
        if (config('catalog71.enabled') !== true
            || config('crm65.enabled') !== true) {
            abort(503, 'Catalog administration disabled');
        }

        $user = Auth::user();

        if (!$user instanceof User) {
            abort(401);
        }

        Gate::forUser($user)->authorize('catalog71.manage');

        return (int) $user->id;
    }

    public function page(array $filters): array
    {
        $this->actor();

        $limit = (int) ($filters['limit'] ?? 25);
        $before = (int) ($filters['before_id'] ?? 0);
        $status = $filters['status'] ?? 'all';
        $search = trim((string) ($filters['q'] ?? ''));

        $query = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_translations as tr', function ($join): void {
                $join->on('tr.product_id', '=', 'p.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->whereNull('p.deleted_at')
            ->select([
                'p.id',
                'p.status',
                'p.catalog_revision',
                'p.updated_at',
                'b.name as brand_name',
                'tr.name',
                'tr.slug',
            ])
            ->selectRaw('
                (SELECT COUNT(*)
                 FROM product_variants v
                 WHERE v.product_id = p.id) AS variant_count
            ');

        if ($status !== 'all') {
            $query->where('p.status', $status);
        }

        if ($before > 0) {
            $query->where('p.id', '<', $before);
        }

        if ($search !== '') {
            $pattern = '%' . str_replace(
                    ['\\', '%', '_'],
                    ['\\\\', '\\%', '\\_'],
                    $search
                ) . '%';

            $query->where(function (Builder $q) use ($pattern): void {
                $q->where('b.name', 'ilike', $pattern)
                    ->orWhereExists(function (Builder $sub) use ($pattern): void {
                        $sub->selectRaw('1')
                            ->from('product_translations as pt')
                            ->whereColumn('pt.product_id', 'p.id')
                            ->where(function (Builder $names) use ($pattern): void {
                                $names->where('pt.name', 'ilike', $pattern)
                                    ->orWhere('pt.slug', 'ilike', $pattern);
                            });
                    })
                    ->orWhereExists(function (Builder $sub) use ($pattern): void {
                        $sub->selectRaw('1')
                            ->from('product_variants as pv')
                            ->whereColumn('pv.product_id', 'p.id')
                            ->where('pv.sku', 'ilike', $pattern);
                    });
            });
        }

        $rows = $query->orderByDesc('p.id')
            ->limit($limit + 1)
            ->get();

        $items = $rows->take($limit)
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'status' => (string) $row->status,
                'name' => $row->name,
                'slug' => $row->slug,
                'brand_name' => $row->brand_name,
                'variant_count' => (int) $row->variant_count,
                'revision' => (int) $row->catalog_revision,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'next_before_id' => $rows->count() > $limit
            && $items !== []
                ? $items[count($items) - 1]['id']
                : null,
        ];
    }

    public function show(int $id): array
    {
        $this->actor();

        $product = $this->product($id);

        $this->audit->assertRevision(
            'product',
            $id,
            (int) $product->catalog_revision
        );

        $variants = DB::table('product_variants')
            ->where('product_id', $id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $variantIds = $variants->pluck('id')->all();

        $options = DB::table('variant_attribute_values')
            ->whereIn('variant_id', $variantIds)
            ->orderBy('attribute_id')
            ->get()
            ->groupBy('variant_id');

        return [
            'id' => $id,
            'brand_id' => $product->brand_id === null
                ? null : (int) $product->brand_id,
            'status' => (string) $product->status,
            'revision' => (int) $product->catalog_revision,

            'translations' => DB::table('product_translations')
                ->where('product_id', $id)
                ->orderBy('locale')
                ->get([
                    'locale',
                    'name',
                    'slug',
                    'short_description',
                    'description',
                    'seo_title',
                    'seo_description',
                ])->all(),

            'categories' => DB::table('product_categories')
                ->where('product_id', $id)
                ->orderBy('category_id')
                ->get(['category_id', 'is_primary'])
                ->map(fn (object $row): array => [
                    'category_id' => (int) $row->category_id,
                    'is_primary' => (bool) $row->is_primary,
                ])->all(),

            'attributes' => DB::table('product_attribute_values')
                ->where('product_id', $id)
                ->orderBy('attribute_id')
                ->get([
                    'attribute_id',
                    'option_id',
                    'value_text',
                    'value_number',
                    'value_boolean',
                ])->all(),

            'variants' => $variants->map(function (object $row) use ($options): array {
                return [
                    'id' => (int) $row->id,
                    'sku' => (string) $row->sku,
                    'barcode' => $row->barcode,
                    'is_active' => (bool) $row->is_active,
                    'sort_order' => (int) $row->sort_order,
                    'weight_grams' => $row->weight_grams === null
                        ? null : (int) $row->weight_grams,
                    'options' => $options->get($row->id, collect())
                        ->map(fn (object $value): array => [
                            'attribute_id' => (int) $value->attribute_id,
                            'option_id' => (int) $value->option_id,
                        ])->values()->all(),
                ];
            })->all(),

            'images' => DB::table('product_images')
                ->where('product_id', $id)
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'path' => (string) $row->path,
                    'url' => url(Storage::disk('public')->url($row->path)),
                    'alt_uk' => $row->alt_uk,
                    'alt_ru' => $row->alt_ru,
                    'is_primary' => (bool) $row->is_primary,
                    'sort_order' => (int) $row->sort_order,
                ])->all(),
        ];
    }

    public function create(array $data): array
    {
        $actor = $this->actor();

        return DB::transaction(function () use ($data, $actor): array {
            $this->writeLock();

            $id = (int) DB::table('products')->insertGetId([
                'status' => 'draft',
                'brand_id' => null,
                'catalog_revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $translation = [
                'locale' => 'uk',
                'name' => $data['name'],
                'slug' => $data['slug'] ?? null,
            ];

            $translation = $this->saveTranslation($id, $translation);

            $this->audit->append(
                'product', $id, 1, $actor,
                'created', $translation
            );

            return $this->show($id);
        });
    }

    public function change(
        int $id,
        string $action,
        int $expected,
        array $data
    ): array {
        return $this->changeBatch($id, $expected, [
            ['action' => $action, 'data' => $data],
        ]);
    }

    public function changeBatch(
        int $id,
        int $expected,
        array $changes
    ): array {
        $actor = $this->actor();

        if ($changes === [] || count($changes) > 500) {
            throw new DomainException('Invalid catalog changes');
        }

        return DB::transaction(function () use (
            $id, $expected, $changes, $actor
        ): array {
            $this->writeLock();

            $product = $this->product($id, true);
            $current = (int) $product->catalog_revision;

            $this->audit->assertRevision(
                'product', $id, $current
            );

            if ($current !== $expected
                || $current >= 2147483646) {
                throw new DomainException(
                    'Product changed. Refresh the product'
                );
            }

            $events = [];
            $checkPublication = false;

            foreach ($changes as $change) {
                $action = $change['action'];
                $data = $change['data'];

                $payload = match ($action) {
                    'details' => $this->saveDetails($id, $data),
                    'translation' => $this->saveTranslation($id, $data),
                    'status' => $this->saveStatus($id, $data),
                    'attribute' => $this->saveAttribute($id, $data),
                    'attribute_remove' => $this->removeAttribute($id, $data),
                    'variant_create' => $this->createVariant($id, $data),
                    'variant_update' => $this->updateVariant($id, $data),
                    'image_upload' => $this->uploadImage($id, $data),
                    'image_update' => $this->updateImage($id, $data),
                    'image_remove' => $this->removeImage($id, $data),
                    default => throw new DomainException(
                        'Unknown catalog action'
                    ),
                };

                $events[] = [
                    'action' => $action,
                    'payload' => $payload,
                ];

                if (!in_array($action, [
                    'image_upload',
                    'image_update',
                    'image_remove',
                ], true)) {
                    $checkPublication = true;
                }
            }

            if ($checkPublication
                && DB::table('products')
                    ->where('id', $id)
                    ->value('status') === 'active') {
                $this->assertPublishable($id);
            }

            $revision = $current + 1;

            DB::table('products')->where('id', $id)->update([
                'catalog_revision' => $revision,
                'updated_at' => now(),
            ]);

            $single = count($events) === 1;

            $this->audit->append(
                'product',
                $id,
                $revision,
                $actor,
                $single ? $events[0]['action'] : 'batch_updated',
                $single
                    ? $events[0]['payload']
                    : ['changes' => $events]
            );

            return $this->show($id);
        });
    }
    public function history(int $id): array
    {
        $this->actor();

        $product = $this->product($id);

        $this->audit->assertRevision(
            'product', $id, (int) $product->catalog_revision
        );

        return $this->audit->history('product', $id);
    }

    private function product(int $id, bool $lock = false): object
    {
        $query = DB::table('products')
            ->where('id', $id)
            ->whereNull('deleted_at');

        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($row === null) {
            abort(404, 'Product not found');
        }

        return $row;
    }

    public function writeLock(): void
    {
        // Serialize catalog71 writes, including dictionary changes.
        // Price and inventory services keep their own locking rules.
        DB::select('SELECT pg_advisory_xact_lock(710071)');
    }

    private function saveDetails(int $id, array $data): array
    {
        $brandId = $data['brand_id'] ?? null;

        if ($brandId !== null && !DB::table('brands')
                ->where('id', $brandId)
                ->where('is_active', true)
                ->exists()) {
            throw new DomainException('Brand is unavailable');
        }

        $categories = array_values(array_unique(
            array_map('intval', $data['category_ids'])
        ));

        $primary = isset($data['primary_category_id'])
            ? (int) $data['primary_category_id'] : null;

        if ($primary !== null
            && !in_array($primary, $categories, true)) {
            throw new DomainException(
                'Primary category must belong to the product'
            );
        }

        if (DB::table('categories')
                ->whereIn('id', $categories)
                ->where('is_active', true)
                ->count() !== count($categories)) {
            throw new DomainException('Category is unavailable');
        }

        DB::table('products')->where('id', $id)->update([
            'brand_id' => $brandId,
        ]);

        DB::table('product_categories')
            ->where('product_id', $id)->delete();

        foreach ($categories as $categoryId) {
            DB::table('product_categories')->insert([
                'product_id' => $id,
                'category_id' => $categoryId,
                'is_primary' => $categoryId === $primary,
            ]);
        }

        return [
            'brand_id' => $brandId,
            'category_ids' => $categories,
            'primary_category_id' => $primary,
        ];
    }

    private function saveTranslation(int $id, array $data): array
    {
        $locale = (string) $data['locale'];

        if (!DB::table('languages')
            ->where('code', $locale)->exists()) {
            throw new DomainException('Language is not configured');
        }

        $values = [
            'name' => trim($data['name']),
            'slug' => $this->resolveSlug(
                $id,
                $locale,
                $data['name'],
                $data['slug'] ?? null
            ),
            'short_description' => $data['short_description'] ?? null,
            'description' => $data['description'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
        ];

        if ($values['name'] === '') {
            throw new DomainException('Product name is required');
        }

        DB::table('product_translations')->upsert(
            [[
                'product_id' => $id,
                'locale' => $locale,
                ...$values,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['product_id', 'locale'],
            [...array_keys($values), 'updated_at']
        );

        return ['locale' => $locale, ...$values];
    }

    private function resolveSlug(
        int $id,
        string $locale,
        string $name,
        ?string $requested
    ): string {
        if ($requested !== null && trim($requested) !== '') {
            return $requested;
        }

        $existing = DB::table('product_translations')
            ->where('product_id', $id)
            ->where('locale', $locale)
            ->value('slug');

        // Do not invalidate an existing public URL.
        if ($existing !== null && $existing !== '') {
            return (string) $existing;
        }

        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v',
            'г' => 'h', 'ґ' => 'g', 'д' => 'd',
            'е' => 'e', 'є' => 'ye', 'ж' => 'zh',
            'з' => 'z', 'и' => 'y', 'і' => 'i',
            'ї' => 'yi', 'й' => 'y', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ь' => '', 'ю' => 'yu', 'я' => 'ya',
            'ы' => 'y', 'э' => 'e', 'ъ' => '',
            'ё' => 'yo',
            "'" => '', '’' => '', 'ʼ' => '',
        ];

        $latin = strtr(
            mb_strtolower(trim($name)),
            $map
        );

        $base = \Illuminate\Support\Str::slug(
            $latin,
            '-',
            'en'
        );

        $base = rtrim(substr($base, 0, 220), '-');

        if ($base === '') {
            $base = 'product-' . $id;
        }

        $candidate = $base;
        $suffix = 2;

        while (DB::table('product_translations')
            ->where('locale', $locale)
            ->where('slug', $candidate)
            ->where('product_id', '<>', $id)
            ->exists()) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function saveStatus(int $id, array $data): array
    {
        DB::table('products')->where('id', $id)->update([
            'status' => $data['status'],
        ]);

        return ['status' => $data['status']];
    }

    private function saveAttribute(int $id, array $data): array
    {
        $attribute = DB::table('attributes')
            ->where('id', $data['attribute_id'])
            ->where('is_active', true)
            ->where('is_variant', false)
            ->first();

        if ($attribute === null) {
            throw new DomainException(
                'Product attribute is unavailable'
            );
        }

        $values = [
            'option_id' => null,
            'value_text' => null,
            'value_number' => null,
            'value_boolean' => null,
        ];

        $value = $data['value'];

        switch ($attribute->type) {
            case 'select':
                if (!is_int($value)
                    || !DB::table('attribute_options')
                        ->where('id', $value)
                        ->where('attribute_id', $attribute->id)
                        ->where('is_active', true)
                        ->exists()) {
                    throw new DomainException('Invalid attribute option');
                }
                $values['option_id'] = $value;
                break;

            case 'text':
                if (!is_string($value)
                    || trim($value) === ''
                    || mb_strlen($value) > 2000) {
                    throw new DomainException('Invalid text attribute');
                }
                $values['value_text'] = trim($value);
                break;

            case 'number':
                if (!is_string($value)
                    || !preg_match(
                        '/^-?[0-9]{1,14}(\.[0-9]{1,4})?$/D',
                        $value
                    )) {
                    throw new DomainException('Invalid numeric attribute');
                }
                $values['value_number'] = $value;
                break;

            case 'boolean':
                if (!is_bool($value)) {
                    throw new DomainException('Invalid boolean attribute');
                }
                $values['value_boolean'] = $value;
                break;

            default:
                throw new DomainException('Unsupported attribute type');
        }

        DB::table('product_attribute_values')->upsert(
            [[
                'product_id' => $id,
                'attribute_id' => (int) $attribute->id,
                ...$values,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['product_id', 'attribute_id'],
            [...array_keys($values), 'updated_at']
        );

        return [
            'attribute_id' => (int) $attribute->id,
            ...$values,
        ];
    }

    private function removeAttribute(int $id, array $data): array
    {
        $deleted = DB::table('product_attribute_values')
            ->where('product_id', $id)
            ->where('attribute_id', $data['attribute_id'])
            ->delete();

        if ($deleted !== 1) {
            throw new DomainException('Attribute not found');
        }

        return ['attribute_id' => (int) $data['attribute_id']];
    }

    private function createVariant(int $id, array $data): array
    {
        $count = DB::table('product_variants')
            ->where('product_id', $id)->count();

        if ($count >= (int) config('catalog71.max_variants_per_product', 200)) {
            throw new DomainException('Variant limit reached');
        }

        $optionIds = array_values(array_unique(
            array_map('intval', $data['option_ids'])
        ));

        $options = DB::table('attribute_options as opt')
            ->join('attributes as a', 'a.id', '=', 'opt.attribute_id')
            ->whereIn('opt.id', $optionIds)
            ->where('opt.is_active', true)
            ->where('a.is_active', true)
            ->where('a.is_variant', true)
            ->where('a.type', 'select')
            ->get(['opt.id', 'opt.attribute_id']);

        if ($options->count() !== count($optionIds)
            || $options->pluck('attribute_id')->unique()->count()
            !== $options->count()) {
            throw new DomainException('Invalid variant combination');
        }

        sort($optionIds, SORT_NUMERIC);

        $existingIds = DB::table('product_variants')
            ->where('product_id', $id)
            ->pluck('id');

        $existingOptions = DB::table('variant_attribute_values')
            ->whereIn('variant_id', $existingIds)
            ->get(['variant_id', 'option_id'])
            ->groupBy('variant_id');

        foreach ($existingIds as $variantId) {
            $old = $existingOptions->get($variantId, collect())
                ->pluck('option_id')
                ->map(fn ($value): int => (int) $value)
                ->all();

            sort($old, SORT_NUMERIC);

            if ($old === $optionIds) {
                throw new DomainException(
                    'Variant combination already exists'
                );
            }
        }

        $sku = trim((string) ($data['sku'] ?? ''));
        $generatedId = null;

        if ($sku === '') {
            do {
                $generatedId = (int) DB::selectOne(
                    "SELECT nextval(
                        pg_get_serial_sequence('product_variants', 'id')
                    ) AS id"
                )->id;

                $sku = sprintf(
                    'BDR-%d-%d',
                    $id,
                    $generatedId
                );
            } while (
                DB::table('product_variants')
                    ->where('sku', $sku)
                    ->exists()
            );
        }

        $variantId = (int) DB::table('product_variants')->insertGetId([
            ...($generatedId === null ? [] : ['id' => $generatedId]),
            'product_id' => $id,
            'sku' => $sku,
            'barcode' => $data['barcode'] ?? null,
            'is_active' => false,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'weight_grams' => $data['weight_grams'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($options as $option) {
            DB::table('variant_attribute_values')->insert([
                'variant_id' => $variantId,
                'attribute_id' => (int) $option->attribute_id,
                'option_id' => (int) $option->id,
            ]);
        }

        return [
            'variant_id' => $variantId,
            'sku' => $sku,
            'barcode' => $data['barcode'] ?? null,
            'option_ids' => $optionIds,
            'is_active' => false,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'weight_grams' => $data['weight_grams'] ?? null,
        ];
    }

    private function updateVariant(int $id, array $data): array
    {
        $variant = DB::table('product_variants')
            ->where('product_id', $id)
            ->where('id', $data['variant_id'])
            ->lockForUpdate()
            ->first();

        if ($variant === null) {
            abort(404, 'Variant not found');
        }

        $values = [
            'barcode' => $data['barcode'] ?? null,
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) $data['sort_order'],
            'weight_grams' => $data['weight_grams'] ?? null,
        ];

        DB::table('product_variants')
            ->where('id', $variant->id)
            ->update([
                ...$values,
                'updated_at' => now(),
            ]);

        return [
            'variant_id' => (int) $variant->id,
            ...$values,
        ];
    }

    private function assertPublishable(int $id): void
    {
        $product = $this->product($id);

        if (!DB::table('product_translations')
            ->where('product_id', $id)
            ->where('locale', 'uk')
            ->whereRaw("btrim(name) <> '' AND btrim(slug) <> ''")
            ->exists()) {
            throw new DomainException(
                'Ukrainian product translation is required'
            );
        }

        if ($product->brand_id !== null
            && !DB::table('brands')
                ->where('id', $product->brand_id)
                ->where('is_active', true)->exists()) {
            throw new DomainException('Active brand is required');
        }

        if (!DB::table('product_categories as pc')
            ->join('categories as c', 'c.id', '=', 'pc.category_id')
            ->join('category_translations as ct', function ($join): void {
                $join->on('ct.category_id', '=', 'c.id')
                    ->where('ct.locale', '=', 'uk');
            })
            ->where('pc.product_id', $id)
            ->where('pc.is_primary', true)
            ->where('c.is_active', true)
            ->exists()) {
            throw new DomainException(
                'Active primary category is required'
            );
        }

        $variants = DB::table('product_variants')
            ->where('product_id', $id)
            ->where('is_active', true)
            ->pluck('id');

        if ($variants->isEmpty()) {
            throw new DomainException(
                'At least one active variant is required'
            );
        }

        $priced = DB::table('variant_prices as vp')
            ->join('price_lists as pl', 'pl.id', '=', 'vp.price_list_id')
            ->join('currencies as c', 'c.code', '=', 'pl.currency_code')
            ->where('pl.code', 'UA_RETAIL')
            ->where('pl.is_active', true)
            ->where('c.is_active', true)
            ->whereIn('vp.variant_id', $variants)
            ->distinct()
            ->count('vp.variant_id');

        if ($priced !== $variants->count()) {
            throw new DomainException(
                'All active variants require retail prices'
            );
        }

        $required = DB::table('category_attributes as ca')
            ->join('product_categories as pc',
                'pc.category_id', '=', 'ca.category_id')
            ->join('attributes as a', 'a.id', '=', 'ca.attribute_id')
            ->where('pc.product_id', $id)
            ->where('ca.is_required', true)
            ->where('a.is_active', true)
            ->distinct()
            ->get(['a.id', 'a.is_variant']);

        foreach ($required as $attribute) {
            if ((bool) $attribute->is_variant) {
                $filled = DB::table('variant_attribute_values as vav')
                    ->join('attribute_options as opt',
                        'opt.id', '=', 'vav.option_id')
                    ->where('opt.is_active', true)
                    ->where('vav.attribute_id', $attribute->id)
                    ->whereIn('vav.variant_id', $variants)
                    ->distinct()
                    ->count('vav.variant_id');

                if ($filled !== $variants->count()) {
                    throw new DomainException(
                        'Required variant attribute is missing'
                    );
                }
            } elseif (!DB::table('product_attribute_values')
                ->where('product_id', $id)
                ->where('attribute_id', $attribute->id)
                ->exists()) {
                throw new DomainException(
                    'Required product attribute is missing'
                );
            }
        }
    }

    private function uploadImage(int $id, array $data): array
    {
        $bytes = base64_decode($data['base64'], true);

        if ($bytes === false
            || strlen($bytes) < 1
            || strlen($bytes) > (int) config('catalog71.max_image_bytes')) {
            throw new DomainException('Invalid image size');
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false
            || $info[0] < 1 || $info[1] < 1
            || $info[0] * $info[1] >
            (int) config('catalog71.max_image_pixels')) {
            throw new DomainException('Invalid image dimensions');
        }

        $extension = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => throw new DomainException(
                'Only JPEG, PNG and WebP are allowed'
            ),
        };

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        $expectedMime = match ($extension) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        };

        if ($mime !== $expectedMime) {
            throw new DomainException('Image content type mismatch');
        }

        $images = DB::table('product_images')->where('product_id', $id);
        $count = (clone $images)->count();

        if ($count >= (int) config('catalog71.max_images_per_product', 20)) {
            throw new DomainException('Image limit reached');
        }

        $path = "products/{$id}/"
            . hash('sha256', $bytes)
            . '.' . $extension;

        if ((clone $images)->where('path', $path)->exists()) {
            throw new DomainException('Image already attached');
        }

        if (!Storage::disk('public')->put($path, $bytes)) {
            throw new RuntimeException('Image storage write failed');
        }

        $imageId = (int) DB::table('product_images')->insertGetId([
            'product_id' => $id,
            'path' => $path,
            'alt_uk' => $data['alt_uk'] ?? null,
            'alt_ru' => $data['alt_ru'] ?? null,
            'is_primary' => $count === 0,
            'sort_order' => $count,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Never write the binary data into the audit log.
        return [
            'image_id' => $imageId,
            'path' => $path,
            'alt_uk' => $data['alt_uk'] ?? null,
            'alt_ru' => $data['alt_ru'] ?? null,
        ];
    }

    private function updateImage(int $id, array $data): array
    {
        $image = DB::table('product_images')
            ->where('product_id', $id)
            ->where('id', $data['image_id'])
            ->first();

        if ($image === null) {
            abort(404, 'Product image not found');
        }

        if ((bool) $data['is_primary']) {
            DB::table('product_images')
                ->where('product_id', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $values = [
            'alt_uk' => $data['alt_uk'] ?? null,
            'alt_ru' => $data['alt_ru'] ?? null,
            'is_primary' => (bool) $data['is_primary'],
            'sort_order' => (int) $data['sort_order'],
        ];

        DB::table('product_images')
            ->where('id', $image->id)
            ->update([...$values, 'updated_at' => now()]);

        return [
            'image_id' => (int) $image->id,
            ...$values,
        ];
    }

    private function removeImage(int $id, array $data): array
    {
        $image = DB::table('product_images')
            ->where('product_id', $id)
            ->where('id', $data['image_id'])
            ->first();

        if ($image === null) {
            abort(404, 'Product image not found');
        }

        DB::table('product_images')->where('id', $image->id)->delete();

        if ((bool) $image->is_primary) {
            $next = DB::table('product_images')
                ->where('product_id', $id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');

            if ($next !== null) {
                DB::table('product_images')
                    ->where('id', $next)
                    ->update(['is_primary' => true]);
            }
        }

        // Detach only. Do not delete potentially referenced media.
        return [
            'image_id' => (int) $image->id,
            'path' => (string) $image->path,
        ];
    }
}
