<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_financial_exceptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('exception_key')->unique();
            $table->string('source_kind', 12);
            $table->unsignedBigInteger('source_id');
            $table->string('reason_code', 48);
            $table->unsignedBigInteger('reported_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(
                ['source_kind', 'source_id', 'id'],
                'cod_exception_source_history'
            );
        });

        Schema::create('checkout_cod_financial_exception_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('review_key')->unique();
            $table->foreignId('exception_id')->unique()
                ->constrained('checkout_cod_financial_exceptions')
                ->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason_code', 48);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_financial_exceptions
            ADD CONSTRAINT cod_financial_exception_values CHECK (
                source_kind IN ('single', 'batch', 'novapay')
                AND source_id > 0 AND reported_by > 0
                AND reason_code IN (
                    'incorrect_amount',
                    'bank_reversal_reported',
                    'order_cancellation_reported',
                    'customer_return_reported',
                    'wrong_source_reference'
                )
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_financial_exception_reviews
            ADD CONSTRAINT cod_financial_exception_review_values CHECK (
                reviewed_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (
                        decision = 'hold_for_investigation'
                        AND reason_code = 'await_external_evidence'
                    )
                    OR (
                        decision = 'dismiss_report'
                        AND reason_code = 'report_not_supported'
                    )
                )
            )
        SQL);

        // Only the latest exception determines the current hold.
        // VOLATILE uses a fresh snapshot after source-row locking.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_financial_has_hold(
                p_kind TEXT, p_source_id BIGINT
            ) RETURNS BOOLEAN LANGUAGE sql VOLATILE AS $$
                SELECT COALESCE((
                    SELECT r.decision IS DISTINCT FROM 'dismiss_report'
                    FROM checkout_cod_financial_exceptions e
                    LEFT JOIN checkout_cod_financial_exception_reviews r
                        ON r.exception_id = e.id
                    WHERE e.source_kind = p_kind
                      AND e.source_id = p_source_id
                    ORDER BY e.id DESC
                    LIMIT 1
                ), FALSE)
            $$
        SQL);

        // Lock the same records used by Blocks 24 and 25.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_financial_lock_source(
                p_kind TEXT, p_source_id BIGINT
            ) RETURNS BIGINT LANGUAGE plpgsql VOLATILE AS $$
            DECLARE
                v_author BIGINT;
            BEGIN
                IF p_kind = 'single' THEN
                    SELECT b.recorded_by INTO v_author
                    FROM checkout_cod_settlement_bank_receipts b
                    JOIN checkout_cod_settlement_cases c
                        ON c.id = b.case_id
                    WHERE b.id = p_source_id
                    FOR UPDATE OF c;

                ELSIF p_kind = 'novapay' THEN
                    SELECT o.recorded_by INTO v_author
                    FROM checkout_cod_settlement_observations o
                    JOIN checkout_cod_settlement_cases c
                        ON c.id = o.case_id
                    WHERE o.id = p_source_id
                    FOR UPDATE OF c;

                ELSIF p_kind = 'batch' THEN
                    SELECT recorded_by INTO v_author
                    FROM checkout_cod_bank_batches
                    WHERE id = p_source_id
                    FOR UPDATE;

                ELSE
                    RAISE EXCEPTION
                        'Invalid COD financial exception source';
                END IF;

                IF NOT FOUND THEN
                    RAISE EXCEPTION
                        'COD financial exception source not found';
                END IF;

                RETURN v_author;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_financial_exception_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_previous_id BIGINT;
            BEGIN
                PERFORM boardriders_cod_financial_lock_source(
                    NEW.source_kind, NEW.source_id
                );

                SELECT id INTO v_previous_id
                FROM checkout_cod_financial_exceptions
                WHERE source_kind = NEW.source_kind
                  AND source_id = NEW.source_id
                ORDER BY id DESC
                LIMIT 1;

                IF v_previous_id IS NOT NULL
                    AND boardriders_cod_financial_has_hold(
                        NEW.source_kind, NEW.source_id
                    )
                THEN
                    RAISE EXCEPTION
                        'Active financial exception already exists';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_financial_exception_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                src RECORD;
                v_author BIGINT;
            BEGIN
                SELECT source_kind, source_id, reported_by
                INTO src
                FROM checkout_cod_financial_exceptions
                WHERE id = NEW.exception_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION
                        'COD financial exception not found';
                END IF;

                v_author := boardriders_cod_financial_lock_source(
                    src.source_kind, src.source_id
                );

                IF src.reported_by = NEW.reviewed_by
                    OR v_author = NEW.reviewed_by
                    OR (
                        src.source_kind = 'batch'
                        AND EXISTS (
                            SELECT 1
                            FROM checkout_cod_bank_batch_allocations a
                            WHERE a.batch_id = src.source_id
                              AND a.allocated_by = NEW.reviewed_by
                        )
                    )
                    OR (
                        src.source_kind = 'single'
                        AND EXISTS (
                            SELECT 1
                            FROM checkout_cod_settlement_bank_matches m
                            WHERE m.bank_receipt_id = src.source_id
                              AND m.proposed_by = NEW.reviewed_by
                        )
                    )
                THEN
                    RAISE EXCEPTION
                        'COD financial exception review must be independent';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        // Protect all existing paths while a source is disputed.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_financial_usage_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_case BIGINT;
                v_receipt BIGINT;
                v_observation BIGINT;
                v_batch BIGINT;
            BEGIN
                IF TG_TABLE_NAME = 'checkout_cod_settlement_bank_matches' THEN
                    v_case := NEW.case_id;
                    v_receipt := NEW.bank_receipt_id;
                    v_observation := NEW.observation_id;

                    PERFORM 1
                    FROM checkout_cod_settlement_cases
                    WHERE id = v_case
                    FOR UPDATE;

                ELSIF TG_TABLE_NAME = 'checkout_cod_settlement_bank_reviews' THEN
                    SELECT case_id, bank_receipt_id, observation_id
                    INTO v_case, v_receipt, v_observation
                    FROM checkout_cod_settlement_bank_matches
                    WHERE id = NEW.match_id;

                    PERFORM 1
                    FROM checkout_cod_settlement_cases
                    WHERE id = v_case
                    FOR UPDATE;

                ELSIF TG_TABLE_NAME = 'checkout_cod_settlement_reviews' THEN
                    v_observation := NEW.observation_id;

                    SELECT case_id INTO v_case
                    FROM checkout_cod_settlement_observations
                    WHERE id = v_observation;

                    PERFORM 1
                    FROM checkout_cod_settlement_cases
                    WHERE id = v_case
                    FOR UPDATE;

                ELSIF TG_TABLE_NAME = 'checkout_cod_bank_batch_allocations' THEN
                    v_batch := NEW.batch_id;
                    v_observation := NEW.observation_id;

                    PERFORM 1
                    FROM checkout_cod_bank_batches
                    WHERE id = v_batch
                    FOR UPDATE;

                    PERFORM 1
                    FROM checkout_cod_settlement_cases
                    WHERE id = NEW.case_id
                    FOR UPDATE;

                ELSIF TG_TABLE_NAME = 'checkout_cod_bank_batch_reviews' THEN
                    v_batch := NEW.batch_id;

                    PERFORM 1
                    FROM checkout_cod_bank_batches
                    WHERE id = v_batch
                    FOR UPDATE;

                    IF EXISTS (
                        SELECT 1
                        FROM checkout_cod_bank_batch_allocations a
                        WHERE a.batch_id = v_batch
                          AND boardriders_cod_financial_has_hold(
                              'novapay', a.observation_id
                          )
                    ) THEN
                        RAISE EXCEPTION
                            'COD bank batch has a disputed NovaPay claim';
                    END IF;
                END IF;

                IF (
                    v_receipt IS NOT NULL
                    AND boardriders_cod_financial_has_hold(
                        'single', v_receipt
                    )
                ) OR (
                    v_observation IS NOT NULL
                    AND boardriders_cod_financial_has_hold(
                        'novapay', v_observation
                    )
                ) OR (
                    v_batch IS NOT NULL
                    AND boardriders_cod_financial_has_hold(
                        'batch', v_batch
                    )
                ) THEN
                    RAISE EXCEPTION
                        'COD financial exception hold blocks operation';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_financial_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD financial exception history is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_fin_exception_guard
            BEFORE INSERT ON checkout_cod_financial_exceptions
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_financial_exception_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_fin_exception_review_guard
            BEFORE INSERT ON checkout_cod_financial_exception_reviews
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_financial_exception_review_guard()
        SQL);

        foreach ([
                     'checkout_cod_settlement_bank_matches',
                     'checkout_cod_settlement_bank_reviews',
                     'checkout_cod_settlement_reviews',
                     'checkout_cod_bank_batch_allocations',
                     'checkout_cod_bank_batch_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER zz_cod_financial_usage_guard
                BEFORE INSERT ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_financial_usage_guard()
            ");
        }

        foreach ([
                     'checkout_cod_financial_exceptions',
                     'checkout_cod_financial_exception_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_financial_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_financial_immutable()
            ");
        }
    }

    public function down(): void
    {
        foreach ([
                     'checkout_cod_settlement_bank_matches',
                     'checkout_cod_settlement_bank_reviews',
                     'checkout_cod_settlement_reviews',
                     'checkout_cod_bank_batch_allocations',
                     'checkout_cod_bank_batch_reviews',
                 ] as $table) {
            DB::statement("
                DROP TRIGGER IF EXISTS zz_cod_financial_usage_guard
                ON {$table}
            ");
        }

        Schema::dropIfExists('checkout_cod_financial_exception_reviews');
        Schema::dropIfExists('checkout_cod_financial_exceptions');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_financial_usage_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_financial_exception_review_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_financial_exception_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_financial_lock_source(TEXT, BIGINT)'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_financial_has_hold(TEXT, BIGINT)'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_financial_immutable()'
        );
    }
};
