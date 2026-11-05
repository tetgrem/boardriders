<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();

            // Унікальний ключ переміщення.
            $table->uuid('transfer_key')->unique();

            $table->foreignId('from_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('to_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->unsignedBigInteger('quantity');

            $table->string('reference', 150);
            $table->text('note')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['variant_id', 'created_at']);
        });

        DB::statement("
            ALTER TABLE inventory_transfers
            ADD CONSTRAINT inventory_transfers_different_warehouses
            CHECK (from_warehouse_id <> to_warehouse_id)
        ");

        DB::statement("
            ALTER TABLE inventory_transfers
            ADD CONSTRAINT inventory_transfers_positive_quantity
            CHECK (quantity > 0)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
