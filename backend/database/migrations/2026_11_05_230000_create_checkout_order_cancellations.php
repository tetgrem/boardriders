<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // У існуючій мінімальній схемі orders є тільки
        // pending / paid / review.
        // Додаємо термінальний статус для скасованих замовлень.
        DB::statement(<<<'SQL'
            ALTER TABLE orders
            DROP CONSTRAINT orders_payment_status_check
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT orders_payment_status_check
            CHECK (
                payment_status IN (
                    'pending',
                    'paid',
                    'review',
                    'canceled'
                )
            )
        SQL);

        Schema::create('checkout_order_cancellations', function (Blueprint $table) {
            $table->id();

            // Скасування дозволене лише для підготовленого checkout.
            $table->unsignedBigInteger('order_id')->unique();

            $table->foreign('order_id')
                ->references('order_id')
                ->on('checkout_order_preparations')
                ->restrictOnDelete();

            $table->uuid('operation_key')->unique();

            $table->string('reason_code', 32);

            $table->unsignedBigInteger('released_stock_qty')
                ->default(0);

            $table->foreignId('promotion_reservation_id')
                ->nullable()
                ->constrained('promotion_reservations')
                ->restrictOnDelete();

            $table->foreignId('loyalty_reservation_id')
                ->nullable()
                ->constrained('loyalty_reservations')
                ->restrictOnDelete();

            $table->unsignedBigInteger('released_bonus_units')
                ->default(0);

            $table->unsignedBigInteger('expired_bonus_units')
                ->default(0);

            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_order_cancellations
            ADD CONSTRAINT checkout_cancellations_reason_check
            CHECK (
                reason_code IN (
                    'customer_cancel',
                    'merchant_cancel',
                    'reservation_timeout'
                )
            )
        SQL);

        // Термінальний canceled не можна повторно зробити pending.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_guard_canceled_order()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.payment_status = 'canceled'
                    AND NEW.payment_status <> 'canceled'
                THEN
                    RAISE EXCEPTION
                        'canceled order cannot be reopened';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER orders_guard_canceled_status
            BEFORE UPDATE OF payment_status ON orders
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_guard_canceled_order()
        SQL);

        // Історія скасування незмінна.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_checkout_cancellation_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'checkout cancellation history is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_cancellations_no_update_delete
            BEFORE UPDATE OR DELETE ON checkout_order_cancellations
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_block_checkout_cancellation_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_cancellations_no_truncate
            BEFORE TRUNCATE ON checkout_order_cancellations
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_block_checkout_cancellation_mutation()
        SQL);
    }

    public function down(): void
    {
        // Не можна безпечно видалити статус canceled,
        // якщо такі замовлення вже існують.
        if (
            DB::table('orders')
                ->where('payment_status', 'canceled')
                ->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back: canceled orders already exist'
            );
        }

        Schema::dropIfExists('checkout_order_cancellations');

        DB::statement(
            'DROP TRIGGER IF EXISTS orders_guard_canceled_status ON orders'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_guard_canceled_order()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_block_checkout_cancellation_mutation()'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE orders
            DROP CONSTRAINT orders_payment_status_check
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT orders_payment_status_check
            CHECK (payment_status IN ('pending', 'paid', 'review'))
        SQL);
    }
};
