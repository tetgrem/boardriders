<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_redemption_policies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_id')
                ->constrained('markets')
                ->restrictOnDelete();

            $table->char('currency_code', 3);
            $table->string('program_code', 64);
            $table->string('rule_version', 80);
            $table->boolean('is_enabled')->default(false);

            // Один цілий крок: bonus_units_per_step бонусів
            // обмінюються на money_minor_per_step мінімальних
            // одиниць валюти. Жодних float.
            $table->bigInteger('bonus_units_per_step');
            $table->bigInteger('money_minor_per_step');

            // Частка вартості товарів ПІСЛЯ всіх знижок,
            // але БЕЗ доставки. 10000 = 100%.
            $table->unsignedInteger('max_goods_share_bps');

            $table->bigInteger('max_discount_minor')->nullable();
            $table->bigInteger('minimum_cash_minor')->default(0);

            // Нова версія замінює попередню з effective_from.
            // Для відключення створюємо нову is_enabled=false.
            $table->timestampTz('effective_from', 6);
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->unique(
                ['market_id', 'rule_version'],
                'loyalty_redemption_policy_market_version_unique'
            );

            $table->unique(
                ['market_id', 'effective_from'],
                'loyalty_redemption_policy_market_effective_unique'
            );

            $table->index(
                ['market_id', 'effective_from'],
                'loyalty_redemption_policy_market_time_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_redemption_policies
            ADD CONSTRAINT loyalty_redemption_policy_values_check
            CHECK (
                bonus_units_per_step > 0
                AND money_minor_per_step > 0
                AND max_goods_share_bps BETWEEN 0 AND 10000
                AND (
                    max_discount_minor IS NULL
                    OR max_discount_minor >= 0
                )
                AND minimum_cash_minor >= 0
                AND length(trim(program_code)) > 0
                AND length(trim(rule_version)) > 0
            )
        SQL);

        // Історія політик незмінна.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_loyalty_policy_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'loyalty redemption policies are append-only';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_redemption_policies_block_update_delete
            BEFORE UPDATE OR DELETE ON loyalty_redemption_policies
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_block_loyalty_policy_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_redemption_policies_block_truncate
            BEFORE TRUNCATE ON loyalty_redemption_policies
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_block_loyalty_policy_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemption_policies');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_block_loyalty_policy_mutation()'
        );
    }
};
