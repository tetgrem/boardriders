<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_ttn_dispatch_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('intent_id')->unique()
                ->constrained('checkout_cod_ttn_submission_intents')
                ->restrictOnDelete();
            $table->char('context_hmac', 64);
            $table->unsignedBigInteger('prepared_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_ttn_dispatch_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('plan_id')->unique()
                ->constrained('checkout_cod_ttn_dispatch_plans')
                ->restrictOnDelete();
            $table->string('decision', 40);
            $table->string('reason_code', 48);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_dispatch_plans
            ADD CONSTRAINT cod_ttn_dispatch_plan_values CHECK (
                prepared_by > 0 AND context_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_dispatch_reviews
            ADD CONSTRAINT cod_ttn_dispatch_review_values CHECK (
                reviewed_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (decision = 'reviewed_for_future_submission'
                        AND reason_code = 'local_plan_checked')
                    OR (decision = 'rejected'
                        AND reason_code IN (
                            'stale_or_conflicting_data',
                            'manual_risk_rejection'
                        ))
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_dispatch_plan_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE source RECORD;
            BEGIN
                SELECT i.id, i.order_id, i.context_hmac,
                       o.payment_status, p.expires_at
                INTO source
                FROM checkout_cod_ttn_submission_intents i
                JOIN orders o ON o.id = i.order_id
                JOIN checkout_order_preparations p ON p.order_id = i.order_id
                WHERE i.id = NEW.intent_id FOR UPDATE OF i;

                IF NOT FOUND
                    OR source.order_id IS DISTINCT FROM NEW.order_id
                    OR source.context_hmac IS DISTINCT FROM NEW.context_hmac
                    OR source.payment_status IS DISTINCT FROM 'pending'
                    OR source.expires_at <= CURRENT_TIMESTAMP
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_ttn_submission_safety_events
                        WHERE intent_id = NEW.intent_id
                    )
                THEN
                    RAISE EXCEPTION 'TTN dispatch plan cannot be prepared';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_dispatch_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE source RECORD;
            BEGIN
                SELECT p.prepared_by, p.intent_id
                INTO source
                FROM checkout_cod_ttn_dispatch_plans p
                JOIN checkout_cod_ttn_submission_intents i
                  ON i.id = p.intent_id
                WHERE p.id = NEW.plan_id FOR UPDATE OF i;

                IF NOT FOUND
                    OR source.prepared_by = NEW.reviewed_by
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_ttn_submission_safety_events
                        WHERE intent_id = source.intent_id
                    )
                THEN
                    RAISE EXCEPTION
                        'TTN dispatch review is not independent or safe';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_dispatch_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD TTN dispatch preparation history is immutable';
            END;
            $$
        SQL);

        DB::statement('
            CREATE TRIGGER cod_ttn_dispatch_plan_insert_guard
            BEFORE INSERT ON checkout_cod_ttn_dispatch_plans
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_ttn_dispatch_plan_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_ttn_dispatch_review_insert_guard
            BEFORE INSERT ON checkout_cod_ttn_dispatch_reviews
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_ttn_dispatch_review_guard()
        ');

        foreach ([
                     'checkout_cod_ttn_dispatch_plans',
                     'checkout_cod_ttn_dispatch_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_ttn_dispatch_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_ttn_dispatch_immutable()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_ttn_dispatch_reviews');
        Schema::dropIfExists('checkout_cod_ttn_dispatch_plans');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_ttn_dispatch_plan_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_ttn_dispatch_review_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_ttn_dispatch_immutable()'
        );
    }
};
