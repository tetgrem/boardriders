<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only the minimal payment protection required by inventory.
        // Order lines, prices, currency and checkout will be added later.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_key')->unique();
            $table->string('payment_status', 24)->default('pending');
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT orders_payment_status_check
            CHECK (payment_status IN ('pending', 'paid', 'review'))
        SQL);

        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->index('order_id', 'inventory_reservations_order_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->dropIndex('inventory_reservations_order_id_idx');
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::dropIfExists('orders');
    }
};
