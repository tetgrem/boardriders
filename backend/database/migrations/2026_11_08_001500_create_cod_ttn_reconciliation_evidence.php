<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_ttn_reconciliation_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('intent_id')
                ->constrained('checkout_cod_ttn_submission_intents')->restrictOnDelete();
            $table->foreignId('trigger_event_id')
                ->constrained('checkout_cod_ttn_submission_safety_events')->restrictOnDelete();
            $table->text('candidate_number_ciphertext');
            $table->char('candidate_number_hmac', 64);
            $table->char('intent_context_hmac', 64);
            $table->string('provider_result', 40);
            $table->unsignedSmallInteger('rows_examined');
            $table->unsignedBigInteger('scanned_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['intent_id', 'id'], 'cod_ttn_recon_scan_history_idx');
        });

        Schema::create('checkout_cod_ttn_reconciliation_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('scan_id')->unique()
                ->constrained('checkout_cod_ttn_reconciliation_scans')->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason_code', 40);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_reconciliation_scans
            ADD CONSTRAINT cod_ttn_recon_scan_values CHECK (
                length(candidate_number_ciphertext) > 24
                AND candidate_number_hmac ~ '^[a-f0-9]{64}$'
                AND intent_context_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND provider_result IN (
                    'number_observed_once', 'number_absent_in_page',
                    'duplicate_number', 'response_inconclusive'
                )
                AND rows_examined <= 100 AND scanned_by > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_reconciliation_reviews
            ADD CONSTRAINT cod_ttn_recon_review_values CHECK (
                reviewed_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (decision = 'escalate_to_carrier'
                        AND reason_code IN ('candidate_seen', 'duplicate_number'))
                    OR (decision = 'keep_reconciliation_hold'
                        AND reason_code IN (
                            'insufficient_api_evidence', 'order_link_unverified'
                        ))
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_reconciliation_scan_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE source RECORD;
            BEGIN
                SELECT i.id AS intent_id, i.context_hmac, e.intent_id AS event_intent,
                       e.event_type AS event_type
                INTO source
                FROM checkout_cod_ttn_submission_intents i
                JOIN checkout_cod_ttn_submission_safety_events e
                  ON e.id = NEW.trigger_event_id
                WHERE i.id = NEW.intent_id
                FOR UPDATE OF i;

                IF NOT FOUND
                    OR source.event_intent IS DISTINCT FROM NEW.intent_id
                    OR source.event_type IS DISTINCT FROM 'reconciliation_required'
                    OR source.context_hmac IS DISTINCT FROM NEW.intent_context_hmac
                    OR EXISTS (
                        SELECT 1 FROM checkout_cod_ttn_submission_safety_events
                        WHERE intent_id = NEW.intent_id
                          AND event_type = 'cancel_before_submission'
                    )
                THEN
                    RAISE EXCEPTION 'TTN reconciliation scan source mismatch';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_reconciliation_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE source RECORD;
            BEGIN
                SELECT s.scanned_by, s.provider_result
                INTO source
                FROM checkout_cod_ttn_reconciliation_scans s
                WHERE s.id = NEW.scan_id;
                IF NOT FOUND OR source.scanned_by = NEW.reviewed_by
                    OR (NEW.reason_code = 'candidate_seen'
                        AND source.provider_result <> 'number_observed_once')
                    OR (NEW.reason_code = 'duplicate_number'
                        AND source.provider_result <> 'duplicate_number')
                THEN
                    RAISE EXCEPTION 'TTN reconciliation review not independent or invalid';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_reconciliation_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'TTN reconciliation history is immutable';
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER cod_ttn_recon_scan_guard BEFORE INSERT ON checkout_cod_ttn_reconciliation_scans FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_reconciliation_scan_guard()');
        DB::statement('CREATE TRIGGER cod_ttn_recon_review_guard BEFORE INSERT ON checkout_cod_ttn_reconciliation_reviews FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_reconciliation_review_guard()');

        foreach (['checkout_cod_ttn_reconciliation_scans', 'checkout_cod_ttn_reconciliation_reviews'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_reconciliation_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_ttn_reconciliation_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_ttn_reconciliation_reviews');
        Schema::dropIfExists('checkout_cod_ttn_reconciliation_scans');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_reconciliation_scan_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_reconciliation_review_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_reconciliation_immutable()');
    }
};
