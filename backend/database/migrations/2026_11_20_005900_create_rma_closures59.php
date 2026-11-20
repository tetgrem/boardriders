<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_rma_closures59', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('journal_id')->unique()
                ->constrained('checkout_mono_refund_journal56')->restrictOnDelete();
            $t->foreignId('case_id')->unique()
                ->constrained('checkout_order_return_cases')->restrictOnDelete();
            $t->foreignId('order_id')
                ->constrained('orders')->restrictOnDelete();
            $t->foreignId('stock_batch_id')->unique()
                ->constrained('checkout_rma_stock_batches58')->restrictOnDelete();
            $t->unsignedInteger('loyalty_postings_count');
            $t->char('loyalty_digest_sha256', 64);
            $t->unsignedBigInteger('closed_by');

            // Закриття RMA не підтверджує зарахування на картку.
            $t->boolean('card_settlement_confirmed')->default(false);
            $t->char('closure_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_rma_closures59
              ADD CONSTRAINT rma59_closure_shape CHECK (
                  closed_by > 0
                  AND NOT card_settlement_confirmed
                  AND loyalty_digest_sha256 ~ '^[a-f0-9]{64}$'
                  AND closure_hmac ~ '^[a-f0-9]{64}$'
              )
        SQL);

        Schema::create('checkout_rma_closure_loyalty59', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('closure_id')
                ->constrained('checkout_rma_closures59')->restrictOnDelete();
            $t->foreignId('posting_id')->unique()
                ->constrained('checkout_mono_refund_loyalty_postings57')->restrictOnDelete();
            $t->char('link_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(
                ['closure_id', 'posting_id'],
                'rma59_closure_posting_unique'
            );
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_rma59_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'RMA59 closure records are immutable';
                END IF;

                IF TG_TABLE_NAME = 'checkout_rma_closures59' THEN
                    IF NOT EXISTS (
                        SELECT 1
                        FROM checkout_mono_refund_journal56 j
                        JOIN checkout_mono_refund_executions e
                          ON e.id = j.execution_id
                        JOIN checkout_mono_rma_refund_plans rp
                          ON rp.id = j.refund_plan_id
                        JOIN checkout_order_return_cases c
                          ON c.id = rp.return_case_id
                        JOIN checkout_order_return_inspections i
                          ON i.case_id = c.id
                        JOIN checkout_order_return_decisions d
                          ON d.case_id = c.id
                        JOIN checkout_rma_stock_batches58 b
                          ON b.journal_id = j.id
                        WHERE j.id = NEW.journal_id
                          AND j.order_id = NEW.order_id
                          AND c.id = NEW.case_id
                          AND c.order_id = NEW.order_id
                          AND b.id = NEW.stock_batch_id
                          AND b.case_id = NEW.case_id
                          AND b.order_id = NEW.order_id
                          AND b.inspection_id = i.id
                          AND b.decision_id = d.id
                          AND b.accepted_units = d.accepted_qty
                          AND d.decision = 'approved'
                          AND e.status = 'succeeded'
                          AND NEW.closed_by NOT IN (
                              c.opened_by,
                              i.inspected_by,
                              d.decided_by,
                              b.posted_by,
                              e.executed_by,
                              rp.planned_by,
                              j.recorded_by
                          )
                    ) THEN
                        RAISE EXCEPTION
                            'RMA59 requires approved independently posted return';
                    END IF;

                    IF EXISTS (
                        SELECT 1
                        FROM checkout_mono_refund_loyalty_plans57 p
                        LEFT JOIN checkout_mono_refund_loyalty_postings57 x
                          ON x.plan_id = p.id
                        WHERE p.journal_id = NEW.journal_id
                          AND x.id IS NULL
                    ) THEN
                        RAISE EXCEPTION
                            'RMA59 cannot close unposted loyalty plan';
                    END IF;
                ELSE
                    IF NOT EXISTS (
                        SELECT 1
                        FROM checkout_rma_closures59 c
                        JOIN checkout_mono_refund_loyalty_postings57 x
                          ON x.id = NEW.posting_id
                        JOIN checkout_mono_refund_loyalty_plans57 p
                          ON p.id = x.plan_id
                        WHERE c.id = NEW.closure_id
                          AND p.journal_id = c.journal_id
                    ) THEN
                        RAISE EXCEPTION
                            'RMA59 loyalty link has incorrect journal';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        foreach ([
                     'checkout_rma_closures59',
                     'checkout_rma_closure_loyalty59',
                 ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_guard
                 BEFORE INSERT OR UPDATE OR DELETE ON {$table}
                 FOR EACH ROW
                 EXECUTE FUNCTION boardriders_rma59_guard()"
            );
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_rma59_no_truncate()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'RMA59 closure history cannot be truncated';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_rma_closures59',
                     'checkout_rma_closure_loyalty59',
                 ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_no_truncate
                 BEFORE TRUNCATE ON {$table}
                 FOR EACH STATEMENT
                 EXECUTE FUNCTION boardriders_rma59_no_truncate()"
            );
        }

        // После закрытия нельзя добавлять новые бонусные планы
        // или проводки к этому журналу возврата.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_rma59_freeze_loyalty()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE journal bigint;
            BEGIN
                IF TG_TABLE_NAME = 'checkout_mono_refund_loyalty_plans57' THEN
                    journal := NEW.journal_id;
                ELSE
                    SELECT p.journal_id INTO journal
                    FROM checkout_mono_refund_loyalty_plans57 p
                    WHERE p.id = NEW.plan_id;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM checkout_rma_closures59
                    WHERE journal_id = journal
                ) THEN
                    RAISE EXCEPTION
                        'RMA59 journal already closed: loyalty additions forbidden';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER rma59_freeze_new_plans
            BEFORE INSERT ON checkout_mono_refund_loyalty_plans57
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_rma59_freeze_loyalty()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER rma59_freeze_new_postings
            BEFORE INSERT ON checkout_mono_refund_loyalty_postings57
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_rma59_freeze_loyalty()
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS rma59_freeze_new_postings
             ON checkout_mono_refund_loyalty_postings57'
        );

        DB::statement(
            'DROP TRIGGER IF EXISTS rma59_freeze_new_plans
             ON checkout_mono_refund_loyalty_plans57'
        );

        Schema::dropIfExists('checkout_rma_closure_loyalty59');
        Schema::dropIfExists('checkout_rma_closures59');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_rma59_freeze_loyalty()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_rma59_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_rma59_no_truncate()'
        );
    }
};
