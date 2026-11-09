<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_settlement_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('case_key')->unique();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('preflight_id')->unique()
                ->constrained('checkout_cod_financial_preflights')
                ->restrictOnDelete();
            $table->unsignedBigInteger('expected_cod_minor');
            $table->unsignedBigInteger('expected_fee_minor')->nullable();
            $table->string('currency_code', 3);
            $table->char('financial_snapshot_sha256', 64);
            $table->unsignedBigInteger('opened_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_settlement_observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('case_id')
                ->constrained('checkout_cod_settlement_cases')
                ->restrictOnDelete();
            $table->char('external_reference_hmac', 64);
            $table->string('source_kind', 24);
            $table->unsignedBigInteger('claimed_gross_minor');
            $table->unsignedBigInteger('claimed_fee_minor');
            $table->unsignedBigInteger('claimed_net_minor');
            $table->string('currency_code', 3);
            $table->string('comparison_result', 40);
            $table->unsignedBigInteger('recorded_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->unique(
                ['case_id', 'external_reference_hmac'],
                'cod_settlement_unique_claim_reference'
            );
            $table->index(
                ['case_id', 'id'],
                'cod_settlement_observation_history'
            );
        });

        Schema::create('checkout_cod_settlement_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('observation_id')->unique()
                ->constrained('checkout_cod_settlement_observations')
                ->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason_code', 48);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_settlement_cases
            ADD CONSTRAINT cod_settlement_case_values CHECK (
                expected_cod_minor BETWEEN 100 AND 100000000
                AND (
                    expected_fee_minor IS NULL
                    OR expected_fee_minor BETWEEN 0 AND 100000000
                )
                AND currency_code = 'UAH'
                AND opened_by > 0
                AND financial_snapshot_sha256 ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_settlement_observations
            ADD CONSTRAINT cod_settlement_observation_values CHECK (
                source_kind IN (
                    'novapay_statement',
                    'bank_statement',
                    'manual_claim'
                )
                AND currency_code = 'UAH'
                AND claimed_gross_minor BETWEEN 0 AND 100000000
                AND claimed_fee_minor BETWEEN 0 AND 100000000
                AND claimed_net_minor BETWEEN 0 AND 100000000
                AND comparison_result IN (
                    'consistent_with_estimate',
                    'gross_mismatch',
                    'net_arithmetic_mismatch',
                    'fee_differs_from_estimate',
                    'fee_not_estimated'
                )
                AND recorded_by > 0
                AND external_reference_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_settlement_reviews
            ADD CONSTRAINT cod_settlement_review_values CHECK (
                reviewed_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (
                        decision = 'retain_unverified'
                        AND reason_code = 'external_confirmation_required'
                    )
                    OR (
                        decision = 'escalate_discrepancy'
                        AND reason_code IN (
                            'gross_amount_mismatch',
                            'fee_amount_mismatch',
                            'net_amount_mismatch'
                        )
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_settlement_case_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE src RECORD;
            BEGIN
                SELECT f.order_id, f.cod_amount_minor,
                       f.estimated_cod_fee_minor,
                       f.financial_snapshot_sha256,
                       f.local_result, o.payment_status,
                       choice.payment_method,
                       req.goods_due_minor,
                       req.currency_code,
                       req.snapshot_sha256
                INTO src
                FROM checkout_cod_financial_preflights f
                JOIN orders o ON o.id = f.order_id
                JOIN checkout_payment_choices choice
                    ON choice.order_id = o.id
                JOIN checkout_cod_requests req
                    ON req.order_id = o.id
                WHERE f.id = NEW.preflight_id
                FOR UPDATE OF o;

                IF NOT FOUND
                    OR src.order_id IS DISTINCT FROM NEW.order_id
                    OR src.cod_amount_minor
                        IS DISTINCT FROM NEW.expected_cod_minor
                    OR src.estimated_cod_fee_minor
                        IS DISTINCT FROM NEW.expected_fee_minor
                    OR src.financial_snapshot_sha256
                        IS DISTINCT FROM NEW.financial_snapshot_sha256
                    OR src.snapshot_sha256
                        IS DISTINCT FROM NEW.financial_snapshot_sha256
                    OR src.goods_due_minor
                        IS DISTINCT FROM NEW.expected_cod_minor
                    OR src.currency_code IS DISTINCT FROM NEW.currency_code
                    OR src.payment_method IS DISTINCT FROM 'cash_on_delivery'
                    OR src.local_result IS DISTINCT FROM 'within_published_limit'
                    OR src.payment_status IS DISTINCT FROM 'pending'
                THEN
                    RAISE EXCEPTION
                        'COD settlement case is not bound to its financial source';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        // Use procedural IF/ELSIF instead of an inline CASE expression in
        // a PL/pgSQL IF condition. The latter was rejected by PostgreSQL.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_settlement_observation_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                src RECORD;
                expected_result TEXT;
            BEGIN
                SELECT c.expected_cod_minor,
                       c.expected_fee_minor,
                       c.currency_code
                INTO src
                FROM checkout_cod_settlement_cases c
                WHERE c.id = NEW.case_id
                FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION
                        'COD settlement case does not exist';
                END IF;

                IF src.currency_code IS DISTINCT FROM NEW.currency_code THEN
                    RAISE EXCEPTION
                        'COD settlement currency mismatch';
                END IF;

                IF NEW.claimed_gross_minor <> src.expected_cod_minor THEN
                    expected_result := 'gross_mismatch';

                ELSIF NEW.claimed_fee_minor > NEW.claimed_gross_minor
                    OR NEW.claimed_net_minor
                        <> NEW.claimed_gross_minor - NEW.claimed_fee_minor
                THEN
                    expected_result := 'net_arithmetic_mismatch';

                ELSIF src.expected_fee_minor IS NULL THEN
                    expected_result := 'fee_not_estimated';

                ELSIF NEW.claimed_fee_minor <> src.expected_fee_minor THEN
                    expected_result := 'fee_differs_from_estimate';

                ELSE
                    expected_result := 'consistent_with_estimate';
                END IF;

                IF NEW.comparison_result IS DISTINCT FROM expected_result THEN
                    RAISE EXCEPTION
                        'COD observation amounts or comparison are inconsistent';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_settlement_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE src RECORD;
            BEGIN
                SELECT o.recorded_by, o.comparison_result
                INTO src
                FROM checkout_cod_settlement_observations o
                WHERE o.id = NEW.observation_id
                FOR UPDATE;

                IF NOT FOUND
                    OR src.recorded_by = NEW.reviewed_by
                    OR (
                        NEW.decision = 'retain_unverified'
                        AND src.comparison_result NOT IN (
                            'consistent_with_estimate',
                            'fee_not_estimated'
                        )
                    )
                    OR (
                        NEW.reason_code = 'gross_amount_mismatch'
                        AND src.comparison_result <> 'gross_mismatch'
                    )
                    OR (
                        NEW.reason_code = 'net_amount_mismatch'
                        AND src.comparison_result <> 'net_arithmetic_mismatch'
                    )
                    OR (
                        NEW.reason_code = 'fee_amount_mismatch'
                        AND src.comparison_result <> 'fee_differs_from_estimate'
                    )
                THEN
                    RAISE EXCEPTION
                        'COD review must be independent and match discrepancy';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_settlement_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD settlement reconciliation is immutable';
            END;
            $$
        SQL);

        DB::statement('
            CREATE TRIGGER cod_settlement_case_guard
            BEFORE INSERT ON checkout_cod_settlement_cases
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_settlement_case_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_settlement_observation_guard
            BEFORE INSERT ON checkout_cod_settlement_observations
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_settlement_observation_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_settlement_review_guard
            BEFORE INSERT ON checkout_cod_settlement_reviews
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_settlement_review_guard()
        ');

        foreach ([
                     'checkout_cod_settlement_cases',
                     'checkout_cod_settlement_observations',
                     'checkout_cod_settlement_reviews',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_settlement_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_settlement_immutable()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_settlement_reviews');
        Schema::dropIfExists('checkout_cod_settlement_observations');
        Schema::dropIfExists('checkout_cod_settlement_cases');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_settlement_case_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_settlement_observation_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_settlement_review_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_settlement_immutable()'
        );
    }
};
