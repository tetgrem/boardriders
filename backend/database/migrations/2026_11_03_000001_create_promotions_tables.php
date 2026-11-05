<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ========================================
        // 1. АКЦІЇ ТА ПРАВИЛА ЗНИЖОК
        // ========================================

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('price_list_id')
                ->constrained('price_lists')
                ->restrictOnDelete();

            $table->string('name', 160);

            // percent — відсоткова знижка
            // fixed — фіксована сума
            $table->string('type', 20);

            // Для percent: базисні пункти.
            // 1000 = 10%, 10000 = 100%.
            //
            // Для fixed: копійки.
            // 50000 = 500 грн.
            $table->unsignedBigInteger('value');

            // Мінімальна сума кошика в копійках.
            $table->unsignedBigInteger('min_subtotal_minor')
                ->default(0);

            // Максимальна сума знижки.
            $table->unsignedBigInteger('max_discount_minor')
                ->nullable();

            // Загальний ліміт використань акції.
            $table->unsignedInteger('usage_limit')
                ->nullable();

            // Ліміт на одного покупця.
            $table->unsignedInteger('per_customer_limit')
                ->nullable();

            // Період дії.
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            // За замовчуванням акція вимкнена.
            $table->boolean('is_active')->default(false);

            $table->timestampsTz();

            $table->index(
                ['price_list_id', 'is_active', 'starts_at'],
                'promotions_active_lookup_idx'
            );
        });

        // Перевірки на рівні PostgreSQL.
        DB::statement(<<<'SQL'
            ALTER TABLE promotions
            ADD CONSTRAINT promotions_valid_values_check
            CHECK (
                (
                    type = 'percent'
                    AND value BETWEEN 1 AND 10000
                )
                OR
                (
                    type = 'fixed'
                    AND value > 0
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE promotions
            ADD CONSTRAINT promotions_limits_check
            CHECK (
                (
                    max_discount_minor IS NULL
                    OR max_discount_minor > 0
                )
                AND (
                    usage_limit IS NULL
                    OR usage_limit > 0
                )
                AND (
                    per_customer_limit IS NULL
                    OR per_customer_limit > 0
                )
                AND (
                    starts_at IS NULL
                    OR ends_at IS NULL
                    OR starts_at < ends_at
                )
            )
        SQL);

        // ========================================
        // 2. ПРОМОКОДИ
        // ========================================

        Schema::create('promotion_codes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('promotion_id')
                ->constrained('promotions')
                ->restrictOnDelete();

            // Зберігаємо коди у верхньому регістрі.
            $table->string('code', 40)->unique();

            $table->boolean('is_active')->default(true);

            // Додатковий ліміт конкретного коду.
            $table->unsignedInteger('usage_limit')
                ->nullable();

            $table->timestampsTz();

            $table->index(
                ['promotion_id', 'is_active'],
                'promotion_codes_lookup_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE promotion_codes
            ADD CONSTRAINT promotion_codes_format_check
            CHECK (
                code ~ '^[A-Z0-9][A-Z0-9_-]{2,39}$'
                AND (
                    usage_limit IS NULL
                    OR usage_limit > 0
                )
            )
        SQL);

        // ========================================
        // 3. ІСТОРІЯ ВИКОРИСТАННЯ
        // ========================================

        Schema::create(
            'promotion_redemptions',
            function (Blueprint $table) {
                $table->id();

                $table->uuid('redemption_key')->unique();

                $table->foreignId('promotion_code_id')
                    ->constrained('promotion_codes')
                    ->restrictOnDelete();

                $table->foreignId('order_id')
                    ->constrained('orders')
                    ->restrictOnDelete();

                // Для гостей user_id може бути NULL.
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                // Фактична сума знижки.
                $table->unsignedBigInteger('discount_minor');

                $table->char('currency_code', 3);

                $table->foreign('currency_code')
                    ->references('code')
                    ->on('currencies')
                    ->restrictOnDelete();

                $table->timestampTz('created_at')
                    ->useCurrent();

                $table->unique(
                    ['promotion_code_id', 'order_id'],
                    'promotion_redemption_order_code_unique'
                );

                $table->index(
                    ['promotion_code_id', 'created_at'],
                    'promotion_redemptions_code_history_idx'
                );

                $table->index(
                    ['user_id', 'created_at'],
                    'promotion_redemptions_customer_idx'
                );
            }
        );

        DB::statement(<<<'SQL'
            ALTER TABLE promotion_redemptions
            ADD CONSTRAINT promotion_redemptions_amount_check
            CHECK (discount_minor > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotion_codes');
        Schema::dropIfExists('promotions');
    }
};
