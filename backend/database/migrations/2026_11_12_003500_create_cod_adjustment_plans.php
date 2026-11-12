<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_adjustment_plans', function (Blueprint $t): void {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->unsignedBigInteger('resolution_proposal_id');
            $t->foreign('resolution_proposal_id', 'cod_adj_resolution_fk')
                ->references('id')->on('checkout_cod_exception_proposals')->restrictOnDelete();
            $t->unsignedBigInteger('journal_draft_id');
            $t->foreign('journal_draft_id', 'cod_adj_journal_fk')
                ->references('id')->on('checkout_cod_journal_drafts')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->string('source_kind', 12);
            $t->unsignedBigInteger('source_id');
            $t->string('plan_kind', 32);
            $t->unsignedBigInteger('original_gross_minor');
            $t->unsignedBigInteger('original_fee_minor');
            $t->unsignedBigInteger('original_order_net_minor');
            $t->unsignedBigInteger('source_net_minor');
            $t->unsignedBigInteger('recommended_source_net_minor');
            $t->unsignedBigInteger('requested_refund_minor');
            $t->bigInteger('source_net_difference_minor');
            $t->char('snapshot_hmac', 64);
            $t->unsignedBigInteger('prepared_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['source_kind', 'source_id', 'order_id'], 'cod_adj_once_source_order');
            $t->index(['order_id', 'id'], 'cod_adj_order_history');
        });
        Schema::create('checkout_cod_adjustment_plan_reviews', function (Blueprint $t): void {
            $t->id();
            $t->uuid('review_key')->unique();
            $t->unsignedBigInteger('plan_id')->unique('cod_adj_review_once');
            $t->foreign('plan_id', 'cod_adj_review_plan_fk')
                ->references('id')->on('checkout_cod_adjustment_plans')->restrictOnDelete();
            $t->string('decision', 32);
            $t->string('reason_code', 48);
            $t->unsignedBigInteger('reviewed_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_adjustment_plans
            ADD CONSTRAINT cod_adj_values CHECK (
                source_kind IN ('single', 'batch') AND source_id > 0
                AND plan_kind IN ('return_case_followup', 'amount_correction', 'reported_reversal')
                AND original_gross_minor BETWEEN 1 AND 100000000
                AND original_fee_minor BETWEEN 0 AND 100000000
                AND original_order_net_minor BETWEEN 1 AND 100000000
                AND original_gross_minor = original_fee_minor + original_order_net_minor
                AND source_net_minor BETWEEN 1 AND 100000000
                AND recommended_source_net_minor BETWEEN 0 AND 100000000
                AND requested_refund_minor BETWEEN 0 AND original_gross_minor
                AND source_net_difference_minor =
                    recommended_source_net_minor::bigint - source_net_minor::bigint
                AND (
                    (plan_kind = 'return_case_followup'
                     AND requested_refund_minor > 0
                     AND recommended_source_net_minor = source_net_minor)
                    OR (plan_kind = 'amount_correction'
                     AND source_kind = 'single' AND requested_refund_minor = 0
                     AND recommended_source_net_minor <> source_net_minor)
                    OR (plan_kind = 'reported_reversal'
                     AND source_kind = 'single' AND requested_refund_minor = 0
                     AND recommended_source_net_minor = 0)
                )
                AND prepared_by > 0
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_adjustment_plan_reviews
            ADD CONSTRAINT cod_adj_review_values CHECK (
                reviewed_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (decision = 'retain_unexecuted'
                     AND reason_code = 'external_execution_evidence_required')
                    OR (decision = 'escalate_investigation'
                     AND reason_code = 'adjustment_investigation_required')
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_adj_plan_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                p RECORD;
                d RECORD;
                b RECORD;
                l RECORD;
                v_status TEXT;
            BEGIN
                SELECT x.*, e.source_kind, e.source_id, e.reason_code,
                       e.reported_by, er.reviewed_by AS incident_reviewer,
                       er.decision AS incident_decision,
                       pr.reviewed_by AS proposal_reviewer,
                       pr.decision AS proposal_decision
                INTO p
                FROM checkout_cod_exception_proposals x
                JOIN checkout_cod_financial_exceptions e ON e.id = x.exception_id
                JOIN checkout_cod_financial_exception_reviews er ON er.exception_id = e.id
                LEFT JOIN checkout_cod_exception_proposal_reviews pr ON pr.proposal_id = x.id
                WHERE x.id = NEW.resolution_proposal_id;
                IF NOT FOUND OR p.incident_decision <> 'hold_for_investigation'
                    OR p.proposal_decision IS DISTINCT FROM 'ready_for_external_verification'
                    OR p.id <> (SELECT MAX(id) FROM checkout_cod_exception_proposals
                        WHERE exception_id = p.exception_id)
                    OR NEW.plan_kind <> p.recommendation_kind
                    OR NEW.source_kind <> p.source_kind OR NEW.source_id <> p.source_id
                    OR NEW.source_net_minor <> p.source_net_minor
                    OR NEW.recommended_source_net_minor <> p.recommended_net_minor
                    OR NEW.prepared_by IN (p.reported_by, p.incident_reviewer,
                        p.proposed_by, p.proposal_reviewer)
                    OR EXISTS (SELECT 1 FROM checkout_cod_exception_evidence
                        WHERE exception_id = p.exception_id AND recorded_by = NEW.prepared_by)
                THEN
                    RAISE EXCEPTION 'Ineligible COD adjustment recommendation';
                END IF;

                PERFORM boardriders_cod_resolution_lock_exception(p.exception_id);
                IF NOT boardriders_cod_financial_has_hold(p.source_kind, p.source_id) THEN
                    RAISE EXCEPTION 'Active COD financial hold required';
                END IF;
                IF NEW.source_kind = 'single' THEN
                    SELECT recorded_by, claimed_net_minor INTO b
                    FROM checkout_cod_settlement_bank_receipts WHERE id = NEW.source_id;
                ELSE
                    SELECT recorded_by, claimed_net_minor INTO b
                    FROM checkout_cod_bank_batches WHERE id = NEW.source_id;
                END IF;
                IF NOT FOUND OR b.claimed_net_minor <> NEW.source_net_minor
                    OR NEW.prepared_by = b.recorded_by THEN
                    RAISE EXCEPTION 'COD adjustment bank claim mismatch';
                END IF;



            SELECT j.*, r.decision AS review_decision,
                   r.reviewed_by AS journal_reviewer,
                   c.compiled_by, cr.reviewed_by AS coverage_reviewer
            INTO d
            FROM checkout_cod_journal_drafts AS j
            JOIN checkout_cod_journal_draft_reviews AS r
                ON r.draft_id = j.id
            JOIN checkout_cod_bank_coverage_packets AS c
                ON c.id = j.coverage_packet_id
            JOIN checkout_cod_bank_coverage_reviews AS cr
                ON cr.packet_id = c.id
            WHERE j.id = NEW.journal_draft_id;


                IF NOT FOUND OR d.review_decision <> 'retain_unposted'
                    OR d.source_kind <> NEW.source_kind OR d.source_id <> NEW.source_id
                    OR NEW.prepared_by IN (d.prepared_by, d.journal_reviewer,
                        d.compiled_by, d.coverage_reviewer)
                THEN
                    RAISE EXCEPTION 'Reviewed journal draft for same source required';
                END IF;
                SELECT payment_status INTO v_status
                FROM orders WHERE id = NEW.order_id FOR UPDATE;
                SELECT gross_minor, fee_minor, allocated_minor INTO l
                FROM checkout_cod_bank_coverage_lines
                WHERE packet_id = d.coverage_packet_id AND order_id = NEW.order_id;
                IF NOT FOUND OR v_status <> 'pending'
                    OR l.gross_minor <> NEW.original_gross_minor
                    OR l.fee_minor <> NEW.original_fee_minor
                    OR l.allocated_minor <> NEW.original_order_net_minor THEN
                    RAISE EXCEPTION 'COD adjustment order does not match the journal';
                END IF;
                IF NEW.plan_kind = 'return_case_followup'
                    AND p.reason_code NOT IN ('customer_return_reported', 'order_cancellation_reported')
                THEN RAISE EXCEPTION 'Invalid return or cancellation reason'; END IF;
                IF NEW.plan_kind = 'amount_correction'
                    AND p.reason_code NOT IN ('incorrect_amount', 'wrong_source_reference')
                THEN RAISE EXCEPTION 'Invalid amount correction reason'; END IF;
                IF NEW.plan_kind = 'reported_reversal'
                    AND p.reason_code <> 'bank_reversal_reported'
                THEN RAISE EXCEPTION 'Invalid bank reversal reason'; END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_adj_review_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p RECORD; x RECORD; d RECORD; v_payment TEXT;
            BEGIN
                SELECT * INTO p FROM checkout_cod_adjustment_plans
                WHERE id = NEW.plan_id;
                IF NOT FOUND OR NEW.reviewed_by = p.prepared_by THEN
                    RAISE EXCEPTION 'Independent adjustment reviewer required';
                END IF;
                SELECT r.*, e.source_kind, e.source_id, e.reported_by,
                       er.reviewed_by AS incident_reviewer,
                       pr.reviewed_by AS proposal_reviewer,
                       pr.decision AS proposal_decision
                INTO x
                FROM checkout_cod_exception_proposals r
                JOIN checkout_cod_financial_exceptions e ON e.id = r.exception_id
                JOIN checkout_cod_financial_exception_reviews er ON er.exception_id = e.id
                LEFT JOIN checkout_cod_exception_proposal_reviews pr ON pr.proposal_id = r.id
                WHERE r.id = p.resolution_proposal_id;
                PERFORM boardriders_cod_resolution_lock_exception(x.exception_id);
                IF NEW.reviewed_by IN (x.reported_by, x.incident_reviewer,
                    x.proposed_by, x.proposal_reviewer)
                    OR EXISTS (SELECT 1 FROM checkout_cod_exception_evidence
                        WHERE exception_id = x.exception_id AND recorded_by = NEW.reviewed_by)
                THEN
                    RAISE EXCEPTION 'Reviewer contributed to original investigation';
                END IF;
                SELECT j.prepared_by, jr.reviewed_by, c.compiled_by,
                       cr.reviewed_by AS coverage_reviewer
                INTO d FROM checkout_cod_journal_drafts j
                JOIN checkout_cod_journal_draft_reviews jr ON jr.draft_id = j.id
                JOIN checkout_cod_bank_coverage_packets c ON c.id = j.coverage_packet_id
                JOIN checkout_cod_bank_coverage_reviews cr ON cr.packet_id = c.id
                WHERE j.id = p.journal_draft_id;
                IF NEW.reviewed_by IN (d.prepared_by, d.reviewed_by,
                    d.compiled_by, d.coverage_reviewer) THEN
                    RAISE EXCEPTION 'Reviewer contributed to original journal';
                END IF;
                SELECT payment_status INTO v_payment
                FROM orders WHERE id = p.order_id FOR UPDATE;
                IF NEW.decision = 'retain_unexecuted' AND (
                    v_payment <> 'pending'
                    OR x.proposal_decision IS DISTINCT FROM 'ready_for_external_verification'
                    OR x.id <> (SELECT MAX(id) FROM checkout_cod_exception_proposals
                        WHERE exception_id = x.exception_id)
                    OR NOT boardriders_cod_financial_has_hold(x.source_kind, x.source_id)
                ) THEN
                    RAISE EXCEPTION 'Stale or unsafe COD adjustment review';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER cod_adj_plan_guard BEFORE INSERT ON checkout_cod_adjustment_plans FOR EACH ROW EXECUTE FUNCTION boardriders_cod_adj_plan_guard()');
        DB::statement('CREATE TRIGGER cod_adj_review_guard BEFORE INSERT ON checkout_cod_adjustment_plan_reviews FOR EACH ROW EXECUTE FUNCTION boardriders_cod_adj_review_guard()');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_adj_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'COD adjustment planning history is immutable'; END;
            $$
        SQL);
        foreach (['checkout_cod_adjustment_plans', 'checkout_cod_adjustment_plan_reviews'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION boardriders_cod_adj_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_adj_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_adjustment_plan_reviews');
        Schema::dropIfExists('checkout_cod_adjustment_plans');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_adj_review_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_adj_plan_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_adj_immutable()');
    }
};
