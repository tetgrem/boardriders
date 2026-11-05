<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Дозволяє перевіряти, що промокод справді
        // належить акції, яку ми резервуємо.
        Schema::table('promotion_codes', function (Blueprint $table) {
            $table->unique(
                ['id', 'promotion_id'],
                'promotion_codes_id_promotion_unique'
            );
        });

        Schema::create('promotion_reservations', function (Blueprint $table) {
            $table->id();

            $table->uuid('reservation_key')->unique();

            // Одне резервування промокоду на одне замовлення.
            $table->foreignId('order_id')->unique()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignId('promotion_id')
                ->constrained('promotions')
                ->restrictOnDelete();

            $table->unsignedBigInteger('promotion_code_id');

            $table->foreign(
                ['promotion_code_id', 'promotion_id'],
                'promotion_reservations_code_promotion_fk'
            )
                ->references(['id', 'promotion_id'])
                ->on('promotion_codes')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->char('currency_code', 3);
            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->unsignedBigInteger('discount_minor');

            // SHA-256 нормалізованих параметрів запиту.
            $table->char('request_sha256', 64);

            $table->timestampTz('expires_at', 6);
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->index(
                ['promotion_id', 'expires_at'],
                'promotion_reservations_promotion_expiry_idx'
            );

            $table->index(
                ['promotion_code_id', 'expires_at'],
                'promotion_reservations_code_expiry_idx'
            );

            $table->index(
                ['promotion_id', 'user_id', 'expires_at'],
                'promotion_reservations_user_expiry_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE promotion_reservations
            ADD CONSTRAINT promotion_reservations_values_check
            CHECK (
                discount_minor > 0
                AND expires_at > created_at
                AND request_sha256 ~ '^[0-9a-f]{64}$'
            )
        SQL);

        // Резерв залишається незмінним.
        // Його завершення фіксується окремою подією.
        Schema::create('promotion_reservation_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reservation_id')->unique()
                ->constrained('promotion_reservations')
                ->restrictOnDelete();

            $table->uuid('operation_key')->unique();

            $table->string('event_type', 16);

            $table->foreignId('redemption_id')
                ->nullable()
                ->unique()
                ->constrained('promotion_redemptions')
                ->restrictOnDelete();

            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE promotion_reservation_events
            ADD CONSTRAINT promotion_reservation_events_type_check
            CHECK (
                (event_type = 'captured' AND redemption_id IS NOT NULL)
                OR
                (event_type = 'released' AND redemption_id IS NULL)
            )
        SQL);

        // Історія резервів та завершальних подій append-only.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_promotion_reservation_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'promotion reservation history is immutable: %',
                    TG_TABLE_NAME;
            END;
            $$
        SQL);

        foreach ([
                     'promotion_reservations',
                     'promotion_reservation_events',
                 ] as $tableName) {
            DB::statement("
                CREATE TRIGGER {$tableName}_no_update_delete
                BEFORE UPDATE OR DELETE ON {$tableName}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_block_promotion_reservation_mutation()
            ");

            DB::statement("
                CREATE TRIGGER {$tableName}_no_truncate
                BEFORE TRUNCATE ON {$tableName}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_block_promotion_reservation_mutation()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_reservation_events');
        Schema::dropIfExists('promotion_reservations');

        Schema::table('promotion_codes', function (Blueprint $table) {
            $table->dropUnique(
                'promotion_codes_id_promotion_unique'
            );
        });

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_block_promotion_reservation_mutation()'
        );
    }
};
