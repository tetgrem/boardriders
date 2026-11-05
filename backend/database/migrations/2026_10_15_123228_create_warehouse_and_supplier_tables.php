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
        | 1. SUPPLIERS
        |--------------------------------------------------------------------------
        | Постачальники товарів.
        */

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            $table->string('code', 100)->unique();
            $table->string('name', 255);

            $table->string('country_code', 2)->default('UA');

            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | 2. WAREHOUSES
        |--------------------------------------------------------------------------
        | Власні та зовнішні склади.
        */

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();

            $table->string('code', 100)->unique();
            $table->string('name', 255);

            // owned або partner
            $table->string('kind', 20)->default('owned');

            // Необов'язковий зв'язок із постачальником
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->string('country_code', 2)->default('UA');
            $table->string('city', 150)->nullable();
            $table->string('address', 500)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['kind', 'is_active']);
        });

        DB::statement("
            ALTER TABLE warehouses
            ADD CONSTRAINT warehouses_kind_check
            CHECK (kind IN ('owned', 'partner'))
        ");

        /*
        |--------------------------------------------------------------------------
        | 3. SUPPLIER VARIANTS
        |--------------------------------------------------------------------------
        | Який постачальник може поставити конкретний SKU.
        */

        Schema::create('supplier_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            // Артикул у системі постачальника
            $table->string('supplier_sku', 150)->nullable();

            // Орієнтовний строк постачання у днях
            $table->unsignedSmallInteger('lead_time_days')
                ->nullable();

            $table->unsignedSmallInteger('priority')
                ->default(100);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['supplier_id', 'variant_id'],
                'supplier_variants_unique'
            );

            $table->unique(
                ['supplier_id', 'supplier_sku'],
                'supplier_sku_unique'
            );

            $table->index(['variant_id', 'is_active']);
        });

        /*
        |--------------------------------------------------------------------------
        | 4. INVENTORY STOCKS
        |--------------------------------------------------------------------------
        | Залишки конкретного SKU на конкретному складі.
        */

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            // Фізична кількість на складі
            $table->unsignedBigInteger('on_hand_qty')
                ->default(0);

            // Кількість, зарезервована під замовлення
            $table->unsignedBigInteger('reserved_qty')
                ->default(0);

            $table->timestamps();

            // Один запис для кожної пари склад + SKU
            $table->unique(
                ['warehouse_id', 'variant_id'],
                'inventory_stock_unique'
            );

            $table->index(
                ['variant_id', 'warehouse_id'],
                'inventory_variant_warehouse_idx'
            );
        });

        DB::statement("
            ALTER TABLE inventory_stocks
            ADD CONSTRAINT inventory_quantities_check
            CHECK (
                on_hand_qty >= 0
                AND reserved_qty >= 0
                AND reserved_qty <= on_hand_qty
            )
        ");

        /*
        |--------------------------------------------------------------------------
        | 5. INVENTORY MOVEMENTS
        |--------------------------------------------------------------------------
        | Історія фізичного руху товарів.
        |
        | Позитивна кількість = надходження.
        | Від'ємна кількість = списання.
        */

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_stock_id')
                ->constrained('inventory_stocks')
                ->restrictOnDelete();

            $table->string('type', 30);

            // Наприклад: +10 або -3
            $table->bigInteger('quantity_delta');

            // Номер документа або зовнішньої операції
            $table->string('reference', 150)->nullable();

            $table->text('note')->nullable();

            // Історія операцій не має updated_at
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['inventory_stock_id', 'created_at'],
                'inventory_movements_history_idx'
            );
        });

        DB::statement("
            ALTER TABLE inventory_movements
            ADD CONSTRAINT inventory_movements_type_check
            CHECK (
                type IN (
                    'receipt',
                    'sale',
                    'customer_return',
                    'supplier_return',
                    'adjustment',
                    'transfer_in',
                    'transfer_out'
                )
            )
        ");

        DB::statement("
            ALTER TABLE inventory_movements
            ADD CONSTRAINT inventory_movements_quantity_check
            CHECK (quantity_delta <> 0)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_stocks');
        Schema::dropIfExists('supplier_variants');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('suppliers');
    }
};
