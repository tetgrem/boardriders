<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_mono_refund_loyalty_plans57', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('journal_id')->constrained('checkout_mono_refund_journal56')->restrictOnDelete();
            $t->foreignId('wallet_id')->constrained('loyalty_wallets')->restrictOnDelete();
            $t->string('effect_kind', 24);
            $t->unsignedBigInteger('source_id');
            $t->jsonb('allocations');
            $t->jsonb('expiry_overrides');
            $t->char('calculation_sha256', 64);
            $t->unsignedBigInteger('approved_by');
            $t->char('plan_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['journal_id', 'effect_kind', 'source_id'], 'mono57_one_effect_per_source');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_refund_loyalty_plans57
              ADD CONSTRAINT mono57_plan_shape CHECK (
                  effect_kind IN ('reverse_earned', 'restore_spent')
                  AND source_id > 0 AND approved_by > 0
                  AND jsonb_typeof(allocations) = 'array'
                  AND jsonb_array_length(allocations) > 0
                  AND jsonb_typeof(expiry_overrides) = 'object'
                  AND calculation_sha256 ~ '^[a-f0-9]{64}$'
                  AND plan_hmac ~ '^[a-f0-9]{64}$'
              )
        SQL);

        Schema::create('checkout_mono_refund_loyalty_postings57', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('plan_id')->unique()->constrained('checkout_mono_refund_loyalty_plans57')->restrictOnDelete();
            $t->uuid('loyalty_operation_id');
            $t->unsignedBigInteger('posted_by');
            $t->char('result_sha256', 64);
            $t->char('posting_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_mono_refund_loyalty_postings57
              ADD CONSTRAINT mono57_posting_shape CHECK (
                  posted_by > 0
                  AND result_sha256 ~ '^[a-f0-9]{64}$'
                  AND posting_hmac ~ '^[a-f0-9]{64}$'
              )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono57_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                j RECORD;
                p RECORD;
                e RECORD;
                pr RECORD;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Mono loyalty refund records are immutable';
                END IF;

                IF TG_TABLE_NAME = 'checkout_mono_refund_loyalty_plans57' THEN
                    SELECT * INTO j FROM checkout_mono_refund_journal56 WHERE id = NEW.journal_id;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Missing verified refund journal';
                    END IF;
                    SELECT * INTO e FROM checkout_mono_refund_executions WHERE id = j.execution_id;
                    SELECT * INTO pr FROM checkout_mono_rma_refund_plans WHERE id = j.refund_plan_id;
                    IF e.status IS DISTINCT FROM 'succeeded'
                       OR NEW.approved_by IN (j.recorded_by, e.executed_by, pr.planned_by)
                       OR NOT EXISTS (
                            SELECT 1 FROM checkout_mono_refund_handoffs56 h
                            WHERE h.journal_id = j.id AND h.topic = 'loyalty_review'
                       )
                    THEN
                        RAISE EXCEPTION 'Unverified refund or forbidden loyalty approval';
                    END IF;
                ELSE
                    SELECT * INTO p FROM checkout_mono_refund_loyalty_plans57 WHERE id = NEW.plan_id;
                    IF NOT FOUND OR NEW.posted_by = p.approved_by
                       OR NOT EXISTS (
                            SELECT 1 FROM loyalty_operations o
                            WHERE o.id = NEW.loyalty_operation_id
                              AND o.wallet_id = p.wallet_id
                              AND o.operation_type = CASE p.effect_kind
                                WHEN 'reverse_earned' THEN 'grant_reversal'
                                ELSE 'redemption_refund' END
                       )
                    THEN
                        RAISE EXCEPTION 'Posting requires recorded matching loyalty operation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        foreach ([
            'checkout_mono_refund_loyalty_plans57',
            'checkout_mono_refund_loyalty_postings57',
        ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_guard
                BEFORE INSERT OR UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_mono57_guard()");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_mono57_no_truncate()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Mono loyalty refund records cannot be truncated';
            END;
            $$
        SQL);
        foreach ([
            'checkout_mono_refund_loyalty_plans57',
            'checkout_mono_refund_loyalty_postings57',
        ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_mono57_no_truncate()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_mono_refund_loyalty_postings57');
        Schema::dropIfExists('checkout_mono_refund_loyalty_plans57');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono57_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_mono57_no_truncate()');
    }
};
