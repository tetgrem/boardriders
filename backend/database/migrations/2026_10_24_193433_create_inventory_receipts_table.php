<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_receipts', function (Blueprint $table) {
            $table->id();

            // Уникальный ключ операции приёмки.
            $table->uuid('receipt_key')->unique();

            $table->foreignId('inventory_stock_id')
                ->constrained('inventory_stocks')
                ->restrictOnDelete();

            $table->unsignedBigInteger('quantity');

            // Номер накладной или другого документа.
            $table->string('reference', 150);

            $table->text('note')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['inventory_stock_id', 'created_at'],
                'inventory_receipts_stock_history_idx'
            );
        });

        DB::statement("
            ALTER TABLE inventory_receipts
            ADD CONSTRAINT inventory_receipts_quantity_check
            CHECK (quantity > 0)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_receipts');
    }
};
