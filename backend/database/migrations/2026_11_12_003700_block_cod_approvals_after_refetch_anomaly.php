<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const TABLES = [
        'checkout_cod_statement_reconciliation_reviews',
        'checkout_cod_posting_preflights',
        'checkout_cod_posting_preflight_reviews',
        'checkout_cod_bank_coverage_packets',
        'checkout_cod_bank_coverage_reviews',
        'checkout_cod_journal_drafts',
        'checkout_cod_journal_draft_reviews',
    ];

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_refetch_negative(
                p_kind TEXT, p_source_id BIGINT
            ) RETURNS BOOLEAN LANGUAGE sql VOLATILE AS $$
                SELECT EXISTS (
                    SELECT 1
                    FROM checkout_cod_privat_refetches AS f
                    WHERE f.source_kind = p_kind
                      AND f.source_id = p_source_id
                      AND f.result_code <> 'consistent_unverified'
                )
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_refetch_risk_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_kind TEXT;
                v_source_id BIGINT;
                v_ref CHAR(64);
            BEGIN
                IF TG_TABLE_NAME = 'checkout_cod_statement_reconciliation_reviews' THEN
                    IF NEW.decision <> 'acknowledge_comparison' THEN
                        RETURN NEW;
                    END IF;
                    SELECT s.source_kind, s.source_id INTO v_kind, v_source_id
                    FROM checkout_cod_statement_reconciliations AS s
                    WHERE s.id = NEW.reconciliation_id;

                ELSIF TG_TABLE_NAME = 'checkout_cod_posting_preflights' THEN
                    v_kind := NEW.source_kind;
                    v_source_id := NEW.source_id;

                ELSIF TG_TABLE_NAME = 'checkout_cod_posting_preflight_reviews' THEN
                    IF NEW.decision <> 'retain_unverified' THEN
                        RETURN NEW;
                    END IF;
                    SELECT f.source_kind, f.source_id INTO v_kind, v_source_id
                    FROM checkout_cod_posting_preflights AS f
                    WHERE f.id = NEW.preflight_id;

                ELSIF TG_TABLE_NAME = 'checkout_cod_bank_coverage_packets' THEN
                    v_kind := NEW.source_kind;
                    v_source_id := NEW.source_id;

                ELSIF TG_TABLE_NAME = 'checkout_cod_bank_coverage_reviews' THEN
                    IF NEW.decision <> 'retain_unverified' THEN
                        RETURN NEW;
                    END IF;
                    SELECT p.source_kind, p.source_id INTO v_kind, v_source_id
                    FROM checkout_cod_bank_coverage_packets AS p
                    WHERE p.id = NEW.packet_id;

                ELSIF TG_TABLE_NAME = 'checkout_cod_journal_drafts' THEN
                    v_kind := NEW.source_kind;
                    v_source_id := NEW.source_id;

                ELSIF TG_TABLE_NAME = 'checkout_cod_journal_draft_reviews' THEN
                    IF NEW.decision <> 'retain_unposted' THEN
                        RETURN NEW;
                    END IF;
                    SELECT d.source_kind, d.source_id INTO v_kind, v_source_id
                    FROM checkout_cod_journal_drafts AS d
                    WHERE d.id = NEW.draft_id;

                ELSE
                    RAISE EXCEPTION 'Unexpected COD refetch guard target';
                END IF;

                IF v_kind IS NULL OR v_source_id IS NULL THEN
                    RAISE EXCEPTION 'Missing COD financial source';
                END IF;

                IF v_kind = 'single' THEN
                    SELECT b.reference_hmac INTO v_ref
                    FROM checkout_cod_settlement_bank_receipts AS b
                    WHERE b.id = v_source_id;
                ELSIF v_kind = 'batch' THEN
                    SELECT b.reference_hmac INTO v_ref
                    FROM checkout_cod_bank_batches AS b
                    WHERE b.id = v_source_id;
                ELSE
                    RAISE EXCEPTION 'Unsupported COD refetch source kind';
                END IF;

                IF v_ref IS NULL THEN
                    RAISE EXCEPTION 'COD bank reference missing';
                END IF;

                -- Same locking point as the refetch writer: no race between
                -- a negative API observation and a positive financial decision.
                PERFORM 1 FROM checkout_cod_bank_reference_claims AS c
                WHERE c.reference_hmac = v_ref FOR UPDATE;

                IF boardriders_cod_refetch_negative(v_kind, v_source_id) THEN
                    RAISE EXCEPTION
                        'COD bank refetch anomaly blocks positive financial decisions';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        foreach (self::TABLES as $table) {
            DB::statement("
                CREATE TRIGGER zz_cod_refetch_risk_guard
                BEFORE INSERT ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_refetch_risk_guard()
            ");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("
                DROP TRIGGER IF EXISTS zz_cod_refetch_risk_guard ON {$table}
            ");
        }

        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_refetch_risk_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_refetch_negative(TEXT, BIGINT)');
    }
};
