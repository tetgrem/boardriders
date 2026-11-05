<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_ledger_entries', function (Blueprint $table) {
            $table->id();

            // Гаманець і ринок операції.
            $table->foreignId('wallet_id');
            $table->foreignId('market_id');

            // Одна бізнес-операція може мати кілька записів.
            $table->uuid('operation_id');

            // Унікальний ключ проводки в межах гаманця.
            // Захист від повторного запису тієї самої події.
            $table->string('entry_key', 191);

            // Тип операції:
            // purchase_pending, activation, reservation,
            // redemption, release, refund, expiration,
            // debt_created, debt_repayment тощо.
            $table->string('entry_type', 48);

            // Зміни залишків у мінімальних одиницях бонусів.
            // Без float. Точність визначатиметься
            // версією правил бонусної програми.
            $table->bigInteger('pending_delta')->default(0);
            $table->bigInteger('available_delta')->default(0);
            $table->bigInteger('reserved_delta')->default(0);
            $table->bigInteger('debt_delta')->default(0);

            // Джерело операції: замовлення, акція,
            // повернення, CRM, ручне коригування тощо.
            $table->string('origin', 48);

            // Версія правил, застосованих до операції.
            $table->string('rule_version', 80)->nullable();

            // Незмінний контекст для аудиту:
            // пов'язані сутності, причини, знімки правил.
            $table->jsonb('context')->nullable();

            // Коли подія фактично відбулася.
            $table->timestampTz('occurred_at');

            // Коли проводка потрапила до журналу.
            $table->timestampTz('posted_at')->useCurrent();

            // Операція може належати лише ринку
            // свого бонусного гаманця.
            $table->foreign(
                ['wallet_id', 'market_id'],
                'loyalty_ledger_wallet_market_fk'
            )
                ->references(['id', 'market_id'])
                ->on('loyalty_wallets')
                ->restrictOnDelete();

            // Однакову проводку не можна вставити двічі
            // для одного гаманця.
            $table->unique(
                ['wallet_id', 'entry_key'],
                'loyalty_ledger_wallet_entry_key_unique'
            );

            // Швидкий перегляд історії гаманця.
            $table->index(
                ['wallet_id', 'id'],
                'loyalty_ledger_wallet_order_idx'
            );

            // Вибірка операцій за ринком і часом.
            $table->index(
                ['market_id', 'occurred_at'],
                'loyalty_ledger_market_occurred_idx'
            );

            // Пошук усіх проводок бізнес-операції.
            $table->index(
                ['operation_id'],
                'loyalty_ledger_operation_idx'
            );
        });

        // Забороняємо проводки без зміни стану.
        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_ledger_entries
            ADD CONSTRAINT loyalty_ledger_nonzero_delta_check
            CHECK (
                pending_delta <> 0
                OR available_delta <> 0
                OR reserved_delta <> 0
                OR debt_delta <> 0
            )
        SQL);

        // Обов'язкові текстові поля не можуть
        // бути порожніми або складатися лише з пробілів.
        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_ledger_entries
            ADD CONSTRAINT loyalty_ledger_required_text_check
            CHECK (
                length(trim(entry_key)) > 0
                AND length(trim(entry_type)) > 0
                AND length(trim(origin)) > 0
            )
        SQL);

        // PostgreSQL захищає історію від змін.
        // CREATE OR REPLACE дозволяє безпечно
        // повторно розгортати тестову схему.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_loyalty_ledger_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'loyalty_ledger_entries is append-only';
            END;
            $$
        SQL);

        // Заборона UPDATE та DELETE.
        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_ledger_block_update_delete
            BEFORE UPDATE OR DELETE ON loyalty_ledger_entries
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_block_loyalty_ledger_mutation()
        SQL);

        // Заборона TRUNCATE.
        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_ledger_block_truncate
            BEFORE TRUNCATE ON loyalty_ledger_entries
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_block_loyalty_ledger_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger_entries');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_block_loyalty_ledger_mutation()'
        );
    }
};
