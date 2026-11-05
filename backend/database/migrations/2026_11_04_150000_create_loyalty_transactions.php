<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Журнал команд: один ключ — один результат.
        Schema::create('loyalty_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('wallet_id');
            $table->foreignId('market_id');
            $table->string('operation_key', 120);
            $table->string('operation_type', 48);
            $table->char('request_hash', 64);
            $table->jsonb('result');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(
                ['wallet_id', 'market_id'],
                'loyalty_ops_wallet_market_fk'
            )
                ->references(['id', 'market_id'])
                ->on('loyalty_wallets')
                ->restrictOnDelete();

            $table->unique(
                ['wallet_id', 'operation_key'],
                'loyalty_ops_wallet_key_unique'
            );
        });

        // Визначення резерву залишається незмінним.
        // Його стан визначає журнал проводок.
        Schema::create('loyalty_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id');
            $table->foreignId('market_id');

            $table->string('reservation_key', 120);
            $table->string('order_reference', 191);
            $table->bigInteger('units_total');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(
                ['wallet_id', 'market_id'],
                'loyalty_res_wallet_market_fk'
            )
                ->references(['id', 'market_id'])
                ->on('loyalty_wallets')
                ->restrictOnDelete();

            $table->unique(
                ['wallet_id', 'reservation_key'],
                'loyalty_res_wallet_key_unique'
            );

            $table->unique(
                ['id', 'wallet_id', 'market_id'],
                'loyalty_res_id_wallet_market_unique'
            );

            $table->index(
                ['expires_at', 'id'],
                'loyalty_res_expiry_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_reservations
            ADD CONSTRAINT loyalty_res_positive_units_check
            CHECK (units_total > 0)
        SQL);

        // Розподіл резерву за бонусними партіями.
        Schema::create('loyalty_reservation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id');
            $table->foreignId('grant_id');
            $table->foreignId('wallet_id');
            $table->foreignId('market_id');
            $table->bigInteger('units');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(
                ['reservation_id', 'wallet_id', 'market_id'],
                'loyalty_res_lines_res_fk'
            )
                ->references(['id', 'wallet_id', 'market_id'])
                ->on('loyalty_reservations')
                ->restrictOnDelete();

            $table->foreign(
                ['grant_id', 'wallet_id', 'market_id'],
                'loyalty_res_lines_grant_fk'
            )
                ->references(['id', 'wallet_id', 'market_id'])
                ->on('loyalty_grants')
                ->restrictOnDelete();

            $table->unique(
                ['reservation_id', 'grant_id'],
                'loyalty_res_lines_unique'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_reservation_lines
            ADD CONSTRAINT loyalty_res_lines_positive_units_check
            CHECK (units > 0)
        SQL);

        // Розширюємо існуючий immutable ledger.
        Schema::table('loyalty_ledger_entries', function (Blueprint $table) {
            $table->foreignId('grant_id')->nullable();
            $table->foreignId('reservation_id')->nullable();

            $table->foreign(
                ['grant_id', 'wallet_id', 'market_id'],
                'loyalty_ledger_grant_fk'
            )
                ->references(['id', 'wallet_id', 'market_id'])
                ->on('loyalty_grants')
                ->restrictOnDelete();

            $table->foreign(
                ['reservation_id', 'wallet_id', 'market_id'],
                'loyalty_ledger_reservation_fk'
            )
                ->references(['id', 'wallet_id', 'market_id'])
                ->on('loyalty_reservations')
                ->restrictOnDelete();

            $table->index(
                ['grant_id', 'id'],
                'loyalty_ledger_grant_order_idx'
            );

            $table->index(
                ['reservation_id', 'id'],
                'loyalty_ledger_res_order_idx'
            );
        });

        // Кожна проводка має посилатися на конкретну партію.
        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_ledger_entries
            ADD CONSTRAINT loyalty_ledger_grant_required_check
            CHECK (grant_id IS NOT NULL)
        SQL);

        // Батьківська команда створюється наприкінці
        // тієї самої транзакції. FK перевіряється при COMMIT.
        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_ledger_entries
            ADD CONSTRAINT loyalty_ledger_operation_fk
            FOREIGN KEY (operation_id)
            REFERENCES loyalty_operations(id)
            DEFERRABLE INITIALLY DEFERRED
        SQL);

        // Захист історії команд та резервів від редагування.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_block_loyalty_transaction_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'loyalty transaction history is append-only: %',
                    TG_TABLE_NAME;
            END;
            $$
        SQL);

        foreach ([
                     'loyalty_operations',
                     'loyalty_reservations',
                     'loyalty_reservation_lines',
                 ] as $tableName) {
            DB::statement("
                CREATE TRIGGER {$tableName}_no_changes
                BEFORE UPDATE OR DELETE ON {$tableName}
                FOR EACH ROW
                EXECUTE FUNCTION
                boardriders_block_loyalty_transaction_mutation()
            ");

            DB::statement("
                CREATE TRIGGER {$tableName}_no_truncate
                BEFORE TRUNCATE ON {$tableName}
                FOR EACH STATEMENT
                EXECUTE FUNCTION
                boardriders_block_loyalty_transaction_mutation()
            ");
        }
    }


    public function down(): void
    {
        DB::statement(
            'ALTER TABLE loyalty_ledger_entries DROP CONSTRAINT IF EXISTS loyalty_ledger_operation_fk'
        );

        DB::statement(
            'ALTER TABLE loyalty_ledger_entries DROP CONSTRAINT IF EXISTS loyalty_ledger_grant_required_check'
        );

        Schema::table('loyalty_ledger_entries', function (Blueprint $table) {
            $table->dropForeign('loyalty_ledger_grant_fk');
            $table->dropForeign('loyalty_ledger_reservation_fk');
            $table->dropIndex('loyalty_ledger_grant_order_idx');
            $table->dropIndex('loyalty_ledger_res_order_idx');
            $table->dropColumn(['grant_id', 'reservation_id']);
        });

        Schema::dropIfExists('loyalty_reservation_lines');
        Schema::dropIfExists('loyalty_reservations');
        Schema::dropIfExists('loyalty_operations');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_block_loyalty_transaction_mutation()'
        );
    }

};
