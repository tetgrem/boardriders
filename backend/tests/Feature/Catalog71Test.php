<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Catalog71Test extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/admin/catalog71';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'boardriders_test') {
            throw new \RuntimeException(
                'Requires PostgreSQL boardriders_test'
            );
        }

        config()->set('crm65.enabled', true);
        config()->set('catalog71.enabled', true);

        $this->seed(LanguageSeeder::class);
    }

    private function staff(string $role = 'manager'): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        DB::table('checkout_crm_staff65')->insert([
            'user_id' => $user->id,
            'role' => $role,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken(
            $user->createToken(
                'crm65-dashboard',
                ['crm65:access']
            )->plainTextToken
        );

        return $user;
    }

    private function product(): int
    {
        return (int) $this->postJson(self::API . '/products', [
            'name' => 'Тестовий товар',
            'slug' => 'product-' . Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('revision', 1)
            ->json('id');
    }

    private function change(
        int $id,
        string $action,
        int $revision,
        array $data
    ) {
        return $this->postJson(
            self::API . "/products/{$id}/change",
            [
                'action' => $action,
                'expected_revision' => $revision,
                ...$data,
            ]
        );
    }

    private function dictionary(string $kind, string $slug): int
    {
        return (int) $this->postJson(
            self::API . "/directories/{$kind}",
            [
                'name' => 'Test ' . $kind,
                'slug' => $slug,
                'expected_revision' => 0,
                'is_active' => true,
                'parent_id' => null,
            ]
        )->assertOk()->json('id');
    }

    private function variant(
        int $id,
        int $revision,
        string $sku,
        array $options = []
    ) {
        return $this->change(
            $id, 'variant_create', $revision,
            ['sku' => $sku, 'option_ids' => $options]
        );
    }

    private function attribute(bool $variant = true): array
    {
        $attribute = (int) DB::table('attributes')->insertGetId([
            'code' => 'test-' . Str::uuid(),
            'type' => 'select',
            'is_variant' => $variant,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $option = (int) DB::table('attribute_options')->insertGetId([
            'attribute_id' => $attribute,
            'code' => 'test-option',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$attribute, $option];
    }

    public function test_catalog71_manager_creates_draft(): void
    {
        $this->staff();

        $id = $this->product();

        $this->getJson(self::API . "/products/{$id}")
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('translations.0.locale', 'uk');

        $this->assertDatabaseCount('checkout_catalog_events71', 1);
    }

    public function test_catalog71_search_by_name(): void
    {
        $this->staff();

        $id = $this->product();

        $this->getJson(
            self::API . '/products?q=' . urlencode('Тестовий')
        )->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $id);
    }

    public function test_catalog71_translation_and_seo(): void
    {
        $this->staff();

        $id = $this->product();

        $this->change($id, 'translation', 1, [
            'locale' => 'en',
            'name' => 'Test product',
            'slug' => 'test-product-en',
            'description' => 'Product description',
            'seo_title' => 'Test SEO title',
            'seo_description' => 'SEO description',
        ])->assertOk()->assertJsonPath('revision', 2);

        $this->assertDatabaseHas('product_translations', [
            'product_id' => $id,
            'locale' => 'en',
            'seo_title' => 'Test SEO title',
        ]);
    }

    public function test_catalog71_stale_revision_is_rejected(): void
    {
        $this->staff();

        $id = $this->product();

        $this->change($id, 'status', 1, [
            'status' => 'archived',
        ])->assertOk();

        $this->change($id, 'status', 1, [
            'status' => 'draft',
        ])->assertStatus(409);
    }

    public function test_catalog71_brand_and_category_assignment(): void
    {
        $this->staff();

        $id = $this->product();
        $brand = $this->dictionary('brand', 'test-brand');
        $category = $this->dictionary('category', 'test-category');

        $this->change($id, 'details', 1, [
            'brand_id' => $brand,
            'category_ids' => [$category],
            'primary_category_id' => $category,
        ])->assertOk()
            ->assertJsonPath('brand_id', $brand)
            ->assertJsonPath('categories.0.is_primary', true);
    }

    public function test_catalog71_primary_category_must_be_selected(): void
    {
        $this->staff();

        $id = $this->product();
        $category = $this->dictionary('category', 'test-category');

        $this->change($id, 'details', 1, [
            'brand_id' => null,
            'category_ids' => [],
            'primary_category_id' => $category,
        ])->assertStatus(409);
    }

    public function test_catalog71_variant_is_created_inactive(): void
    {
        $this->staff();

        $id = $this->product();
        [, $option] = $this->attribute();

        $this->variant($id, 1, 'TEST-SKU-01', [$option])
            ->assertOk()
            ->assertJsonPath('variants.0.sku', 'TEST-SKU-01')
            ->assertJsonPath('variants.0.is_active', false)
            ->assertJsonPath('variants.0.options.0.option_id', $option);
    }

    public function test_catalog71_duplicate_sku_is_rejected(): void
    {
        $this->staff();

        $first = $this->product();
        $second = $this->product();

        $this->variant($first, 1, 'UNIQUE-SKU')->assertOk();

        $this->variant($second, 1, 'UNIQUE-SKU')->assertStatus(409);

        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_catalog71_duplicate_combination_is_rejected(): void
    {
        $this->staff();

        $id = $this->product();
        [, $option] = $this->attribute();

        $this->variant($id, 1, 'SKU-A', [$option])->assertOk();

        $this->variant($id, 2, 'SKU-B', [$option])->assertStatus(409);
    }

    public function test_catalog71_product_attribute_is_saved(): void
    {
        $this->staff();

        $id = $this->product();
        [$attribute, $option] = $this->attribute(false);

        $this->change($id, 'attribute', 1, [
            'attribute_id' => $attribute,
            'value' => $option,
        ])->assertOk();

        $this->assertDatabaseHas('product_attribute_values', [
            'product_id' => $id,
            'attribute_id' => $attribute,
            'option_id' => $option,
        ]);
    }

    public function test_catalog71_wrong_attribute_option_is_rejected(): void
    {
        $this->staff();

        $id = $this->product();
        [$attribute] = $this->attribute(false);
        [, $wrongOption] = $this->attribute(false);

        $this->change($id, 'attribute', 1, [
            'attribute_id' => $attribute,
            'value' => $wrongOption,
        ])->assertStatus(409);
    }

    public function test_catalog71_unfinished_product_cannot_publish(): void
    {
        $this->staff();

        $id = $this->product();

        $this->change($id, 'status', 1, [
            'status' => 'active',
        ])->assertStatus(409);

        $this->assertDatabaseHas('products', [
            'id' => $id,
            'status' => 'draft',
            'catalog_revision' => 1,
        ]);
    }

    public function test_catalog71_archive_preserves_variants(): void
    {
        $this->staff();

        $id = $this->product();

        $this->variant($id, 1, 'ARCHIVE-SKU')->assertOk();

        $this->change($id, 'status', 2, [
            'status' => 'archived',
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $id,
            'sku' => 'ARCHIVE-SKU',
        ]);
    }

    public function test_catalog71_valid_png_can_be_attached(): void
    {
        $this->staff();

        Storage::fake('public');

        $id = $this->product();

        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII=';

        $response = $this->change($id, 'image_upload', 1, [
            'base64' => $png,
            'alt_uk' => 'Фото товару',
        ])->assertOk()
            ->assertJsonPath('images.0.is_primary', true);

        Storage::disk('public')->assertExists(
            $response->json('images.0.path')
        );
    }

    public function test_catalog71_non_image_upload_is_rejected(): void
    {
        $this->staff();

        Storage::fake('public');

        $id = $this->product();

        $this->change($id, 'image_upload', 1, [
            'base64' => base64_encode('<svg>invalid</svg>'),
        ])->assertStatus(409);

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_catalog71_history_contains_signed_changes(): void
    {
        $this->staff();

        $id = $this->product();

        $this->change($id, 'status', 1, [
            'status' => 'archived',
        ])->assertOk();

        $this->getJson(self::API . "/products/{$id}/history")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.action', 'status')
            ->assertJsonPath('0.revision', 2);
    }

    public function test_catalog71_audit_is_immutable(): void
    {
        $this->staff();

        $this->product();

        $this->expectException(QueryException::class);

        DB::table('checkout_catalog_events71')
            ->update(['action' => 'tampered']);
    }

    public function test_catalog71_support_cannot_manage_catalog(): void
    {
        $this->staff('support');

        $this->getJson(self::API . '/products')
            ->assertForbidden();

        $this->postJson(self::API . '/products', [
            'name' => 'Forbidden',
            'slug' => 'forbidden',
        ])->assertForbidden();
    }

    public function test_catalog71_disabled_flag_is_enforced(): void
    {
        $this->staff();

        config()->set('catalog71.enabled', false);

        $this->getJson(self::API . '/products')
            ->assertStatus(503);
    }

    public function test_catalog71_publish_and_archive_affect_public_catalog(): void
    {
        $this->staff();

        $this->seed(\Database\Seeders\PricingFoundationSeeder::class);

        $id = $this->product();

        $category = $this->dictionary(
            'category',
            'publish-test-category'
        );

        $this->change($id, 'details', 1, [
            'brand_id' => null,
            'category_ids' => [$category],
            'primary_category_id' => $category,
        ])->assertOk();

        $variantId = (int) $this->variant(
            $id,
            2,
            'PUBLISH-TEST-SKU'
        )->assertOk()->json('variants.0.id');

        app(\App\Services\Pricing\PriceService::class)->setPrice(
            $variantId,
            'UA_RETAIL',
            199900,
            null,
            'CATALOG71-TEST',
            (string) Str::uuid(),
            0
        );

        $this->change($id, 'variant_update', 3, [
            'variant_id' => $variantId,
            'barcode' => null,
            'is_active' => true,
            'sort_order' => 0,
            'weight_grams' => null,
        ])->assertOk();

        $this->getJson(
            '/api/v1/catalog/variants/PUBLISH-TEST-SKU'
        )->assertNotFound();

        $this->change($id, 'status', 4, [
            'status' => 'active',
        ])->assertOk();

        $this->getJson(
            '/api/v1/catalog/variants/PUBLISH-TEST-SKU'
        )->assertOk()
            ->assertJsonPath('data.sku', 'PUBLISH-TEST-SKU')
            ->assertJsonPath('data.price.amount_minor', 199900);

        $this->change($id, 'status', 5, [
            'status' => 'archived',
        ])->assertOk();

        $this->getJson(
            '/api/v1/catalog/variants/PUBLISH-TEST-SKU'
        )->assertNotFound();

        $this->assertDatabaseHas('product_variants', [
            'id' => $variantId,
            'sku' => 'PUBLISH-TEST-SKU',
        ]);
    }

    public function test_catalog71_authentication_is_required(): void
    {
        $this->getJson(self::API . '/products')
            ->assertUnauthorized();
    }

    public function test_catalog71_generates_slug_from_name(): void
    {
        $this->staff();

        $this->postJson(self::API . '/products', [
            'name' => 'Чорна футболка Vans',
        ])->assertCreated()
            ->assertJsonPath(
                'translations.0.slug',
                'chorna-futbolka-vans'
            );
    }

    public function test_catalog71_generated_slugs_are_unique(): void
    {
        $this->staff();

        $this->postJson(self::API . '/products', [
            'name' => 'Чорна футболка Vans',
        ])->assertCreated()
            ->assertJsonPath(
                'translations.0.slug',
                'chorna-futbolka-vans'
            );

        $this->postJson(self::API . '/products', [
            'name' => 'Чорна футболка Vans',
        ])->assertCreated()
            ->assertJsonPath(
                'translations.0.slug',
                'chorna-futbolka-vans-2'
            );
    }

    public function test_catalog71_generates_sku_automatically(): void
    {
        $this->staff();

        $id = $this->product();

        $response = $this->change(
            $id,
            'variant_create',
            1,
            ['option_ids' => []]
        )->assertOk();

        $variantId = $response->json('variants.0.id');

        $this->assertSame(
            "BDR-{$id}-{$variantId}",
            $response->json('variants.0.sku')
        );
    }

    public function test_catalog71_batch_saves_in_one_revision(): void
    {
        $this->staff();

        $id = $this->product();

        $this->change($id, 'batch', 1, [
            'changes' => [
                [
                    'action' => 'translation',
                    'data' => [
                        'locale' => 'en',
                        'name' => 'English product',
                    ],
                ],
                [
                    'action' => 'variant_create',
                    'data' => [
                        'option_ids' => [],
                    ],
                ],
                [
                    'action' => 'status',
                    'data' => [
                        'status' => 'archived',
                    ],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('revision', 2)
            ->assertJsonPath('status', 'archived')
            ->assertJsonCount(1, 'variants')
            ->assertJsonCount(2, 'translations');

        $this->assertDatabaseHas(
            'checkout_catalog_events71',
            [
                'entity_type' => 'product',
                'entity_id' => $id,
                'revision' => 2,
                'action' => 'batch_updated',
            ]
        );
    }

    public function test_catalog71_failed_batch_rolls_back_all_changes(): void
    {
        $this->staff();

        $id = $this->product();

        $this->change($id, 'batch', 1, [
            'changes' => [
                [
                    'action' => 'translation',
                    'data' => [
                        'locale' => 'en',
                        'name' => 'Must not be saved',
                    ],
                ],
                [
                    'action' => 'variant_create',
                    'data' => [
                        'option_ids' => [],
                    ],
                ],
                [
                    'action' => 'status',
                    'data' => [
                        'status' => 'active',
                    ],
                ],
            ],
        ])->assertStatus(409);

        $this->assertDatabaseMissing(
            'product_translations',
            [
                'product_id' => $id,
                'locale' => 'en',
            ]
        );

        $this->assertDatabaseMissing(
            'product_variants',
            [
                'product_id' => $id,
            ]
        );

        $this->assertDatabaseHas('products', [
            'id' => $id,
            'catalog_revision' => 1,
            'status' => 'draft',
        ]);

        $this->assertDatabaseCount(
            'checkout_catalog_events71',
            1
        );
    }
}
