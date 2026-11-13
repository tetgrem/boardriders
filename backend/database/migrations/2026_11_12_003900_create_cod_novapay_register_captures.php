<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_novapay_register_captures', function (Blueprint $t): void {
            $t->id();
            $t->uuid('operation_key')->unique();
            $t->unsignedBigInteger('bank_posting_id')->unique('cod_npr_posting_once');
            $t->foreign('bank_posting_id', 'cod_npr_posting_fk')
                ->references('id')->on('checkout_cod_bank_receipt_postings')->restrictOnDelete();
            $t->string('source_kind', 24);
            $t->char('document_sha256', 64);
            $t->unsignedInteger('orders_count');
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('fee_minor');
            $t->unsignedBigInteger('bank_minor');
            $t->char('snapshot_hmac', 64);
            $t->unsignedBigInteger('captured_by');
            $t->char('request_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_novapay_register_lines', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('capture_id');
            $t->foreign('capture_id', 'cod_npr_line_capture_fk')
                ->references('id')->on('checkout_cod_novapay_register_captures')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->char('shipment_hmac', 64);
            $t->char('external_payment_hmac', 64);
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('fee_minor');
            $t->unsignedBigInteger('net_minor');
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique('order_id', 'cod_npr_order_once');
            $t->unique('shipment_hmac', 'cod_npr_shipment_once');
            $t->unique('external_payment_hmac', 'cod_npr_external_payment_once');
            $t->index(['capture_id', 'id'], 'cod_npr_capture_lines');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_novapay_register_captures
            ADD CONSTRAINT cod_npr_capture_values CHECK (
                source_kind = 'operator_extract'
                AND document_sha256 ~ '^[a-f0-9]{64}$'
                AND orders_count BETWEEN 1 AND 1000
                AND gross_minor BETWEEN 1 AND 1000000000
                AND fee_minor BETWEEN 0 AND 1000000000
                AND bank_minor BETWEEN 1 AND 100000000
                AND gross_minor = fee_minor + bank_minor
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
                AND captured_by > 0
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_novapay_register_lines
            ADD CONSTRAINT cod_npr_line_values CHECK (
                shipment_hmac ~ '^[a-f0-9]{64}$'
                AND external_payment_hmac ~ '^[a-f0-9]{64}$'
                AND gross_minor BETWEEN 1 AND 100000000
                AND fee_minor BETWEEN 0 AND 100000000
                AND net_minor BETWEEN 1 AND 100000000
                AND gross_minor = fee_minor + net_minor
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_npr_capture_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v RECORD;
            BEGIN
                SELECT p.id, p.source_kind, p.source_id, p.reference_hmac,
                       p.amount_minor, p.posted_by,
                       d.orders_count, d.gross_minor, d.fee_minor, d.bank_minor
                INTO v
                FROM checkout_cod_bank_receipt_postings AS p
                JOIN checkout_cod_journal_drafts AS d ON d.id = p.journal_draft_id
                WHERE p.id = NEW.bank_posting_id
                FOR UPDATE OF p;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Booked COD bank posting missing';
                END IF;
                PERFORM 1 FROM checkout_cod_bank_reference_claims AS c
                WHERE c.reference_hmac = v.reference_hmac FOR UPDATE;
                IF NEW.captured_by = v.posted_by
                    OR NEW.orders_count <> v.orders_count
                    OR NEW.gross_minor <> v.gross_minor
                    OR NEW.fee_minor <> v.fee_minor
                    OR NEW.bank_minor <> v.bank_minor
                    OR NEW.bank_minor <> v.amount_minor
                    OR boardriders_cod_financial_has_hold(v.source_kind, v.source_id)
                    OR boardriders_cod_refetch_negative(v.source_kind, v.source_id)
                    OR EXISTS (
                        SELECT 1 FROM checkout_cod_bank_evidence_events AS e
                        WHERE e.reference_hmac = v.reference_hmac
                          AND e.event_kind = 'reversal'
                    ) OR EXISTS (
                        SELECT 1 FROM checkout_cod_gateway_reports AS e
                        WHERE e.reference_hmac = v.reference_hmac
                          AND e.event_kind = 'reversal_reported'
                    )
                THEN
                    RAISE EXCEPTION 'NovaPay extract cannot be tied to disputed bank credit';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_npr_line_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v RECORD;
            BEGIN
                SELECT l.gross_minor, l.fee_minor, l.allocated_minor,
                       o.payment_status, pc.payment_method
                INTO v
                FROM checkout_cod_novapay_register_captures AS r
                JOIN checkout_cod_bank_receipt_postings AS p
                    ON p.id = r.bank_posting_id
                JOIN checkout_cod_journal_drafts AS d
                    ON d.id = p.journal_draft_id
                JOIN checkout_cod_bank_coverage_lines AS l
                    ON l.packet_id = d.coverage_packet_id AND l.order_id = NEW.order_id
                JOIN orders AS o ON o.id = l.order_id
                JOIN checkout_payment_choices AS pc ON pc.order_id = o.id
                WHERE r.id = NEW.capture_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'NovaPay extract order not in posted bank coverage';
                END IF;
                IF v.gross_minor <> NEW.gross_minor
                    OR v.fee_minor <> NEW.fee_minor
                    OR v.allocated_minor <> NEW.net_minor
                    OR v.payment_status <> 'pending'
                    OR v.payment_method <> 'cash_on_delivery'
                THEN
                    RAISE EXCEPTION 'NovaPay extract order allocation differs';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_npr_complete_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_count BIGINT;
                v_gross BIGINT;
                v_fee BIGINT;
                v_bank BIGINT;
            BEGIN
                SELECT COUNT(*),
                       COALESCE(SUM(gross_minor), 0),
                       COALESCE(SUM(fee_minor), 0),
                       COALESCE(SUM(net_minor), 0)
                INTO v_count, v_gross, v_fee, v_bank
                FROM checkout_cod_novapay_register_lines
                WHERE capture_id = NEW.id;
                IF v_count <> NEW.orders_count
                    OR v_gross <> NEW.gross_minor
                    OR v_fee <> NEW.fee_minor
                    OR v_bank <> NEW.bank_minor
                THEN
                    RAISE EXCEPTION 'NovaPay extract incomplete at transaction boundary';
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_npr_capture_guard
            BEFORE INSERT ON checkout_cod_novapay_register_captures
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_npr_capture_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_npr_line_guard
            BEFORE INSERT ON checkout_cod_novapay_register_lines
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_npr_line_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER cod_npr_complete_at_commit
            AFTER INSERT ON checkout_cod_novapay_register_captures
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_npr_complete_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_npr_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'NovaPay afterpayment register evidence is immutable';
            END;
            $$
        SQL);
        foreach ([
                     'checkout_cod_novapay_register_lines',
                     'checkout_cod_novapay_register_captures',
                 ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_npr_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_npr_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_novapay_register_lines');
        Schema::dropIfExists('checkout_cod_novapay_register_captures');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_npr_capture_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_npr_line_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_npr_complete_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_npr_immutable()');
    }
};
