<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_ttn_tracking_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('tracking_key')->unique();
            $table->foreignId('intent_id')->unique()
                ->constrained('checkout_cod_ttn_submission_intents')->restrictOnDelete();
            $table->foreignId('comparison_id')->unique()
                ->constrained('checkout_cod_ttn_document_comparisons')->restrictOnDelete();
            $table->foreignId('scan_id')->unique()
                ->constrained('checkout_cod_ttn_reconciliation_scans')->restrictOnDelete();
            $table->char('candidate_number_hmac', 64)->unique();
            $table->char('intent_context_hmac', 64);
            $table->unsignedBigInteger('started_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_ttn_tracking_observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('tracking_case_id')
                ->constrained('checkout_cod_ttn_tracking_cases')->restrictOnDelete();
            $table->string('outcome', 24);
            $table->string('status_code', 3)->nullable();
            $table->unsignedBigInteger('observed_by'); // 0 = scheduler
            $table->char('request_hmac', 64);
            $table->timestampTz('observed_started_at', 6);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(
                ['tracking_case_id', 'observed_started_at', 'id'],
                'cod_ttn_tracking_observation_history'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_tracking_cases
            ADD CONSTRAINT cod_ttn_tracking_case_values CHECK (
                started_by > 0
                AND candidate_number_hmac ~ '^[a-f0-9]{64}$'
                AND intent_context_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_tracking_observations
            ADD CONSTRAINT cod_ttn_tracking_observation_values CHECK (
                request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (outcome = 'observed' AND status_code ~ '^[0-9]{1,3}$')
                    OR (outcome IN ('inconclusive', 'provider_failure')
                        AND status_code IS NULL)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_tracking_case_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE src RECORD;
            BEGIN
                SELECT c.intent_id, c.scan_id, c.summary, c.document_ref_hmac,
                       c.candidate_number_hmac AS comparison_number_hmac,
                       c.intent_context_hmac AS comparison_context_hmac,
                       s.candidate_number_hmac AS scan_number_hmac,
                       s.intent_context_hmac AS scan_context_hmac,
                       s.provider_result, r.decision, r.reason_code,
                       i.context_hmac AS intent_context_hmac
                INTO src
                FROM checkout_cod_ttn_document_comparisons c
                JOIN checkout_cod_ttn_reconciliation_scans s
                    ON s.id = c.scan_id
                JOIN checkout_cod_ttn_reconciliation_reviews r
                    ON r.id = c.review_id
                JOIN checkout_cod_ttn_submission_intents i
                    ON i.id = c.intent_id
                WHERE c.id = NEW.comparison_id
                FOR UPDATE OF i;

                IF NOT FOUND
                    OR src.intent_id IS DISTINCT FROM NEW.intent_id
                    OR src.scan_id IS DISTINCT FROM NEW.scan_id
                    OR src.summary IS DISTINCT FROM 'snapshot_consistent'
                    OR src.document_ref_hmac IS NULL
                    OR src.provider_result IS DISTINCT FROM 'number_observed_once'
                    OR src.decision IS DISTINCT FROM 'escalate_to_carrier'
                    OR src.reason_code IS DISTINCT FROM 'candidate_seen'
                    OR src.comparison_number_hmac IS DISTINCT FROM NEW.candidate_number_hmac
                    OR src.scan_number_hmac IS DISTINCT FROM NEW.candidate_number_hmac
                    OR src.comparison_context_hmac IS DISTINCT FROM NEW.intent_context_hmac
                    OR src.scan_context_hmac IS DISTINCT FROM NEW.intent_context_hmac
                    OR src.intent_context_hmac IS DISTINCT FROM NEW.intent_context_hmac
                    OR NOT EXISTS (
                        SELECT 1
                        FROM checkout_cod_ttn_submission_safety_events e
                        WHERE e.intent_id = NEW.intent_id
                          AND e.event_type = 'reconciliation_required'
                    )
                THEN
                    RAISE EXCEPTION
                        'COD TTN tracking requires independently reviewed matching candidate';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_tracking_observation_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM checkout_cod_ttn_tracking_cases c
                    JOIN checkout_cod_ttn_submission_intents i
                        ON i.id = c.intent_id
                    WHERE c.id = NEW.tracking_case_id
                ) THEN
                    RAISE EXCEPTION 'Tracking case does not exist';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_tracking_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD TTN tracking evidence is immutable';
            END;
            $$
        SQL);

        DB::statement('
            CREATE TRIGGER cod_ttn_tracking_case_guard
            BEFORE INSERT ON checkout_cod_ttn_tracking_cases
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_ttn_tracking_case_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_ttn_tracking_observation_guard
            BEFORE INSERT ON checkout_cod_ttn_tracking_observations
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_ttn_tracking_observation_guard()
        ');

        foreach ([
                     'checkout_cod_ttn_tracking_cases',
                     'checkout_cod_ttn_tracking_observations',
                 ] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION boardriders_cod_ttn_tracking_immutable()
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT
                EXECUTE FUNCTION boardriders_cod_ttn_tracking_immutable()
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_ttn_tracking_observations');
        Schema::dropIfExists('checkout_cod_ttn_tracking_cases');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_ttn_tracking_case_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_ttn_tracking_observation_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_ttn_tracking_immutable()'
        );
    }
};
