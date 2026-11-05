<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Языки магазина
        Schema::create('languages', function (Blueprint $table) {
            $table->string('code', 5)->primary();
            $table->string('name', 100);
            $table->string('native_name', 100);
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });

        // 2. Бренды
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Переводы описаний брендов
        Schema::create('brand_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->constrained('brands')
                ->cascadeOnDelete();

            $table->string('locale', 5);

            $table->foreign('locale')
                ->references('code')
                ->on('languages')
                ->restrictOnDelete();

            $table->text('description')->nullable();
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 320)->nullable();

            $table->timestamps();

            $table->unique(['brand_id', 'locale']);
        });

        // 4. Категории товаров
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                ->restrictOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });

        // 5. Переводы категорий
        Schema::create('category_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->string('locale', 5);

            $table->foreign('locale')
                ->references('code')
                ->on('languages')
                ->restrictOnDelete();

            $table->string('name', 200);
            $table->string('slug', 200);
            $table->text('description')->nullable();

            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 320)->nullable();

            $table->timestamps();

            $table->unique(['category_id', 'locale']);
            $table->index(['locale', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_translations');
        Schema::dropIfExists('categories');

        Schema::dropIfExists('brand_translations');
        Schema::dropIfExists('brands');

        Schema::dropIfExists('languages');
    }
};
