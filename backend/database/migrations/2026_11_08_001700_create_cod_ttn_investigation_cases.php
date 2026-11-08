<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_ttn_investigation_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('case_key')->unique();
            $table->foreignId('intent_id')->unique()
                ->constrained('checkout_cod_ttn_submission_intents')->restrictOnDelete();
            $table->foreignId('trigger_event_id')
                ->constrained('checkout_cod_ttn_submission_safety_events')->restrictOnDelete();
            $table->char('intent_context_hmac', 64);
            $table->timestampTz('deadline_at', 6);
            $table->unsignedBigInteger('opened_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_ttn_investigation_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('case_id')
                ->constrained('checkout_cod_ttn_investigation_cases')->restrictOnDelete();
            $table->string('event_type', 16);
            $table->string('reason_code', 40);
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->timestampTz('deadline_at', 6)->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['case_id', 'id'], 'cod_ttn_investigation_timeline_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_investigation_cases
            ADD CONSTRAINT cod_ttn_case_values CHECK (
                opened_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND intent_context_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_investigation_events
            ADD CONSTRAINT cod_ttn_case_event_values CHECK (
                recorded_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (event_type = 'assign' AND reason_code IN ('initial_assignment', 'reassignment')
                        AND assignee_id IS NOT NULL AND assignee_id > 0 AND deadline_at IS NULL)
                    OR (event_type = 'escalate' AND reason_code IN
                        ('sla_breached', 'carrier_contact_needed', 'conflicting_evidence')
                        AND assignee_id IS NULL AND deadline_at IS NULL)
                    OR (event_type = 'reschedule' AND reason_code IN
                        ('carrier_response_pending', 'additional_review_needed')
                        AND assignee_id IS NULL AND deadline_at IS NOT NULL)
                    OR (event_type = 'archive' AND reason_code IN
                        ('inconclusive_reviewed', 'comparison_documented', 'carrier_escalation_documented')
                        AND assignee_id IS NULL AND deadline_at IS NULL)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_case_insert_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent RECORD;
            BEGIN
                SELECT i.context_hmac, e.intent_id AS event_intent, e.event_type
                INTO parent
                FROM checkout_cod_ttn_submission_intents i
                JOIN checkout_cod_ttn_submission_safety_events e ON e.id = NEW.trigger_event_id
                WHERE i.id = NEW.intent_id FOR UPDATE OF i;
                IF NOT FOUND OR parent.event_intent IS DISTINCT FROM NEW.intent_id
                   OR parent.event_type IS DISTINCT FROM 'reconciliation_required'
                   OR parent.context_hmac IS DISTINCT FROM NEW.intent_context_hmac
                   OR NEW.deadline_at <= CURRENT_TIMESTAMP
                   OR EXISTS (SELECT 1 FROM checkout_cod_ttn_submission_safety_events
                       WHERE intent_id = NEW.intent_id AND event_type = 'cancel_before_submission')
                THEN
                    RAISE EXCEPTION 'TTN investigation case must bind to an uncertain intent';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_case_event_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                c RECORD;
                last_assignee bigint;
                latest_scan RECORD;
                last_review RECORD;
            BEGIN
                SELECT id, intent_id, opened_by INTO c
                FROM checkout_cod_ttn_investigation_cases
                WHERE id = NEW.case_id FOR UPDATE;
                IF NOT FOUND OR EXISTS (
                    SELECT 1 FROM checkout_cod_ttn_investigation_events
                    WHERE case_id = NEW.case_id AND event_type = 'archive'
                ) THEN
                    RAISE EXCEPTION 'TTN investigation case is not writable';
                END IF;
                IF NEW.event_type <> 'assign' AND NOT EXISTS (
                    SELECT 1 FROM checkout_cod_ttn_investigation_events
                    WHERE case_id = NEW.case_id AND event_type = 'assign'
                ) THEN
                    RAISE EXCEPTION 'Investigation must be assigned before other actions';
                END IF;
                IF NEW.event_type = 'reschedule' AND (NEW.deadline_at <= CURRENT_TIMESTAMP
                    OR NEW.deadline_at > CURRENT_TIMESTAMP + interval '30 days') THEN
                    RAISE EXCEPTION 'Investigation deadline must be in the future';
                END IF;
                IF NEW.event_type = 'archive' THEN
                    SELECT assignee_id INTO last_assignee
                    FROM checkout_cod_ttn_investigation_events
                    WHERE case_id = NEW.case_id AND event_type = 'assign'
                    ORDER BY id DESC LIMIT 1;
                    SELECT id, scanned_by INTO latest_scan
                    FROM checkout_cod_ttn_reconciliation_scans
                    WHERE intent_id = c.intent_id ORDER BY id DESC LIMIT 1;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Investigation requires a scanned carrier result';
                    END IF;
                    SELECT decision, reviewed_by INTO last_review
                    FROM checkout_cod_ttn_reconciliation_reviews
                    WHERE scan_id = latest_scan.id;
                    IF NOT FOUND OR c.opened_by = NEW.recorded_by
                       OR last_assignee = NEW.recorded_by
                       OR latest_scan.scanned_by = NEW.recorded_by
                       OR last_review.reviewed_by = NEW.recorded_by
                       OR (NEW.reason_code = 'comparison_documented' AND NOT EXISTS (
                           SELECT 1 FROM checkout_cod_ttn_document_comparisons
                           WHERE scan_id = latest_scan.id
                       ))
                       OR (NEW.reason_code = 'carrier_escalation_documented'
                           AND last_review.decision <> 'escalate_to_carrier')
                    THEN
                        RAISE EXCEPTION 'TTN investigation archival requires independent review';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_case_history_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD TTN investigation history is immutable';
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER cod_ttn_case_insert_guard BEFORE INSERT ON checkout_cod_ttn_investigation_cases FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_case_insert_guard()');
        DB::statement('CREATE TRIGGER cod_ttn_case_event_guard BEFORE INSERT ON checkout_cod_ttn_investigation_events FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_case_event_guard()');
        DB::statement("CREATE UNIQUE INDEX cod_ttn_case_single_archive ON checkout_cod_ttn_investigation_events (case_id) WHERE event_type = 'archive'");
        foreach (['checkout_cod_ttn_investigation_cases', 'checkout_cod_ttn_investigation_events'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_case_history_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_ttn_case_history_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_ttn_investigation_events');
        Schema::dropIfExists('checkout_cod_ttn_investigation_cases');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_case_insert_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_case_event_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_case_history_immutable()');
    }
};
