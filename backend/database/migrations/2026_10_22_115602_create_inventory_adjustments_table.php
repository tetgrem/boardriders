<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();

            // Защита от повторного выполнения.
            $table->uuid('adjustment_key')->unique();

            $table->foreignId('inventory_stock_id')
                ->constrained('inventory_stocks')
                ->restrictOnDelete();

            // Остаток до инвентаризации.
            $table->unsignedBigInteger('previous_qty');

            // Фактически подсчитанное количество.
            $table->unsignedBigInteger('counted_qty');

            // Разница: новое количество - старое.
            $table->bigInteger('quantity_delta');

            // Причина изменения.
            $table->string('reason', 30);

            // Документ инвентаризации.
            $table->string('reference', 150);

            $table->text('note')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['inventory_stock_id', 'created_at'],
                'inventory_adjustments_stock_history_idx'
            );
        });

        DB::statement("
            ALTER TABLE inventory_adjustments
            ADD CONSTRAINT inventory_adjustments_reason_check
            CHECK (
                reason IN (
                    'stocktake',
                    'damage',
                    'loss',
                    'correction'
                )
            )
        ");

        DB::statement("
            ALTER TABLE inventory_adjustments
            ADD CONSTRAINT inventory_adjustments_quantity_check
            CHECK (
                previous_qty >= 0
                AND counted_qty >= 0
                AND quantity_delta = counted_qty - previous_qty
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};
