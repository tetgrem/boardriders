<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One identity namespace across the single-receipt path (#24) and batches (#25).
        Schema::create('checkout_cod_bank_reference_claims', function (Blueprint $table) {
            $table->id();
            $table->char('reference_hmac', 64)->unique();
            $table->string('source_kind', 12); // single | batch
            $table->unsignedBigInteger('source_id');
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['source_kind', 'source_id'], 'cod_bank_reference_owner_uniq');
        });

        Schema::create('checkout_cod_bank_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->char('reference_hmac', 64)->unique();
            $table->unsignedBigInteger('claimed_net_minor');
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('recorded_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_bank_batch_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('batch_id')
                ->constrained('checkout_cod_bank_batches')->restrictOnDelete();
            $table->foreignId('case_id')
                ->constrained('checkout_cod_settlement_cases')->restrictOnDelete();
            $table->foreignId('observation_id')
                ->constrained('checkout_cod_settlement_observations')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('allocated_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['batch_id', 'observation_id'], 'cod_batch_observation_once');
            $table->index(['case_id', 'id'], 'cod_batch_allocation_case_history');
        });

        Schema::create('checkout_cod_bank_batch_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('batch_id')->unique()
                ->constrained('checkout_cod_bank_batches')->restrictOnDelete();
            $table->string('decision', 48);
            $table->string('reason_code', 48);
            $table->unsignedInteger('allocation_count');
            $table->unsignedBigInteger('allocated_minor');
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_reference_claims
            ADD CONSTRAINT cod_bank_reference_values CHECK (
                reference_hmac ~ '^[a-f0-9]{64}$'
                AND source_kind IN ('single', 'batch')
                AND source_id > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_batches
            ADD CONSTRAINT cod_bank_batch_values CHECK (
                reference_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND currency_code = 'UAH'
                AND claimed_net_minor BETWEEN 1 AND 100000000
                AND recorded_by > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_batch_allocations
            ADD CONSTRAINT cod_bank_allocation_values CHECK (
                amount_minor BETWEEN 1 AND 100000000
                AND allocated_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_bank_batch_reviews
            ADD CONSTRAINT cod_bank_batch_review_values CHECK (
                allocation_count > 0
                AND allocated_minor BETWEEN 1 AND 100000000
                AND reviewed_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (
                        decision = 'allocation_balanced_unverified'
                        AND reason_code = 'external_confirmation_required'
                    )
                    OR (
                        decision = 'allocation_partial_unverified'
                        AND reason_code = 'unallocated_balance'
                    )
                )
            )
        SQL);

        // Backfill references from Block 24.
        DB::statement(<<<'SQL'
            INSERT INTO checkout_cod_bank_reference_claims
                (reference_hmac, source_kind, source_id, created_at)
            SELECT reference_hmac, 'single', id, created_at
            FROM checkout_cod_settlement_bank_receipts
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_register_reference()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO checkout_cod_bank_reference_claims
                    (reference_hmac, source_kind, source_id, created_at)
                VALUES (
                    NEW.reference_hmac,
                    CASE
                        WHEN TG_TABLE_NAME = 'checkout_cod_bank_batches'
                            THEN 'batch'
                        ELSE 'single'
                    END,
                    NEW.id,
                    NEW.created_at
                );
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_bank_single_reference
            AFTER INSERT ON checkout_cod_settlement_bank_receipts
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_bank_register_reference()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_bank_batch_reference
            AFTER INSERT ON checkout_cod_bank_batches
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_bank_register_reference()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_batch_allocation_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                batch_row RECORD;
                source_row RECORD;
                batch_used BIGINT;
                observation_used BIGINT;
            BEGIN
                SELECT id, claimed_net_minor, recorded_by
                INTO batch_row
                FROM checkout_cod_bank_batches
                WHERE id = NEW.batch_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Bank batch not found';
                END IF;

                SELECT c.id AS case_id,
                       o.claimed_net_minor,
                       o.source_kind,
                       o.comparison_result,
                       o.recorded_by,
                       r.decision AS source_review
                INTO source_row
                FROM checkout_cod_settlement_cases c
                JOIN checkout_cod_settlement_observations o
                    ON o.case_id = c.id
                LEFT JOIN checkout_cod_settlement_reviews r
                    ON r.observation_id = o.id
                WHERE c.id = NEW.case_id
                    AND o.id = NEW.observation_id
                FOR UPDATE OF c;

                IF NOT FOUND
                    OR source_row.source_kind IS DISTINCT FROM 'novapay_statement'
                    OR source_row.comparison_result IS DISTINCT FROM 'consistent_with_estimate'
                    OR source_row.source_review IS DISTINCT FROM 'retain_unverified'
                    OR source_row.recorded_by = NEW.allocated_by
                    OR batch_row.recorded_by = NEW.allocated_by
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_settlement_bank_matches
                        WHERE observation_id = NEW.observation_id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_bank_batch_reviews
                        WHERE batch_id = NEW.batch_id
                    )
                THEN
                    RAISE EXCEPTION
                        'Reviewed, independent, unclaimed NovaPay source required';
                END IF;

                SELECT COALESCE(SUM(amount_minor), 0)
                INTO batch_used
                FROM checkout_cod_bank_batch_allocations
                WHERE batch_id = NEW.batch_id;

                SELECT COALESCE(SUM(amount_minor), 0)
                INTO observation_used
                FROM checkout_cod_bank_batch_allocations
                WHERE observation_id = NEW.observation_id;

                IF batch_used + NEW.amount_minor > batch_row.claimed_net_minor
                    OR observation_used + NEW.amount_minor > source_row.claimed_net_minor
                THEN
                    RAISE EXCEPTION
                        'COD batch allocation exceeds claimed amount';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_bank_batch_allocation_guard
            BEFORE INSERT ON checkout_cod_bank_batch_allocations
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_bank_batch_allocation_guard()
        SQL);

        // A NovaPay claim allocated to a batch cannot also use the Block 24 route.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_single_match_exclusion()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1
                FROM checkout_cod_settlement_cases
                WHERE id = NEW.case_id
                FOR UPDATE;

                IF EXISTS (
                    SELECT 1
                    FROM checkout_cod_bank_batch_allocations
                    WHERE observation_id = NEW.observation_id
                ) THEN
                    RAISE EXCEPTION
                        'NovaPay claim is already batch-allocated';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER zz_cod_bank_single_match_exclusion
            BEFORE INSERT ON checkout_cod_settlement_bank_matches
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_bank_single_match_exclusion()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_batch_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                batch_row RECORD;
                allocation_total BIGINT;
                allocation_count INTEGER;
            BEGIN
                SELECT id, claimed_net_minor, recorded_by
                INTO batch_row
                FROM checkout_cod_bank_batches
                WHERE id = NEW.batch_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Bank batch not found';
                END IF;

                SELECT COALESCE(SUM(amount_minor), 0), COUNT(*)
                INTO allocation_total, allocation_count
                FROM checkout_cod_bank_batch_allocations
                WHERE batch_id = NEW.batch_id;

                IF allocation_count = 0
                    OR allocation_total IS DISTINCT FROM NEW.allocated_minor
                    OR allocation_count IS DISTINCT FROM NEW.allocation_count
                    OR batch_row.recorded_by = NEW.reviewed_by
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_bank_batch_allocations a
                        JOIN checkout_cod_settlement_observations o
                            ON o.id = a.observation_id
                        WHERE a.batch_id = NEW.batch_id
                          AND (
                            a.allocated_by = NEW.reviewed_by
                            OR o.recorded_by = NEW.reviewed_by
                          )
                    )
                THEN
                    RAISE EXCEPTION
                        'Invalid or non-independent bank batch review';
                END IF;

                IF allocation_total = batch_row.claimed_net_minor THEN
                    IF NEW.decision <> 'allocation_balanced_unverified'
                        OR NEW.reason_code <> 'external_confirmation_required'
                    THEN
                        RAISE EXCEPTION
                            'Incorrect balanced batch decision';
                    END IF;
                ELSE
                    IF NEW.decision <> 'allocation_partial_unverified'
                        OR NEW.reason_code <> 'unallocated_balance'
                    THEN
                        RAISE EXCEPTION
                            'Incorrect partial batch decision';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_bank_batch_review_guard
            BEFORE INSERT ON checkout_cod_bank_batch_reviews
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_bank_batch_review_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_batch_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD batch allocation records are immutable';
            END;
            $$
        SQL);

        foreach ([
                     'checkout_cod_bank_reference_claims',
                     'checkout_cod_bank_batches',
                     'checkout_cod_bank_batch_allocations',
                     'checkout_cod_bank_batch_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_bank_batch_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_bank_batch_immutable()
            ");
        }
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS zz_cod_bank_single_match_exclusion
             ON checkout_cod_settlement_bank_matches'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS cod_bank_single_reference
             ON checkout_cod_settlement_bank_receipts'
        );

        Schema::dropIfExists('checkout_cod_bank_batch_reviews');
        Schema::dropIfExists('checkout_cod_bank_batch_allocations');
        Schema::dropIfExists('checkout_cod_bank_batches');
        Schema::dropIfExists('checkout_cod_bank_reference_claims');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_bank_register_reference()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_bank_batch_allocation_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_bank_single_match_exclusion()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_bank_batch_review_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_bank_batch_immutable()'
        );
    }
};
