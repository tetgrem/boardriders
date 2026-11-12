<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_privat_refetches', function (Blueprint $t): void {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->string('source_kind', 12);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('statement_entry_id');
            $t->foreign('statement_entry_id', 'cod_pr_ref_entry_fk')
                ->references('id')->on('checkout_cod_privat_statement_entries')
                ->restrictOnDelete();
            $t->char('reference_hmac', 64);
            $t->char('transaction_hmac', 64);
            $t->char('row_hmac', 64);
            $t->date('booked_on');
            $t->unsignedBigInteger('amount_minor');
            $t->string('result_code', 32);
            $t->unsignedInteger('reference_rows');
            $t->unsignedInteger('identity_rows');
            $t->unsignedInteger('exact_rows');
            $t->char('api_result_sha256', 64);
            $t->unsignedBigInteger('rechecked_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['source_kind', 'source_id', 'id'], 'cod_pr_ref_source_history');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_privat_refetches
            ADD CONSTRAINT cod_pr_ref_values CHECK (
                source_kind IN ('single', 'batch') AND source_id > 0
                AND reference_hmac ~ '^[a-f0-9]{64}$'
                AND transaction_hmac ~ '^[a-f0-9]{64}$'
                AND row_hmac ~ '^[a-f0-9]{64}$'
                AND api_result_sha256 ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND amount_minor BETWEEN 1 AND 100000000
                AND rechecked_by > 0
                AND reference_rows BETWEEN 0 AND 2000
                AND identity_rows BETWEEN 0 AND reference_rows
                AND exact_rows BETWEEN 0 AND identity_rows
                AND result_code IN (
                    'reference_not_found', 'ambiguous_reference',
                    'transaction_changed', 'consistent_unverified'
                )
                AND (result_code <> 'reference_not_found' OR reference_rows = 0)
                AND (result_code <> 'ambiguous_reference' OR reference_rows > 1)
                AND (result_code <> 'transaction_changed' OR
                    (reference_rows = 1 AND exact_rows = 0))
                AND (result_code <> 'consistent_unverified' OR
                    (reference_rows = 1 AND identity_rows = 1 AND exact_rows = 1))
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_pr_ref_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_source RECORD;
                v_entry RECORD;
            BEGIN
                IF NEW.source_kind = 'single' THEN
                    SELECT recorded_by, reference_hmac, claimed_net_minor
                    INTO v_source FROM checkout_cod_settlement_bank_receipts
                    WHERE id = NEW.source_id;
                ELSIF NEW.source_kind = 'batch' THEN
                    SELECT recorded_by, reference_hmac, claimed_net_minor
                    INTO v_source FROM checkout_cod_bank_batches
                    WHERE id = NEW.source_id;
                ELSE
                    RAISE EXCEPTION 'Invalid refetch source kind';
                END IF;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Refetch source not found';
                END IF;
                PERFORM 1 FROM checkout_cod_bank_reference_claims
                    WHERE reference_hmac = v_source.reference_hmac FOR UPDATE;
                SELECT e.reference_hmac, e.transaction_hmac, e.row_hmac,
                       e.booked_on, e.amount_minor, e.direction,
                       r.imported_by INTO v_entry
                FROM checkout_cod_privat_statement_entries AS e
                JOIN checkout_cod_privat_import_runs AS r ON r.id = e.first_run_id
                WHERE e.id = NEW.statement_entry_id;
                IF NOT FOUND
                    OR NEW.reference_hmac IS DISTINCT FROM v_source.reference_hmac
                    OR NEW.reference_hmac IS DISTINCT FROM v_entry.reference_hmac
                    OR NEW.transaction_hmac <> v_entry.transaction_hmac
                    OR NEW.row_hmac <> v_entry.row_hmac
                    OR NEW.booked_on <> v_entry.booked_on
                    OR NEW.amount_minor <> v_entry.amount_minor
                    OR NEW.amount_minor <> v_source.claimed_net_minor
                    OR v_entry.direction <> 'credit'
                    OR NEW.rechecked_by IN (v_source.recorded_by, v_entry.imported_by)
                    OR (SELECT COUNT(*) FROM checkout_cod_privat_statement_entries
                        WHERE reference_hmac = NEW.reference_hmac) <> 1
                THEN
                    RAISE EXCEPTION 'Refetch source or independent operator invalid';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_pr_ref_guard
            BEFORE INSERT ON checkout_cod_privat_refetches
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_pr_ref_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_pr_ref_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Bank refetch history is immutable';
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_pr_ref_immutable
            BEFORE UPDATE OR DELETE ON checkout_cod_privat_refetches
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_pr_ref_immutable()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_pr_ref_no_truncate
            BEFORE TRUNCATE ON checkout_cod_privat_refetches
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_pr_ref_immutable()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_privat_refetches');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_pr_ref_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_pr_ref_immutable()');
    }
};
