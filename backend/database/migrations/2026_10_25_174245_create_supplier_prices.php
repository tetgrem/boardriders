<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->char('currency_code', 3);

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            // Закупівельна ціна в мінімальних одиницях валюти.
            $table->unsignedBigInteger('cost_minor');

            // net — без податку; gross — з податком.
            $table->string('tax_mode', 10);

            // Мінімальна кількість для замовлення.
            $table->unsignedInteger('min_order_quantity')
                ->default(1);

            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();

            $table->unique(
                ['supplier_id', 'variant_id', 'currency_code'],
                'supplier_prices_supplier_variant_currency_uq'
            );

            $table->index(
                ['variant_id', 'currency_code'],
                'supplier_prices_variant_currency_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_prices
            ADD CONSTRAINT supplier_prices_values_check
            CHECK (
                cost_minor > 0
                AND min_order_quantity > 0
                AND revision > 0
                AND tax_mode IN ('net', 'gross')
            )
        SQL);

        /*
         * Журнал змін закупівельних цін.
         */
        Schema::create('supplier_price_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('change_key')->unique();

            $table->foreignId('supplier_price_id')
                ->constrained('supplier_prices')
                ->restrictOnDelete();

            $table->unsignedBigInteger('previous_cost_minor')
                ->nullable();

            $table->unsignedBigInteger('new_cost_minor');

            $table->string('previous_tax_mode', 10)
                ->nullable();

            $table->string('new_tax_mode', 10);

            $table->unsignedInteger('previous_min_order_quantity')
                ->nullable();

            $table->unsignedInteger('new_min_order_quantity');

            $table->unsignedBigInteger('previous_revision');
            $table->unsignedBigInteger('new_revision');

            $table->string('reference', 150);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['supplier_price_id', 'created_at'],
                'supplier_price_changes_history_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_price_changes
            ADD CONSTRAINT supplier_price_changes_values_check
            CHECK (
                new_cost_minor > 0
                AND (
                    previous_cost_minor IS NULL
                    OR previous_cost_minor > 0
                )
                AND new_min_order_quantity > 0
                AND (
                    previous_min_order_quantity IS NULL
                    OR previous_min_order_quantity > 0
                )
                AND new_tax_mode IN ('net', 'gross')
                AND (
                    previous_tax_mode IS NULL
                    OR previous_tax_mode IN ('net', 'gross')
                )
                AND new_revision = previous_revision + 1
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_price_changes');
        Schema::dropIfExists('supplier_prices');
    }
};
