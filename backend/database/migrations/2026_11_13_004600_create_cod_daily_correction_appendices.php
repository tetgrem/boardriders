<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_daily_corrections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('report_id')->constrained('checkout_cod_daily_reports')->restrictOnDelete();
            $t->uuid('correction_key')->unique('cod_c46_key_once');
            $t->uuid('operation_key')->unique('cod_c46_operation_once');
            $t->string('reason_code', 48);
            $t->char('base_report_hmac', 64);
            $t->jsonb('observation_json');
            $t->char('observation_hmac', 64);
            $t->char('appendix_hmac', 64);
            $t->unsignedBigInteger('proposed_by');
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['report_id', 'observation_hmac'], 'cod_c46_observation_once');
            $t->index(['report_id', 'id'], 'cod_c46_report_history');
        });

        Schema::create('checkout_cod_daily_correction_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('correction_id')->constrained('checkout_cod_daily_corrections')->restrictOnDelete();
            $t->uuid('event_key')->unique('cod_c46_event_once');
            $t->string('event_kind', 24);
            $t->string('reason_code', 48);
            $t->unsignedBigInteger('actor_id');
            $t->char('appendix_hmac', 64);
            $t->char('previous_hmac', 64)->nullable();
            $t->char('event_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['correction_id', 'id'], 'cod_c46_events_history');
        });

        Schema::table('checkout_cod_bank_receipt_postings', function (Blueprint $t): void {
            $t->index(['booked_on', 'created_at'], 'cod_c46_late_bookings');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_daily_corrections
            ADD CONSTRAINT cod_c46_record_values CHECK (
                proposed_by > 0
                AND reason_code IN (
                    'late_bank_booking', 'late_register',
                    'late_financial_evidence', 'financial_projection_changed'
                )
                AND base_report_hmac ~ '^[a-f0-9]{64}$'
                AND observation_hmac ~ '^[a-f0-9]{64}$'
                AND appendix_hmac ~ '^[a-f0-9]{64}$'
                AND jsonb_typeof(observation_json) = 'object'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_daily_correction_events
            ADD CONSTRAINT cod_c46_event_values CHECK (
                actor_id > 0
                AND appendix_hmac ~ '^[a-f0-9]{64}$'
                AND event_hmac ~ '^[a-f0-9]{64}$'
                AND (previous_hmac IS NULL OR previous_hmac ~ '^[a-f0-9]{64}$')
                AND (
                    (event_kind = 'proposed' AND reason_code IN (
                        'late_bank_booking', 'late_register',
                        'late_financial_evidence', 'financial_projection_changed'
                    )) OR (event_kind = 'approved' AND reason_code = 'evidence_reviewed')
                    OR (event_kind = 'rejected' AND reason_code = 'insufficient_evidence')
                    OR (event_kind = 'published' AND reason_code = 'approved_appendix')
                    OR (event_kind = 'export_requested' AND reason_code = 'csv_appendix')
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_c46_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD daily correction evidence is immutable';
            END;
            $$
        SQL);

        foreach (['checkout_cod_daily_corrections', 'checkout_cod_daily_correction_events'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_c46_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_c46_immutable()");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_c46_event_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v RECORD;
                v_prev_kind TEXT;
                v_prev_hmac CHAR(64);
                v_reviewer BIGINT;
            BEGIN
                SELECT c.*, r.sealed_by, r.payload_hmac AS report_hmac
                INTO v FROM checkout_cod_daily_corrections c
                JOIN checkout_cod_daily_reports r ON r.id = c.report_id
                WHERE c.id = NEW.correction_id
                FOR UPDATE OF c;

                IF NOT FOUND OR NEW.appendix_hmac <> v.appendix_hmac
                    OR v.base_report_hmac <> v.report_hmac THEN
                    RAISE EXCEPTION 'COD correction event references invalid appendix';
                END IF;

                SELECT event_kind, event_hmac INTO v_prev_kind, v_prev_hmac
                FROM checkout_cod_daily_correction_events
                WHERE correction_id = NEW.correction_id
                ORDER BY id DESC LIMIT 1;

                IF NEW.previous_hmac IS DISTINCT FROM v_prev_hmac THEN
                    RAISE EXCEPTION 'COD correction audit chain is broken';
                END IF;

                IF v_prev_kind IS NULL THEN
                    IF NEW.event_kind <> 'proposed'
                        OR NEW.event_key <> v.operation_key
                        OR NEW.actor_id <> v.proposed_by
                        OR NEW.reason_code <> v.reason_code
                        OR v.proposed_by = v.sealed_by THEN
                        RAISE EXCEPTION 'Invalid COD correction proposal';
                    END IF;
                ELSIF v_prev_kind = 'proposed' THEN
                    IF NEW.event_kind NOT IN ('approved', 'rejected')
                        OR NEW.actor_id IN (v.proposed_by, v.sealed_by) THEN
                        RAISE EXCEPTION 'Independent correction review required';
                    END IF;
                ELSIF v_prev_kind = 'approved' THEN
                    SELECT actor_id INTO v_reviewer
                    FROM checkout_cod_daily_correction_events
                    WHERE correction_id = NEW.correction_id AND event_kind = 'approved';
                    IF NEW.event_kind <> 'published'
                        OR NEW.actor_id IN (v.proposed_by, v.sealed_by, v_reviewer) THEN
                        RAISE EXCEPTION 'Independent correction publisher required';
                    END IF;
                ELSIF v_prev_kind IN ('published', 'export_requested') THEN
                    IF NEW.event_kind <> 'export_requested' THEN
                        RAISE EXCEPTION 'Published correction has no further transitions';
                    END IF;
                ELSE
                    RAISE EXCEPTION 'Rejected correction is terminal';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_c46_event_guard
            BEFORE INSERT ON checkout_cod_daily_correction_events
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_c46_event_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_c46_require_proposal()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM checkout_cod_daily_correction_events
                    WHERE correction_id = NEW.id AND event_kind = 'proposed'
                      AND event_key = NEW.operation_key
                ) THEN
                    RAISE EXCEPTION 'COD correction requires an immutable proposal event';
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER cod_c46_proposal_required
            AFTER INSERT ON checkout_cod_daily_corrections
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_c46_require_proposal()
        SQL);
    }

    public function down(): void
    {
        Schema::table('checkout_cod_bank_receipt_postings', function (Blueprint $t): void {
            $t->dropIndex('cod_c46_late_bookings');
        });
        Schema::dropIfExists('checkout_cod_daily_correction_events');
        Schema::dropIfExists('checkout_cod_daily_corrections');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_c46_require_proposal()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_c46_event_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_c46_immutable()');
    }
};
