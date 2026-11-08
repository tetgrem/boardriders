<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('np_cod_external_attestation_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('claim_key')->unique();
            $table->foreignId('evidence_id')->unique()
                ->constrained('np_cod_contract_evidence')->restrictOnDelete();
            $table->string('source_kind', 32);
            $table->char('source_sha256', 64);
            $table->char('reference_sha256', 64);
            $table->timestampTz('issued_at', 6);
            $table->timestampTz('valid_until', 6)->nullable();
            $table->unsignedBigInteger('submitted_by');
            $table->char('request_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('np_cod_external_attestation_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('claim_id')->unique()
                ->constrained('np_cod_external_attestation_claims')->restrictOnDelete();
            $table->string('decision', 12);
            $table->string('reason_code', 32);
            $table->unsignedBigInteger('reviewed_by');
            $table->char('request_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('np_cod_external_attestation_revocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('claim_id')->unique()
                ->constrained('np_cod_external_attestation_claims')->restrictOnDelete();
            $table->string('reason_code', 32);
            $table->unsignedBigInteger('revoked_by');
            $table->char('request_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE np_cod_external_attestation_claims
            ADD CONSTRAINT np_cod_external_claim_values_check CHECK (
                source_kind IN ('signed_document', 'issuer_portal_export', 'issuer_correspondence')
                AND source_sha256 ~ '^[a-f0-9]{64}$'
                AND reference_sha256 ~ '^[a-f0-9]{64}$'
                AND request_sha256 ~ '^[a-f0-9]{64}$'
                AND submitted_by > 0
                AND (valid_until IS NULL OR valid_until > issued_at)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE np_cod_external_attestation_reviews
            ADD CONSTRAINT np_cod_external_review_values_check CHECK (
                decision IN ('accepted', 'rejected')
                AND ((decision = 'accepted' AND reason_code = 'source_checked')
                    OR (decision = 'rejected' AND reason_code IN (
                        'missing_source', 'reference_mismatch', 'issuer_unconfirmed',
                        'invalid_scope', 'other_issue'
                    )))
                AND reviewed_by > 0 AND request_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE np_cod_external_attestation_revocations
            ADD CONSTRAINT np_cod_external_revocation_values_check CHECK (
                reason_code IN ('issuer_withdrawal', 'source_invalidated',
                    'security_hold', 'contract_changed')
                AND revoked_by > 0 AND request_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_np_cod_external_claim_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE doc RECORD;
            BEGIN
                SELECT e.id, e.profile_code, e.capability, e.revision,
                       e.reference_sha256, e.valid_from, e.valid_until,
                       r.decision
                INTO doc
                FROM np_cod_contract_evidence e
                LEFT JOIN np_cod_contract_reviews r ON r.evidence_id = e.id
                WHERE e.id = NEW.evidence_id
                FOR UPDATE OF e;

                IF NOT FOUND
                   OR doc.decision IS DISTINCT FROM 'accepted'
                   OR doc.reference_sha256 IS DISTINCT FROM NEW.reference_sha256
                   OR doc.revision <> (
                        SELECT MAX(revision) FROM np_cod_contract_evidence
                        WHERE profile_code = doc.profile_code AND capability = doc.capability
                   )
                   OR EXISTS (SELECT 1 FROM np_cod_contract_revocations
                              WHERE evidence_id = NEW.evidence_id)
                   OR doc.valid_from > CURRENT_TIMESTAMP
                   OR (doc.valid_until IS NOT NULL AND doc.valid_until <= CURRENT_TIMESTAMP)
                   OR NEW.issued_at > CURRENT_TIMESTAMP
                   OR (NEW.valid_until IS NOT NULL AND NEW.valid_until <= CURRENT_TIMESTAMP)
                   OR (doc.valid_until IS NOT NULL AND
                       (NEW.valid_until IS NULL OR NEW.valid_until > doc.valid_until))
                THEN
                    RAISE EXCEPTION 'COD external claim: invalid underlying agreement';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_np_cod_external_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE claim_submitter bigint;
            DECLARE doc_submitter bigint;
            DECLARE doc_id bigint;
            BEGIN
                SELECT c.submitted_by, e.submitted_by, e.id
                INTO claim_submitter, doc_submitter, doc_id
                FROM np_cod_external_attestation_claims c
                JOIN np_cod_contract_evidence e ON e.id = c.evidence_id
                WHERE c.id = NEW.claim_id;
                IF NOT FOUND OR NEW.reviewed_by = claim_submitter
                   OR NEW.reviewed_by = doc_submitter
                THEN
                    RAISE EXCEPTION 'COD external attestation requires independent reviewer';
                END IF;
                IF NEW.decision = 'accepted' AND (
                    EXISTS (SELECT 1 FROM np_cod_contract_revocations WHERE evidence_id = doc_id)
                    OR EXISTS (SELECT 1 FROM np_cod_external_attestation_revocations
                               WHERE claim_id = NEW.claim_id)
                ) THEN
                    RAISE EXCEPTION 'COD external attestation no longer current';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_np_cod_external_revocation_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE claim_submitter bigint;
            DECLARE reviewer bigint;
            BEGIN
                SELECT c.submitted_by, r.reviewed_by
                INTO claim_submitter, reviewer
                FROM np_cod_external_attestation_claims c
                LEFT JOIN np_cod_external_attestation_reviews r ON r.claim_id = c.id
                    AND r.decision = 'accepted'
                WHERE c.id = NEW.claim_id;
                IF NOT FOUND OR reviewer IS NULL
                   OR NEW.revoked_by = claim_submitter
                   OR NEW.revoked_by = reviewer
                THEN
                    RAISE EXCEPTION 'COD external attestation revocation is not authorized';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_np_cod_external_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD external attestation history is immutable';
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER np_cod_external_claim_guard BEFORE INSERT ON np_cod_external_attestation_claims FOR EACH ROW EXECUTE FUNCTION boardriders_np_cod_external_claim_guard()');
        DB::statement('CREATE TRIGGER np_cod_external_review_guard BEFORE INSERT ON np_cod_external_attestation_reviews FOR EACH ROW EXECUTE FUNCTION boardriders_np_cod_external_review_guard()');
        DB::statement('CREATE TRIGGER np_cod_external_revocation_guard BEFORE INSERT ON np_cod_external_attestation_revocations FOR EACH ROW EXECUTE FUNCTION boardriders_np_cod_external_revocation_guard()');

        foreach (['np_cod_external_attestation_claims', 'np_cod_external_attestation_reviews',
                     'np_cod_external_attestation_revocations'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION boardriders_np_cod_external_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION boardriders_np_cod_external_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('np_cod_external_attestation_revocations');
        Schema::dropIfExists('np_cod_external_attestation_reviews');
        Schema::dropIfExists('np_cod_external_attestation_claims');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_np_cod_external_claim_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_np_cod_external_review_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_np_cod_external_revocation_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_np_cod_external_immutable()');
    }
};
