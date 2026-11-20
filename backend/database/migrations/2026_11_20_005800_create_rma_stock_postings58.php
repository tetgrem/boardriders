<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_rma_stock_batches58', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('journal_id')->unique()
                ->constrained('checkout_mono_refund_journal56')->restrictOnDelete();
            $t->foreignId('case_id')->unique()
                ->constrained('checkout_order_return_cases')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('inspection_id')
                ->constrained('checkout_order_return_inspections')->restrictOnDelete();
            $t->foreignId('decision_id')
                ->constrained('checkout_order_return_decisions')->restrictOnDelete();
            $t->foreignId('warehouse_id')
                ->constrained('warehouses')->restrictOnDelete();
            $t->unsignedBigInteger('posted_by');
            $t->unsignedInteger('accepted_units');
            $t->unsignedInteger('sellable_units');
            $t->unsignedInteger('quarantine_units');
            $t->char('batch_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_rma_stock_batches58
            ADD CONSTRAINT rma58_batch_qty CHECK (
                accepted_units > 0
                AND sellable_units + quarantine_units = accepted_units
                AND posted_by > 0
            )
        SQL);

        Schema::create('checkout_rma_stock_units58', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('batch_id')
                ->constrained('checkout_rma_stock_batches58')->restrictOnDelete();
            $t->foreignId('order_id')
                ->constrained('orders')->restrictOnDelete();
            $t->foreignId('return_unit_id')->unique()
                ->constrained('checkout_order_return_units')->restrictOnDelete();
            $t->foreignId('variant_id')
                ->constrained('product_variants')->restrictOnDelete();
            $t->foreignId('warehouse_id')
                ->constrained('warehouses')->restrictOnDelete();
            $t->string('condition', 20);
            $t->string('disposition', 20);
            $t->uuid('receipt_key')->nullable()->unique();
            $t->unsignedBigInteger('posted_by');
            $t->char('entry_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['order_id', 'variant_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_rma_stock_units58
            ADD CONSTRAINT rma58_unit_disposition CHECK (
                posted_by > 0 AND (
                    (condition = 'resellable'
                     AND disposition = 'sellable'
                     AND receipt_key IS NOT NULL)
                    OR
                    (condition = 'damaged'
                     AND disposition = 'quarantine'
                     AND receipt_key IS NULL)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_rma58_batch_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM checkout_mono_refund_journal56 j
                    JOIN checkout_mono_refund_executions e
                      ON e.id = j.execution_id
                    JOIN checkout_mono_rma_refund_plans p
                      ON p.id = j.refund_plan_id
                    JOIN checkout_order_return_cases c
                      ON c.id = p.return_case_id
                    JOIN checkout_order_return_inspections i
                      ON i.case_id = c.id
                    JOIN checkout_order_return_decisions d
                      ON d.case_id = c.id
                    WHERE j.id = NEW.journal_id
                      AND j.order_id = NEW.order_id
                      AND c.id = NEW.case_id
                      AND c.order_id = NEW.order_id
                      AND i.id = NEW.inspection_id
                      AND i.warehouse_id = NEW.warehouse_id
                      AND d.id = NEW.decision_id
                      AND d.inspection_id = i.id
                      AND d.decision = 'approved'
                      AND d.accepted_qty = NEW.accepted_units
                      AND e.status = 'succeeded'
                      AND NEW.posted_by NOT IN (
                          c.opened_by,
                          i.inspected_by,
                          d.decided_by,
                          e.executed_by,
                          p.planned_by,
                          j.recorded_by
                      )
                ) THEN
                    RAISE EXCEPTION
                        'RMA58 batch requires verified independent approval';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_rma58_unit_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM checkout_rma_stock_batches58 b
                    JOIN checkout_order_return_units u
                      ON u.id = NEW.return_unit_id
                    WHERE b.id = NEW.batch_id
                      AND u.case_id = b.case_id
                      AND u.order_id = NEW.order_id
                      AND b.order_id = NEW.order_id
                      AND u.variant_id = NEW.variant_id
                      AND b.warehouse_id = NEW.warehouse_id
                      AND b.posted_by = NEW.posted_by
                ) THEN
                    RAISE EXCEPTION
                        'RMA58 return unit belongs to another order or case';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(
            'CREATE TRIGGER rma58_batch_guard
             BEFORE INSERT ON checkout_rma_stock_batches58
             FOR EACH ROW
             EXECUTE FUNCTION boardriders_rma58_batch_guard()'
        );

        DB::statement(
            'CREATE TRIGGER rma58_unit_guard
             BEFORE INSERT ON checkout_rma_stock_units58
             FOR EACH ROW
             EXECUTE FUNCTION boardriders_rma58_unit_guard()'
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_rma58_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'RMA stock posting is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_rma58_no_truncate()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'RMA stock posting cannot be truncated';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_rma_stock_batches58',
                     'checkout_rma_stock_units58',
                 ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_immutable
                 BEFORE UPDATE OR DELETE ON {$table}
                 FOR EACH ROW
                 EXECUTE FUNCTION boardriders_rma58_immutable()"
            );

            DB::statement(
                "CREATE TRIGGER {$table}_no_truncate
                 BEFORE TRUNCATE ON {$table}
                 FOR EACH STATEMENT
                 EXECUTE FUNCTION boardriders_rma58_no_truncate()"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_rma_stock_units58');
        Schema::dropIfExists('checkout_rma_stock_batches58');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_rma58_batch_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_rma58_unit_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_rma58_immutable()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_rma58_no_truncate()'
        );
    }
};
