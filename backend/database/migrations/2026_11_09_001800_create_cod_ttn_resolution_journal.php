<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_ttn_resolution_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('intent_id')
                ->constrained('checkout_cod_ttn_submission_intents')
                ->restrictOnDelete();
            $table->foreignId('trigger_event_id')
                ->constrained('checkout_cod_ttn_submission_safety_events')
                ->restrictOnDelete();
            $table->foreignId('latest_scan_id')->nullable()
                ->constrained('checkout_cod_ttn_reconciliation_scans')
                ->restrictOnDelete();
            $table->char('context_hmac', 64);
            $table->char('evidence_hmac', 64);
            $table->string('outcome', 40);
            $table->unsignedBigInteger('created_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(
                ['intent_id', 'evidence_hmac'],
                'cod_ttn_unique_evidence_snapshot'
            );
            $table->index(
                ['intent_id', 'id'],
                'cod_ttn_resolution_history'
            );
        });

        Schema::create('checkout_cod_ttn_resolution_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('snapshot_id')->unique()
                ->constrained('checkout_cod_ttn_resolution_snapshots')
                ->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason_code', 48);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_resolution_snapshots
            ADD CONSTRAINT cod_ttn_resolution_snapshot_values CHECK (
                created_by > 0
                AND outcome IN (
                    'insufficient_evidence',
                    'conflicting_evidence',
                    'candidate_snapshot_consistent'
                )
                AND context_hmac ~ '^[a-f0-9]{64}$'
                AND evidence_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_resolution_reviews
            ADD CONSTRAINT cod_ttn_resolution_review_values CHECK (
                reviewed_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (
                        decision = 'retain_hold'
                        AND reason_code = 'evidence_not_authoritative'
                    )
                    OR (
                        decision = 'escalate_to_carrier'
                        AND reason_code = 'carrier_confirmation_required'
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_resolution_snapshot_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                original RECORD;
                latest_trigger bigint;
                latest_scan bigint;
            BEGIN
                SELECT id, context_hmac INTO original
                FROM checkout_cod_ttn_submission_intents
                WHERE id = NEW.intent_id FOR UPDATE;

                SELECT MAX(id) INTO latest_trigger
                FROM checkout_cod_ttn_submission_safety_events
                WHERE intent_id = NEW.intent_id
                  AND event_type = 'reconciliation_required';

                SELECT MAX(id) INTO latest_scan
                FROM checkout_cod_ttn_reconciliation_scans
                WHERE intent_id = NEW.intent_id;

                IF original.id IS NULL
                    OR latest_trigger IS NULL
                    OR latest_trigger IS DISTINCT FROM NEW.trigger_event_id
                    OR latest_scan IS DISTINCT FROM NEW.latest_scan_id
                    OR original.context_hmac IS DISTINCT FROM NEW.context_hmac
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_ttn_submission_safety_events
                        WHERE intent_id = NEW.intent_id
                          AND event_type = 'cancel_before_submission'
                    )
                    OR (
                        NEW.latest_scan_id IS NOT NULL
                        AND NOT EXISTS (
                            SELECT 1
                            FROM checkout_cod_ttn_reconciliation_scans
                            WHERE id = NEW.latest_scan_id
                              AND intent_id = NEW.intent_id
                              AND trigger_event_id = NEW.trigger_event_id
                        )
                    )
                THEN
                    RAISE EXCEPTION
                        'Resolution snapshot must bind to current uncertain TTN intent';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_resolution_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE owner_actor bigint;
            BEGIN
                SELECT created_by INTO owner_actor
                FROM checkout_cod_ttn_resolution_snapshots
                WHERE id = NEW.snapshot_id FOR UPDATE;

                IF NOT FOUND OR owner_actor = NEW.reviewed_by THEN
                    RAISE EXCEPTION
                        'Independent resolution reviewer required';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_resolution_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD TTN resolution journal is immutable';
            END;
            $$
        SQL);

        DB::statement('
            CREATE TRIGGER cod_ttn_resolution_snapshot_guard
            BEFORE INSERT ON checkout_cod_ttn_resolution_snapshots
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_ttn_resolution_snapshot_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_ttn_resolution_review_guard
            BEFORE INSERT ON checkout_cod_ttn_resolution_reviews
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_ttn_resolution_review_guard()
        ');

        foreach ([
                     'checkout_cod_ttn_resolution_snapshots',
                     'checkout_cod_ttn_resolution_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_ttn_resolution_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_ttn_resolution_immutable()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_ttn_resolution_reviews');
        Schema::dropIfExists('checkout_cod_ttn_resolution_snapshots');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_ttn_resolution_snapshot_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_ttn_resolution_review_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_ttn_resolution_immutable()'
        );
    }
};
