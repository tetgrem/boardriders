<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservation_operations', function (Blueprint $table) {
            $table->id();

            // Унікальний ключ кожної операції
            $table->uuid('operation_key')->unique();

            // Резервування, до якого належить операція
            $table->foreignId('inventory_reservation_id')
                ->constrained('inventory_reservations')
                ->restrictOnDelete();

            // ship або release
            $table->string('type', 20);

            $table->unsignedBigInteger('quantity');

            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['inventory_reservation_id', 'created_at'],
                'reservation_operations_history_idx'
            );
        });

        DB::statement("
            ALTER TABLE inventory_reservation_operations
            ADD CONSTRAINT reservation_operations_type_check
            CHECK (type IN ('ship', 'release'))
        ");

        DB::statement("
            ALTER TABLE inventory_reservation_operations
            ADD CONSTRAINT reservation_operations_quantity_check
            CHECK (quantity > 0)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservation_operations');
    }
};
