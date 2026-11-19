<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_mono_refund_executions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('refund_plan_id')->unique()
                ->constrained('checkout_mono_rma_refund_plans')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('capture_receipt_id')
                ->constrained('checkout_payment_capture_receipts')->restrictOnDelete();
            $t->uuid('operation_key')->unique();
            $t->string('ext_ref', 32)->unique();
            $t->string('invoice_id', 150);
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency_code', 3);
            $t->unsignedBigInteger('executed_by');
            $t->string('status', 20)->default('sending');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->timestampTz('updated_at', 6)->useCurrent();
            $t->index(['capture_receipt_id', 'status'], 'mono54_capture_status_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_refund_executions
            ADD CONSTRAINT mono54_execution_check CHECK (
                amount_minor > 0 AND currency_code = 'UAH'
                AND executed_by > 0
                AND ext_ref ~ '^[a-f0-9]{32}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND status IN ('sending','awaiting_evidence','succeeded','needs_review')
            )
        SQL);

        Schema::create('checkout_mono_refund_observations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('execution_id')
                ->constrained('checkout_mono_refund_executions')->restrictOnDelete();
            $t->uuid('observation_key')->unique();
            $t->string('source', 16);
            $t->string('state', 24);
            $t->unsignedBigInteger('matched_amount_minor')->nullable();
            $t->char('evidence_sha256', 64);
            $t->char('evidence_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['execution_id', 'id'], 'mono54_observations_case_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_refund_observations
            ADD CONSTRAINT mono54_observation_check CHECK (
                evidence_hmac ~ '^[a-f0-9]{64}$'
                AND evidence_sha256 ~ '^[a-f0-9]{64}$'
                AND (
                    (source = 'submission' AND state IN ('submitted','uncertain','blocked')
                     AND matched_amount_minor IS NULL)
                    OR
                    (source = 'invoice_status' AND state IN
                     ('absent','processing','success','failure','unexpected')
                     AND ((state = 'success' AND matched_amount_minor > 0)
                          OR (state <> 'success' AND matched_amount_minor IS NULL)))
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono54_execution_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                p RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Mono refund execution is immutable';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT plan.order_id, plan.capture_receipt_id, plan.operation_key,
                           plan.amount_minor, plan.currency_code, plan.planned_by
                      INTO p
                      FROM checkout_mono_rma_refund_plans plan
                     WHERE plan.id = NEW.refund_plan_id;
                    IF NOT FOUND
                       OR p.order_id IS DISTINCT FROM NEW.order_id
                       OR p.capture_receipt_id IS DISTINCT FROM NEW.capture_receipt_id
                       OR p.operation_key IS DISTINCT FROM NEW.operation_key
                       OR p.amount_minor IS DISTINCT FROM NEW.amount_minor
                       OR p.currency_code IS DISTINCT FROM NEW.currency_code
                       OR p.planned_by = NEW.executed_by
                       OR NEW.ext_ref <> REPLACE(NEW.operation_key::text, '-', '')
                       OR NEW.status <> 'sending'
                    THEN
                        RAISE EXCEPTION 'Mono refund execution does not match approved plan';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM orders o
                        JOIN checkout_payment_capture_receipts r
                          ON r.id = NEW.capture_receipt_id AND r.order_id = o.id
                        JOIN checkout_payment_capture_decisions d
                          ON d.receipt_id = r.id AND d.decision = 'paid'
                        JOIN checkout_mono_invoice_launches l
                          ON l.order_id = o.id AND l.status = 'ready'
                        WHERE o.id = NEW.order_id
                          AND o.payment_status = 'paid'
                          AND r.provider_code = 'mono'
                          AND r.provider_payment_id = NEW.invoice_id
                          AND l.provider_invoice_id = NEW.invoice_id
                          AND NEW.amount_minor <= r.amount_minor
                    ) THEN
                        RAISE EXCEPTION 'Mono execution has no verified paid invoice';
                    END IF;
                    RETURN NEW;
                END IF;

                IF ROW(OLD.id, OLD.refund_plan_id, OLD.order_id,
                       OLD.capture_receipt_id, OLD.operation_key, OLD.ext_ref,
                       OLD.invoice_id, OLD.amount_minor, OLD.currency_code,
                       OLD.executed_by, OLD.request_hmac, OLD.created_at)
                    IS DISTINCT FROM
                   ROW(NEW.id, NEW.refund_plan_id, NEW.order_id,
                       NEW.capture_receipt_id, NEW.operation_key, NEW.ext_ref,
                       NEW.invoice_id, NEW.amount_minor, NEW.currency_code,
                       NEW.executed_by, NEW.request_hmac, NEW.created_at)
                THEN
                    RAISE EXCEPTION 'Mono refund execution identity cannot be changed';
                END IF;

                IF NOT (
                    (OLD.status = 'sending' AND NEW.status IN
                     ('awaiting_evidence','succeeded','needs_review'))
                    OR (OLD.status = 'awaiting_evidence' AND NEW.status IN
                     ('succeeded','needs_review'))
                    OR (OLD.status = 'needs_review' AND NEW.status = 'succeeded')
                ) THEN
                    RAISE EXCEPTION 'Invalid Mono refund status transition';
                END IF;
                IF NEW.status = 'succeeded' AND NOT EXISTS (
                    SELECT 1 FROM checkout_mono_refund_observations o
                     WHERE o.execution_id = NEW.id
                       AND o.source = 'invoice_status'
                       AND o.state = 'success'
                       AND o.matched_amount_minor = NEW.amount_minor
                ) THEN
                    RAISE EXCEPTION 'Successful refund requires matched provider evidence';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER mono54_execution_guard
            BEFORE INSERT OR UPDATE OR DELETE ON checkout_mono_refund_executions
            FOR EACH ROW EXECUTE FUNCTION boardriders_mono54_execution_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono54_observation_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE expected_amount BIGINT;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Mono provider observations cannot be modified';
                END IF;
                SELECT amount_minor INTO expected_amount
                  FROM checkout_mono_refund_executions
                 WHERE id = NEW.execution_id;
                IF NOT FOUND OR (
                    NEW.state = 'success'
                    AND NEW.matched_amount_minor IS DISTINCT FROM expected_amount
                ) THEN
                    RAISE EXCEPTION 'Mono observation amount mismatch';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER mono54_observation_guard
            BEFORE INSERT OR UPDATE OR DELETE ON checkout_mono_refund_observations
            FOR EACH ROW EXECUTE FUNCTION boardriders_mono54_observation_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono54_no_truncate()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Mono refund history cannot be truncated';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_mono_refund_executions',
                     'checkout_mono_refund_observations',
                 ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_mono54_no_truncate()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_mono_refund_observations');
        Schema::dropIfExists('checkout_mono_refund_executions');

        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono54_observation_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono54_execution_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono54_no_truncate()');
    }
};
