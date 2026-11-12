<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_bank_receipt_postings', function (Blueprint $t): void {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->unsignedBigInteger('journal_draft_id')->unique('cod_brp_one_per_draft');
            $t->foreign('journal_draft_id', 'cod_brp_journal_fk')
                ->references('id')->on('checkout_cod_journal_drafts')->restrictOnDelete();
            $t->unsignedBigInteger('refetch_id')->unique('cod_brp_one_per_refetch');
            $t->foreign('refetch_id', 'cod_brp_refetch_fk')
                ->references('id')->on('checkout_cod_privat_refetches')->restrictOnDelete();
            $t->unsignedBigInteger('statement_entry_id')->unique('cod_brp_one_per_bank_tx');
            $t->foreign('statement_entry_id', 'cod_brp_entry_fk')
                ->references('id')->on('checkout_cod_privat_statement_entries')->restrictOnDelete();
            $t->string('source_kind', 12);
            $t->unsignedBigInteger('source_id');
            $t->char('reference_hmac', 64);
            $t->char('transaction_hmac', 64);
            $t->char('row_hmac', 64);
            $t->string('account_alias', 48);
            $t->date('booked_on');
            $t->unsignedBigInteger('amount_minor');
            $t->char('api_result_sha256', 64);
            $t->char('snapshot_hmac', 64);
            $t->unsignedBigInteger('posted_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['source_kind', 'source_id'], 'cod_brp_once_per_source');
        });

        Schema::create('checkout_cod_bank_receipt_ledger_lines', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('posting_id');
            $t->foreign('posting_id', 'cod_brp_ledger_posting_fk')
                ->references('id')->on('checkout_cod_bank_receipt_postings')->restrictOnDelete();
            $t->string('side', 6);
            $t->string('account_code', 32);
            $t->unsignedBigInteger('amount_minor');
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['posting_id', 'side'], 'cod_brp_ledger_one_per_side');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_receipt_postings
            ADD CONSTRAINT cod_brp_values CHECK (
                source_kind IN ('single', 'batch') AND source_id > 0
                AND account_alias ~ '^[a-z][a-z0-9_-]{0,47}$'
                AND amount_minor BETWEEN 1 AND 100000000
                AND posted_by > 0
                AND reference_hmac ~ '^[a-f0-9]{64}$'
                AND transaction_hmac ~ '^[a-f0-9]{64}$'
                AND row_hmac ~ '^[a-f0-9]{64}$'
                AND api_result_sha256 ~ '^[a-f0-9]{64}$'
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_receipt_ledger_lines
            ADD CONSTRAINT cod_brp_line_values CHECK (
                amount_minor BETWEEN 1 AND 100000000
                AND (
                    (side = 'debit' AND account_code = 'cod_bank_asset')
                    OR (side = 'credit' AND account_code = 'cod_carrier_clearing')
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_brp_posting_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_bank RECORD;
                v_draft RECORD;
                v_f RECORD;
                v_e RECORD;
                v_count BIGINT;
                v_sum BIGINT;
            BEGIN
                IF NEW.source_kind = 'single' THEN
                    SELECT recorded_by, reference_hmac, claimed_net_minor
                    INTO v_bank FROM checkout_cod_settlement_bank_receipts
                    WHERE id = NEW.source_id FOR UPDATE;
                ELSIF NEW.source_kind = 'batch' THEN
                    SELECT recorded_by, reference_hmac, claimed_net_minor
                    INTO v_bank FROM checkout_cod_bank_batches
                    WHERE id = NEW.source_id FOR UPDATE;
                ELSE
                    RAISE EXCEPTION 'Invalid COD posted receipt source';
                END IF;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'COD posted receipt source missing';
                END IF;
                PERFORM 1 FROM checkout_cod_bank_reference_claims
                WHERE reference_hmac = v_bank.reference_hmac FOR UPDATE;

                SELECT j.*, r.decision AS review_decision,
                       r.reviewed_by AS draft_reviewer,
                       c.id AS packet_id, c.compiled_by,
                       cr.reviewed_by AS coverage_reviewer,
                       cr.decision AS coverage_decision
                INTO v_draft
                FROM checkout_cod_journal_drafts AS j
                JOIN checkout_cod_journal_draft_reviews AS r ON r.draft_id = j.id
                JOIN checkout_cod_bank_coverage_packets AS c ON c.id = j.coverage_packet_id
                JOIN checkout_cod_bank_coverage_reviews AS cr ON cr.packet_id = c.id
                WHERE j.id = NEW.journal_draft_id;
                IF NOT FOUND OR v_draft.review_decision <> 'retain_unposted'
                    OR v_draft.coverage_decision <> 'retain_unverified'
                    OR v_draft.source_kind <> NEW.source_kind
                    OR v_draft.source_id <> NEW.source_id
                    OR v_draft.bank_minor <> NEW.amount_minor
                    OR v_draft.bank_minor <> v_bank.claimed_net_minor
                    OR v_draft.gross_minor <> v_draft.fee_minor + v_draft.bank_minor
                    OR NEW.posted_by IN (v_draft.prepared_by, v_draft.draft_reviewer,
                                        v_draft.compiled_by, v_draft.coverage_reviewer,
                                        v_bank.recorded_by)
                THEN
                    RAISE EXCEPTION 'Invalid independently reviewed COD bank journal';
                END IF;

                SELECT * INTO v_f FROM checkout_cod_privat_refetches
                WHERE id = NEW.refetch_id;
                SELECT e.*, i.imported_by INTO v_e
                FROM checkout_cod_privat_statement_entries AS e
                JOIN checkout_cod_privat_import_runs AS i ON i.id = e.first_run_id
                WHERE e.id = NEW.statement_entry_id;
                IF NOT FOUND OR v_f.id IS NULL
                    OR v_f.source_kind <> NEW.source_kind
                    OR v_f.source_id <> NEW.source_id
                    OR v_f.statement_entry_id <> NEW.statement_entry_id
                    OR v_f.result_code <> 'consistent_unverified'
                    OR v_f.id <> (SELECT MAX(id) FROM checkout_cod_privat_refetches
                        WHERE source_kind = NEW.source_kind AND source_id = NEW.source_id)
                    OR v_f.created_at < NOW() - INTERVAL '15 minutes'
                    OR v_f.created_at > NOW() + INTERVAL '1 minute'
                    OR v_f.reference_hmac <> NEW.reference_hmac
                    OR v_f.transaction_hmac <> NEW.transaction_hmac
                    OR v_f.row_hmac <> NEW.row_hmac
                    OR v_f.booked_on <> NEW.booked_on
                    OR v_f.amount_minor <> NEW.amount_minor
                    OR v_e.reference_hmac IS DISTINCT FROM NEW.reference_hmac
                    OR v_e.transaction_hmac <> NEW.transaction_hmac
                    OR v_e.row_hmac <> NEW.row_hmac
                    OR v_e.account_alias <> NEW.account_alias
                    OR v_e.direction <> 'credit'
                    OR v_e.amount_minor <> NEW.amount_minor
                    OR v_e.booked_on <> NEW.booked_on
                    OR NEW.reference_hmac <> v_bank.reference_hmac
                    OR NEW.posted_by IN (v_f.rechecked_by, v_e.imported_by)
                THEN
                    RAISE EXCEPTION 'Fresh imported bank credit provenance required';
                END IF;
                IF boardriders_cod_financial_has_hold(NEW.source_kind, NEW.source_id)
                    OR (NEW.source_kind = 'single' AND EXISTS (
                        SELECT 1 FROM checkout_cod_settlement_bank_matches AS m
                        WHERE m.bank_receipt_id = NEW.source_id
                          AND boardriders_cod_financial_has_hold('novapay', m.observation_id)
                    ))
                    OR (NEW.source_kind = 'batch' AND EXISTS (
                        SELECT 1 FROM checkout_cod_bank_batch_allocations AS a
                        WHERE a.batch_id = NEW.source_id
                          AND boardriders_cod_financial_has_hold('novapay', a.observation_id)
                    ))
                    OR boardriders_cod_refetch_negative(NEW.source_kind, NEW.source_id)
                    OR (SELECT COUNT(*) FROM checkout_cod_privat_statement_entries
                        WHERE reference_hmac = NEW.reference_hmac) <> 1
                    OR EXISTS (SELECT 1 FROM checkout_cod_bank_evidence_events
                        WHERE reference_hmac = NEW.reference_hmac
                        AND event_kind = 'reversal')
                    OR EXISTS (SELECT 1 FROM checkout_cod_gateway_reports
                        WHERE reference_hmac = NEW.reference_hmac
                        AND event_kind = 'reversal_reported')
                THEN
                    RAISE EXCEPTION 'Bank receipt disputed, reversed or ambiguous';
                END IF;
                SELECT COUNT(*), COALESCE(SUM(amount_minor), 0)
                INTO v_count, v_sum
                FROM checkout_cod_journal_draft_entries
                WHERE draft_id = v_draft.id AND entry_kind = 'bank_remittance';
                IF v_count <> v_draft.orders_count OR v_sum <> NEW.amount_minor
                    OR EXISTS (
                        SELECT 1 FROM checkout_cod_bank_coverage_lines AS l
                        JOIN orders AS o ON o.id = l.order_id
                        WHERE l.packet_id = v_draft.packet_id
                          AND o.payment_status <> 'pending'
                    ) OR (SELECT COUNT(*) FROM checkout_cod_bank_coverage_lines
                        WHERE packet_id = v_draft.packet_id) <> v_draft.orders_count
                THEN
                    RAISE EXCEPTION 'COD bank receipt distribution invalid';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_brp_line_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v_post RECORD;
            BEGIN
                SELECT * INTO v_post FROM checkout_cod_bank_receipt_postings
                WHERE id = NEW.posting_id FOR UPDATE;
                IF NOT FOUND OR NEW.amount_minor <> v_post.amount_minor THEN
                    RAISE EXCEPTION 'COD bank receipt ledger amount mismatch';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_brp_balance_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_debit BIGINT;
                v_credit BIGINT;
                v_count BIGINT;
                v_expected BIGINT;
            BEGIN
                SELECT amount_minor INTO v_expected
                FROM checkout_cod_bank_receipt_postings WHERE id = NEW.id;
                SELECT COUNT(*),
                    COALESCE(SUM(amount_minor) FILTER (WHERE side = 'debit'), 0),
                    COALESCE(SUM(amount_minor) FILTER (WHERE side = 'credit'), 0)
                INTO v_count, v_debit, v_credit
                FROM checkout_cod_bank_receipt_ledger_lines WHERE posting_id = NEW.id;
                IF v_count <> 2 OR v_debit <> v_expected OR v_credit <> v_expected THEN
                    RAISE EXCEPTION 'COD bank receipt ledger must balance at commit';
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_brp_posting_guard
            BEFORE INSERT ON checkout_cod_bank_receipt_postings
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_brp_posting_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_brp_line_guard
            BEFORE INSERT ON checkout_cod_bank_receipt_ledger_lines
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_brp_line_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER cod_brp_balance_at_commit
            AFTER INSERT ON checkout_cod_bank_receipt_postings
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_brp_balance_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_brp_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Posted bank receipt ledger history is immutable';
            END;
            $$
        SQL);
        foreach (['checkout_cod_bank_receipt_postings', 'checkout_cod_bank_receipt_ledger_lines'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_brp_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_brp_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_bank_receipt_ledger_lines');
        Schema::dropIfExists('checkout_cod_bank_receipt_postings');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_brp_posting_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_brp_line_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_brp_balance_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_brp_immutable()');
    }
};
