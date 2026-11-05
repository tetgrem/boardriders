<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | PRODUCTS
        |--------------------------------------------------------------------------
        | Основна інформація про товар.
        | Без перекладів, цін та залишків.
        */

        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->nullable()
                ->constrained('brands')
                ->restrictOnDelete();

            $table->string('status', 20)
                ->default('draft');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'id']);
            $table->index(['brand_id', 'status']);
        });

        // Допустимі статуси товарів
        DB::statement("
            ALTER TABLE products
            ADD CONSTRAINT products_status_check
            CHECK (status IN ('draft', 'active', 'archived'))
        ");

        /*
        |--------------------------------------------------------------------------
        | PRODUCT TRANSLATIONS
        |--------------------------------------------------------------------------
        | Переклади: uk, ru, en.
        */

        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->string('locale', 5);

            $table->foreign('locale')
                ->references('code')
                ->on('languages')
                ->restrictOnDelete();

            $table->string('name', 255);
            $table->string('slug', 255);

            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();

            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 320)->nullable();

            $table->timestamps();

            // Лише один переклад кожною мовою
            $table->unique(['product_id', 'locale']);

            // Унікальний URL товару в межах мови
            $table->unique(['locale', 'slug']);
        });

        /*
        |--------------------------------------------------------------------------
        | PRODUCT VARIANTS
        |--------------------------------------------------------------------------
        | Кожен варіант товару має власний SKU.
        */

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->string('sku', 100)->unique();

            $table->string('barcode', 100)
                ->nullable()
                ->unique();

            $table->boolean('is_active')
                ->default(true);

            $table->unsignedInteger('sort_order')
                ->default(0);

            $table->unsignedInteger('weight_grams')
                ->nullable();

            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        /*
        |--------------------------------------------------------------------------
        | PRODUCT CATEGORIES
        |--------------------------------------------------------------------------
        | Товар може належати до кількох категорій.
        */

        Schema::create('product_categories', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->boolean('is_primary')
                ->default(false);

            $table->primary([
                'product_id',
                'category_id',
            ]);

            $table->index([
                'category_id',
                'product_id',
            ]);
        });

        // У товару може бути не більш як
        // одна основна категорія.
        DB::statement("
            CREATE UNIQUE INDEX product_categories_one_primary
            ON product_categories (product_id)
            WHERE is_primary = true
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_translations');
        Schema::dropIfExists('products');
    }
};
