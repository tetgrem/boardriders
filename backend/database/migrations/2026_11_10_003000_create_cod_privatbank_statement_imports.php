<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_privat_import_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->string('account_alias', 48);
            $t->date('start_date');
            $t->date('end_date');
            $t->unsignedInteger('page_count');
            $t->unsignedInteger('received_count');
            $t->unsignedInteger('posted_count');
            $t->unsignedInteger('skipped_count');
            $t->unsignedBigInteger('imported_by');
            $t->char('request_hmac', 64);
            $t->char('result_sha256', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(
                ['account_alias', 'end_date', 'id'],
                'cod_privat_import_history'
            );
        });

        Schema::create('checkout_cod_privat_statement_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('first_run_id')
                ->constrained('checkout_cod_privat_import_runs')
                ->restrictOnDelete();
            $t->string('account_alias', 48);
            $t->char('transaction_hmac', 64);
            $t->char('reference_hmac', 64)->nullable();
            $t->string('direction', 6);
            $t->unsignedBigInteger('amount_minor');
            $t->date('booked_on');
            $t->char('row_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(
                ['account_alias', 'transaction_hmac'],
                'cod_privat_transaction_once'
            );
            $t->index(
                ['reference_hmac', 'id'],
                'cod_privat_reference_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_privat_import_runs
            ADD CONSTRAINT cod_privat_run_values CHECK (
                account_alias ~ '^[a-z][a-z0-9_-]{0,47}$'
                AND start_date <= end_date
                AND page_count BETWEEN 1 AND 20
                AND received_count <= 2000
                AND posted_count <= received_count
                AND skipped_count = received_count - posted_count
                AND imported_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND result_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_privat_statement_entries
            ADD CONSTRAINT cod_privat_entry_values CHECK (
                account_alias ~ '^[a-z][a-z0-9_-]{0,47}$'
                AND transaction_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    reference_hmac IS NULL
                    OR reference_hmac ~ '^[a-f0-9]{64}$'
                )
                AND direction IN ('credit', 'debit')
                AND amount_minor BETWEEN 1 AND 100000000
                AND row_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_privat_entry_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD;
            BEGIN
                SELECT account_alias, start_date, end_date INTO v
                FROM checkout_cod_privat_import_runs
                WHERE id = NEW.first_run_id;

                IF NOT FOUND
                    OR v.account_alias IS DISTINCT FROM NEW.account_alias
                    OR NEW.booked_on < v.start_date
                    OR NEW.booked_on > v.end_date
                THEN
                    RAISE EXCEPTION
                        'PrivatBank entry does not belong to import period';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_privat_entry_guard
            BEFORE INSERT ON checkout_cod_privat_statement_entries
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_privat_entry_guard()
        SQL);

        // Protect existing COD reconciliation paths against contradictory
        // posted bank lines. Matching references are serialized by DB lock.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_privat_match_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_ref CHAR(64);
                v_amount BIGINT;
                v_credits BIGINT;
                v_debits BIGINT;
                v_mismatches BIGINT;
            BEGIN
                IF TG_TABLE_NAME =
                    'checkout_cod_settlement_bank_matches'
                THEN
                    SELECT reference_hmac, claimed_net_minor
                    INTO v_ref, v_amount
                    FROM checkout_cod_settlement_bank_receipts
                    WHERE id = NEW.bank_receipt_id;

                ELSIF TG_TABLE_NAME =
                    'checkout_cod_settlement_bank_reviews'
                THEN
                    SELECT b.reference_hmac, b.claimed_net_minor
                    INTO v_ref, v_amount
                    FROM checkout_cod_settlement_bank_matches m
                    JOIN checkout_cod_settlement_bank_receipts b
                        ON b.id = m.bank_receipt_id
                    WHERE m.id = NEW.match_id;

                ELSIF TG_TABLE_NAME IN (
                    'checkout_cod_bank_batch_allocations',
                    'checkout_cod_bank_batch_reviews'
                ) THEN
                    SELECT reference_hmac, claimed_net_minor
                    INTO v_ref, v_amount
                    FROM checkout_cod_bank_batches
                    WHERE id = NEW.batch_id;
                END IF;

                IF v_ref IS NOT NULL THEN
                    PERFORM 1
                    FROM checkout_cod_bank_reference_claims
                    WHERE reference_hmac = v_ref
                    FOR UPDATE;

                    SELECT
                        COUNT(*) FILTER (
                            WHERE direction = 'credit'
                        ),
                        COUNT(*) FILTER (
                            WHERE direction = 'debit'
                        ),
                        COUNT(*) FILTER (
                            WHERE direction = 'credit'
                              AND amount_minor <> v_amount
                        )
                    INTO v_credits, v_debits, v_mismatches
                    FROM checkout_cod_privat_statement_entries
                    WHERE reference_hmac = v_ref;

                    IF v_debits > 0
                        OR v_mismatches > 0
                        OR v_credits > 1
                    THEN
                        RAISE EXCEPTION
                            'PrivatBank statement requires reconciliation investigation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_settlement_bank_matches',
                     'checkout_cod_settlement_bank_reviews',
                     'checkout_cod_bank_batch_allocations',
                     'checkout_cod_bank_batch_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER zzzzz_cod_privat_match_guard
                BEFORE INSERT ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_privat_match_guard()
            ");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_privat_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD PrivatBank statement audit is immutable';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_privat_import_runs',
                     'checkout_cod_privat_statement_entries',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_privat_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_privat_immutable()
            ");
        }
    }

    public function down(): void
    {
        foreach ([
                     'checkout_cod_settlement_bank_matches',
                     'checkout_cod_settlement_bank_reviews',
                     'checkout_cod_bank_batch_allocations',
                     'checkout_cod_bank_batch_reviews',
                 ] as $table) {
            DB::statement("
                DROP TRIGGER IF EXISTS zzzzz_cod_privat_match_guard
                ON {$table}
            ");
        }

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_privat_match_guard()'
        );

        Schema::dropIfExists('checkout_cod_privat_statement_entries');
        Schema::dropIfExists('checkout_cod_privat_import_runs');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_privat_entry_guard()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_privat_immutable()'
        );
    }
};
