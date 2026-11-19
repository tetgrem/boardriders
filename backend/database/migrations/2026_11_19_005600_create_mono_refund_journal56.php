<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_mono_refund_journal56', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('execution_id')->unique()
                ->constrained('checkout_mono_refund_executions')->restrictOnDelete();
            $t->foreignId('refund_plan_id')
                ->constrained('checkout_mono_rma_refund_plans')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('capture_receipt_id')
                ->constrained('checkout_payment_capture_receipts')->restrictOnDelete();
            $t->foreignId('observation_id')->unique()
                ->constrained('checkout_mono_refund_observations')->restrictOnDelete();
            $t->string('ext_ref', 32);
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency_code', 3);
            $t->unsignedBigInteger('recorded_by');
            $t->char('entry_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_refund_journal56
            ADD CONSTRAINT mono56_journal_shape CHECK (
                amount_minor > 0 AND currency_code = 'UAH'
                AND recorded_by > 0
                AND ext_ref ~ '^[a-f0-9]{32}$'
                AND entry_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        Schema::create('checkout_mono_refund_handoffs56', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('journal_id')
                ->constrained('checkout_mono_refund_journal56')->restrictOnDelete();
            $t->string('topic', 24);
            $t->char('event_key', 64)->unique();
            $t->char('event_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['journal_id', 'topic'], 'mono56_unique_handoff');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_refund_handoffs56
            ADD CONSTRAINT mono56_handoff_shape CHECK (
                topic IN ('loyalty_review', 'inventory_review')
                AND event_key ~ '^[a-f0-9]{64}$'
                AND event_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono56_journal_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Mono refund journal is immutable';
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM checkout_mono_refund_executions e
                    JOIN checkout_mono_rma_refund_plans p
                      ON p.id = e.refund_plan_id
                    JOIN checkout_mono_refund_observations o
                      ON o.id = NEW.observation_id
                     AND o.execution_id = e.id
                    WHERE e.id = NEW.execution_id
                      AND e.refund_plan_id = NEW.refund_plan_id
                      AND e.order_id = NEW.order_id
                      AND e.capture_receipt_id = NEW.capture_receipt_id
                      AND e.ext_ref = NEW.ext_ref
                      AND e.amount_minor = NEW.amount_minor
                      AND e.currency_code = NEW.currency_code
                      AND e.status = 'succeeded'
                      AND NEW.recorded_by <> e.executed_by
                      AND NEW.recorded_by <> p.planned_by
                      AND o.source = 'invoice_status'
                      AND o.state = 'success'
                      AND o.matched_amount_minor = e.amount_minor
                      AND o.id = (
                          SELECT MAX(o2.id)
                          FROM checkout_mono_refund_observations o2
                          WHERE o2.execution_id = e.id
                      )
                ) THEN
                    RAISE EXCEPTION 'Mono journal requires latest exact provider success';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER mono56_journal_guard
            BEFORE INSERT OR UPDATE OR DELETE ON checkout_mono_refund_journal56
            FOR EACH ROW EXECUTE FUNCTION boardriders_mono56_journal_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono56_handoff_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Mono refund handoff is immutable';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER mono56_handoff_guard
            BEFORE INSERT OR UPDATE OR DELETE ON checkout_mono_refund_handoffs56
            FOR EACH ROW EXECUTE FUNCTION boardriders_mono56_handoff_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono56_no_truncate()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Mono refund journal history cannot be truncated';
            END;
            $$
        SQL);

        foreach (['checkout_mono_refund_journal56', 'checkout_mono_refund_handoffs56'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_mono56_no_truncate()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_mono_refund_handoffs56');
        Schema::dropIfExists('checkout_mono_refund_journal56');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono56_handoff_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono56_journal_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono56_no_truncate()');
    }
};
