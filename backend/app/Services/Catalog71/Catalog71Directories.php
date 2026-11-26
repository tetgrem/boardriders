<?php

declare(strict_types=1);

namespace App\Services\Catalog71;

use DomainException;
use Illuminate\Support\Facades\DB;

final class Catalog71Directories
{
    public function __construct(
        private readonly Catalog71Products $products,
        private readonly Catalog71Audit $audit
    ) {}

    public function lookups(): array
    {
        $this->products->actor();

        $brands = DB::table('brands')
            ->orderBy('name')
            ->limit(2001)
            ->get([
                'id', 'name', 'slug', 'is_active',
                'catalog_revision',
            ]);

        $categories = DB::table('categories as c')
            ->leftJoin('category_translations as tr', function ($join): void {
                $join->on('tr.category_id', '=', 'c.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->orderBy('c.sort_order')
            ->orderBy('c.id')
            ->limit(2001)
            ->get([
                'c.id', 'c.parent_id', 'c.is_active',
                'c.sort_order', 'c.catalog_revision',
                'tr.name', 'tr.slug',
                'tr.description', 'tr.seo_title', 'tr.seo_description',
            ]);

        $attributes = DB::table('attributes as a')
            ->leftJoin('attribute_translations as tr', function ($join): void {
                $join->on('tr.attribute_id', '=', 'a.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->where('a.is_active', true)
            ->orderBy('a.sort_order')
            ->orderBy('a.id')
            ->limit(501)
            ->get([
                'a.id', 'a.code', 'a.type', 'a.is_variant', 'tr.name',
            ]);

        if ($brands->count() > 2000
            || $categories->count() > 2000
            || $attributes->count() > 500) {
            abort(409, 'Catalog directory size limit reached');
        }

        $options = DB::table('attribute_options as o')
            ->leftJoin('attribute_option_translations as tr', function ($join): void {
                $join->on('tr.attribute_option_id', '=', 'o.id')
                    ->where('tr.locale', '=', 'uk');
            })
            ->whereIn('o.attribute_id', $attributes->pluck('id'))
            ->where('o.is_active', true)
            ->orderBy('o.sort_order')
            ->orderBy('o.id')
            ->limit(5001)
            ->get([
                'o.id', 'o.attribute_id', 'o.code', 'tr.name',
            ]);

        if ($options->count() > 5000) {
            abort(409, 'Attribute option size limit reached');
        }

        $grouped = $options->groupBy('attribute_id');

        return [
            'brands' => $brands,
            'categories' => $categories,
            'languages' => DB::table('languages')
                ->orderBy('code')
                ->get(['code', 'native_name', 'is_enabled']),
            'attributes' => $attributes->map(
                fn (object $row): array => [
                    'id' => (int) $row->id,
                    'code' => (string) $row->code,
                    'name' => $row->name ?? $row->code,
                    'type' => (string) $row->type,
                    'is_variant' => (bool) $row->is_variant,
                    'options' => $grouped
                        ->get($row->id, collect())
                        ->values()->all(),
                ]
            )->all(),
        ];
    }

    public function save(string $kind, array $data): array
    {
        $actor = $this->products->actor();

        if (!in_array($kind, ['brand', 'category'], true)) {
            throw new DomainException('Unknown directory type');
        }

        return DB::transaction(function () use ($kind, $data, $actor): array {
            $this->products->writeLock();

            $table = $kind === 'brand' ? 'brands' : 'categories';
            $id = isset($data['id']) ? (int) $data['id'] : null;
            $expected = (int) $data['expected_revision'];
            $current = 0;

            if ($id !== null) {
                $row = DB::table($table)->where('id', $id)
                    ->lockForUpdate()->first();

                if ($row === null) {
                    abort(404, 'Directory entry not found');
                }

                $current = (int) $row->catalog_revision;

                $this->audit->assertRevision(
                    $kind, $id, $current
                );

                if ($current !== $expected
                    || $current >= 2147483646) {
                    throw new DomainException(
                        'Directory entry changed. Refresh the page'
                    );
                }
            } elseif ($expected !== 0) {
                throw new DomainException(
                    'Invalid new directory revision'
                );
            }

            $revision = $current + 1;
            $active = (bool) $data['is_active'];

            if ($kind === 'brand') {
                if ($id !== null && !$active
                    && DB::table('products')
                        ->where('brand_id', $id)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')->exists()) {
                    throw new DomainException(
                        'Brand is used by active products'
                    );
                }

                $values = [
                    'name' => trim($data['name']),
                    'slug' => $data['slug'],
                    'is_active' => $active,
                    'catalog_revision' => $revision,
                    'updated_at' => now(),
                ];

                if ($id === null) {
                    $id = (int) DB::table('brands')->insertGetId([
                        ...$values,
                        'created_at' => now(),
                    ]);
                } else {
                    DB::table('brands')
                        ->where('id', $id)
                        ->update($values);
                }
            } else {
                $parent = isset($data['parent_id'])
                    ? (int) $data['parent_id'] : null;

                if ($id !== null) {
                    $oldParent = $row->parent_id === null
                        ? null : (int) $row->parent_id;

                    if ($oldParent !== $parent) {
                        throw new DomainException(
                            'Existing category parent cannot be changed'
                        );
                    }
                }

                if ($parent !== null
                    && !DB::table('categories')
                        ->where('id', $parent)
                        ->where('is_active', true)
                        ->exists()) {
                    throw new DomainException(
                        'Parent category is unavailable'
                    );
                }

                if ($id !== null && !$active) {
                    if (DB::table('categories')
                        ->where('parent_id', $id)
                        ->where('is_active', true)->exists()) {
                        throw new DomainException(
                            'Category has active child categories'
                        );
                    }

                    if (DB::table('product_categories as pc')
                        ->join('products as p', 'p.id', '=', 'pc.product_id')
                        ->where('pc.category_id', $id)
                        ->where('p.status', 'active')
                        ->whereNull('p.deleted_at')
                        ->exists()) {
                        throw new DomainException(
                            'Category is used by active products'
                        );
                    }
                }

                $duplicate = DB::table('category_translations')
                    ->where('locale', 'uk')
                    ->where('slug', $data['slug']);

                if ($id !== null) {
                    $duplicate->where('category_id', '<>', $id);
                }

                if ($duplicate->exists()) {
                    throw new DomainException(
                        'Category slug already exists'
                    );
                }

                $values = [
                    'parent_id' => $parent,
                    'is_active' => $active,
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'catalog_revision' => $revision,
                    'updated_at' => now(),
                ];

                if ($id === null) {
                    $id = (int) DB::table('categories')->insertGetId([
                        ...$values,
                        'created_at' => now(),
                    ]);
                } else {
                    DB::table('categories')
                        ->where('id', $id)
                        ->update($values);
                }

                DB::table('category_translations')->upsert(
                    [[
                        'category_id' => $id,
                        'locale' => 'uk',
                        'name' => trim($data['name']),
                        'slug' => $data['slug'],
                        'description' => $data['description'] ?? null,
                        'seo_title' => $data['seo_title'] ?? null,
                        'seo_description' => $data['seo_description'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]],
                    ['category_id', 'locale'],
                    [
                        'name', 'slug', 'description',
                        'seo_title', 'seo_description', 'updated_at',
                    ]
                );
            }

            $this->audit->append(
                $kind,
                $id,
                $revision,
                $actor,
                $current === 0 && !isset($data['id'])
                    ? 'created' : 'updated',
                $data
            );

            return [
                'id' => $id,
                'revision' => $revision,
                'kind' => $kind,
            ];
        });
    }

    public function history(string $kind, int $id): array
    {
        $this->products->actor();

        $table = match ($kind) {
            'brand' => 'brands',
            'category' => 'categories',
            default => throw new DomainException(
                'Unknown directory type'
            ),
        };

        $revision = DB::table($table)
            ->where('id', $id)->value('catalog_revision');

        if ($revision === null) {
            abort(404, 'Directory entry not found');
        }

        $this->audit->assertRevision(
            $kind, $id, (int) $revision
        );

        return $this->audit->history($kind, $id);
    }
}
