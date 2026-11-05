<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_payment_review_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_case_id')->unique()
                ->constrained('checkout_payment_review_cases')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->foreignId('reviewer_user_id')
                ->constrained('users')->restrictOnDelete();
            $table->string('resolution', 24);
            $table->string('reason_note', 1000);
            $table->unsignedBigInteger('refund_amount_minor')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_payment_review_resolutions
            ADD CONSTRAINT checkout_review_resolutions_valid_check
            CHECK (
                char_length(btrim(reason_note)) BETWEEN 10 AND 1000
                AND (
                    (resolution = 'refund_proposed'
                        AND refund_amount_minor > 0)
                    OR
                    (resolution = 'no_refund'
                        AND refund_amount_minor IS NULL)
                )
            )
        SQL);

        // Це лише заявка для майбутнього перевіреного refund-adapter.
        // INSERT не викликає API провайдера й не змінює стан платежу.
        Schema::create('checkout_payment_refund_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resolution_id')->unique()
                ->constrained('checkout_payment_review_resolutions')->restrictOnDelete();
            $table->foreignId('receipt_id')->unique()
                ->constrained('checkout_payment_capture_receipts')->restrictOnDelete();
            $table->uuid('refund_key')->unique();
            $table->string('provider_code', 32);
            $table->string('provider_capture_id', 150);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')
                ->on('currencies')->restrictOnDelete();
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_payment_refund_intents
            ADD CONSTRAINT checkout_refund_intent_amount_check
            CHECK (amount_minor > 0)
        SQL);

        // Перевіряємо зв'язки та ліміти навіть для прямого SQL INSERT.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_validate_checkout_refund_intent()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                expected_receipt bigint;
                expected_decision text;
                expected_resolution text;
                expected_amount bigint;
                captured_amount bigint;
                captured_currency text;
                captured_provider text;
                captured_id text;
            BEGIN
                SELECT d.receipt_id, d.decision, r.resolution, r.refund_amount_minor
                INTO expected_receipt, expected_decision, expected_resolution, expected_amount
                FROM checkout_payment_review_resolutions r
                JOIN checkout_payment_review_cases c ON c.id = r.review_case_id
                JOIN checkout_payment_capture_decisions d ON d.id = c.decision_id
                WHERE r.id = NEW.resolution_id;

                IF NOT FOUND
                    OR expected_decision <> 'review'
                    OR expected_resolution <> 'refund_proposed'
                    OR expected_receipt <> NEW.receipt_id
                    OR expected_amount <> NEW.amount_minor
                THEN
                    RAISE EXCEPTION 'refund intent does not match approved review resolution';
                END IF;

                SELECT amount_minor, currency_code, provider_code, provider_capture_id
                INTO captured_amount, captured_currency, captured_provider, captured_id
                FROM checkout_payment_capture_receipts
                WHERE id = NEW.receipt_id;

                IF NOT FOUND
                    OR NEW.amount_minor > captured_amount
                    OR NEW.currency_code <> captured_currency
                    OR NEW.provider_code <> captured_provider
                    OR NEW.provider_capture_id <> captured_id
                THEN
                    RAISE EXCEPTION 'refund intent exceeds or mismatches verified capture';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_refund_intent_validate_insert
            BEFORE INSERT ON checkout_payment_refund_intents
            FOR EACH ROW EXECUTE FUNCTION boardriders_validate_checkout_refund_intent()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_review_resolution_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'payment review resolutions and refund intents are immutable';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_payment_review_resolutions',
                     'checkout_payment_refund_intents',
                 ] as $name) {
            DB::statement("CREATE TRIGGER {$name}_no_update_delete
                BEFORE UPDATE OR DELETE ON {$name}
                FOR EACH ROW EXECUTE FUNCTION boardriders_block_review_resolution_mutation()");
            DB::statement("CREATE TRIGGER {$name}_no_truncate
                BEFORE TRUNCATE ON {$name}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_block_review_resolution_mutation()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_payment_refund_intents');
        Schema::dropIfExists('checkout_payment_review_resolutions');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_validate_checkout_refund_intent()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_block_review_resolution_mutation()');
    }
};
