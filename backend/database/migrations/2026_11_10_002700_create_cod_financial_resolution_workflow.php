<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_exception_evidence', function (Blueprint $t) {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->foreignId('exception_id')
                ->constrained('checkout_cod_financial_exceptions')
                ->restrictOnDelete();
            $t->string('evidence_kind', 32);
            $t->char('document_sha256', 64);
            $t->unsignedBigInteger('recorded_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(
                ['exception_id', 'document_sha256'],
                'cod_exception_document_once'
            );
        });

        Schema::create('checkout_cod_exception_proposals', function (Blueprint $t) {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->foreignId('exception_id')
                ->constrained('checkout_cod_financial_exceptions')
                ->restrictOnDelete();
            $t->string('recommendation_kind', 32);
            $t->unsignedBigInteger('source_net_minor');
            $t->unsignedBigInteger('recommended_net_minor');
            $t->bigInteger('delta_minor');
            $t->unsignedInteger('evidence_count');
            $t->unsignedBigInteger('proposed_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(
                ['exception_id', 'id'],
                'cod_exception_proposal_history'
            );
        });

        Schema::create('checkout_cod_exception_proposal_reviews', function (Blueprint $t) {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->foreignId('proposal_id')->unique()
                ->constrained('checkout_cod_exception_proposals')
                ->restrictOnDelete();
            $t->string('decision', 40);
            $t->string('reason_code', 48);
            $t->unsignedBigInteger('reviewed_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_exception_evidence
            ADD CONSTRAINT cod_resolution_evidence_values CHECK (
                evidence_kind IN (
                    'bank_notice_claim',
                    'novapay_notice_claim',
                    'order_record_claim',
                    'internal_reconciliation'
                )
                AND document_sha256 ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND recorded_by > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_exception_proposals
            ADD CONSTRAINT cod_resolution_proposal_values CHECK (
                recommendation_kind IN (
                    'amount_correction',
                    'reported_reversal',
                    'batch_reallocation',
                    'return_case_followup'
                )
                AND source_net_minor BETWEEN 0 AND 100000000
                AND recommended_net_minor BETWEEN 0 AND 100000000
                AND delta_minor =
                    recommended_net_minor::bigint - source_net_minor::bigint
                AND delta_minor BETWEEN -100000000 AND 100000000
                AND evidence_count BETWEEN 2 AND 100
                AND proposed_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_exception_proposal_reviews
            ADD CONSTRAINT cod_resolution_review_values CHECK (
                reviewed_by > 0
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (
                        decision = 'ready_for_external_verification'
                        AND reason_code = 'recommendation_documented'
                    )
                    OR (
                        decision = 'return_for_rework'
                        AND reason_code = 'insufficient_consistency'
                    )
                )
            )
        SQL);

        // All writes serialize on the original financial source.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_resolution_lock_exception(
                p_id BIGINT
            )
            RETURNS BIGINT LANGUAGE plpgsql VOLATILE AS $$
            DECLARE
                v RECORD;
            BEGIN
                SELECT e.source_kind,
                       e.source_id,
                       e.reported_by,
                       e.reason_code,
                       r.decision AS initial_decision,
                       r.reviewed_by AS initial_reviewer
                INTO v
                FROM checkout_cod_financial_exceptions e
                LEFT JOIN checkout_cod_financial_exception_reviews r
                    ON r.exception_id = e.id
                WHERE e.id = p_id;

                IF NOT FOUND
                    OR v.initial_decision IS DISTINCT FROM 'hold_for_investigation'
                THEN
                    RAISE EXCEPTION
                        'Independent investigation hold required';
                END IF;

                PERFORM boardriders_cod_financial_lock_source(
                    v.source_kind,
                    v.source_id
                );

                RETURN v.source_id;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_resolution_evidence_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v RECORD;
                last_decision TEXT;
            BEGIN
                PERFORM boardriders_cod_resolution_lock_exception(
                    NEW.exception_id
                );

                SELECT e.source_kind,
                       e.source_id,
                       e.reported_by,
                       r.reviewed_by AS initial_reviewer
                INTO v
                FROM checkout_cod_financial_exceptions e
                JOIN checkout_cod_financial_exception_reviews r
                    ON r.exception_id = e.id
                WHERE e.id = NEW.exception_id;

                IF NEW.recorded_by = v.reported_by
                    OR NEW.recorded_by = v.initial_reviewer
                    OR NEW.recorded_by =
                        boardriders_cod_financial_lock_source(
                            v.source_kind,
                            v.source_id
                        )
                THEN
                    RAISE EXCEPTION
                        'Evidence collector must be independent';
                END IF;

                SELECT d.decision
                INTO last_decision
                FROM checkout_cod_exception_proposals p
                LEFT JOIN checkout_cod_exception_proposal_reviews d
                    ON d.proposal_id = p.id
                WHERE p.exception_id = NEW.exception_id
                ORDER BY p.id DESC
                LIMIT 1;

                IF FOUND
                    AND last_decision IS DISTINCT FROM 'return_for_rework'
                THEN
                    RAISE EXCEPTION
                        'Evidence is frozen while proposal is active';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_resolution_proposal_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v RECORD;
                last_decision TEXT;
                n INTEGER;
                authors INTEGER;
                kinds INTEGER;
                conflicts INTEGER;
                source_net BIGINT;
            BEGIN
                PERFORM boardriders_cod_resolution_lock_exception(
                    NEW.exception_id
                );

                SELECT e.source_kind,
                       e.source_id,
                       e.reported_by,
                       e.reason_code,
                       r.reviewed_by AS initial_reviewer
                INTO v
                FROM checkout_cod_financial_exceptions e
                JOIN checkout_cod_financial_exception_reviews r
                    ON r.exception_id = e.id
                WHERE e.id = NEW.exception_id;

                IF NEW.proposed_by IN (
                    v.reported_by,
                    v.initial_reviewer
                )
                    OR NEW.proposed_by =
                        boardriders_cod_financial_lock_source(
                            v.source_kind,
                            v.source_id
                        )
                THEN
                    RAISE EXCEPTION
                        'Correction proposer is not independent';
                END IF;

                SELECT d.decision
                INTO last_decision
                FROM checkout_cod_exception_proposals p
                LEFT JOIN checkout_cod_exception_proposal_reviews d
                    ON d.proposal_id = p.id
                WHERE p.exception_id = NEW.exception_id
                ORDER BY p.id DESC
                LIMIT 1;

                IF FOUND
                    AND last_decision IS DISTINCT FROM 'return_for_rework'
                THEN
                    RAISE EXCEPTION
                        'Previous correction proposal still active';
                END IF;

                SELECT COUNT(*),
                       COUNT(DISTINCT recorded_by),
                       COUNT(DISTINCT evidence_kind),
                       COUNT(*) FILTER (
                           WHERE recorded_by = NEW.proposed_by
                       )
                INTO n, authors, kinds, conflicts
                FROM checkout_cod_exception_evidence
                WHERE exception_id = NEW.exception_id;

                IF n < 2
                    OR n > 100
                    OR authors < 2
                    OR kinds < 2
                    OR conflicts > 0
                    OR n IS DISTINCT FROM NEW.evidence_count
                THEN
                    RAISE EXCEPTION
                        'Two independent evidence sources required';
                END IF;

                IF v.source_kind = 'single' THEN
                    SELECT claimed_net_minor
                    INTO source_net
                    FROM checkout_cod_settlement_bank_receipts
                    WHERE id = v.source_id;

                ELSIF v.source_kind = 'batch' THEN
                    SELECT claimed_net_minor
                    INTO source_net
                    FROM checkout_cod_bank_batches
                    WHERE id = v.source_id;

                ELSE
                    SELECT claimed_net_minor
                    INTO source_net
                    FROM checkout_cod_settlement_observations
                    WHERE id = v.source_id;
                END IF;

                IF source_net IS DISTINCT FROM NEW.source_net_minor THEN
                    RAISE EXCEPTION
                        'Original claim snapshot mismatch';
                END IF;

                IF NEW.recommendation_kind = 'reported_reversal' THEN
                    IF v.source_kind = 'novapay'
                        OR v.reason_code <> 'bank_reversal_reported'
                        OR NEW.recommended_net_minor <> 0
                    THEN
                        RAISE EXCEPTION
                            'Invalid reversal recommendation';
                    END IF;

                ELSIF NEW.recommendation_kind = 'batch_reallocation' THEN
                    IF v.source_kind <> 'batch'
                        OR NEW.recommended_net_minor <> source_net
                    THEN
                        RAISE EXCEPTION
                            'Invalid batch reallocation recommendation';
                    END IF;

                ELSIF NEW.recommendation_kind = 'return_case_followup' THEN
                    IF v.reason_code NOT IN (
                        'customer_return_reported',
                        'order_cancellation_reported'
                    )
                        OR NEW.recommended_net_minor <> source_net
                    THEN
                        RAISE EXCEPTION
                            'Invalid return investigation recommendation';
                    END IF;

                ELSE
                    IF v.reason_code NOT IN (
                        'incorrect_amount',
                        'wrong_source_reference'
                    )
                    THEN
                        RAISE EXCEPTION
                            'Invalid net correction reason';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_resolution_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                p RECORD;
                v RECORD;
                source_author BIGINT;
            BEGIN
                SELECT *
                INTO p
                FROM checkout_cod_exception_proposals
                WHERE id = NEW.proposal_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Proposal does not exist';
                END IF;

                PERFORM boardriders_cod_resolution_lock_exception(
                    p.exception_id
                );

                SELECT e.source_kind,
                       e.source_id,
                       e.reported_by,
                       r.reviewed_by AS initial_reviewer
                INTO v
                FROM checkout_cod_financial_exceptions e
                JOIN checkout_cod_financial_exception_reviews r
                    ON r.exception_id = e.id
                WHERE e.id = p.exception_id;

                source_author :=
                    boardriders_cod_financial_lock_source(
                        v.source_kind,
                        v.source_id
                    );

                IF NEW.reviewed_by IN (
                    p.proposed_by,
                    v.reported_by,
                    v.initial_reviewer,
                    source_author
                )
                    OR EXISTS (
                        SELECT 1
                        FROM checkout_cod_exception_evidence
                        WHERE exception_id = p.exception_id
                          AND recorded_by = NEW.reviewed_by
                    )
                    OR p.id <> (
                        SELECT MAX(id)
                        FROM checkout_cod_exception_proposals
                        WHERE exception_id = p.exception_id
                    )
                THEN
                    RAISE EXCEPTION
                        'Independent latest-proposal review required';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement('
            CREATE TRIGGER cod_resolution_evidence_guard
            BEFORE INSERT ON checkout_cod_exception_evidence
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_resolution_evidence_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_resolution_proposal_guard
            BEFORE INSERT ON checkout_cod_exception_proposals
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_resolution_proposal_guard()
        ');

        DB::statement('
            CREATE TRIGGER cod_resolution_review_guard
            BEFORE INSERT ON checkout_cod_exception_proposal_reviews
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_resolution_review_guard()
        ');

        foreach ([
                     'checkout_cod_exception_evidence',
                     'checkout_cod_exception_proposals',
                     'checkout_cod_exception_proposal_reviews',
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
        Schema::dropIfExists('checkout_cod_exception_proposal_reviews');
        Schema::dropIfExists('checkout_cod_exception_proposals');
        Schema::dropIfExists('checkout_cod_exception_evidence');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_resolution_evidence_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_resolution_proposal_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_resolution_review_guard()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_resolution_lock_exception(BIGINT)'
        );
    }
};
