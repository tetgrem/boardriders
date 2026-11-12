<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_posting_preflights', function (Blueprint $t) {
            $t->id();
            $t->uuid('operation_key')->unique();

            $t->unsignedBigInteger('reconciliation_id');
            $t->foreign(
                'reconciliation_id',
                'cod_post_pre_reconciliation_fk'
            )->references('id')
                ->on('checkout_cod_statement_reconciliations')
                ->restrictOnDelete();

            $t->foreignId('order_id')
                ->constrained('orders')->restrictOnDelete();

            $t->string('source_kind', 12);
            $t->unsignedBigInteger('source_id');

            $t->foreignId('statement_entry_id')
                ->constrained('checkout_cod_privat_statement_entries')
                ->restrictOnDelete();

            $t->foreignId('case_id')
                ->constrained('checkout_cod_settlement_cases')
                ->restrictOnDelete();

            $t->foreignId('observation_id')
                ->constrained('checkout_cod_settlement_observations')
                ->restrictOnDelete();

            $t->unsignedBigInteger('cod_gross_minor');
            $t->unsignedBigInteger('fee_minor');
            $t->unsignedBigInteger('bank_allocation_minor');
            $t->char('snapshot_hmac', 64);
            $t->unsignedBigInteger('prepared_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();

            $t->unique(
                ['reconciliation_id', 'order_id'],
                'cod_post_pre_once_per_revision_order'
            );

            $t->index(
                ['order_id', 'id'],
                'cod_post_pre_order_history'
            );
        });

        Schema::create('checkout_cod_posting_preflight_reviews', function (Blueprint $t) {
            $t->id();
            $t->uuid('review_key')->unique();

            $t->unsignedBigInteger('preflight_id');
            $t->unique(
                'preflight_id',
                'cod_post_pre_review_once'
            );
            $t->foreign(
                'preflight_id',
                'cod_post_pre_review_preflight_fk'
            )->references('id')
                ->on('checkout_cod_posting_preflights')
                ->restrictOnDelete();

            $t->string('decision', 32);
            $t->string('reason_code', 48);
            $t->unsignedBigInteger('reviewed_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_posting_preflights
            ADD CONSTRAINT cod_post_pre_values CHECK (
                source_kind IN ('single', 'batch')
                AND source_id > 0
                AND prepared_by > 0
                AND cod_gross_minor BETWEEN 1 AND 100000000
                AND fee_minor BETWEEN 0 AND 100000000
                AND bank_allocation_minor BETWEEN 0 AND 100000000
                AND cod_gross_minor = fee_minor + bank_allocation_minor
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_posting_preflight_reviews
            ADD CONSTRAINT cod_post_pre_review_values CHECK (
                reviewed_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (
                        decision = 'retain_unverified'
                        AND reason_code = 'external_payment_evidence_required'
                    )
                    OR (
                        decision = 'hold_for_investigation'
                        AND reason_code = 'posting_preflight_investigation'
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_post_pre_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v RECORD;
                a RECORD;
                v_payment TEXT;
                v_ref CHAR(64);
            BEGIN
                SELECT p.source_kind, p.source_id, p.statement_entry_id,
                       p.result_code, p.proposed_by,
                       r.decision, r.reviewed_by
                INTO v
                FROM checkout_cod_statement_reconciliations p
                LEFT JOIN checkout_cod_statement_reconciliation_reviews r
                    ON r.reconciliation_id = p.id
                WHERE p.id = NEW.reconciliation_id;

                IF NOT FOUND
                    OR v.result_code <> 'balanced_unverified'
                    OR v.decision IS DISTINCT FROM 'acknowledge_comparison'
                    OR v.source_kind <> NEW.source_kind
                    OR v.source_id <> NEW.source_id
                    OR v.statement_entry_id <> NEW.statement_entry_id
                    OR NEW.prepared_by IN (v.proposed_by, v.reviewed_by)
                    OR NEW.reconciliation_id <> (
                        SELECT MAX(id)
                        FROM checkout_cod_statement_reconciliations
                        WHERE source_kind = v.source_kind
                          AND source_id = v.source_id
                    )
                THEN
                    RAISE EXCEPTION 'COD posting preflight source not eligible';
                END IF;

                SELECT payment_status INTO v_payment
                FROM orders
                WHERE id = NEW.order_id
                FOR UPDATE;

                IF NOT FOUND OR v_payment <> 'pending' THEN
                    RAISE EXCEPTION 'COD order must remain pending';
                END IF;

                IF v.source_kind = 'single' THEN
                    SELECT c.id AS case_id,
                           o.id AS observation_id,
                           c.expected_cod_minor,
                           o.claimed_gross_minor,
                           o.claimed_fee_minor,
                           o.claimed_net_minor,
                           b.reference_hmac
                    INTO a
                    FROM checkout_cod_settlement_bank_receipts b
                    JOIN checkout_cod_settlement_cases c
                        ON c.id = b.case_id
                    JOIN checkout_cod_settlement_bank_matches m
                        ON m.bank_receipt_id = b.id
                    JOIN checkout_cod_settlement_observations o
                        ON o.id = m.observation_id
                    WHERE b.id = v.source_id
                      AND c.order_id = NEW.order_id;
                ELSE
                    SELECT c.id AS case_id,
                           o.id AS observation_id,
                           c.expected_cod_minor,
                           o.claimed_gross_minor,
                           o.claimed_fee_minor,
                           o.claimed_net_minor,
                           b.reference_hmac
                    INTO a
                    FROM checkout_cod_bank_batches b
                    JOIN checkout_cod_bank_batch_allocations al
                        ON al.batch_id = b.id
                    JOIN checkout_cod_settlement_cases c
                        ON c.id = al.case_id
                    JOIN checkout_cod_settlement_observations o
                        ON o.id = al.observation_id
                    WHERE b.id = v.source_id
                      AND c.order_id = NEW.order_id
                      AND al.amount_minor = o.claimed_net_minor;
                END IF;

                IF NOT FOUND
                    OR NEW.case_id <> a.case_id
                    OR NEW.observation_id <> a.observation_id
                    OR NEW.cod_gross_minor <> a.expected_cod_minor
                    OR NEW.cod_gross_minor <> a.claimed_gross_minor
                    OR NEW.fee_minor <> a.claimed_fee_minor
                    OR NEW.bank_allocation_minor <> a.claimed_net_minor
                THEN
                    RAISE EXCEPTION 'COD posting preflight amounts or order mismatch';
                END IF;

                v_ref := a.reference_hmac;

                PERFORM 1
                FROM checkout_cod_bank_reference_claims
                WHERE reference_hmac = v_ref
                FOR UPDATE;

                IF boardriders_cod_financial_has_hold(
                    v.source_kind, v.source_id
                ) OR boardriders_cod_financial_has_hold(
                    'novapay', a.observation_id
                ) THEN
                    RAISE EXCEPTION 'Financial hold blocks COD preflight';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_post_pre_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v RECORD;
                v_ref CHAR(64);
                v_origin BIGINT;
                v_importer BIGINT;
                v_payment TEXT;
            BEGIN
                SELECT f.*, p.proposed_by, p.source_kind AS p_kind,
                       p.source_id AS p_source,
                       p.statement_entry_id AS p_entry,
                       p.result_code, r.reviewed_by AS comparison_reviewer,
                       r.decision AS comparison_decision
                INTO v
                FROM checkout_cod_posting_preflights f
                JOIN checkout_cod_statement_reconciliations p
                    ON p.id = f.reconciliation_id
                LEFT JOIN checkout_cod_statement_reconciliation_reviews r
                    ON r.reconciliation_id = p.id
                WHERE f.id = NEW.preflight_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'COD preflight missing';
                END IF;

                SELECT payment_status INTO v_payment
                FROM orders
                WHERE id = v.order_id
                FOR UPDATE;

                SELECT r.imported_by INTO v_importer
                FROM checkout_cod_privat_statement_entries e
                JOIN checkout_cod_privat_import_runs r
                    ON r.id = e.first_run_id
                WHERE e.id = v.statement_entry_id;

                IF v.source_kind = 'single' THEN
                    SELECT recorded_by, reference_hmac
                    INTO v_origin, v_ref
                    FROM checkout_cod_settlement_bank_receipts
                    WHERE id = v.source_id;
                ELSE
                    SELECT recorded_by, reference_hmac
                    INTO v_origin, v_ref
                    FROM checkout_cod_bank_batches
                    WHERE id = v.source_id;
                END IF;

                IF NEW.reviewed_by IN (
                    v.prepared_by, v.proposed_by,
                    v.comparison_reviewer, v_origin, v_importer
                ) THEN
                    RAISE EXCEPTION 'COD preflight review must be independent';
                END IF;

                PERFORM 1
                FROM checkout_cod_bank_reference_claims
                WHERE reference_hmac = v_ref
                FOR UPDATE;

                IF NEW.decision = 'retain_unverified' THEN
                    IF v_payment <> 'pending'
                        OR v.result_code <> 'balanced_unverified'
                        OR v.comparison_decision IS DISTINCT FROM
                            'acknowledge_comparison'
                        OR v.reconciliation_id <> (
                            SELECT MAX(id)
                            FROM checkout_cod_statement_reconciliations
                            WHERE source_kind = v.source_kind
                              AND source_id = v.source_id
                        )
                        OR boardriders_cod_financial_has_hold(
                            v.source_kind, v.source_id
                        )
                        OR boardriders_cod_financial_has_hold(
                            'novapay', v.observation_id
                        )
                        OR (
                            SELECT COUNT(*)
                            FROM checkout_cod_privat_statement_entries
                            WHERE reference_hmac = v_ref
                        ) <> 1
                        OR EXISTS (
                            SELECT 1
                            FROM checkout_cod_gateway_reports g
                            WHERE g.reference_hmac = v_ref
                              AND g.event_kind = 'reversal_reported'
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM checkout_cod_bank_evidence_events e
                            WHERE e.reference_hmac = v_ref
                              AND e.event_kind = 'reversal'
                        )
                    THEN
                        RAISE EXCEPTION 'Unsafe COD preflight cannot be retained';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_post_pre_insert_guard
            BEFORE INSERT ON checkout_cod_posting_preflights
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_post_pre_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_post_pre_review_guard
            BEFORE INSERT ON checkout_cod_posting_preflight_reviews
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_post_pre_review_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_post_pre_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD posting preflight audit is immutable';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_posting_preflights',
                     'checkout_cod_posting_preflight_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_post_pre_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_post_pre_immutable()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'checkout_cod_posting_preflight_reviews'
        );

        Schema::dropIfExists(
            'checkout_cod_posting_preflights'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_post_pre_review_guard()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_post_pre_guard()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_post_pre_immutable()'
        );
    }
};
