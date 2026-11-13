<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_correction_sla_cursor', function (Blueprint $t): void {
            $t->string('cursor_name', 32)->primary();
            $t->unsignedBigInteger('last_correction_id')->nullable();
            $t->timestampTz('updated_at', 6);
        });
        DB::table('checkout_cod_correction_sla_cursor')->insert([
            'cursor_name' => 'correction_sla_v48',
            'last_correction_id' => null,
            'updated_at' => now(),
        ]);

        Schema::create('checkout_cod_correction_sla_alerts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('correction_id')
                ->constrained('checkout_cod_daily_corrections')->restrictOnDelete();
            $t->foreignId('phase_event_id')
                ->constrained('checkout_cod_daily_correction_events')->restrictOnDelete();
            $t->string('phase', 16);
            $t->string('stage', 24);
            $t->string('severity', 12);
            $t->string('target_role', 32);
            $t->timestampTz('due_at', 6);
            $t->char('alert_hmac', 64);
            $t->timestampTz('created_at', 6);
            $t->unique(['correction_id', 'phase_event_id', 'stage'], 'cod_sla48_once_per_phase_stage');
            $t->index(['correction_id', 'id'], 'cod_sla48_correction_history');
            $t->index(['target_role', 'id'], 'cod_sla48_role_history');
        });

        Schema::create('checkout_cod_correction_sla_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('alert_id')->unique('cod_sla48_one_receipt')
                ->constrained('checkout_cod_correction_sla_alerts')->restrictOnDelete();
            $t->uuid('operation_key')->unique('cod_sla48_receipt_operation_once');
            $t->unsignedBigInteger('actor_id');
            $t->char('alert_hmac', 64);
            $t->char('receipt_hmac', 64);
            $t->timestampTz('created_at', 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_correction_sla_alerts
            ADD CONSTRAINT cod_sla48_alert_values CHECK (
                alert_hmac ~ '^[a-f0-9]{64}$'
                AND phase IN ('review', 'publication', 'rejection')
                AND stage IN ('due_soon', 'overdue', 'overdue_4h', 'overdue_24h', 'rejected_action')
                AND severity IN ('warning', 'high', 'critical')
                AND target_role IN ('finance_review', 'finance_publication', 'finance_management')
                AND (
                    (stage = 'due_soon' AND phase <> 'rejection' AND severity = 'warning'
                        AND target_role = CASE WHEN phase = 'review' THEN 'finance_review'
                            ELSE 'finance_publication' END)
                    OR (stage = 'rejected_action' AND phase = 'rejection'
                        AND severity = 'critical' AND target_role = 'finance_management')
                    OR (stage = 'overdue' AND severity = 'high'
                        AND target_role = CASE WHEN phase = 'review' THEN 'finance_review'
                            WHEN phase = 'publication' THEN 'finance_publication'
                            ELSE 'finance_management' END)
                    OR (stage IN ('overdue_4h', 'overdue_24h')
                        AND severity = 'critical' AND target_role = 'finance_management')
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_correction_sla_receipts
            ADD CONSTRAINT cod_sla48_receipt_values CHECK (
                actor_id > 0 AND alert_hmac ~ '^[a-f0-9]{64}$'
                AND receipt_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_sla48_alert_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD; v_latest BIGINT;
            BEGIN
                SELECT e.correction_id, e.event_kind, c.appendix_hmac
                INTO v FROM checkout_cod_daily_correction_events e
                JOIN checkout_cod_daily_corrections c ON c.id = e.correction_id
                WHERE e.id = NEW.phase_event_id
                FOR UPDATE OF c;
                IF NOT FOUND OR v.correction_id <> NEW.correction_id OR (
                    (NEW.phase = 'review' AND v.event_kind <> 'proposed') OR
                    (NEW.phase = 'publication' AND v.event_kind <> 'approved') OR
                    (NEW.phase = 'rejection' AND v.event_kind <> 'rejected')
                ) THEN
                    RAISE EXCEPTION 'COD SLA phase source mismatch';
                END IF;
                SELECT MAX(id) INTO v_latest
                FROM checkout_cod_daily_correction_events
                WHERE correction_id = NEW.correction_id
                    AND event_kind IN ('proposed', 'approved', 'rejected', 'published');
                IF v_latest IS DISTINCT FROM NEW.phase_event_id THEN
                    RAISE EXCEPTION 'COD SLA alert refers to superseded workflow phase';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_sla48_alert_guard
            BEFORE INSERT ON checkout_cod_correction_sla_alerts
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_sla48_alert_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_sla48_receipt_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD; v_phase BIGINT; v_alert BIGINT;
            BEGIN
                SELECT a.correction_id, a.phase_event_id, a.alert_hmac
                INTO v FROM checkout_cod_correction_sla_alerts a
                JOIN checkout_cod_daily_corrections c ON c.id = a.correction_id
                WHERE a.id = NEW.alert_id
                FOR UPDATE OF c;
                IF NOT FOUND OR v.alert_hmac <> NEW.alert_hmac THEN
                    RAISE EXCEPTION 'COD SLA receipt references invalid alert';
                END IF;
                SELECT MAX(id) INTO v_phase
                FROM checkout_cod_daily_correction_events
                WHERE correction_id = v.correction_id
                    AND event_kind IN ('proposed', 'approved', 'rejected', 'published');
                SELECT MAX(id) INTO v_alert
                FROM checkout_cod_correction_sla_alerts
                WHERE correction_id = v.correction_id;
                IF v_phase IS DISTINCT FROM v.phase_event_id OR v_alert IS DISTINCT FROM NEW.alert_id THEN
                    RAISE EXCEPTION 'Cannot acknowledge superseded COD SLA alert';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_sla48_receipt_guard
            BEFORE INSERT ON checkout_cod_correction_sla_receipts
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_sla48_receipt_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_sla48_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD correction SLA alert history is immutable';
            END;
            $$
        SQL);
        foreach (['checkout_cod_correction_sla_alerts', 'checkout_cod_correction_sla_receipts'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_sla48_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_sla48_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_correction_sla_receipts');
        Schema::dropIfExists('checkout_cod_correction_sla_alerts');
        Schema::dropIfExists('checkout_cod_correction_sla_cursor');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_sla48_alert_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_sla48_receipt_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_sla48_immutable()');
    }
};
