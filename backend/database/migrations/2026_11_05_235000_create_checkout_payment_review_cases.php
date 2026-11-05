<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_payment_review_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->unique()
                ->constrained('checkout_payment_capture_decisions')
                ->restrictOnDelete();
            $table->string('case_type', 40);
            $table->string('suggested_action', 40);
            $table->unsignedBigInteger('captured_amount_minor');
            $table->char('captured_currency_code', 3);
            $table->unsignedBigInteger('expected_amount_minor')->nullable();
            $table->char('expected_currency_code', 3)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_payment_review_cases
            ADD CONSTRAINT checkout_payment_review_case_check
            CHECK (
                captured_amount_minor > 0
                AND case_type IN (
                    'possible_extra_capture',
                    'payment_mismatch',
                    'reservation_conflict',
                    'missing_checkout_context',
                    'other'
                )
                AND suggested_action IN (
                    'assess_refund',
                    'reconcile_amount',
                    'reconcile_fulfillment',
                    'investigate'
                )
                AND (
                    (expected_amount_minor IS NULL AND expected_currency_code IS NULL)
                    OR
                    (expected_amount_minor IS NOT NULL AND expected_currency_code IS NOT NULL)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_payment_review_case_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'payment review cases are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_payment_review_cases_no_update_delete
            BEFORE UPDATE OR DELETE ON checkout_payment_review_cases
            FOR EACH ROW EXECUTE FUNCTION boardriders_block_payment_review_case_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_payment_review_cases_no_truncate
            BEFORE TRUNCATE ON checkout_payment_review_cases
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_block_payment_review_case_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_payment_review_cases');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_block_payment_review_case_mutation()');
    }
};
