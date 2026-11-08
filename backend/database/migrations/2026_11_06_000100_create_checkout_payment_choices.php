<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_payment_choices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->foreign('order_id')
                ->references('order_id')
                ->on('checkout_order_preparations')
                ->restrictOnDelete();
            $table->string('payment_method', 24);
            $table->string('provider_code', 32)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_payment_choices
            ADD CONSTRAINT checkout_payment_choices_valid_check
            CHECK (
                (
                    payment_method IN ('card', 'apple_pay', 'google_pay')
                    AND provider_code IS NOT NULL
                    AND provider_code IN ('mono', 'liqpay')
                )
                OR (
                    payment_method = 'cash_on_delivery'
                    AND provider_code IS NULL
                )
            )
        SQL);

        // SQL guard: a choice is possible only on a live pending preparation,
        // before a provider attempt or verified capture has been recorded.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_validate_checkout_payment_choice()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1
                FROM orders o
                JOIN checkout_order_preparations p ON p.order_id = o.id
                WHERE o.id = NEW.order_id
                  AND o.payment_status = 'pending'
                  AND p.expires_at > CURRENT_TIMESTAMP
                FOR UPDATE OF o;

                IF NOT FOUND
                    OR EXISTS (
                        SELECT 1 FROM checkout_payment_attempts a
                        WHERE a.order_id = NEW.order_id
                    )
                    OR EXISTS (
                        SELECT 1 FROM checkout_payment_capture_receipts r
                        WHERE r.order_id = NEW.order_id
                    )
                THEN
                    RAISE EXCEPTION 'payment choice requires an unpaid prepared checkout';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_payment_choices_validate_insert
            BEFORE INSERT ON checkout_payment_choices
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_validate_checkout_payment_choice()
        SQL);

        // Backward-compatible: older checkouts without a choice can still use
        // payment attempts. With a choice present, provider must match and
        // COD must NEVER create an online payment attempt.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_guard_checkout_payment_attempt_choice()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1 FROM orders WHERE id = NEW.order_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1 FROM checkout_payment_choices c
                    WHERE c.order_id = NEW.order_id
                      AND (
                          c.payment_method = 'cash_on_delivery'
                          OR c.provider_code <> NEW.provider_code
                      )
                ) THEN
                    RAISE EXCEPTION 'payment attempt conflicts with chosen payment method';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_payment_attempts_guard_choice_insert
            BEFORE INSERT ON checkout_payment_attempts
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_guard_checkout_payment_attempt_choice()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_checkout_payment_choice_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'checkout payment choices are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_payment_choices_no_update_delete
            BEFORE UPDATE OR DELETE ON checkout_payment_choices
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_block_checkout_payment_choice_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_payment_choices_no_truncate
            BEFORE TRUNCATE ON checkout_payment_choices
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_block_checkout_payment_choice_mutation()
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS checkout_payment_attempts_guard_choice_insert ON checkout_payment_attempts'
        );

        Schema::dropIfExists('checkout_payment_choices');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_validate_checkout_payment_choice()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_guard_checkout_payment_attempt_choice()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_block_checkout_payment_choice_mutation()'
        );
    }
};
