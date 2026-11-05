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
        | INVENTORY RESERVATIONS
        |--------------------------------------------------------------------------
        | Окремий запис для кожного резервування товару.
        |
        | quantity      - початкова кількість
        | shipped_qty   - вже відвантажено
        | released_qty  - звільнено з резерву
        |
        | Активний резерв:
        | quantity - shipped_qty - released_qty
        */

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();

            // Унікальний ідентифікатор операції резервування
            $table->uuid('reservation_key')->unique();

            // Залишок конкретного SKU на конкретному складі
            $table->foreignId('inventory_stock_id')
                ->constrained('inventory_stocks')
                ->restrictOnDelete();

            // Тимчасовий зв'язок із номером замовлення.
            // Пізніше додамо зовнішній ключ до orders.
            $table->string('reference', 150);

            // Початкова кількість у резерві
            $table->unsignedBigInteger('quantity');

            // Скільки вже відвантажено
            $table->unsignedBigInteger('shipped_qty')
                ->default(0);

            // Скільки звільнено з резерву
            $table->unsignedBigInteger('released_qty')
                ->default(0);

            // Час автоматичного завершення резервування
            $table->timestampTz('expires_at')->nullable();

            $table->timestampsTz();

            // Швидкий пошук резервів за складським залишком
            $table->index(
                ['inventory_stock_id', 'created_at'],
                'inventory_reservations_stock_idx'
            );

            // Пошук за номером замовлення
            $table->index('reference');

            // Пошук резервів, термін яких закінчується
            $table->index('expires_at');
        });

        /*
        |--------------------------------------------------------------------------
        | DATABASE CONSTRAINTS
        |--------------------------------------------------------------------------
        | Забороняємо некоректні кількості.
        */

        DB::statement("
            ALTER TABLE inventory_reservations
            ADD CONSTRAINT inventory_reservations_quantity_check
            CHECK (
                quantity > 0
                AND shipped_qty >= 0
                AND released_qty >= 0
                AND shipped_qty <= quantity
                AND released_qty <= quantity - shipped_qty
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
    }
};
