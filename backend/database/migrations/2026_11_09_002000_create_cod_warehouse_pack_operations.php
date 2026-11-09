<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_warehouse_pack_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('dispatch_plan_id')->unique()
                ->constrained('checkout_cod_ttn_dispatch_plans')->restrictOnDelete();
            $table->foreignId('manifest_id')->unique()
                ->constrained('checkout_cod_parcel_manifests')->restrictOnDelete();
            $table->foreignId('assessment_id')
                ->constrained('checkout_cod_package_assessments')->restrictOnDelete();
            $table->char('source_hmac', 64);
            $table->char('items_sha256', 64);
            $table->string('container_type', 24);
            $table->unsignedInteger('counted_units');
            $table->unsignedInteger('gross_grams');
            $table->unsignedInteger('length_mm');
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('height_mm');
            $table->unsignedBigInteger('checked_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_warehouse_pack_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('pack_check_id')->unique()
                ->constrained('checkout_cod_warehouse_pack_checks')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('reason_code', 48);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_warehouse_pick_slip_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('pack_check_id')
                ->constrained('checkout_cod_warehouse_pack_checks')->restrictOnDelete();
            $table->unsignedBigInteger('requested_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['pack_check_id', 'id'], 'cod_pick_slip_history_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_warehouse_pack_checks
            ADD CONSTRAINT cod_pack_check_values CHECK (
                container_type IN ('mailer', 'box', 'reinforced_box')
                AND counted_units BETWEEN 1 AND 19800
                AND gross_grams BETWEEN 1 AND 10000000
                AND length_mm BETWEEN 1 AND 100000
                AND width_mm BETWEEN 1 AND 100000
                AND height_mm BETWEEN 1 AND 100000
                AND checked_by > 0
                AND source_hmac ~ '^[a-f0-9]{64}$'
                AND items_sha256 ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_warehouse_pack_reviews
            ADD CONSTRAINT cod_pack_review_values CHECK (
                reviewed_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (decision = 'accepted'
                        AND reason_code = 'physical_count_and_dimensions_verified')
                    OR (decision = 'rejected'
                        AND reason_code IN ('physical_mismatch', 'packaging_damaged'))
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_warehouse_pick_slip_events
            ADD CONSTRAINT cod_pick_slip_values CHECK (
                requested_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_warehouse_pack_check_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE s RECORD;
            BEGIN
                SELECT p.order_id, p.intent_id, p.context_hmac,
                       i.context_hmac AS intent_hmac,
                       o.payment_status, prep.expires_at,
                       r.decision, m.order_id AS manifest_order, m.items_sha256,
                       m.total_units, m.gross_grams, a.id AS assessment_id,
                       a.manifest_id AS assessment_manifest, a.length_mm,
                       a.width_mm, a.height_mm, d.manifest_id AS draft_manifest,
                       d.assessment_id AS draft_assessment
                INTO s
                FROM checkout_cod_ttn_dispatch_plans p
                JOIN checkout_cod_ttn_submission_intents i
                    ON i.id = p.intent_id
                JOIN checkout_cod_ttn_dispatch_reviews r
                    ON r.plan_id = p.id
                JOIN orders o ON o.id = p.order_id
                JOIN checkout_order_preparations prep
                    ON prep.order_id = o.id
                JOIN checkout_cod_waybill_drafts d
                    ON d.order_id = o.id
                JOIN checkout_cod_parcel_manifests m
                    ON m.id = NEW.manifest_id
                JOIN checkout_cod_package_assessments a
                    ON a.id = NEW.assessment_id
                WHERE p.id = NEW.dispatch_plan_id
                FOR UPDATE OF i;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Warehouse pack source does not exist';
                END IF;

                IF s.order_id IS DISTINCT FROM NEW.order_id
                    OR s.manifest_order IS DISTINCT FROM NEW.order_id
                    OR s.context_hmac IS DISTINCT FROM s.intent_hmac
                    OR s.decision IS DISTINCT FROM 'reviewed_for_future_submission'
                    OR s.payment_status IS DISTINCT FROM 'pending'
                    OR s.expires_at <= CURRENT_TIMESTAMP
                    OR s.draft_manifest IS DISTINCT FROM NEW.manifest_id
                    OR s.draft_assessment IS DISTINCT FROM NEW.assessment_id
                    OR s.assessment_manifest IS DISTINCT FROM NEW.manifest_id
                    OR s.items_sha256 IS DISTINCT FROM NEW.items_sha256
                    OR s.total_units IS DISTINCT FROM NEW.counted_units
                    OR s.gross_grams IS DISTINCT FROM NEW.gross_grams
                    OR s.length_mm IS DISTINCT FROM NEW.length_mm
                    OR s.width_mm IS DISTINCT FROM NEW.width_mm
                    OR s.height_mm IS DISTINCT FROM NEW.height_mm
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_ttn_submission_safety_events
                        WHERE intent_id = s.intent_id
                    )
                THEN
                    RAISE EXCEPTION
                        'Warehouse pack check must match an active reviewed dispatch plan';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_warehouse_pack_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE s RECORD;
            BEGIN
                SELECT c.checked_by, p.intent_id,
                       o.payment_status, prep.expires_at
                INTO s
                FROM checkout_cod_warehouse_pack_checks c
                JOIN checkout_cod_ttn_dispatch_plans p
                    ON p.id = c.dispatch_plan_id
                JOIN checkout_cod_ttn_submission_intents i
                    ON i.id = p.intent_id
                JOIN orders o ON o.id = c.order_id
                JOIN checkout_order_preparations prep
                    ON prep.order_id = o.id
                WHERE c.id = NEW.pack_check_id
                FOR UPDATE OF i;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Warehouse pack review source does not exist';
                END IF;

                IF s.checked_by = NEW.reviewed_by
                    OR s.payment_status IS DISTINCT FROM 'pending'
                    OR s.expires_at <= CURRENT_TIMESTAMP
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_ttn_submission_safety_events
                        WHERE intent_id = s.intent_id
                    )
                THEN
                    RAISE EXCEPTION
                        'Independent warehouse review requires an active unheld plan';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_warehouse_pick_slip_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE s RECORD;
            BEGIN
                SELECT p.intent_id, r.decision,
                       o.payment_status, prep.expires_at
                INTO s
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

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Warehouse pick slip source does not exist';
                END IF;

                IF s.decision IS DISTINCT FROM 'accepted'
                    OR s.payment_status IS DISTINCT FROM 'pending'
                    OR s.expires_at <= CURRENT_TIMESTAMP
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_ttn_submission_safety_events
                        WHERE intent_id = s.intent_id
                    )
                THEN
                    RAISE EXCEPTION
                        'Pick slip requires an accepted active pack check';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_warehouse_pack_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Warehouse pack journal is immutable';
            END;
            $$
        SQL);

        DB::statement('
            CREATE TRIGGER cod_pack_check_guard
            BEFORE INSERT ON checkout_cod_warehouse_pack_checks
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_warehouse_pack_check_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_pack_review_guard
            BEFORE INSERT ON checkout_cod_warehouse_pack_reviews
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_warehouse_pack_review_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_pick_slip_guard
            BEFORE INSERT ON checkout_cod_warehouse_pick_slip_events
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_warehouse_pick_slip_guard()
        ');

        foreach ([
                     'checkout_cod_warehouse_pack_checks',
                     'checkout_cod_warehouse_pack_reviews',
                     'checkout_cod_warehouse_pick_slip_events',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_warehouse_pack_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_warehouse_pack_immutable()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_warehouse_pick_slip_events');
        Schema::dropIfExists('checkout_cod_warehouse_pack_reviews');
        Schema::dropIfExists('checkout_cod_warehouse_pack_checks');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_warehouse_pack_check_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_warehouse_pack_review_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_warehouse_pick_slip_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_warehouse_pack_immutable()'
        );
    }
};
