<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_statement_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->string('source_kind', 12);
            $t->unsignedBigInteger('source_id');
            $t->foreignId('statement_entry_id')
                ->constrained('checkout_cod_privat_statement_entries')->restrictOnDelete();
            $t->unsignedBigInteger('claimed_net_minor');
            $t->unsignedBigInteger('bank_net_minor');
            $t->unsignedBigInteger('novapay_gross_minor');
            $t->unsignedBigInteger('novapay_fee_minor');
            $t->unsignedBigInteger('novapay_net_minor');
            $t->unsignedInteger('allocation_count');
            $t->bigInteger('difference_minor');
            $t->string('result_code', 32);
            $t->char('snapshot_hmac', 64);
            $t->unsignedBigInteger('proposed_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['source_kind', 'source_id', 'id'], 'cod_statement_source_history');
            $t->unique(['source_kind', 'source_id', 'snapshot_hmac'], 'cod_statement_unique_snapshot');
            $t->index(['result_code', 'id'], 'cod_statement_review_queue');
        });

        Schema::create('checkout_cod_statement_reconciliation_reviews', function (Blueprint $t) {
            $t->id();
            $t->uuid('review_key')->unique();

            $t->foreignId('reconciliation_id')
                ->unique('cod_stmt_review_recon_unique')
                ->constrained('checkout_cod_statement_reconciliations')
                ->restrictOnDelete();

            $t->string('decision', 32);
            $t->string('reason_code', 48);
            $t->unsignedBigInteger('reviewed_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_statement_reconciliations
            ADD CONSTRAINT cod_statement_reconciliation_values CHECK (
                source_kind IN ('single', 'batch')
                AND source_id > 0 AND proposed_by > 0
                AND claimed_net_minor BETWEEN 1 AND 100000000
                AND bank_net_minor BETWEEN 1 AND 100000000
                AND novapay_gross_minor BETWEEN 0 AND 1000000000
                AND novapay_fee_minor BETWEEN 0 AND 1000000000
                AND novapay_net_minor BETWEEN 0 AND 1000000000
                AND allocation_count BETWEEN 0 AND 100000
                AND difference_minor = CAST(bank_net_minor AS BIGINT) - CAST(claimed_net_minor AS BIGINT)
                AND result_code IN (
                    'balanced_unverified', 'bank_underpayment', 'bank_overpayment',
                    'novapay_difference', 'fee_difference', 'upstream_unreviewed',
                    'evidence_conflict'
                )
                AND (result_code <> 'balanced_unverified' OR (
                    allocation_count > 0
                    AND novapay_gross_minor >= novapay_fee_minor
                    AND novapay_gross_minor - novapay_fee_minor = novapay_net_minor
                    AND novapay_net_minor = claimed_net_minor
                    AND bank_net_minor = claimed_net_minor
                ))
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_statement_reconciliation_reviews
            ADD CONSTRAINT cod_statement_reconciliation_review_values CHECK (
                reviewed_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (decision = 'acknowledge_comparison'
                        AND reason_code = 'external_payment_verification_required')
                    OR (decision = 'escalate_investigation'
                        AND reason_code = 'bank_reconciliation_discrepancy')
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_statement_reconciliation_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_source RECORD;
                v_entry RECORD;
                v_importer BIGINT;
            BEGIN
                IF NEW.source_kind = 'single' THEN
                    SELECT id, recorded_by, reference_hmac, claimed_net_minor
                    INTO v_source FROM checkout_cod_settlement_bank_receipts
                    WHERE id = NEW.source_id;
                ELSIF NEW.source_kind = 'batch' THEN
                    SELECT id, recorded_by, reference_hmac, claimed_net_minor
                    INTO v_source FROM checkout_cod_bank_batches
                    WHERE id = NEW.source_id;
                ELSE
                    RAISE EXCEPTION 'Unknown COD statement reconciliation source';
                END IF;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'COD statement source missing';
                END IF;

                PERFORM 1 FROM checkout_cod_bank_reference_claims
                    WHERE reference_hmac = v_source.reference_hmac FOR UPDATE;

                SELECT e.*, r.imported_by INTO v_entry
                FROM checkout_cod_privat_statement_entries e
                JOIN checkout_cod_privat_import_runs r ON r.id = e.first_run_id
                WHERE e.id = NEW.statement_entry_id;
                IF NOT FOUND OR v_entry.reference_hmac IS DISTINCT FROM v_source.reference_hmac
                    OR v_entry.amount_minor <> NEW.bank_net_minor
                    OR v_source.claimed_net_minor <> NEW.claimed_net_minor
                    OR NEW.proposed_by IN (v_source.recorded_by, v_entry.imported_by)
                THEN
                    RAISE EXCEPTION 'Invalid or non-independent COD statement proposal';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_statement_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v RECORD;
                v_source RECORD;
                v_ref CHAR(64);
            BEGIN
                SELECT p.*, e.reference_hmac, e.direction, e.account_alias,
                       r.imported_by, e.row_hmac
                INTO v
                FROM checkout_cod_statement_reconciliations p
                JOIN checkout_cod_privat_statement_entries e ON e.id = p.statement_entry_id
                JOIN checkout_cod_privat_import_runs r ON r.id = e.first_run_id
                WHERE p.id = NEW.reconciliation_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'COD statement proposal missing';
                END IF;

                IF NEW.reviewed_by IN (v.proposed_by, v.imported_by) THEN
                    RAISE EXCEPTION 'Reviewer not independent of proposal/import';
                END IF;

                IF v.source_kind = 'single' THEN
                    SELECT recorded_by, reference_hmac INTO v_source
                    FROM checkout_cod_settlement_bank_receipts WHERE id = v.source_id;
                ELSE
                    SELECT recorded_by, reference_hmac INTO v_source
                    FROM checkout_cod_bank_batches WHERE id = v.source_id;
                END IF;
                IF NOT FOUND OR NEW.reviewed_by = v_source.recorded_by THEN
                    RAISE EXCEPTION 'Reviewer not independent of source';
                END IF;

                v_ref := v_source.reference_hmac;
                PERFORM 1 FROM checkout_cod_bank_reference_claims
                    WHERE reference_hmac = v_ref FOR UPDATE;

                IF NEW.decision = 'acknowledge_comparison' THEN
                    IF v.id <> (SELECT MAX(id) FROM checkout_cod_statement_reconciliations
                        WHERE source_kind = v.source_kind AND source_id = v.source_id)
                        OR v.result_code <> 'balanced_unverified'
                        OR v.direction <> 'credit'
                        OR v.reference_hmac IS DISTINCT FROM v_ref
                        OR v.bank_net_minor <> v.claimed_net_minor
                        OR v.novapay_net_minor <> v.claimed_net_minor
                        OR v.novapay_gross_minor < v.novapay_fee_minor
                        OR v.novapay_gross_minor - v.novapay_fee_minor <> v.novapay_net_minor
                        OR boardriders_cod_financial_has_hold(v.source_kind, v.source_id)
                        OR (SELECT COUNT(*) FROM checkout_cod_privat_statement_entries
                            WHERE reference_hmac = v_ref) <> 1
                        OR EXISTS (SELECT 1 FROM checkout_cod_gateway_reports
                            WHERE reference_hmac = v_ref AND (
                                event_kind = 'reversal_reported'
                                OR net_minor <> v.claimed_net_minor
                            ))
                        OR (SELECT COUNT(*) FROM checkout_cod_gateway_reports
                            WHERE reference_hmac = v_ref AND event_kind = 'credit_reported') > 1
                        OR EXISTS (SELECT 1 FROM checkout_cod_bank_evidence_events
                            WHERE reference_hmac = v_ref AND (
                                event_kind = 'reversal'
                                OR amount_minor <> v.claimed_net_minor
                            ))
                        OR (SELECT COUNT(*) FROM checkout_cod_bank_evidence_events
                            WHERE reference_hmac = v_ref AND event_kind = 'credit') > 1
                    THEN
                        RAISE EXCEPTION 'Reconciliation no longer eligible for acknowledgment';
                    END IF;

                    IF v.source_kind = 'single' THEN
                        IF EXISTS (
                            SELECT 1 FROM checkout_cod_settlement_bank_matches m
                            JOIN checkout_cod_settlement_observations o ON o.id = m.observation_id
                            LEFT JOIN checkout_cod_settlement_bank_reviews mr ON mr.match_id = m.id
                            LEFT JOIN checkout_cod_settlement_reviews nr ON nr.observation_id = o.id
                            WHERE m.bank_receipt_id = v.source_id
                              AND NEW.reviewed_by IN (m.proposed_by, mr.reviewed_by,
                                  o.recorded_by, nr.reviewed_by)
                        ) THEN
                            RAISE EXCEPTION 'Reviewer involved in original NovaPay matching';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1
                            FROM checkout_cod_settlement_bank_matches m
                            JOIN checkout_cod_settlement_bank_reviews mr ON mr.match_id = m.id
                            JOIN checkout_cod_settlement_observations o ON o.id = m.observation_id
                            JOIN checkout_cod_settlement_reviews nr ON nr.observation_id = o.id
                            WHERE m.bank_receipt_id = v.source_id
                              AND m.match_result = 'amounts_match_unverified'
                              AND mr.decision = 'retain_external_confirmation'
                              AND nr.decision = 'retain_unverified'
                              AND o.comparison_result = 'consistent_with_estimate'
                              AND o.claimed_gross_minor = v.novapay_gross_minor
                              AND o.claimed_fee_minor = v.novapay_fee_minor
                              AND o.claimed_net_minor = v.novapay_net_minor
                              AND NOT boardriders_cod_financial_has_hold('novapay', o.id)
                        ) THEN
                            RAISE EXCEPTION 'Independently reviewed NovaPay pair required';
                        END IF;
                    ELSE
                        IF EXISTS (
                            SELECT 1 FROM checkout_cod_bank_batch_reviews br
                            WHERE br.batch_id = v.source_id AND br.reviewed_by = NEW.reviewed_by
                        ) OR EXISTS (
                            SELECT 1 FROM checkout_cod_bank_batch_allocations a
                            JOIN checkout_cod_settlement_observations o ON o.id = a.observation_id
                            LEFT JOIN checkout_cod_settlement_reviews nr ON nr.observation_id = o.id
                            WHERE a.batch_id = v.source_id
                              AND NEW.reviewed_by IN (a.allocated_by, o.recorded_by, nr.reviewed_by)
                        ) THEN
                            RAISE EXCEPTION 'Reviewer involved in original batch';
                        END IF;
                        IF NOT EXISTS (
                            SELECT 1 FROM checkout_cod_bank_batch_reviews br
                            WHERE br.batch_id = v.source_id
                              AND br.decision = 'allocation_balanced_unverified'
                              AND br.allocated_minor = v.claimed_net_minor
                        ) OR EXISTS (
                            SELECT 1 FROM checkout_cod_bank_batch_allocations a
                            JOIN checkout_cod_settlement_observations o ON o.id = a.observation_id
                            LEFT JOIN checkout_cod_settlement_reviews nr ON nr.observation_id = o.id
                            WHERE a.batch_id = v.source_id
                              AND (
                                  a.amount_minor <> o.claimed_net_minor
                                  OR nr.decision IS DISTINCT FROM 'retain_unverified'
                                  OR o.comparison_result <> 'consistent_with_estimate'
                                  OR boardriders_cod_financial_has_hold('novapay', o.id)
                              )
                        ) OR NOT EXISTS (
                            SELECT 1 FROM checkout_cod_bank_batch_allocations a
                            JOIN checkout_cod_settlement_observations o ON o.id = a.observation_id
                            WHERE a.batch_id = v.source_id
                            GROUP BY a.batch_id
                            HAVING COUNT(*) = v.allocation_count
                               AND SUM(o.claimed_gross_minor) = v.novapay_gross_minor
                               AND SUM(o.claimed_fee_minor) = v.novapay_fee_minor
                               AND SUM(o.claimed_net_minor) = v.novapay_net_minor
                               AND SUM(a.amount_minor) = v.claimed_net_minor
                        ) THEN
                            RAISE EXCEPTION 'Reviewed balanced batch allocations required';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_statement_proposal_guard
            BEFORE INSERT ON checkout_cod_statement_reconciliations
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_statement_reconciliation_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_statement_review_guard
            BEFORE INSERT ON checkout_cod_statement_reconciliation_reviews
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_statement_review_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_statement_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD statement reconciliation history is immutable';
            END;
            $$
        SQL);
        foreach (['checkout_cod_statement_reconciliations', 'checkout_cod_statement_reconciliation_reviews'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_statement_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_statement_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_statement_reconciliation_reviews');
        Schema::dropIfExists('checkout_cod_statement_reconciliations');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_statement_review_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_statement_reconciliation_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_statement_immutable()');
    }
};
