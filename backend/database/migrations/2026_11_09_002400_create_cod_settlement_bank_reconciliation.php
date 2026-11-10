<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_settlement_bank_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('case_id')
                ->constrained('checkout_cod_settlement_cases')->restrictOnDelete();
            $table->char('reference_hmac', 64)->unique(); // Globally unique: even across COD orders
            $table->string('source_kind', 24);
            $table->unsignedBigInteger('claimed_net_minor');
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('recorded_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['case_id', 'id'], 'cod_bank_receipts_case_history');
        });

        Schema::create('checkout_cod_settlement_bank_matches', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('case_id')
                ->constrained('checkout_cod_settlement_cases')->restrictOnDelete();
            $table->foreignId('observation_id')->unique()
                ->constrained('checkout_cod_settlement_observations')->restrictOnDelete();
            $table->foreignId('bank_receipt_id')->unique()
                ->constrained('checkout_cod_settlement_bank_receipts')->restrictOnDelete();
            $table->unsignedBigInteger('novapay_net_minor');
            $table->unsignedBigInteger('bank_net_minor');
            $table->bigInteger('difference_minor');
            $table->string('match_result', 40);
            $table->unsignedBigInteger('proposed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['case_id', 'id'], 'cod_bank_matches_case_history');
        });

        Schema::create('checkout_cod_settlement_bank_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('match_id')->unique()
                ->constrained('checkout_cod_settlement_bank_matches')->restrictOnDelete();
            $table->string('decision', 40);
            $table->string('reason_code', 48);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_settlement_bank_receipts
            ADD CONSTRAINT cod_bank_receipt_values CHECK (
                source_kind IN ('bank_statement_claim', 'manual_bank_claim')
                AND currency_code = 'UAH'
                AND claimed_net_minor BETWEEN 0 AND 100000000
                AND recorded_by > 0
                AND reference_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_settlement_bank_matches
            ADD CONSTRAINT cod_bank_match_values CHECK (
                novapay_net_minor BETWEEN 0 AND 100000000
                AND bank_net_minor BETWEEN 0 AND 100000000
                AND difference_minor BETWEEN -100000000 AND 100000000
                AND match_result IN (
                    'amounts_match_unverified',
                    'bank_net_mismatch',
                    'source_claim_discrepancy'
                )
                AND proposed_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_settlement_bank_reviews
            ADD CONSTRAINT cod_bank_review_values CHECK (
                reviewed_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (decision = 'retain_external_confirmation'
                        AND reason_code = 'bank_and_novapay_unverified')
                    OR (decision = 'escalate_difference'
                        AND reason_code IN (
                            'bank_net_mismatch',
                            'source_claim_discrepancy'
                        ))
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_receipt_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v_currency TEXT;
            BEGIN
                SELECT currency_code INTO v_currency
                FROM checkout_cod_settlement_cases
                WHERE id = NEW.case_id
                FOR UPDATE;

                IF NOT FOUND OR v_currency IS DISTINCT FROM NEW.currency_code THEN
                    RAISE EXCEPTION 'Bank claim must match COD settlement currency';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_match_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                src RECORD;
                expected_result TEXT;
            BEGIN
                SELECT b.case_id AS bank_case_id,
                       b.claimed_net_minor AS bank_net,
                       b.recorded_by AS bank_author,
                       b.currency_code AS bank_currency,
                       o.case_id AS observation_case_id,
                       o.claimed_net_minor AS novapay_net,
                       o.recorded_by AS observation_author,
                       o.currency_code AS observation_currency,
                       o.source_kind AS observation_kind,
                       o.comparison_result,
                       r.id AS prior_review_id
                INTO src
                FROM checkout_cod_settlement_bank_receipts b
                JOIN checkout_cod_settlement_cases c ON c.id = b.case_id
                JOIN checkout_cod_settlement_observations o ON o.id = NEW.observation_id
                LEFT JOIN checkout_cod_settlement_reviews r ON r.observation_id = o.id
                WHERE b.id = NEW.bank_receipt_id
                FOR UPDATE OF c;

                IF NOT FOUND
                    OR src.bank_case_id IS DISTINCT FROM NEW.case_id
                    OR src.observation_case_id IS DISTINCT FROM NEW.case_id
                    OR src.bank_currency IS DISTINCT FROM 'UAH'
                    OR src.observation_currency IS DISTINCT FROM 'UAH'
                    OR src.observation_kind IS DISTINCT FROM 'novapay_statement'
                    OR src.prior_review_id IS NULL
                    OR src.bank_author = NEW.proposed_by
                    OR src.observation_author = NEW.proposed_by
                    OR src.bank_net IS DISTINCT FROM NEW.bank_net_minor
                    OR src.novapay_net IS DISTINCT FROM NEW.novapay_net_minor
                    OR NEW.difference_minor IS DISTINCT FROM
                        (src.bank_net::bigint - src.novapay_net::bigint)
                THEN
                    RAISE EXCEPTION 'COD bank match evidence is inconsistent';
                END IF;

                IF src.comparison_result <> 'consistent_with_estimate' THEN
                    expected_result := 'source_claim_discrepancy';
                ELSIF src.bank_net <> src.novapay_net THEN
                    expected_result := 'bank_net_mismatch';
                ELSE
                    expected_result := 'amounts_match_unverified';
                END IF;

                IF NEW.match_result IS DISTINCT FROM expected_result THEN
                    RAISE EXCEPTION 'COD bank match classification is inconsistent';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE src RECORD;
            BEGIN
                SELECT m.match_result, m.proposed_by,
                       b.recorded_by AS bank_author,
                       o.recorded_by AS observation_author
                INTO src
                FROM checkout_cod_settlement_bank_matches m
                JOIN checkout_cod_settlement_bank_receipts b ON b.id = m.bank_receipt_id
                JOIN checkout_cod_settlement_observations o ON o.id = m.observation_id
                WHERE m.id = NEW.match_id
                FOR UPDATE OF m;

                IF NOT FOUND
                    OR src.proposed_by = NEW.reviewed_by
                    OR src.bank_author = NEW.reviewed_by
                    OR src.observation_author = NEW.reviewed_by
                    OR (
                        src.match_result = 'amounts_match_unverified'
                        AND (NEW.decision <> 'retain_external_confirmation'
                            OR NEW.reason_code <> 'bank_and_novapay_unverified')
                    )
                    OR (
                        src.match_result = 'bank_net_mismatch'
                        AND (NEW.decision <> 'escalate_difference'
                            OR NEW.reason_code <> 'bank_net_mismatch')
                    )
                    OR (
                        src.match_result = 'source_claim_discrepancy'
                        AND (NEW.decision <> 'escalate_difference'
                            OR NEW.reason_code <> 'source_claim_discrepancy')
                    )
                THEN
                    RAISE EXCEPTION 'Independent bank match review is invalid';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_bank_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD bank reconciliation records are immutable';
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER cod_bank_receipt_guard BEFORE INSERT ON checkout_cod_settlement_bank_receipts FOR EACH ROW EXECUTE FUNCTION boardriders_cod_bank_receipt_guard()');
        DB::statement('CREATE TRIGGER cod_bank_match_guard BEFORE INSERT ON checkout_cod_settlement_bank_matches FOR EACH ROW EXECUTE FUNCTION boardriders_cod_bank_match_guard()');
        DB::statement('CREATE TRIGGER cod_bank_review_guard BEFORE INSERT ON checkout_cod_settlement_bank_reviews FOR EACH ROW EXECUTE FUNCTION boardriders_cod_bank_review_guard()');

        foreach ([
                     'checkout_cod_settlement_bank_receipts',
                     'checkout_cod_settlement_bank_matches',
                     'checkout_cod_settlement_bank_reviews',
                 ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION boardriders_cod_bank_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_bank_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_settlement_bank_reviews');
        Schema::dropIfExists('checkout_cod_settlement_bank_matches');
        Schema::dropIfExists('checkout_cod_settlement_bank_receipts');

        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_bank_receipt_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_bank_match_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_bank_review_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_bank_immutable()');
    }
};
