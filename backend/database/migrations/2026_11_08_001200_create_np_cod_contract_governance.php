<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('np_cod_contract_evidence', function (Blueprint $table) {
            $table->id();
            $table->uuid('evidence_key')->unique();
            $table->string('profile_code', 40);
            $table->string('capability', 32);
            $table->unsignedInteger('revision');
            $table->string('issuer_code', 24);
            $table->char('document_sha256', 64);
            $table->char('reference_sha256', 64);
            $table->timestampTz('valid_from', 6);
            $table->timestampTz('valid_until', 6)->nullable();
            $table->unsignedBigInteger('submitted_by');
            $table->char('request_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['profile_code', 'capability', 'revision'], 'np_cod_evidence_revision_unique');
        });

        Schema::create('np_cod_contract_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('evidence_id')->unique()
                ->constrained('np_cod_contract_evidence')->restrictOnDelete();
            $table->string('decision', 12);
            $table->string('reason_code', 32);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('np_cod_contract_revocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('evidence_id')->unique()
                ->constrained('np_cod_contract_evidence')->restrictOnDelete();
            $table->string('reason_code', 32);
            $table->unsignedBigInteger('revoked_by');
            $table->char('request_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE np_cod_contract_evidence ADD CONSTRAINT np_cod_evidence_values_check CHECK (
                profile_code ~ '^[a-z][a-z0-9_-]{1,39}$'
                AND capability IN ('transport_agreement', 'cod_agreement', 'payout_agreement')
                AND revision > 0
                AND issuer_code = CASE
                    WHEN capability = 'transport_agreement' THEN 'nova_poshta'
                    ELSE 'novapay' END
                AND document_sha256 ~ '^[a-f0-9]{64}$'
                AND reference_sha256 ~ '^[a-f0-9]{64}$'
                AND request_sha256 ~ '^[a-f0-9]{64}$'
                AND submitted_by > 0
                AND (valid_until IS NULL OR valid_until > valid_from)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE np_cod_contract_reviews ADD CONSTRAINT np_cod_review_values_check CHECK (
                decision IN ('accepted', 'rejected')
                AND ((decision = 'accepted' AND reason_code = 'documents_reviewed')
                  OR (decision = 'rejected' AND reason_code IN (
                      'missing_document', 'reference_mismatch', 'insufficient_scope', 'other_issue'
                  )))
                AND reviewed_by > 0
                AND request_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE np_cod_contract_revocations ADD CONSTRAINT np_cod_revocation_values_check CHECK (
                reason_code IN ('contract_terminated', 'issuer_notice', 'security_hold', 'invalid_evidence')
                AND revoked_by > 0
                AND request_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        // Revision control is enforced even for direct SQL inserts.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_np_cod_evidence_insert_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE expected_revision integer;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext(
                    'np-cod-contract:' || NEW.profile_code || ':' || NEW.capability
                ));
                SELECT COALESCE(MAX(revision), 0) + 1 INTO expected_revision
                FROM np_cod_contract_evidence
                WHERE profile_code = NEW.profile_code AND capability = NEW.capability;
                IF NEW.revision <> expected_revision THEN
                    RAISE EXCEPTION 'COD contract evidence revision is not sequential';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_np_cod_review_insert_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE submitter bigint;
            BEGIN
                SELECT submitted_by INTO submitter
                FROM np_cod_contract_evidence WHERE id = NEW.evidence_id;
                IF NOT FOUND OR submitter = NEW.reviewed_by THEN
                    RAISE EXCEPTION 'COD contract evidence requires a different reviewer';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_np_cod_revocation_insert_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM np_cod_contract_reviews
                    WHERE evidence_id = NEW.evidence_id AND decision = 'accepted'
                ) THEN
                    RAISE EXCEPTION 'Only accepted contract evidence may be revoked';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_np_cod_contract_history_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD contract governance history is immutable';
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER np_cod_evidence_insert_guard BEFORE INSERT ON np_cod_contract_evidence FOR EACH ROW EXECUTE FUNCTION boardriders_np_cod_evidence_insert_guard()');
        DB::statement('CREATE TRIGGER np_cod_review_insert_guard BEFORE INSERT ON np_cod_contract_reviews FOR EACH ROW EXECUTE FUNCTION boardriders_np_cod_review_insert_guard()');
        DB::statement('CREATE TRIGGER np_cod_revocation_insert_guard BEFORE INSERT ON np_cod_contract_revocations FOR EACH ROW EXECUTE FUNCTION boardriders_np_cod_revocation_insert_guard()');

        foreach (['np_cod_contract_evidence', 'np_cod_contract_reviews', 'np_cod_contract_revocations'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION boardriders_np_cod_contract_history_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION boardriders_np_cod_contract_history_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('np_cod_contract_revocations');
        Schema::dropIfExists('np_cod_contract_reviews');
        Schema::dropIfExists('np_cod_contract_evidence');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_np_cod_evidence_insert_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_np_cod_review_insert_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_np_cod_revocation_insert_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_np_cod_contract_history_immutable()');
    }
};
