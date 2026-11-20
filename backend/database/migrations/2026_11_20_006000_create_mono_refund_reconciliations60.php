<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_mono_refund_reconciliations60', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('capture_receipt_id');
            $table->foreign('order_id', 'mono60_run_order_fk')
                ->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('capture_receipt_id', 'mono60_run_capture_fk')
                ->references('id')->on('checkout_payment_capture_receipts')->restrictOnDelete();
            $table->char('evidence_sha256', 64);
            $table->string('result_code', 40);
            $table->bigInteger('captured_minor');
            $table->bigInteger('planned_minor');
            $table->bigInteger('other_intents_minor');
            $table->bigInteger('executed_minor');
            $table->bigInteger('confirmed_minor');
            $table->bigInteger('journaled_minor');
            $table->bigInteger('available_to_plan_minor');
            $table->unsignedInteger('plan_count');
            $table->unsignedInteger('unresolved_count');
            $table->unsignedInteger('unjournaled_count');
            $table->unsignedBigInteger('reviewed_by');
            $table->char('reconciliation_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(
                ['capture_receipt_id', 'evidence_sha256'],
                'mono60_one_snapshot_per_capture'
            );
            $table->index(['order_id', 'id'], 'mono60_order_history');
        });

        Schema::create('checkout_mono_refund_reconciliation_lines60', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_id');
            $table->foreignId('refund_plan_id');
            $table->foreignId('execution_id')->nullable();
            $table->foreignId('journal_id')->nullable();
            $table->foreignId('observation_id')->nullable();
            $table->foreign('reconciliation_id', 'mono60_line_run_fk')
                ->references('id')->on('checkout_mono_refund_reconciliations60')->restrictOnDelete();
            $table->foreign('refund_plan_id', 'mono60_line_plan_fk')
                ->references('id')->on('checkout_mono_rma_refund_plans')->restrictOnDelete();
            $table->foreign('execution_id', 'mono60_line_exec_fk')
                ->references('id')->on('checkout_mono_refund_executions')->restrictOnDelete();
            $table->foreign('journal_id', 'mono60_line_journal_fk')
                ->references('id')->on('checkout_mono_refund_journal56')->restrictOnDelete();
            $table->foreign('observation_id', 'mono60_line_obs_fk')
                ->references('id')->on('checkout_mono_refund_observations')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('line_status', 40);
            $table->char('line_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['reconciliation_id', 'refund_plan_id'], 'mono60_unique_plan_line');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_refund_reconciliations60
            ADD CONSTRAINT mono60_amounts_nonnegative CHECK (
                captured_minor > 0 AND planned_minor >= 0
                AND other_intents_minor >= 0 AND executed_minor >= 0
                AND confirmed_minor >= 0 AND journaled_minor >= 0
                AND available_to_plan_minor >= 0
                AND reviewed_by > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_refund_reconciliation_lines60
            ADD CONSTRAINT mono60_line_positive CHECK (amount_minor > 0)
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono60_write_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Mono60 reconciliation audit is append-only';
                END IF;

                IF TG_TABLE_NAME = 'checkout_mono_refund_reconciliations60' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM checkout_payment_capture_receipts r
                        WHERE r.id = NEW.capture_receipt_id
                          AND r.order_id = NEW.order_id
                          AND r.provider_code = 'mono'
                          AND r.currency_code = 'UAH'
                    ) THEN
                        RAISE EXCEPTION 'Mono60 capture/order mismatch';
                    END IF;
                ELSE
                    IF NOT EXISTS (
                        SELECT 1
                        FROM checkout_mono_refund_reconciliations60 r
                        JOIN checkout_mono_rma_refund_plans p
                          ON p.id = NEW.refund_plan_id
                        WHERE r.id = NEW.reconciliation_id
                          AND p.capture_receipt_id = r.capture_receipt_id
                          AND p.order_id = r.order_id
                          AND p.amount_minor = NEW.amount_minor
                    ) THEN
                        RAISE EXCEPTION 'Mono60 line belongs to another capture';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono60_truncate_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Mono60 audit cannot be truncated';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_mono_refund_reconciliations60',
                     'checkout_mono_refund_reconciliation_lines60',
                 ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_write_guard
                BEFORE INSERT OR UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_mono60_write_guard()");
            DB::statement("CREATE TRIGGER {$table}_truncate_guard
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_mono60_truncate_guard()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_mono_refund_reconciliation_lines60');
        Schema::dropIfExists('checkout_mono_refund_reconciliations60');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono60_write_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono60_truncate_guard()');
    }
};
