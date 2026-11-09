<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_fulfillment_staging', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('pack_check_id')->unique()
                ->constrained('checkout_cod_warehouse_pack_checks')
                ->restrictOnDelete();
            $table->char('source_hmac', 64);
            $table->unsignedInteger('expected_units');
            $table->unsignedBigInteger('staged_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_fulfillment_staging_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('staging_id')->unique()
                ->constrained('checkout_cod_fulfillment_staging')
                ->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('reason_code', 48);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_fulfillment_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('staging_id')
                ->constrained('checkout_cod_fulfillment_staging')
                ->restrictOnDelete();
            $table->string('reason_code', 48);
            $table->unsignedBigInteger('recorded_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['staging_id', 'id'], 'cod_fulfillment_holds_timeline');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_fulfillment_staging
            ADD CONSTRAINT cod_fulfillment_staging_values CHECK (
                expected_units BETWEEN 1 AND 19800 AND staged_by > 0
                AND source_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_fulfillment_staging_reviews
            ADD CONSTRAINT cod_fulfillment_review_values CHECK (
                reviewed_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (decision = 'accepted'
                        AND reason_code = 'internal_custody_verified')
                    OR (decision = 'rejected'
                        AND reason_code IN (
                            'packaging_doubt',
                            'stock_discrepancy',
                            'security_concern'
                        ))
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_fulfillment_holds
            ADD CONSTRAINT cod_fulfillment_hold_values CHECK (
                recorded_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND reason_code IN (
                    'carrier_document_missing',
                    'package_integrity_doubt',
                    'stock_hold_broken',
                    'manual_security_hold',
                    'reported_unknown_remote_outcome'
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_fulfillment_staging_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE src RECORD;
            BEGIN
                SELECT c.order_id, c.source_hmac, c.counted_units,
                       r.decision, p.intent_id, o.payment_status,
                       prep.expires_at
                INTO src
                FROM checkout_cod_warehouse_pack_checks c
                JOIN checkout_cod_warehouse_pack_reviews r
                    ON r.pack_check_id = c.id
                JOIN checkout_cod_ttn_dispatch_plans p
                    ON p.id = c.dispatch_plan_id
                JOIN checkout_cod_ttn_submission_intents i
                    ON i.id = p.intent_id
                JOIN orders o ON o.id = c.order_id
                JOIN checkout_order_preparations prep
                    ON prep.order_id = o.id
                WHERE c.id = NEW.pack_check_id
                FOR UPDATE OF i;

                IF NOT FOUND
                    OR src.order_id IS DISTINCT FROM NEW.order_id
                    OR src.source_hmac IS DISTINCT FROM NEW.source_hmac
                    OR src.counted_units IS DISTINCT FROM NEW.expected_units
                    OR src.decision IS DISTINCT FROM 'accepted'
                    OR src.payment_status IS DISTINCT FROM 'pending'
                    OR src.expires_at <= CURRENT_TIMESTAMP
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_ttn_submission_safety_events
                        WHERE intent_id = src.intent_id
                    )
                THEN
                    RAISE EXCEPTION
                        'COD fulfillment staging requires verified active pack';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_fulfillment_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE src RECORD;
            BEGIN
                SELECT s.staged_by, p.intent_id,
                       o.payment_status, prep.expires_at
                INTO src
                FROM checkout_cod_fulfillment_staging s
                JOIN checkout_cod_warehouse_pack_checks c
                    ON c.id = s.pack_check_id
                JOIN checkout_cod_ttn_dispatch_plans p
                    ON p.id = c.dispatch_plan_id
                JOIN checkout_cod_ttn_submission_intents i
                    ON i.id = p.intent_id
                JOIN orders o ON o.id = s.order_id
                JOIN checkout_order_preparations prep
                    ON prep.order_id = o.id
                WHERE s.id = NEW.staging_id
                FOR UPDATE OF i;

                IF NOT FOUND
                    OR src.staged_by = NEW.reviewed_by
                    OR src.payment_status IS DISTINCT FROM 'pending'
                    OR src.expires_at <= CURRENT_TIMESTAMP
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_fulfillment_holds
                        WHERE staging_id = NEW.staging_id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_ttn_submission_safety_events
                        WHERE intent_id = src.intent_id
                    )
                THEN
                    RAISE EXCEPTION
                        'COD fulfillment review requires independent unheld operator';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_fulfillment_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD fulfillment staging journal is immutable';
            END;
            $$
        SQL);

        DB::statement('
            CREATE TRIGGER cod_fulfillment_stage_guard
            BEFORE INSERT ON checkout_cod_fulfillment_staging
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_fulfillment_staging_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_fulfillment_review_guard
            BEFORE INSERT ON checkout_cod_fulfillment_staging_reviews
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_fulfillment_review_guard()
        ');

        foreach ([
                     'checkout_cod_fulfillment_staging',
                     'checkout_cod_fulfillment_staging_reviews',
                     'checkout_cod_fulfillment_holds',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_fulfillment_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_fulfillment_immutable()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_fulfillment_holds');
        Schema::dropIfExists('checkout_cod_fulfillment_staging_reviews');
        Schema::dropIfExists('checkout_cod_fulfillment_staging');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_fulfillment_staging_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_fulfillment_review_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_fulfillment_immutable()'
        );
    }
};
