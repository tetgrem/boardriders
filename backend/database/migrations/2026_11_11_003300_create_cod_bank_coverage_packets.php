<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_bank_coverage_packets', function (Blueprint $t): void {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->string('source_kind', 12);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('reconciliation_id');
            $t->foreign('reconciliation_id', 'cod_cov_packet_reconciliation_fk')
                ->references('id')->on('checkout_cod_statement_reconciliations')->restrictOnDelete();
            $t->unsignedBigInteger('statement_entry_id');
            $t->foreign('statement_entry_id', 'cod_cov_packet_entry_fk')
                ->references('id')->on('checkout_cod_privat_statement_entries')->restrictOnDelete();
            $t->unsignedInteger('expected_orders_count');
            $t->unsignedBigInteger('bank_net_minor');
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('fee_minor');
            $t->unsignedBigInteger('allocated_minor');
            $t->char('snapshot_hmac', 64);
            $t->unsignedBigInteger('compiled_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['source_kind', 'source_id', 'snapshot_hmac'], 'cod_cov_unique_source_snapshot');
            $t->index(['source_kind', 'source_id', 'id'], 'cod_cov_source_history');
        });

        Schema::create('checkout_cod_bank_coverage_lines', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('packet_id');
            $t->foreign('packet_id', 'cod_cov_line_packet_fk')
                ->references('id')->on('checkout_cod_bank_coverage_packets')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->unsignedBigInteger('preflight_id');
            $t->foreign('preflight_id', 'cod_cov_line_preflight_fk')
                ->references('id')->on('checkout_cod_posting_preflights')->restrictOnDelete();
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('fee_minor');
            $t->unsignedBigInteger('allocated_minor');
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['packet_id', 'order_id'], 'cod_cov_line_unique_order');
            $t->unique(['packet_id', 'preflight_id'], 'cod_cov_line_unique_preflight');
        });

        Schema::create('checkout_cod_bank_coverage_reviews', function (Blueprint $t): void {
            $t->id();
            $t->uuid('review_key')->unique();
            $t->unsignedBigInteger('packet_id');
            $t->unique('packet_id', 'cod_cov_review_once');
            $t->foreign('packet_id', 'cod_cov_review_packet_fk')
                ->references('id')->on('checkout_cod_bank_coverage_packets')->restrictOnDelete();
            $t->string('decision', 32);
            $t->string('reason_code', 48);
            $t->unsignedBigInteger('reviewed_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_coverage_packets
            ADD CONSTRAINT cod_cov_packet_values CHECK (
                source_kind IN ('single', 'batch')
                AND source_id > 0 AND compiled_by > 0
                AND expected_orders_count BETWEEN 1 AND 100000
                AND bank_net_minor BETWEEN 1 AND 100000000
                AND gross_minor BETWEEN 1 AND 1000000000
                AND fee_minor BETWEEN 0 AND 1000000000
                AND allocated_minor BETWEEN 1 AND 1000000000
                AND gross_minor = fee_minor + allocated_minor
                AND allocated_minor = bank_net_minor
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_coverage_lines
            ADD CONSTRAINT cod_cov_line_values CHECK (
                gross_minor BETWEEN 1 AND 100000000
                AND fee_minor BETWEEN 0 AND 100000000
                AND allocated_minor BETWEEN 1 AND 100000000
                AND gross_minor = fee_minor + allocated_minor
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_coverage_reviews
            ADD CONSTRAINT cod_cov_review_values CHECK (
                reviewed_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (decision = 'retain_unverified'
                        AND reason_code = 'external_bank_confirmation_required')
                    OR (decision = 'escalate_investigation'
                        AND reason_code = 'coverage_exception_detected')
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_cov_packet_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                p RECORD;
                b RECORD;
                e RECORD;
                v_count BIGINT;
            BEGIN
                SELECT r.*, x.decision, x.reviewed_by
                INTO p
                FROM checkout_cod_statement_reconciliations r
                LEFT JOIN checkout_cod_statement_reconciliation_reviews x
                    ON x.reconciliation_id = r.id
                WHERE r.id = NEW.reconciliation_id;
                IF NOT FOUND
                    OR p.source_kind <> NEW.source_kind
                    OR p.source_id <> NEW.source_id
                    OR p.statement_entry_id <> NEW.statement_entry_id
                    OR p.result_code <> 'balanced_unverified'
                    OR p.decision IS DISTINCT FROM 'acknowledge_comparison'
                    OR p.id <> (SELECT MAX(id)
                        FROM checkout_cod_statement_reconciliations
                        WHERE source_kind = p.source_kind AND source_id = p.source_id)
                THEN
                    RAISE EXCEPTION 'Current reviewed COD bank comparison required';
                END IF;

                IF NEW.source_kind = 'single' THEN
                    SELECT recorded_by, reference_hmac, claimed_net_minor
                    INTO b FROM checkout_cod_settlement_bank_receipts
                    WHERE id = NEW.source_id;
                    SELECT COUNT(*) INTO v_count
                    FROM checkout_cod_settlement_bank_matches
                    WHERE bank_receipt_id = NEW.source_id;
                ELSE
                    SELECT recorded_by, reference_hmac, claimed_net_minor
                    INTO b FROM checkout_cod_bank_batches
                    WHERE id = NEW.source_id;
                    SELECT COUNT(*) INTO v_count
                    FROM checkout_cod_bank_batch_allocations
                    WHERE batch_id = NEW.source_id;
                END IF;
                IF NOT FOUND OR v_count <> NEW.expected_orders_count THEN
                    RAISE EXCEPTION 'Incomplete COD bank coverage source';
                END IF;
                PERFORM 1 FROM checkout_cod_bank_reference_claims
                    WHERE reference_hmac = b.reference_hmac FOR UPDATE;

                SELECT s.account_alias, s.direction, s.amount_minor,
                       s.reference_hmac, i.imported_by INTO e
                FROM checkout_cod_privat_statement_entries s
                JOIN checkout_cod_privat_import_runs i ON i.id = s.first_run_id
                WHERE s.id = NEW.statement_entry_id;
                IF NOT FOUND
                    OR e.direction <> 'credit'
                    OR e.reference_hmac IS DISTINCT FROM b.reference_hmac
                    OR e.amount_minor <> NEW.bank_net_minor
                    OR b.claimed_net_minor <> NEW.bank_net_minor
                    OR NEW.compiled_by IN (b.recorded_by, e.imported_by,
                        p.proposed_by, p.reviewed_by)
                    OR (SELECT COUNT(*) FROM checkout_cod_privat_statement_entries
                        WHERE reference_hmac = b.reference_hmac) <> 1
                    OR boardriders_cod_financial_has_hold(NEW.source_kind, NEW.source_id)
                THEN
                    RAISE EXCEPTION 'Unsafe or non-independent COD bank coverage packet';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_cov_line_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                p RECORD;
                f RECORD;
                v_valid BOOLEAN;
            BEGIN
                SELECT * INTO p FROM checkout_cod_bank_coverage_packets
                WHERE id = NEW.packet_id;
                IF NOT FOUND OR EXISTS (
                    SELECT 1 FROM checkout_cod_bank_coverage_reviews
                    WHERE packet_id = NEW.packet_id
                ) THEN
                    RAISE EXCEPTION 'Coverage packet missing or sealed';
                END IF;
                SELECT x.*, r.decision AS review_decision, r.reviewed_by
                INTO f FROM checkout_cod_posting_preflights x
                LEFT JOIN checkout_cod_posting_preflight_reviews r
                    ON r.preflight_id = x.id
                WHERE x.id = NEW.preflight_id;
                IF NOT FOUND
                    OR f.order_id <> NEW.order_id
                    OR f.reconciliation_id <> p.reconciliation_id
                    OR f.source_kind <> p.source_kind
                    OR f.source_id <> p.source_id
                    OR f.review_decision IS DISTINCT FROM 'retain_unverified'
                    OR p.compiled_by IN (f.prepared_by, f.reviewed_by)
                    OR f.cod_gross_minor <> NEW.gross_minor
                    OR f.fee_minor <> NEW.fee_minor
                    OR f.bank_allocation_minor <> NEW.allocated_minor
                THEN
                    RAISE EXCEPTION 'Unreviewed or mismatched COD order coverage';
                END IF;

                IF p.source_kind = 'single' THEN
                    SELECT EXISTS (
                        SELECT 1 FROM checkout_cod_settlement_bank_receipts b
                        JOIN checkout_cod_settlement_cases c ON c.id = b.case_id
                        JOIN checkout_cod_settlement_bank_matches m ON m.bank_receipt_id = b.id
                        JOIN checkout_cod_settlement_observations o ON o.id = m.observation_id
                        WHERE b.id = p.source_id AND c.order_id = NEW.order_id
                          AND o.claimed_gross_minor = NEW.gross_minor
                          AND o.claimed_fee_minor = NEW.fee_minor
                          AND o.claimed_net_minor = NEW.allocated_minor
                          AND c.expected_cod_minor = NEW.gross_minor
                    ) INTO v_valid;
                ELSE
                    SELECT EXISTS (
                        SELECT 1 FROM checkout_cod_bank_batch_allocations a
                        JOIN checkout_cod_settlement_cases c ON c.id = a.case_id
                        JOIN checkout_cod_settlement_observations o ON o.id = a.observation_id
                        WHERE a.batch_id = p.source_id AND c.order_id = NEW.order_id
                          AND a.amount_minor = NEW.allocated_minor
                          AND o.claimed_gross_minor = NEW.gross_minor
                          AND o.claimed_fee_minor = NEW.fee_minor
                          AND o.claimed_net_minor = NEW.allocated_minor
                          AND c.expected_cod_minor = NEW.gross_minor
                    ) INTO v_valid;
                END IF;
                IF NOT v_valid THEN
                    RAISE EXCEPTION 'Bank coverage order is not part of this source';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_cov_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                p RECORD;
                b RECORD;
                r RECORD;
                totals RECORD;
                v_count BIGINT;
            BEGIN
                SELECT * INTO p FROM checkout_cod_bank_coverage_packets
                WHERE id = NEW.packet_id;
                IF NOT FOUND OR NEW.reviewed_by = p.compiled_by THEN
                    RAISE EXCEPTION 'Independent COD coverage reviewer required';
                END IF;
                IF p.source_kind = 'single' THEN
                    SELECT recorded_by, reference_hmac INTO b
                    FROM checkout_cod_settlement_bank_receipts WHERE id = p.source_id;
                    SELECT COUNT(*) INTO v_count
                    FROM checkout_cod_settlement_bank_matches
                    WHERE bank_receipt_id = p.source_id;
                ELSE
                    SELECT recorded_by, reference_hmac INTO b
                    FROM checkout_cod_bank_batches WHERE id = p.source_id;
                    SELECT COUNT(*) INTO v_count
                    FROM checkout_cod_bank_batch_allocations
                    WHERE batch_id = p.source_id;
                END IF;
                IF NOT FOUND OR NEW.reviewed_by = b.recorded_by THEN
                    RAISE EXCEPTION 'Coverage reviewer created bank source';
                END IF;
                PERFORM 1 FROM checkout_cod_bank_reference_claims
                    WHERE reference_hmac = b.reference_hmac FOR UPDATE;

                SELECT x.proposed_by, y.reviewed_by, x.result_code,
                       y.decision, x.statement_entry_id,
                       i.imported_by
                INTO r
                FROM checkout_cod_statement_reconciliations x
                LEFT JOIN checkout_cod_statement_reconciliation_reviews y
                    ON y.reconciliation_id = x.id
                JOIN checkout_cod_privat_statement_entries e
                    ON e.id = x.statement_entry_id
                JOIN checkout_cod_privat_import_runs i ON i.id = e.first_run_id
                WHERE x.id = p.reconciliation_id;
                IF NOT FOUND OR NEW.reviewed_by IN (
                    r.proposed_by, r.reviewed_by, r.imported_by
                ) THEN
                    RAISE EXCEPTION 'Coverage reviewer involved in earlier steps';
                END IF;

                SELECT COUNT(*) AS count,
                       COALESCE(SUM(gross_minor), 0) AS gross,
                       COALESCE(SUM(fee_minor), 0) AS fee,
                       COALESCE(SUM(allocated_minor), 0) AS net
                INTO totals FROM checkout_cod_bank_coverage_lines
                WHERE packet_id = p.id;
                IF totals.count <> p.expected_orders_count
                    OR totals.gross <> p.gross_minor
                    OR totals.fee <> p.fee_minor
                    OR totals.net <> p.allocated_minor
                THEN
                    RAISE EXCEPTION 'Bank packet lines do not balance';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM checkout_cod_bank_coverage_lines l
                    JOIN checkout_cod_posting_preflights f ON f.id = l.preflight_id
                    JOIN checkout_cod_posting_preflight_reviews fr ON fr.preflight_id = f.id
                    JOIN orders o ON o.id = l.order_id
                    WHERE l.packet_id = p.id
                      AND (NEW.reviewed_by IN (f.prepared_by, fr.reviewed_by)
                           OR fr.decision <> 'retain_unverified'
                           OR o.payment_status <> 'pending')
                ) THEN
                    RAISE EXCEPTION 'Coverage reviewer conflicts with per-order evidence';
                END IF;

                IF NEW.decision = 'retain_unverified' THEN
                    IF v_count <> p.expected_orders_count
                        OR p.reconciliation_id <> (
                            SELECT MAX(id) FROM checkout_cod_statement_reconciliations
                            WHERE source_kind = p.source_kind AND source_id = p.source_id)
                        OR p.id <> (SELECT MAX(id) FROM checkout_cod_bank_coverage_packets
                            WHERE source_kind = p.source_kind AND source_id = p.source_id)
                        OR r.result_code <> 'balanced_unverified'
                        OR r.decision IS DISTINCT FROM 'acknowledge_comparison'
                        OR (SELECT COUNT(*) FROM checkout_cod_privat_statement_entries
                            WHERE reference_hmac = b.reference_hmac) <> 1
                        OR boardriders_cod_financial_has_hold(p.source_kind, p.source_id)
                        OR EXISTS (
                            SELECT 1 FROM checkout_cod_gateway_reports g
                            WHERE g.reference_hmac = b.reference_hmac
                              AND (g.event_kind = 'reversal_reported'
                                   OR g.net_minor <> p.bank_net_minor)
                        )
                        OR (SELECT COUNT(*) FROM checkout_cod_gateway_reports g
                            WHERE g.reference_hmac = b.reference_hmac
                              AND g.event_kind = 'credit_reported') > 1
                        OR EXISTS (
                            SELECT 1 FROM checkout_cod_bank_evidence_events e
                            WHERE e.reference_hmac = b.reference_hmac
                              AND (e.event_kind = 'reversal'
                                   OR e.amount_minor <> p.bank_net_minor)
                        )
                        OR (SELECT COUNT(*) FROM checkout_cod_bank_evidence_events e
                            WHERE e.reference_hmac = b.reference_hmac
                              AND e.event_kind = 'credit') > 1
                        OR EXISTS (
                            SELECT 1 FROM checkout_cod_bank_coverage_lines l
                            JOIN checkout_cod_posting_preflights f ON f.id = l.preflight_id
                            JOIN checkout_cod_posting_preflight_reviews fr ON fr.preflight_id = f.id
                            JOIN orders o ON o.id = l.order_id
                            WHERE l.packet_id = p.id
                              AND (o.payment_status <> 'pending'
                                   OR fr.decision <> 'retain_unverified'
                                   OR boardriders_cod_financial_has_hold('novapay', f.observation_id))
                        )
                    THEN
                        RAISE EXCEPTION 'Unsafe COD coverage cannot be retained';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER cod_cov_packet_guard BEFORE INSERT ON checkout_cod_bank_coverage_packets FOR EACH ROW EXECUTE FUNCTION boardriders_cod_cov_packet_guard()');
        DB::statement('CREATE TRIGGER cod_cov_line_guard BEFORE INSERT ON checkout_cod_bank_coverage_lines FOR EACH ROW EXECUTE FUNCTION boardriders_cod_cov_line_guard()');
        DB::statement('CREATE TRIGGER cod_cov_review_guard BEFORE INSERT ON checkout_cod_bank_coverage_reviews FOR EACH ROW EXECUTE FUNCTION boardriders_cod_cov_review_guard()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_cov_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD bank coverage audit is immutable';
            END;
            $$
        SQL);
        foreach ([
                     'checkout_cod_bank_coverage_packets',
                     'checkout_cod_bank_coverage_lines',
                     'checkout_cod_bank_coverage_reviews',
                 ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_cov_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_cov_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_bank_coverage_reviews');
        Schema::dropIfExists('checkout_cod_bank_coverage_lines');
        Schema::dropIfExists('checkout_cod_bank_coverage_packets');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_cov_review_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_cov_line_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_cov_packet_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_cov_immutable()');
    }
};
