<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_daily_reports', function (Blueprint $t): void {
            $t->id();
            $t->uuid('report_key')->unique('cod_daily45_report_key_once');
            $t->date('report_date')->unique('cod_daily45_date_once');
            $t->uuid('operation_key')->unique('cod_daily45_seal_operation_once');
            $t->unsignedSmallInteger('schema_version');
            $t->jsonb('payload_json');
            $t->jsonb('reconciliation_json');
            $t->char('payload_hmac', 64);
            $t->unsignedBigInteger('sealed_by');
            $t->timestampTz('sealed_at', 6);
            $t->index(['id', 'report_date'], 'cod_daily45_history');
        });

        Schema::create('checkout_cod_daily_report_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('report_id');
            $t->foreign('report_id', 'cod_daily45_event_report_fk')
                ->references('id')->on('checkout_cod_daily_reports')->restrictOnDelete();
            $t->uuid('event_key')->unique('cod_daily45_event_once');
            $t->string('event_kind', 24);
            $t->unsignedBigInteger('actor_id');
            $t->char('report_hmac', 64);
            $t->char('event_hmac', 64);
            $t->timestampTz('created_at', 6);
            $t->index(['report_id', 'id'], 'cod_daily45_events_history');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_daily_reports
            ADD CONSTRAINT cod_daily45_report_values CHECK (
                schema_version = 1
                AND sealed_by > 0
                AND payload_hmac ~ '^[a-f0-9]{64}$'
                AND jsonb_typeof(payload_json) = 'object'
                AND jsonb_typeof(reconciliation_json) = 'object'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_daily_report_events
            ADD CONSTRAINT cod_daily45_event_values CHECK (
                event_kind IN ('sealed', 'export_requested')
                AND actor_id > 0
                AND report_hmac ~ '^[a-f0-9]{64}$'
                AND event_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_daily45_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD daily report and audit history are immutable';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_daily_reports',
                     'checkout_cod_daily_report_events',
                 ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_daily45_immutable()");

            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_daily45_immutable()");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_daily45_event_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD;
            BEGIN
                SELECT report_key, operation_key, payload_hmac, sealed_by
                INTO v FROM checkout_cod_daily_reports
                WHERE id = NEW.report_id;

                IF NOT FOUND OR NEW.report_hmac <> v.payload_hmac THEN
                    RAISE EXCEPTION 'COD daily report event references wrong report hash';
                END IF;

                IF NEW.event_kind = 'sealed' AND (
                    NEW.event_key <> v.operation_key
                    OR NEW.actor_id <> v.sealed_by
                ) THEN
                    RAISE EXCEPTION 'COD daily seal event does not match report';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_daily45_event_guard
            BEFORE INSERT ON checkout_cod_daily_report_events
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_daily45_event_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_daily45_require_seal()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM checkout_cod_daily_report_events
                    WHERE report_id = NEW.id AND event_kind = 'sealed'
                    AND event_key = NEW.operation_key
                ) THEN
                    RAISE EXCEPTION 'COD daily snapshot requires seal audit event';
                END IF;

                RETURN NULL;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER cod_daily45_seal_required
            AFTER INSERT ON checkout_cod_daily_reports
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_daily45_require_seal()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_daily_report_events');
        Schema::dropIfExists('checkout_cod_daily_reports');

        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_daily45_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_daily45_event_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_daily45_require_seal()');
    }
};
