<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_ttn_document_comparisons', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('scan_id')->unique()
                ->constrained('checkout_cod_ttn_reconciliation_scans')->restrictOnDelete();
            $table->foreignId('review_id')->unique()
                ->constrained('checkout_cod_ttn_reconciliation_reviews')->restrictOnDelete();
            $table->foreignId('intent_id')
                ->constrained('checkout_cod_ttn_submission_intents')->restrictOnDelete();
            $table->string('summary', 32);
            $table->jsonb('checks');
            $table->char('candidate_number_hmac', 64);
            $table->char('intent_context_hmac', 64);
            $table->char('document_ref_hmac', 64)->nullable();
            $table->unsignedBigInteger('compared_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['intent_id', 'id'], 'cod_ttn_document_comparisons_history_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_document_comparisons
            ADD CONSTRAINT cod_ttn_document_comparison_values_check CHECK (
                summary IN ('snapshot_consistent', 'snapshot_mismatch',
                    'inconclusive_list', 'inconclusive_document', 'inconclusive_fields')
                AND jsonb_typeof(checks) = 'object'
                AND compared_by > 0
                AND candidate_number_hmac ~ '^[a-f0-9]{64}$'
                AND intent_context_hmac ~ '^[a-f0-9]{64}$'
                AND (document_ref_hmac IS NULL OR document_ref_hmac ~ '^[a-f0-9]{64}$')
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_document_comparison_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE source RECORD;
            BEGIN
                SELECT s.intent_id, s.provider_result, s.candidate_number_hmac,
                       s.intent_context_hmac, s.scanned_by,
                       r.id AS expected_review_id, r.reviewed_by, r.decision,
                       i.context_hmac
                INTO source
                FROM checkout_cod_ttn_reconciliation_scans s
                JOIN checkout_cod_ttn_reconciliation_reviews r ON r.scan_id = s.id
                JOIN checkout_cod_ttn_submission_intents i ON i.id = s.intent_id
                WHERE s.id = NEW.scan_id
                FOR UPDATE OF i;

                IF NOT FOUND
                    OR source.intent_id IS DISTINCT FROM NEW.intent_id
                    OR source.expected_review_id IS DISTINCT FROM NEW.review_id
                    OR source.provider_result IS DISTINCT FROM 'number_observed_once'
                    OR source.decision IS DISTINCT FROM 'escalate_to_carrier'
                    OR source.candidate_number_hmac IS DISTINCT FROM NEW.candidate_number_hmac
                    OR source.intent_context_hmac IS DISTINCT FROM NEW.intent_context_hmac
                    OR source.context_hmac IS DISTINCT FROM NEW.intent_context_hmac
                    OR source.scanned_by = NEW.compared_by
                    OR source.reviewed_by = NEW.compared_by
                THEN
                    RAISE EXCEPTION 'TTN document comparison is not bound to a reviewed scan';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_document_comparison_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'TTN document comparisons are immutable';
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER cod_ttn_document_comparison_insert_guard BEFORE INSERT ON checkout_cod_ttn_document_comparisons FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_document_comparison_guard()');
        DB::statement('CREATE TRIGGER cod_ttn_document_comparison_immutable BEFORE UPDATE OR DELETE ON checkout_cod_ttn_document_comparisons FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_document_comparison_immutable()');
        DB::statement('CREATE TRIGGER cod_ttn_document_comparison_no_truncate BEFORE TRUNCATE ON checkout_cod_ttn_document_comparisons FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_ttn_document_comparison_immutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_ttn_document_comparisons');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_document_comparison_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_document_comparison_immutable()');
    }
};
