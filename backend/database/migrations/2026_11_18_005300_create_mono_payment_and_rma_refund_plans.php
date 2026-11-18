<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_mono_invoice_launches', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $t->uuid('operation_key')->unique();
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency_code', 3);
            $t->timestampTz('expires_at', 6);
            $t->char('request_sha256', 64);
            $t->string('status', 16)->default('creating');
            $t->string('provider_invoice_id', 150)->nullable()->unique();
            $t->string('payment_url', 512)->nullable();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->timestampTz('updated_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_invoice_launches
            ADD CONSTRAINT mono53_launch_check CHECK (
                amount_minor > 0 AND currency_code = 'UAH'
                AND request_sha256 ~ '^[a-f0-9]{64}$'
                AND status IN ('creating','linked','ready')
                AND (
                    (status = 'creating' AND provider_invoice_id IS NULL AND payment_url IS NULL)
                    OR (status IN ('linked','ready') AND provider_invoice_id IS NOT NULL AND payment_url IS NOT NULL)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono53_launch_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Mono launch cannot be deleted';
                END IF;

                IF ROW(OLD.order_id, OLD.operation_key, OLD.amount_minor,
                       OLD.currency_code, OLD.expires_at, OLD.request_sha256, OLD.created_at)
                    IS DISTINCT FROM
                   ROW(NEW.order_id, NEW.operation_key, NEW.amount_minor,
                       NEW.currency_code, NEW.expires_at, NEW.request_sha256, NEW.created_at)
                THEN
                    RAISE EXCEPTION 'Mono launch identity is immutable';
                END IF;

                IF NOT (
                    (OLD.status = 'creating' AND NEW.status = 'linked')
                    OR (OLD.status = 'linked' AND NEW.status = 'ready')
                ) THEN
                    RAISE EXCEPTION 'Invalid Mono launch transition';
                END IF;

                IF OLD.status = 'linked' AND
                    (OLD.provider_invoice_id IS DISTINCT FROM NEW.provider_invoice_id
                    OR OLD.payment_url IS DISTINCT FROM NEW.payment_url)
                THEN
                    RAISE EXCEPTION 'Mono provider reference cannot change';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER mono53_launch_guard
            BEFORE UPDATE OR DELETE ON checkout_mono_invoice_launches
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_mono53_launch_guard()
        SQL);

        Schema::create('checkout_mono_rma_refund_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('return_case_id')->unique()
                ->constrained('checkout_order_return_cases')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('capture_receipt_id')
                ->constrained('checkout_payment_capture_receipts')->restrictOnDelete();
            $t->uuid('operation_key')->unique();
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency_code', 3);
            $t->char('plan_hmac', 64);
            $t->unsignedBigInteger('planned_by');
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['order_id', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_rma_refund_plans
            ADD CONSTRAINT mono53_refund_check CHECK (
                amount_minor > 0 AND currency_code = 'UAH'
                AND planned_by > 0 AND plan_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono53_refund_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                source RECORD;
                captured BIGINT;
                allocated BIGINT;
            BEGIN
                SELECT c.order_id, d.decision, d.cash_candidate_minor,
                       d.decided_by, c.opened_by, i.inspected_by,
                       o.payment_status
                  INTO source
                  FROM checkout_order_return_cases c
                  JOIN checkout_order_return_decisions d ON d.case_id = c.id
                  JOIN checkout_order_return_inspections i ON i.case_id = c.id
                  JOIN orders o ON o.id = c.order_id
                 WHERE c.id = NEW.return_case_id
                 FOR UPDATE OF o;

                IF NOT FOUND OR source.order_id <> NEW.order_id
                   OR source.decision <> 'approved'
                   OR source.payment_status <> 'paid'
                   OR source.cash_candidate_minor <> NEW.amount_minor
                   OR NEW.planned_by IN (
                       source.opened_by,
                       source.inspected_by,
                       source.decided_by
                   )
                THEN
                    RAISE EXCEPTION
                        'Mono refund requires independently approved paid RMA';
                END IF;

                SELECT r.amount_minor INTO captured
                  FROM checkout_payment_capture_receipts r
                  JOIN checkout_payment_capture_decisions d
                    ON d.receipt_id = r.id
                 WHERE r.id = NEW.capture_receipt_id
                   AND r.order_id = NEW.order_id
                   AND r.provider_code = 'mono'
                   AND r.currency_code = NEW.currency_code
                   AND d.decision = 'paid';

                IF NOT FOUND THEN
                    RAISE EXCEPTION
                        'Mono refund has no accepted captured payment';
                END IF;

                SELECT COALESCE(SUM(p.amount_minor), 0) INTO allocated
                  FROM checkout_mono_rma_refund_plans p
                 WHERE p.capture_receipt_id = NEW.capture_receipt_id;

                allocated := allocated + COALESCE((
                    SELECT SUM(ri.amount_minor)
                      FROM checkout_payment_refund_intents ri
                     WHERE ri.receipt_id = NEW.capture_receipt_id
                ), 0);

                IF NEW.amount_minor > captured - allocated THEN
                    RAISE EXCEPTION
                        'Mono planned refunds exceed captured payment';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER mono53_refund_guard
            BEFORE INSERT ON checkout_mono_rma_refund_plans
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_mono53_refund_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono53_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Mono financial audit history is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER mono53_refund_immutable
            BEFORE UPDATE OR DELETE ON checkout_mono_rma_refund_plans
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_mono53_immutable()
        SQL);

        foreach ([
                     'checkout_mono_invoice_launches',
                     'checkout_mono_rma_refund_plans',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_mono53_immutable()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_mono_rma_refund_plans');
        Schema::dropIfExists('checkout_mono_invoice_launches');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_mono53_refund_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_mono53_launch_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_mono53_immutable()'
        );
    }
};
