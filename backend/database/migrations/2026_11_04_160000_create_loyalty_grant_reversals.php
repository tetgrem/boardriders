<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Кожне анулювання нарахування зберігається окремо.
        // Жоден початковий grant або ledger entry не переписується.
        Schema::create('loyalty_grant_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grant_id');
            $table->foreignId('wallet_id');
            $table->foreignId('market_id');
            $table->uuid('operation_id');
            $table->string('reversal_key', 120);
            $table->string('reason_code', 80);
            $table->string('source_reference', 191);
            $table->string('rule_version', 80);
            $table->bigInteger('units_total');
            $table->bigInteger('pending_units')->default(0);
            $table->bigInteger('available_units')->default(0);
            $table->bigInteger('expired_units')->default(0);
            $table->bigInteger('debt_units')->default(0);
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(
                ['grant_id', 'wallet_id', 'market_id'],
                'loyalty_grant_reversals_grant_fk'
            )->references(['id', 'wallet_id', 'market_id'])
                ->on('loyalty_grants')->restrictOnDelete();

            $table->unique(
                ['wallet_id', 'reversal_key'],
                'loyalty_grant_reversals_wallet_key_unique'
            );

            // Один підтверджений рядок повернення не може
            // анулювати ту саму партію повторно.
            $table->unique(
                ['grant_id', 'source_reference'],
                'loyalty_grant_reversals_source_unique'
            );

            $table->index(
                ['grant_id', 'id'],
                'loyalty_grant_reversals_grant_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_grant_reversals
            ADD CONSTRAINT loyalty_grant_reversals_units_check CHECK (
                units_total > 0
                AND pending_units >= 0
                AND available_units >= 0
                AND expired_units >= 0
                AND debt_units >= 0
                AND units_total = pending_units + available_units + expired_units + debt_units
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_grant_reversals
            ADD CONSTRAINT loyalty_grant_reversals_text_check CHECK (
                length(trim(reversal_key)) > 0
                AND length(trim(reason_code)) > 0
                AND length(trim(source_reference)) > 0
                AND length(trim(rule_version)) > 0
            )
        SQL);

        // Команда з loyalty_operations створюється наприкінці
        // тієї самої транзакції.
        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_grant_reversals
            ADD CONSTRAINT loyalty_grant_reversals_operation_fk
            FOREIGN KEY (operation_id)
            REFERENCES loyalty_operations(id)
            DEFERRABLE INITIALLY DEFERRED
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_loyalty_reversal_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'loyalty_grant_reversals is append-only';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_grant_reversals_no_changes
            BEFORE UPDATE OR DELETE ON loyalty_grant_reversals
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_block_loyalty_reversal_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_grant_reversals_no_truncate
            BEFORE TRUNCATE ON loyalty_grant_reversals
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_block_loyalty_reversal_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_grant_reversals');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_block_loyalty_reversal_mutation()'
        );
    }
};
