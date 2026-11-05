<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_redemption_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id');
            $table->foreignId('market_id');
            $table->foreignId('reservation_id');
            $table->foreignId('grant_id');
            $table->uuid('operation_id');
            $table->string('refund_reference', 191);
            $table->bigInteger('units');
            $table->bigInteger('debt_repaid_units')->default(0);
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(
                ['reservation_id', 'wallet_id', 'market_id'],
                'loyalty_refunds_reservation_market_fk'
            )->references(['id', 'wallet_id', 'market_id'])
                ->on('loyalty_reservations')->restrictOnDelete();

            $table->foreign(
                ['grant_id', 'wallet_id', 'market_id'],
                'loyalty_refunds_grant_market_fk'
            )->references(['id', 'wallet_id', 'market_id'])
                ->on('loyalty_grants')->restrictOnDelete();

            $table->foreign(
                ['reservation_id', 'grant_id'],
                'loyalty_refunds_original_allocation_fk'
            )->references(['reservation_id', 'grant_id'])
                ->on('loyalty_reservation_lines')->restrictOnDelete();

            $table->unique(
                ['reservation_id', 'grant_id', 'refund_reference'],
                'loyalty_refunds_event_grant_unique'
            );

            $table->index(
                ['reservation_id', 'grant_id'],
                'loyalty_refunds_res_grant_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_redemption_refunds
            ADD CONSTRAINT loyalty_refunds_positive_units_check
            CHECK (
                units > 0
                AND debt_repaid_units >= 0
                AND debt_repaid_units <= units
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_redemption_refunds
            ADD CONSTRAINT loyalty_refunds_ref_not_empty_check
            CHECK (length(trim(refund_reference)) > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_redemption_refunds
            ADD CONSTRAINT loyalty_refunds_operation_fk
            FOREIGN KEY (operation_id)
            REFERENCES loyalty_operations(id)
            DEFERRABLE INITIALLY DEFERRED
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION
            boardriders_block_loyalty_redemption_refund_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'loyalty_redemption_refunds is append-only';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_refunds_no_update_delete
            BEFORE UPDATE OR DELETE ON loyalty_redemption_refunds
            FOR EACH ROW
            EXECUTE FUNCTION
            boardriders_block_loyalty_redemption_refund_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_refunds_no_truncate
            BEFORE TRUNCATE ON loyalty_redemption_refunds
            FOR EACH STATEMENT
            EXECUTE FUNCTION
            boardriders_block_loyalty_redemption_refund_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemption_refunds');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_block_loyalty_redemption_refund_mutation()'
        );
    }
};
