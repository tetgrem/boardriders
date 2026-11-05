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
        | 1. Attributes
        |--------------------------------------------------------------------------
        | Довідник характеристик товарів.
        */

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();

            $table->string('code', 100)->unique();

            // select, text, number, boolean
            $table->string('type', 20)->default('select');

            // Чи визначає характеристика конкретний SKU
            $table->boolean('is_variant')->default(false);

            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE attributes
            ADD CONSTRAINT attributes_type_check
            CHECK (type IN ('select', 'text', 'number', 'boolean'))
        ");

        DB::statement("
            ALTER TABLE attributes
            ADD CONSTRAINT attributes_variant_type_check
            CHECK (is_variant = false OR type = 'select')
        ");

        /*
        |--------------------------------------------------------------------------
        | 2. Attribute translations
        |--------------------------------------------------------------------------
        | Назви характеристик різними мовами.
        */

        Schema::create('attribute_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attribute_id')
                ->constrained('attributes')
                ->cascadeOnDelete();

            $table->string('locale', 5);

            $table->foreign('locale')
                ->references('code')
                ->on('languages')
                ->restrictOnDelete();

            $table->string('name', 200);

            $table->timestamps();

            $table->unique(
                ['attribute_id', 'locale'],
                'attr_translations_unique'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | 3. Attribute options
        |--------------------------------------------------------------------------
        | Варіанти значень:
        | black, white, s, m, l тощо.
        */

        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attribute_id')
                ->constrained('attributes')
                ->restrictOnDelete();

            $table->string('code', 100);

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['attribute_id', 'code'],
                'attr_options_code_unique'
            );

            // Потрібно для складеного зовнішнього ключа
            $table->unique(
                ['attribute_id', 'id'],
                'attr_options_attr_id_unique'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | 4. Attribute option translations
        |--------------------------------------------------------------------------
        | black -> Чорний / Черный / Black
        */

        Schema::create('attribute_option_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attribute_option_id')
                ->constrained('attribute_options')
                ->cascadeOnDelete();

            $table->string('locale', 5);

            $table->foreign('locale')
                ->references('code')
                ->on('languages')
                ->restrictOnDelete();

            $table->string('name', 200);

            $table->timestamps();

            $table->unique(
                ['attribute_option_id', 'locale'],
                'attr_option_translations_unique'
            );
        });

        /*
        |--------------------------------------------------------------------------
        | 5. Category attributes
        |--------------------------------------------------------------------------
        | Які характеристики доступні для категорій.
        */

        Schema::create('category_attributes', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->foreignId('attribute_id')
                ->constrained('attributes')
                ->restrictOnDelete();

            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary([
                'category_id',
                'attribute_id',
            ]);

            $table->index('attribute_id');
        });

        /*
        |--------------------------------------------------------------------------
        | 6. Product attribute values
        |--------------------------------------------------------------------------
        | Значення характеристик самого товару.
        | Один запис на характеристику товару.
        */

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('attribute_id')
                ->constrained('attributes')
                ->restrictOnDelete();

            $table->unsignedBigInteger('option_id')->nullable();

            $table->text('value_text')->nullable();
            $table->decimal('value_number', 18, 4)->nullable();
            $table->boolean('value_boolean')->nullable();

            $table->timestamps();

            $table->foreign(
                ['attribute_id', 'option_id'],
                'pav_attribute_option_fk'
            )
                ->references(['attribute_id', 'id'])
                ->on('attribute_options')
                ->restrictOnDelete();

            $table->unique(
                ['product_id', 'attribute_id'],
                'pav_product_attribute_unique'
            );

            $table->index(
                ['attribute_id', 'option_id', 'product_id'],
                'pav_option_filter_idx'
            );

            $table->index(
                ['attribute_id', 'value_number'],
                'pav_number_filter_idx'
            );
        });

        // Рівно одне значення в кожному записі.
        DB::statement("
            ALTER TABLE product_attribute_values
            ADD CONSTRAINT pav_exactly_one_value
            CHECK (
                num_nonnulls(
                    option_id,
                    value_text,
                    value_number,
                    value_boolean
                ) = 1
            )
        ");

        /*
        |--------------------------------------------------------------------------
        | 7. Variant attribute values
        |--------------------------------------------------------------------------
        | Значення, які визначають SKU:
        | розмір, колір тощо.
        */

        Schema::create('variant_attribute_values', function (Blueprint $table) {
            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();

            $table->foreignId('attribute_id')
                ->constrained('attributes')
                ->restrictOnDelete();

            $table->unsignedBigInteger('option_id');

            $table->foreign(
                ['attribute_id', 'option_id'],
                'vav_attribute_option_fk'
            )
                ->references(['attribute_id', 'id'])
                ->on('attribute_options')
                ->restrictOnDelete();

            $table->primary([
                'variant_id',
                'attribute_id',
            ]);

            $table->index(
                ['attribute_id', 'option_id', 'variant_id'],
                'vav_filter_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_attribute_values');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('category_attributes');
        Schema::dropIfExists('attribute_option_translations');
        Schema::dropIfExists('attribute_options');
        Schema::dropIfExists('attribute_translations');
        Schema::dropIfExists('attributes');
    }
};
