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
         * Партії бонусних нарахувань.
         *
         * Кожна партія зберігає первинні умови.
         * Поточні pending / available / reserved / debt
         * визначатимуться з ledger, а не з mutable status.
         */
        Schema::create('loyalty_grants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id');
            $table->foreignId('market_id');

            // Унікальний ідентифікатор нарахування.
            // Повторна подія не створює дубль.
            $table->string('grant_key', 191);

            // Тип нарахування: purchase, action,
            // referral, birthday, compensation тощо.
            $table->string('grant_type', 48);

            // Джерело нарахування.
            $table->string('source_type', 48);
            $table->string('source_ref', 191)->nullable();

            // Бонусна програма та версія її правил.
            $table->string('program_code', 64);
            $table->string('rule_version', 80);

            // Початковий розмір партії в облікових
            // одиницях бонусної програми.
            // Використовуємо тільки цілі значення.
            $table->bigInteger('units_total');

            // Коли право на нарахування виникло.
            $table->timestampTz('granted_at');

            // Найраніший можливий момент активації.
            // NULL означає відсутність часової затримки,
            // але НЕ скасовує перевірки інших умов.
            $table->timestampTz('activation_not_before_at')
                ->nullable();

            // Початковий строк дії.
            // NULL означає безстрокову партію.
            $table->timestampTz('expires_at')->nullable();

            // Умови активації: повернення, модерація,
            // підтвердження дії та інші перевірки.
            $table->jsonb('activation_requirements')
                ->nullable();

            // Незмінний знімок застосованих правил.
            $table->jsonb('rule_snapshot');

            // Додаткова інформація для аудиту.
            $table->jsonb('context')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            // Бонусна партія не може належати
            // іншому ринку, ніж її гаманець.
            $table->foreign(
                ['wallet_id', 'market_id'],
                'loyalty_grants_wallet_market_fk'
            )
                ->references(['id', 'market_id'])
                ->on('loyalty_wallets')
                ->restrictOnDelete();

            $table->unique(
                ['wallet_id', 'grant_key'],
                'loyalty_grants_wallet_key_unique'
            );

            // Для складених зовнішніх ключів
            // майбутніх операцій з партіями.
            $table->unique(
                ['id', 'wallet_id', 'market_id'],
                'loyalty_grants_id_wallet_market_unique'
            );

            $table->index(
                ['wallet_id', 'expires_at'],
                'loyalty_grants_wallet_expiry_idx'
            );

            $table->index(
                ['wallet_id', 'activation_not_before_at'],
                'loyalty_grants_wallet_activation_idx'
            );

            $table->index(
                ['source_type', 'source_ref'],
                'loyalty_grants_source_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_grants
            ADD CONSTRAINT loyalty_grants_positive_units_check
            CHECK (units_total > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_grants
            ADD CONSTRAINT loyalty_grants_required_text_check
            CHECK (
                length(trim(grant_key)) > 0
                AND length(trim(grant_type)) > 0
                AND length(trim(source_type)) > 0
                AND length(trim(program_code)) > 0
                AND length(trim(rule_version)) > 0
            )
        SQL);

        /*
         * Історія змін строків дії.
         *
         * Первинний expires_at у loyalty_grants
         * ніколи не перезаписується.
         *
         * Кожне продовження, скорочення або
         * встановлення безстроковості створює
         * окремий запис з причиною та версією правил.
         */
        Schema::create(
            'loyalty_grant_expiry_adjustments',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('grant_id');
                $table->foreignId('wallet_id');
                $table->foreignId('market_id');

                // Ідемпотентність конкретної зміни.
                $table->string('adjustment_key', 191);

                // NULL означає безстроковість.
                $table->timestampTz('old_expires_at')
                    ->nullable();

                $table->timestampTz('new_expires_at')
                    ->nullable();

                $table->string('reason_code', 80);
                $table->string('rule_version', 80);

                // NULL для системної операції.
                $table->foreignId('actor_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->jsonb('context')->nullable();

                // Фактичний час прийняття рішення.
                $table->timestampTz('effective_at');

                // Час запису в базу.
                $table->timestampTz('created_at')
                    ->useCurrent();

                // Захист від прив'язки зміни
                // до чужого гаманця або ринку.
                $table->foreign(
                    ['grant_id', 'wallet_id', 'market_id'],
                    'loyalty_grant_expiry_grant_fk'
                )
                    ->references(
                        ['id', 'wallet_id', 'market_id']
                    )
                    ->on('loyalty_grants')
                    ->restrictOnDelete();

                $table->unique(
                    ['grant_id', 'adjustment_key'],
                    'loyalty_grant_expiry_key_unique'
                );

                $table->index(
                    ['grant_id', 'id'],
                    'loyalty_grant_expiry_history_idx'
                );
            }
        );

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_grant_expiry_adjustments
            ADD CONSTRAINT loyalty_grant_expiry_changed_check
            CHECK (
                old_expires_at IS DISTINCT FROM new_expires_at
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_grant_expiry_adjustments
            ADD CONSTRAINT loyalty_grant_expiry_text_check
            CHECK (
                length(trim(adjustment_key)) > 0
                AND length(trim(reason_code)) > 0
                AND length(trim(rule_version)) > 0
            )
        SQL);

        /*
         * Захищаємо обидві таблиці від UPDATE,
         * DELETE та TRUNCATE.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_block_loyalty_grant_history_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'loyalty grant history is append-only: %',
                    TG_TABLE_NAME;
            END;
            $$
        SQL);

        foreach ([
                     'loyalty_grants',
                     'loyalty_grant_expiry_adjustments',
                 ] as $tableName) {
            DB::statement("
                CREATE TRIGGER {$tableName}_block_update_delete
                BEFORE UPDATE OR DELETE ON {$tableName}
                FOR EACH ROW
                EXECUTE FUNCTION
                boardriders_block_loyalty_grant_history_mutation()
            ");

            DB::statement("
                CREATE TRIGGER {$tableName}_block_truncate
                BEFORE TRUNCATE ON {$tableName}
                FOR EACH STATEMENT
                EXECUTE FUNCTION
                boardriders_block_loyalty_grant_history_mutation()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'loyalty_grant_expiry_adjustments'
        );

        Schema::dropIfExists('loyalty_grants');

        DB::statement(<<<'SQL'
            DROP FUNCTION IF EXISTS
            boardriders_block_loyalty_grant_history_mutation()
        SQL);
    }
};
