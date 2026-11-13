<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_daily_monitor_cursor', function (Blueprint $t): void {
            $t->string('cursor_name', 32)->primary();
            $t->unsignedBigInteger('last_report_id')->nullable();
            $t->timestampTz('updated_at', 6)->useCurrent();
        });
        DB::table('checkout_cod_daily_monitor_cursor')->insert([
            'cursor_name' => 'daily_v47',
            'last_report_id' => null,
            'updated_at' => now(),
        ]);

        Schema::create('checkout_cod_daily_monitor_signals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('report_id')->constrained('checkout_cod_daily_reports')->restrictOnDelete();
            $t->char('observation_hmac', 64);
            $t->string('coverage_status', 48);
            $t->string('severity', 12);
            $t->string('next_action', 24);
            $t->char('signal_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['report_id', 'observation_hmac', 'coverage_status'], 'cod_m47_signal_once');
            $t->index(['report_id', 'id'], 'cod_m47_report_history');
            $t->index(['severity', 'id'], 'cod_m47_severity_history');
        });

        Schema::create('checkout_cod_daily_monitor_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('signal_id')->unique('cod_m47_ack_once')
                ->constrained('checkout_cod_daily_monitor_signals')->restrictOnDelete();
            $t->uuid('operation_key')->unique('cod_m47_ack_operation_once');
            $t->unsignedBigInteger('actor_id');
            $t->char('signal_hmac', 64);
            $t->char('receipt_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_daily_monitor_signals
            ADD CONSTRAINT cod_m47_signal_values CHECK (
                observation_hmac ~ '^[a-f0-9]{64}$'
                AND signal_hmac ~ '^[a-f0-9]{64}$'
                AND coverage_status IN (
                    'clean', 'covered', 'awaiting_review', 'awaiting_publication',
                    'rejected_requires_action', 'changed_after_publication', 'correction_required'
                )
                AND (
                    (coverage_status IN ('clean', 'covered')
                     AND severity = 'none' AND next_action = 'none')
                    OR (coverage_status = 'awaiting_review'
                     AND severity = 'warning' AND next_action = 'review')
                    OR (coverage_status = 'awaiting_publication'
                     AND severity = 'warning' AND next_action = 'publish')
                    OR (coverage_status = 'rejected_requires_action'
                     AND severity = 'critical' AND next_action = 'investigate')
                    OR (coverage_status = 'changed_after_publication'
                     AND severity = 'critical' AND next_action = 'propose_updated')
                    OR (coverage_status = 'correction_required'
                     AND severity = 'critical' AND next_action = 'propose')
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_daily_monitor_receipts
            ADD CONSTRAINT cod_m47_receipt_values CHECK (
                actor_id > 0 AND signal_hmac ~ '^[a-f0-9]{64}$'
                AND receipt_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_m47_no_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD daily monitoring history immutable';
            END;
            $$
        SQL);

        foreach (['checkout_cod_daily_monitor_signals', 'checkout_cod_daily_monitor_receipts'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_m47_no_mutation()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_m47_no_mutation()");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_m47_receipt_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v CHAR(64);
            BEGIN
                SELECT signal_hmac INTO v FROM checkout_cod_daily_monitor_signals
                WHERE id = NEW.signal_id;
                IF NOT FOUND OR v <> NEW.signal_hmac THEN
                    RAISE EXCEPTION 'COD monitoring receipt must reference signed signal';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_m47_receipt_guard
            BEFORE INSERT ON checkout_cod_daily_monitor_receipts
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_m47_receipt_guard()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_daily_monitor_receipts');
        Schema::dropIfExists('checkout_cod_daily_monitor_signals');
        Schema::dropIfExists('checkout_cod_daily_monitor_cursor');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_m47_receipt_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_m47_no_mutation()');
    }
};
