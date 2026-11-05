<?php

namespace Database\Seeders;

use App\Services\Inventory\InventoryService;
use App\Services\Pricing\PriceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Only for local/testing databases. All generated identifiers are prefixed DEMO-BDR.
 * Existing products, prices and stocks are never overwritten.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoCatalogSeeder дозволено запускати лише локально.');
        }

        foreach (['uk', 'ru'] as $locale) {
            if (! DB::table('languages')->where('code', $locale)->exists()) {
                throw new RuntimeException(
                    "Мова {$locale} відсутня у languages. Спершу заповніть довідник мов."
                );
            }
        }

        $priceList = DB::table('price_lists')
            ->where('code', 'UA_RETAIL')
            ->where('currency_code', 'UAH')
            ->where('is_active', true)
            ->first();

        if ($priceList === null) {
            throw new RuntimeException(
                'Не знайдено активний UA_RETAIL (UAH). Запустіть PricingFoundationSeeder.'
            );
        }

        $categories = [
            'shoes' => ['Взуття', 'Обувь'],
            'tshirts' => ['Футболки', 'Футболки'],
            'hoodies' => ['Худі', 'Худи'],
            'jackets' => ['Куртки', 'Куртки'],
            'jeans' => ['Джинси', 'Джинсы'],
            'backpacks' => ['Рюкзаки', 'Рюкзаки'],
            'caps' => ['Кепки', 'Кепки'],
        ];

        // These are fictional demonstration listings, not genuine brand SKUs.
        $products = [
            ['nike-runner', 'Nike', 'shoes', 'ДЕМО: кросівки Nike Runner', 'ДЕМО: кроссовки Nike Runner', 'shoe_size_eu', ['41', '42', '43', '44'], 329990, 389990],
            ['adidas-street', 'Adidas', 'shoes', 'ДЕМО: кросівки Adidas Street', 'ДЕМО: кроссовки Adidas Street', 'shoe_size_eu', ['39', '40', '41', '42'], 299990, 359990],
            ['vans-classic', 'Vans', 'shoes', 'ДЕМО: кеди Vans Classic', 'ДЕМО: кеды Vans Classic', 'shoe_size_eu', ['38', '39', '40', '41'], 259990, 299990],
            ['converse-high', 'Converse', 'shoes', 'ДЕМО: кеди Converse High', 'ДЕМО: кеды Converse High', 'shoe_size_eu', ['37', '38', '39', '40'], 279990, 319990],
            ['quiksilver-tee', 'Quiksilver', 'tshirts', 'ДЕМО: футболка Quiksilver', 'ДЕМО: футболка Quiksilver', 'clothing_size', ['S', 'M', 'L', 'XL'], 89900, null],
            ['nike-hoodie', 'Nike', 'hoodies', 'ДЕМО: худі Nike', 'ДЕМО: худи Nike', 'clothing_size', ['S', 'M', 'L', 'XL'], 229990, 259990],
            ['tnf-jacket', 'The North Face', 'jackets', 'ДЕМО: куртка The North Face', 'ДЕМО: куртка The North Face', 'clothing_size', ['M', 'L', 'XL'], 599990, 659990],
            ['levis-jeans', "Levi's", 'jeans', 'ДЕМО: джинси Levi’s', 'ДЕМО: джинсы Levi’s', 'clothing_size', ['30', '32', '34'], 289990, 339990],
            ['dc-backpack', 'DC Shoes', 'backpacks', 'ДЕМО: рюкзак DC Shoes', 'ДЕМО: рюкзак DC Shoes', null, ['ONE'], 189990, null],
            ['newera-cap', 'New Era', 'caps', 'ДЕМО: кепка New Era', 'ДЕМО: кепка New Era', null, ['ONE'], 119990, null],
        ];

        $totals = DB::transaction(function () use ($categories, $products): array {
            $warehouseId = $this->warehouseId();
            $categoryIds = [];

            foreach ($categories as $key => [$uk, $ru]) {
                $categoryIds[$key] = $this->categoryId($key, $uk, $ru);
            }

            $inventory = app(InventoryService::class);
            $prices = app(PriceService::class);
            $variantCount = 0;
            $productCount = 0;

            foreach ($products as $productIndex => $item) {
                [$key, $brand, $category, $uk, $ru, $attribute, $sizes, $price, $oldPrice] = $item;
                $brandId = $this->brandId($brand);
                $productId = $this->productId($key, $brandId, $uk, $ru);
                $this->linkCategory($productId, $categoryIds[$category]);
                $productCount++;

                foreach ($sizes as $sizeIndex => $size) {
                    $sku = 'DEMO-BDR-' . strtoupper($key) . '-' . $size;
                    $variantId = $this->variantId($productId, $sku, $sizeIndex);
                    $variantCount++;

                    if ($attribute !== null) {
                        $this->assignOption($variantId, $attribute, $size);
                    }

                    // Do not overwrite any previously assigned price.
                    if (! DB::table('variant_prices')
                        ->where('variant_id', $variantId)
                        ->where('price_list_id', DB::table('price_lists')->where('code', 'UA_RETAIL')->value('id'))
                        ->exists()) {
                        $prices->setPrice(
                            $variantId, 'UA_RETAIL', $price, $oldPrice,
                            'DEMO-BDR-CATALOG', (string) Str::uuid(), 0
                        );
                    }

                    // A few sizes deliberately have zero stock to test disabled sizes.
                    $quantity = ($productIndex + $sizeIndex) % 5 === 0
                        ? 0
                        : 2 + (($productIndex + $sizeIndex) % 6);

                    // Never replenish previously seeded or manually changed stock.
                    if ($quantity > 0 && ! DB::table('inventory_stocks')
                            ->where('warehouse_id', $warehouseId)
                            ->where('variant_id', $variantId)
                            ->exists()) {
                        $inventory->receiveOnce(
                            $warehouseId,
                            $variantId,
                            $quantity,
                            'DEMO-BDR-RECEIPT-' . $sku,
                            (string) Str::uuid()
                        );
                    }
                }
            }

            return [$productCount, $variantCount];
        }, 3);

        $this->command?->info(
            "Демо-каталог готовий: {$totals[0]} товарів, {$totals[1]} SKU. Повторний запуск не дублює записи."
        );
        $this->command?->warn('Це вигадані DEMO-картки. Фото поки не додані.');
    }

    private function warehouseId(): int
    {
        $id = DB::table('warehouses')->where('code', 'DEMO-BDR-WH')->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('warehouses')->insertGetId([
            'code' => 'DEMO-BDR-WH',
            'name' => 'Демонстраційний склад BOARDRIDERS',
            'kind' => 'owned',
            'country_code' => 'UA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function brandId(string $name): int
    {
        $slug = Str::slug($name);
        $id = DB::table('brands')->where('slug', $slug)->value('id');
        $id ??= DB::table('brands')->where('name', $name)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('brands')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function categoryId(string $key, string $uk, string $ru): int
    {
        $slugUk = 'demo-bdr-' . $key . '-uk';
        $id = DB::table('category_translations')
            ->where('locale', 'uk')
            ->where('slug', $slugUk)
            ->value('category_id');

        if ($id === null) {
            $id = DB::table('categories')->insertGetId([
                'parent_id' => null,
                'sort_order' => 900,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['uk' => [$uk, $slugUk], 'ru' => [$ru, 'demo-bdr-' . $key . '-ru']] as $locale => [$name, $slug]) {
            if (! DB::table('category_translations')
                ->where('category_id', $id)->where('locale', $locale)->exists()) {
                DB::table('category_translations')->insert([
                    'category_id' => $id,
                    'locale' => $locale,
                    'name' => $name,
                    'slug' => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return (int) $id;
    }

    private function productId(string $key, int $brandId, string $uk, string $ru): int
    {
        $slugUk = 'demo-bdr-' . $key . '-uk';
        $id = DB::table('product_translations')
            ->where('locale', 'uk')->where('slug', $slugUk)->value('product_id');

        if ($id === null) {
            $id = DB::table('products')->insertGetId([
                'brand_id' => $brandId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['uk' => [$uk, $slugUk], 'ru' => [$ru, 'demo-bdr-' . $key . '-ru']] as $locale => [$name, $slug]) {
            if (! DB::table('product_translations')
                ->where('product_id', $id)->where('locale', $locale)->exists()) {
                DB::table('product_translations')->insert([
                    'product_id' => $id,
                    'locale' => $locale,
                    'name' => $name,
                    'slug' => $slug,
                    'short_description' => 'Демонстраційний товар BOARDRIDERS / демонстрационный товар.',
                    'description' => 'Демо-картка для тестування каталогу. Це не реальна товарна пропозиція.',
                    'seo_title' => $name,
                    'seo_description' => 'Демонстраційний товар інтернет-магазину BOARDRIDERS.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return (int) $id;
    }

    private function linkCategory(int $productId, int $categoryId): void
    {
        if (! DB::table('product_categories')
            ->where('product_id', $productId)
            ->where('category_id', $categoryId)
            ->exists()) {
            DB::table('product_categories')->insert([
                'product_id' => $productId,
                'category_id' => $categoryId,
                'is_primary' => true,
            ]);
        }
    }

    private function variantId(int $productId, string $sku, int $sortOrder): int
    {
        $variant = DB::table('product_variants')->where('sku', $sku)->first();

        if ($variant !== null) {
            if ((int) $variant->product_id !== $productId) {
                throw new RuntimeException("SKU {$sku} вже належить іншому товару.");
            }
            return (int) $variant->id;
        }

        return (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'sku' => $sku,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignOption(int $variantId, string $attributeCode, string $optionCode): void
    {
        $attribute = DB::table('attributes')->where('code', $attributeCode)->first();
        if ($attribute === null || $attribute->type !== 'select' || ! $attribute->is_variant) {
            throw new RuntimeException("Не знайдено коректний атрибут {$attributeCode}.");
        }

        $option = DB::table('attribute_options')
            ->where('attribute_id', $attribute->id)
            ->where('code', $optionCode)
            ->first();

        if ($option === null) {
            $optionId = DB::table('attribute_options')->insertGetId([
                'attribute_id' => $attribute->id,
                'code' => $optionCode,
                'sort_order' => is_numeric($optionCode) ? (int) $optionCode : 100,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $optionId = (int) $option->id;
        }

        foreach (['uk', 'ru'] as $locale) {
            if (! DB::table('attribute_option_translations')
                ->where('attribute_option_id', $optionId)->where('locale', $locale)->exists()) {
                DB::table('attribute_option_translations')->insert([
                    'attribute_option_id' => $optionId,
                    'locale' => $locale,
                    'name' => $optionCode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $existing = DB::table('variant_attribute_values')
            ->where('variant_id', $variantId)
            ->where('attribute_id', $attribute->id)
            ->value('option_id');

        if ($existing !== null) {
            if ((int) $existing !== $optionId) {
                throw new RuntimeException("Розмір SKU {$variantId} змінений вручну; нічого не перезаписано.");
            }
            return;
        }

        DB::table('variant_attribute_values')->insert([
            'variant_id' => $variantId,
            'attribute_id' => $attribute->id,
            'option_id' => $optionId,
        ]);
    }
}
