<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\PriceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductCatalogApiTest extends TestCase
{
    use DatabaseTransactions;

    private int $productId;
    private int $categoryId;
    private string $ukSlug;
    private string $ruSlug;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            config('database.default') !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test'
        ) {
            throw new RuntimeException(
                'Only boardriders_test is allowed.'
            );
        }

        $now = now();
        $this->prepareTestLanguages();
        $suffix = Str::lower((string) Str::uuid());
        $this->ukSlug = 'test-krosivky-' . $suffix;
        $this->ruSlug = 'test-krossovki-' . $suffix;

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

        $brandId = DB::table('brands')->insertGetId([
            'name' => 'Test Boardriders Brand',
            'slug' => 'test-brand-' . $suffix,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->categoryId = DB::table('categories')->insertGetId([
            'parent_id' => null,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
                     ['uk', 'Кросівки', 'test-vzuttya-' . $suffix],
                     ['ru', 'Кроссовки', 'test-obuv-' . $suffix],
                 ] as [$locale, $name, $slug]) {
            DB::table('category_translations')->insert([
                'category_id' => $this->categoryId,
                'locale' => $locale,
                'name' => $name,
                'slug' => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->productId = DB::table('products')->insertGetId([
            'brand_id' => $brandId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
                     [
                         'uk',
                         'Тестові кросівки',
                         $this->ukSlug,
                         'Опис українською',
                     ],
                     [
                         'ru',
                         'Тестовые кроссовки',
                         $this->ruSlug,
                         'Описание на русском',
                     ],
                 ] as [$locale, $name, $slug, $description]) {
            DB::table('product_translations')->insert([
                'product_id' => $this->productId,
                'locale' => $locale,
                'name' => $name,
                'slug' => $slug,
                'short_description' => $description,
                'description' => $description,
                'seo_title' => $name,
                'seo_description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('product_categories')->insert([
            'product_id' => $this->productId,
            'category_id' => $this->categoryId,
            'is_primary' => true,
        ]);

        $variantId = DB::table('product_variants')
            ->insertGetId([
                'product_id' => $this->productId,
                'sku' => 'PUBLIC-TEST-' . $suffix,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        app(PriceService::class)->setPrice(
            $variantId,
            'UA_RETAIL',
            249990,
            299990,
            'PUBLIC-CATALOG-TEST',
            (string) Str::uuid(),
            0
        );

        $warehouseId = DB::table('warehouses')->insertGetId([
            'code' => 'CAT-WH-' . $suffix,
            'name' => 'Тестовий склад каталогу',
            'kind' => 'owned',
            'country_code' => 'UA',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $inventory = app(InventoryService::class);

        $inventory->receiveOnce(
            $warehouseId,
            $variantId,
            5,
            'PUBLIC-CATALOG-RECEIPT',
            (string) Str::uuid()
        );

        $inventory->reserveForOrder(
            $warehouseId,
            $variantId,
            2,
            'PUBLIC-CATALOG-RESERVE',
            (string) Str::uuid()
        );
    }

    public function test_uk_list_contains_brand_category_price_and_available_stock(): void
    {
        $response = $this->getJson(
            '/api/v1/catalog/products?locale=uk'
            . '&category_id=' . $this->categoryId
            . '&in_stock=1'
        )->assertOk();

        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.name',
                'Тестові кросівки'
            )
            ->assertJsonPath(
                'data.0.slug',
                $this->ukSlug
            )
            ->assertJsonPath(
                'data.0.brand.name',
                'Test Boardriders Brand'
            )
            ->assertJsonPath(
                'data.0.primary_category.name',
                'Кросівки'
            )
            ->assertJsonPath(
                'data.0.price_from.amount_minor',
                249990
            )
            ->assertJsonPath(
                'data.0.price_from.currency',
                'UAH'
            )
            ->assertJsonPath(
                'data.0.stock.available',
                3
            )
            ->assertJsonPath(
                'data.0.stock.in_stock',
                true
            );
    }

    public function test_russian_detail_contains_translations_and_priced_variants(): void
    {
        $this->getJson(
            '/api/v1/catalog/products/'
            . $this->ruSlug
            . '?locale=ru'
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Тестовые кроссовки'
            )
            ->assertJsonPath(
                'data.primary_category.name',
                'Кроссовки'
            )
            ->assertJsonPath(
                'data.description',
                'Описание на русском'
            )
            ->assertJsonPath(
                'data.variants.0.price.amount_minor',
                249990
            )
            ->assertJsonPath(
                'data.variants.0.price.compare_at_minor',
                299990
            )
            ->assertJsonPath(
                'data.variants.0.stock.available',
                3
            )
            ->assertJsonMissingPath(
                'data.supplier_prices'
            );
    }

    public function test_draft_product_is_hidden(): void
    {
        DB::table('products')
            ->where('id', $this->productId)
            ->update(['status' => 'draft']);

        $this->getJson(
            '/api/v1/catalog/products/' . $this->ukSlug
        )->assertNotFound();

        $this->getJson(
            '/api/v1/catalog/products?category_id='
            . $this->categoryId
        )
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_missing_russian_translation_falls_back_to_ukrainian(): void
    {
        DB::table('product_translations')
            ->where('product_id', $this->productId)
            ->where('locale', 'ru')
            ->delete();

        $this->getJson(
            '/api/v1/catalog/products/'
            . $this->ukSlug
            . '?locale=ru'
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Тестові кросівки'
            )
            ->assertJsonPath(
                'data.slug',
                $this->ukSlug
            );
    }

    public function test_invalid_locale_and_page_size_are_rejected(): void
    {
        $this->getJson(
            '/api/v1/catalog/products?locale=en'
        )->assertUnprocessable();

        $this->getJson(
            '/api/v1/catalog/products?per_page=1000'
        )->assertUnprocessable();
    }
    private function prepareTestLanguages(): void
    {
        $columns = Schema::getColumnListing('languages');

        $languages = [
            'uk' => 'Українська',
            'ru' => 'Русский',
        ];

        foreach ($languages as $code => $name) {
            $data = ['code' => $code];

            $availableValues = [
                'name' => $name,
                'native_name' => $name,
                'is_active' => true,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            foreach ($availableValues as $column => $value) {
                if (in_array($column, $columns, true)) {
                    $data[$column] = $value;
                }
            }

            DB::table('languages')->insertOrIgnore($data);
        }
    }

    public function test_product_gallery_returns_main_image_first(): void
    {
        $now = now();

        DB::table('product_images')->insert([
            [
                'product_id' => $this->productId,
                'path' => "products/{$this->productId}/side.jpg",
                'alt_uk' => 'Вигляд збоку',
                'alt_ru' => 'Вид сбоку',
                'is_primary' => false,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'product_id' => $this->productId,
                'path' => "products/{$this->productId}/main.jpg",
                'alt_uk' => 'Головне фото',
                'alt_ru' => 'Главное фото',
                'is_primary' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->getJson(
            '/api/v1/catalog/products/'
            . $this->ukSlug
            . '?locale=uk'
        )
            ->assertOk()
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath(
                'data.image.alt',
                'Головне фото'
            )
            ->assertJsonPath(
                'data.images.0.is_primary',
                true
            )
            ->assertJsonPath(
                'data.images.0.alt',
                'Головне фото'
            );

        $this->getJson(
            '/api/v1/catalog/products/'
            . $this->ruSlug
            . '?locale=ru'
        )
            ->assertOk()
            ->assertJsonPath(
                'data.image.alt',
                'Главное фото'
            )
            ->assertJsonPath(
                'data.images.0.alt',
                'Главное фото'
            );
    }

    public function test_product_without_images_returns_empty_gallery(): void
    {
        $this->getJson(
            '/api/v1/catalog/products/' . $this->ukSlug
        )
            ->assertOk()
            ->assertJsonPath('data.image', null)
            ->assertJsonCount(0, 'data.images');
    }


    public function test_shoe_sizes_are_linked_to_variants_and_stock(): void
    {
        app(\Database\Seeders\ShoeSizeEuSeeder::class)->run();

        $firstVariantId = (int) DB::table('product_variants')
            ->where('product_id', $this->productId)
            ->value('id');

        // EU 42: є 3 доступні одиниці.
        $this->assignEuSize($firstVariantId, '42');

        // EU 43: ціна є, але складського залишку немає.
        $secondVariantId = DB::table('product_variants')
            ->insertGetId([
                'product_id' => $this->productId,
                'sku' => 'SIZE-43-' . Str::uuid(),
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        app(PriceService::class)->setPrice(
            $secondVariantId,
            'UA_RETAIL',
            259990,
            null,
            'SIZE-TEST-43',
            (string) Str::uuid(),
            0
        );

        $this->assignEuSize($secondVariantId, '43');

        $this->getJson(
            '/api/v1/catalog/products/'
            . $this->ukSlug
            . '?locale=uk'
        )
            ->assertOk()
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath(
                'data.variants.0.size_eu.code',
                '42'
            )
            ->assertJsonPath(
                'data.variants.0.size_eu.system',
                'EU'
            )
            ->assertJsonPath(
                'data.variants.0.stock.available',
                3
            )
            ->assertJsonPath(
                'data.variants.1.size_eu.code',
                '43'
            )
            ->assertJsonPath(
                'data.variants.1.stock.available',
                0
            )
            ->assertJsonPath(
                'data.sizes_eu.0.code',
                '42'
            )
            ->assertJsonPath(
                'data.sizes_eu.0.available',
                3
            )
            ->assertJsonPath(
                'data.sizes_eu.1.code',
                '43'
            )
            ->assertJsonPath(
                'data.sizes_eu.1.in_stock',
                false
            );
    }

    public function test_variant_without_size_returns_null(): void
    {
        $this->getJson(
            '/api/v1/catalog/products/' . $this->ukSlug
        )
            ->assertOk()
            ->assertJsonPath(
                'data.variants.0.size_eu',
                null
            )
            ->assertJsonCount(0, 'data.sizes_eu');
    }

    private function assignEuSize(
        int $variantId,
        string $size
    ): void {
        $attributeId = (int) DB::table('attributes')
            ->where('code', 'shoe_size_eu')
            ->value('id');

        $optionId = DB::table('attribute_options')
            ->where('attribute_id', $attributeId)
            ->where('code', $size)
            ->value('id');

        if ($optionId === null) {
            throw new \RuntimeException(
                'EU size option is missing: ' . $size
            );
        }

        DB::table('variant_attribute_values')->insert([
            'variant_id' => $variantId,
            'attribute_id' => $attributeId,
            'option_id' => $optionId,
        ]);
    }


}
