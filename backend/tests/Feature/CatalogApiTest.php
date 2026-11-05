<?php

namespace Tests\Feature;

use App\Services\Pricing\PriceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName()
            !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Only boardriders_test is allowed.'
            );
        }
    }

    public function test_catalog_returns_paginated_json(): void
    {
        $this->getJson(
            '/api/v1/catalog/variants?per_page=5'
        )
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => [
                    'per_page',
                    'has_more',
                    'next_cursor',
                    'previous_cursor',
                ],
            ])
            ->assertJsonPath('meta.per_page', 5);
    }

    public function test_catalog_rejects_invalid_page_size(): void
    {
        $this->getJson(
            '/api/v1/catalog/variants?per_page=1000'
        )->assertUnprocessable();
    }

    public function test_draft_product_is_not_public(): void
    {
        $suffix = (string) Str::uuid();
        $sku = 'HIDDEN-' . $suffix;
        $now = now();

        DB::table('currencies')->insertOrIgnore([
            'code' => 'UAH',
            'name' => 'Українська гривня',
            'symbol' => '₴',
            'minor_units' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('price_lists')->insertOrIgnore([
            'code' => 'UA_RETAIL',
            'name' => 'Роздрібні ціни — Україна',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'type' => 'retail',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = DB::table('products')->insertGetId([
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $variantId = DB::table('product_variants')
            ->insertGetId([
                'product_id' => $productId,
                'sku' => $sku,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        app(PriceService::class)->setPrice(
            $variantId,
            'UA_RETAIL',
            249990,
            null,
            'CATALOG-API-TEST',
            (string) Str::uuid(),
            0
        );

        $this->getJson(
            '/api/v1/catalog/variants/' . $sku
        )->assertNotFound();

        $this->getJson(
            '/api/v1/catalog/variants?per_page=60'
        )
            ->assertOk()
            ->assertJsonMissing([
                'sku' => $sku,
            ]);
    }
}
