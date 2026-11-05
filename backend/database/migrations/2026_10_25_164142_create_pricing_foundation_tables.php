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
         * 1. Валюты.
         */
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name', 80);
            $table->string('symbol', 10)->nullable();
            $table->unsignedTinyInteger('minor_units')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("
            ALTER TABLE currencies
            ADD CONSTRAINT currencies_code_check
            CHECK (code ~ '^[A-Z]{3}$')
        ");

        DB::statement("
            ALTER TABLE currencies
            ADD CONSTRAINT currencies_minor_units_check
            CHECK (minor_units BETWEEN 0 AND 4)
        ");

        /*
         * 2. Прайс-листы.
         */
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();

            $table->string('code', 80)->unique();
            $table->string('name', 150);

            $table->char('country_code', 2);
            $table->char('currency_code', 3);

            $table->string('type', 30)->default('retail');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->index(
                ['country_code', 'currency_code', 'is_active'],
                'price_lists_market_idx'
            );
        });

        DB::statement("
            ALTER TABLE price_lists
            ADD CONSTRAINT price_lists_country_check
            CHECK (country_code ~ '^[A-Z]{2}$')
        ");

        DB::statement("
            ALTER TABLE price_lists
            ADD CONSTRAINT price_lists_type_check
            CHECK (type IN ('retail', 'wholesale'))
        ");

        // Только один прайс-лист по умолчанию
        // для сочетания страны и валюты.
        DB::statement("
            CREATE UNIQUE INDEX price_lists_default_market_idx
            ON price_lists (country_code, currency_code)
            WHERE is_default = TRUE
        ");

        /*
         * 3. Цены конкретных SKU.
         */
        Schema::create('variant_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->foreignId('price_list_id')
                ->constrained('price_lists')
                ->restrictOnDelete();

            // Цена в минимальных единицах валюты.
            $table->unsignedBigInteger('price_minor');

            // Старая цена для отображения скидки.
            $table->unsignedBigInteger('compare_at_minor')
                ->nullable();

            $table->timestampsTz();

            $table->unique(
                ['price_list_id', 'variant_id'],
                'variant_prices_unique_sku_list'
            );

            $table->index(
                ['variant_id', 'price_list_id'],
                'variant_prices_sku_lookup_idx'
            );
        });

        DB::statement("
            ALTER TABLE variant_prices
            ADD CONSTRAINT variant_prices_amount_check
            CHECK (
                price_minor >= 0
                AND (
                    compare_at_minor IS NULL
                    OR compare_at_minor > price_minor
                )
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_prices');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('currencies');
    }
};
