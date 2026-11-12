<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_journal_drafts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('operation_key')->unique();

            $t->unsignedBigInteger('coverage_packet_id')
                ->unique('cod_jd_one_per_packet');

            $t->foreign('coverage_packet_id', 'cod_jd_packet_fk')
                ->references('id')
                ->on('checkout_cod_bank_coverage_packets')
                ->restrictOnDelete();

            $t->string('source_kind', 12);
            $t->unsignedBigInteger('source_id');
            $t->unsignedInteger('orders_count');
            $t->unsignedInteger('entry_count');
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('fee_minor');
            $t->unsignedBigInteger('bank_minor');
            $t->char('snapshot_hmac', 64);
            $t->unsignedBigInteger('prepared_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();

            $t->index(
                ['source_kind', 'source_id', 'id'],
                'cod_jd_source_history'
            );
        });

        Schema::create('checkout_cod_journal_draft_entries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('draft_id');

            $t->foreign('draft_id', 'cod_jd_entry_draft_fk')
                ->references('id')
                ->on('checkout_cod_journal_drafts')
                ->restrictOnDelete();

            $t->unsignedBigInteger('coverage_line_id');

            $t->foreign('coverage_line_id', 'cod_jd_entry_coverage_fk')
                ->references('id')
                ->on('checkout_cod_bank_coverage_lines')
                ->restrictOnDelete();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            $t->string('entry_kind', 32);
            $t->string('debit_account', 48);
            $t->string('credit_account', 48);
            $t->unsignedBigInteger('amount_minor');
            $t->timestampTz('created_at', 6)->useCurrent();

            $t->unique(
                ['draft_id', 'coverage_line_id', 'entry_kind'],
                'cod_jd_entry_once'
            );

            $t->index(
                ['order_id', 'id'],
                'cod_jd_order_history'
            );
        });

        Schema::create('checkout_cod_journal_draft_reviews', function (Blueprint $t): void {
            $t->id();
            $t->uuid('review_key')->unique();

            $t->unsignedBigInteger('draft_id')
                ->unique('cod_jd_review_once');

            $t->foreign('draft_id', 'cod_jd_review_draft_fk')
                ->references('id')
                ->on('checkout_cod_journal_drafts')
                ->restrictOnDelete();

            $t->string('decision', 32);
            $t->string('reason_code', 48);
            $t->unsignedBigInteger('reviewed_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_journal_drafts
            ADD CONSTRAINT cod_jd_values CHECK (
                source_kind IN ('single', 'batch') AND source_id > 0
                AND orders_count BETWEEN 1 AND 100000
                AND entry_count BETWEEN orders_count * 2 AND orders_count * 3
                AND gross_minor BETWEEN 1 AND 1000000000
                AND fee_minor BETWEEN 0 AND 1000000000
                AND bank_minor BETWEEN 1 AND 100000000
                AND gross_minor = fee_minor + bank_minor
                AND prepared_by > 0
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_journal_draft_entries
            ADD CONSTRAINT cod_jd_entry_values CHECK (
                amount_minor BETWEEN 1 AND 100000000
                AND (
                    (entry_kind = 'carrier_collection'
                     AND debit_account = 'draft_carrier_clearing'
                     AND credit_account = 'draft_cod_receivable')
                    OR (entry_kind = 'bank_remittance'
                     AND debit_account = 'draft_bank_receipt_suspense'
                     AND credit_account = 'draft_carrier_clearing')
                    OR (entry_kind = 'carrier_fee'
                     AND debit_account = 'draft_carrier_fee_expense'
                     AND credit_account = 'draft_carrier_clearing')
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_journal_draft_reviews
            ADD CONSTRAINT cod_jd_review_values CHECK (
                reviewed_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (decision = 'retain_unposted'
                     AND reason_code = 'authenticated_payment_evidence_required')
                    OR (decision = 'escalate_investigation'
                     AND reason_code = 'journal_investigation_required')
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_jd_draft_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD; s RECORD;
            BEGIN
                SELECT p.*, r.decision, r.reviewed_by
                INTO v FROM checkout_cod_bank_coverage_packets p
                LEFT JOIN checkout_cod_bank_coverage_reviews r
                    ON r.packet_id = p.id
                WHERE p.id = NEW.coverage_packet_id;

                IF NOT FOUND
                    OR v.decision IS DISTINCT FROM 'retain_unverified'
                    OR v.source_kind <> NEW.source_kind
                    OR v.source_id <> NEW.source_id
                    OR v.expected_orders_count <> NEW.orders_count
                    OR v.gross_minor <> NEW.gross_minor
                    OR v.fee_minor <> NEW.fee_minor
                    OR v.bank_net_minor <> NEW.bank_minor
                    OR v.allocated_minor <> NEW.bank_minor
                    OR NEW.prepared_by IN (v.compiled_by, v.reviewed_by)
                    OR v.id <> (
                        SELECT MAX(id)
                        FROM checkout_cod_bank_coverage_packets
                        WHERE source_kind = v.source_kind
                          AND source_id = v.source_id
                    )
                THEN
                    RAISE EXCEPTION
                        'Current reviewed bank coverage packet required';
                END IF;

                IF NEW.source_kind = 'single' THEN
                    SELECT recorded_by, reference_hmac INTO s
                    FROM checkout_cod_settlement_bank_receipts
                    WHERE id = NEW.source_id;
                ELSE
                    SELECT recorded_by, reference_hmac INTO s
                    FROM checkout_cod_bank_batches
                    WHERE id = NEW.source_id;
                END IF;

                IF NOT FOUND OR s.recorded_by = NEW.prepared_by THEN
                    RAISE EXCEPTION
                        'Journal draft author must be independent of bank source';
                END IF;

                PERFORM 1
                FROM checkout_cod_bank_reference_claims
                WHERE reference_hmac = s.reference_hmac
                FOR UPDATE;

                IF boardriders_cod_financial_has_hold(
                    NEW.source_kind, NEW.source_id
                ) OR (
                    SELECT COUNT(*)
                    FROM checkout_cod_privat_statement_entries
                    WHERE reference_hmac = s.reference_hmac
                ) <> 1
                THEN
                    RAISE EXCEPTION
                        'Financial hold or ambiguous bank statement';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_jd_entry_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE d RECORD; l RECORD;
            BEGIN
                SELECT * INTO d
                FROM checkout_cod_journal_drafts
                WHERE id = NEW.draft_id
                FOR UPDATE;

                IF NOT FOUND OR EXISTS (
                    SELECT 1
                    FROM checkout_cod_journal_draft_reviews
                    WHERE draft_id = NEW.draft_id
                ) THEN
                    RAISE EXCEPTION 'Journal draft absent or sealed';
                END IF;

                SELECT * INTO l
                FROM checkout_cod_bank_coverage_lines
                WHERE id = NEW.coverage_line_id;

                IF NOT FOUND
                    OR l.packet_id <> d.coverage_packet_id
                    OR l.order_id <> NEW.order_id
                THEN
                    RAISE EXCEPTION
                        'Journal entry is not part of bank coverage packet';
                END IF;

                IF (
                    (
                        NEW.entry_kind = 'carrier_collection'
                        AND NEW.amount_minor <> l.gross_minor
                    )
                    OR (
                        NEW.entry_kind = 'bank_remittance'
                        AND NEW.amount_minor <> l.allocated_minor
                    )
                    OR (
                        NEW.entry_kind = 'carrier_fee'
                        AND NEW.amount_minor <> l.fee_minor
                    )
                ) THEN
                    RAISE EXCEPTION
                        'Journal entry amount differs from coverage line';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_jd_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                d RECORD;
                p RECORD;
                s RECORD;
                t RECORD;
                v_importer BIGINT;
                v_expected_fees BIGINT;
            BEGIN
                SELECT * INTO d
                FROM checkout_cod_journal_drafts
                WHERE id = NEW.draft_id
                FOR UPDATE;

                IF NOT FOUND OR NEW.reviewed_by = d.prepared_by THEN
                    RAISE EXCEPTION
                        'Independent journal draft review required';
                END IF;

                SELECT x.*, r.decision AS coverage_decision,
                       r.reviewed_by AS coverage_reviewer
                INTO p
                FROM checkout_cod_bank_coverage_packets x
                LEFT JOIN checkout_cod_bank_coverage_reviews r
                    ON r.packet_id = x.id
                WHERE x.id = d.coverage_packet_id;

                IF NOT FOUND OR NEW.reviewed_by IN (
                    p.compiled_by, p.coverage_reviewer
                ) THEN
                    RAISE EXCEPTION
                        'Reviewer involved in coverage packet';
                END IF;

                IF p.source_kind = 'single' THEN
                    SELECT recorded_by, reference_hmac INTO s
                    FROM checkout_cod_settlement_bank_receipts
                    WHERE id = p.source_id;
                ELSE
                    SELECT recorded_by, reference_hmac INTO s
                    FROM checkout_cod_bank_batches
                    WHERE id = p.source_id;
                END IF;

                IF NOT FOUND OR NEW.reviewed_by = s.recorded_by THEN
                    RAISE EXCEPTION
                        'Reviewer created bank source';
                END IF;

                PERFORM 1
                FROM checkout_cod_bank_reference_claims
                WHERE reference_hmac = s.reference_hmac
                FOR UPDATE;

                SELECT i.imported_by INTO v_importer
                FROM checkout_cod_statement_reconciliations r
                JOIN checkout_cod_privat_statement_entries e
                    ON e.id = r.statement_entry_id
                JOIN checkout_cod_privat_import_runs i
                    ON i.id = e.first_run_id
                WHERE r.id = p.reconciliation_id;

                IF NOT FOUND
                    OR NEW.reviewed_by = v_importer
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_statement_reconciliations x
                        LEFT JOIN checkout_cod_statement_reconciliation_reviews y
                            ON y.reconciliation_id = x.id
                        WHERE x.id = p.reconciliation_id
                          AND NEW.reviewed_by IN (
                              x.proposed_by, y.reviewed_by
                          )
                    )
                THEN
                    RAISE EXCEPTION
                        'Reviewer involved in bank statement reconciliation';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM checkout_cod_bank_coverage_lines l
                    JOIN checkout_cod_posting_preflights f
                        ON f.id = l.preflight_id
                    LEFT JOIN checkout_cod_posting_preflight_reviews r
                        ON r.preflight_id = f.id
                    WHERE l.packet_id = p.id
                      AND NEW.reviewed_by IN (
                          f.prepared_by, r.reviewed_by
                      )
                ) THEN
                    RAISE EXCEPTION
                        'Reviewer involved in order preflight';
                END IF;

                SELECT
                    COUNT(*) AS cnt,
                    COUNT(*) FILTER (
                        WHERE entry_kind = 'carrier_collection'
                    ) AS collections,
                    COUNT(*) FILTER (
                        WHERE entry_kind = 'bank_remittance'
                    ) AS remittances,
                    COUNT(*) FILTER (
                        WHERE entry_kind = 'carrier_fee'
                    ) AS fees,
                    COALESCE(SUM(amount_minor) FILTER (
                        WHERE entry_kind = 'carrier_collection'
                    ), 0) AS gross,
                    COALESCE(SUM(amount_minor) FILTER (
                        WHERE entry_kind = 'bank_remittance'
                    ), 0) AS net,
                    COALESCE(SUM(amount_minor) FILTER (
                        WHERE entry_kind = 'carrier_fee'
                    ), 0) AS fee
                INTO t
                FROM checkout_cod_journal_draft_entries
                WHERE draft_id = d.id;

                SELECT COUNT(*) INTO v_expected_fees
                FROM checkout_cod_bank_coverage_lines
                WHERE packet_id = p.id AND fee_minor > 0;

                IF t.cnt <> d.entry_count
                    OR t.collections <> d.orders_count
                    OR t.remittances <> d.orders_count
                    OR t.fees <> v_expected_fees
                    OR t.gross <> d.gross_minor
                    OR t.net <> d.bank_minor
                    OR t.fee <> d.fee_minor
                    OR d.gross_minor <> d.bank_minor + d.fee_minor
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_bank_coverage_lines l
                        WHERE l.packet_id = p.id AND (
                            NOT EXISTS (
                                SELECT 1
                                FROM checkout_cod_journal_draft_entries e
                                WHERE e.draft_id = d.id
                                  AND e.coverage_line_id = l.id
                                  AND e.entry_kind = 'carrier_collection'
                            )
                            OR NOT EXISTS (
                                SELECT 1
                                FROM checkout_cod_journal_draft_entries e
                                WHERE e.draft_id = d.id
                                  AND e.coverage_line_id = l.id
                                  AND e.entry_kind = 'bank_remittance'
                            )
                            OR (
                                l.fee_minor > 0 AND NOT EXISTS (
                                    SELECT 1
                                    FROM checkout_cod_journal_draft_entries e
                                    WHERE e.draft_id = d.id
                                      AND e.coverage_line_id = l.id
                                      AND e.entry_kind = 'carrier_fee'
                                )
                            )
                        )
                    )
                THEN
                    RAISE EXCEPTION
                        'Journal draft does not contain all balanced entries';
                END IF;

                IF NEW.decision = 'retain_unposted' THEN
                    IF p.coverage_decision IS DISTINCT FROM 'retain_unverified'
                        OR p.id <> (
                            SELECT MAX(id)
                            FROM checkout_cod_bank_coverage_packets
                            WHERE source_kind = p.source_kind
                              AND source_id = p.source_id
                        )
                        OR (
                            SELECT COUNT(*)
                            FROM checkout_cod_privat_statement_entries
                            WHERE reference_hmac = s.reference_hmac
                        ) <> 1
                        OR boardriders_cod_financial_has_hold(
                            p.source_kind, p.source_id
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM checkout_cod_bank_coverage_lines l
                            JOIN checkout_cod_posting_preflights f
                                ON f.id = l.preflight_id
                            JOIN checkout_cod_posting_preflight_reviews r
                                ON r.preflight_id = f.id
                            JOIN orders o ON o.id = l.order_id
                            WHERE l.packet_id = p.id
                              AND (
                                  o.payment_status <> 'pending'
                                  OR r.decision <> 'retain_unverified'
                                  OR boardriders_cod_financial_has_hold(
                                      'novapay', f.observation_id
                                  )
                              )
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM checkout_cod_gateway_reports g
                            WHERE g.reference_hmac = s.reference_hmac
                              AND (
                                  g.event_kind = 'reversal_reported'
                                  OR g.net_minor <> p.bank_net_minor
                              )
                        )
                        OR (
                            SELECT COUNT(*)
                            FROM checkout_cod_gateway_reports g
                            WHERE g.reference_hmac = s.reference_hmac
                              AND g.event_kind = 'credit_reported'
                        ) > 1
                        OR EXISTS (
                            SELECT 1
                            FROM checkout_cod_bank_evidence_events e
                            WHERE e.reference_hmac = s.reference_hmac
                              AND (
                                  e.event_kind = 'reversal'
                                  OR e.amount_minor <> p.bank_net_minor
                              )
                        )
                        OR (
                            SELECT COUNT(*)
                            FROM checkout_cod_bank_evidence_events e
                            WHERE e.reference_hmac = s.reference_hmac
                              AND e.event_kind = 'credit'
                        ) > 1
                    THEN
                        RAISE EXCEPTION
                            'Journal draft is stale or has financial risk';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(
            'CREATE TRIGGER cod_jd_draft_guard
             BEFORE INSERT ON checkout_cod_journal_drafts
             FOR EACH ROW
             EXECUTE FUNCTION boardriders_cod_jd_draft_guard()'
        );

        DB::statement(
            'CREATE TRIGGER cod_jd_entry_guard
             BEFORE INSERT ON checkout_cod_journal_draft_entries
             FOR EACH ROW
             EXECUTE FUNCTION boardriders_cod_jd_entry_guard()'
        );

        DB::statement(
            'CREATE TRIGGER cod_jd_review_guard
             BEFORE INSERT ON checkout_cod_journal_draft_reviews
             FOR EACH ROW
             EXECUTE FUNCTION boardriders_cod_jd_review_guard()'
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_jd_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD journal draft audit is immutable';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_journal_drafts',
                     'checkout_cod_journal_draft_entries',
                     'checkout_cod_journal_draft_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_jd_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_jd_immutable()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_journal_draft_reviews');
        Schema::dropIfExists('checkout_cod_journal_draft_entries');
        Schema::dropIfExists('checkout_cod_journal_drafts');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_jd_review_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_jd_entry_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_jd_draft_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_jd_immutable()'
        );
    }
};
