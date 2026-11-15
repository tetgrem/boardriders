<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_settlement_seals', function (Blueprint $t): void {
            $t->id();
            $t->uuid('seal_key')->unique('cod_seal50_key_once');
            $t->uuid('operation_key')->unique('cod_seal50_operation_once');
            $t->foreignId('posting_id')->unique('cod_seal50_posting_once')
                ->constrained('checkout_cod_bank_receipt_postings')->restrictOnDelete();
            $t->foreignId('capture_id')->unique('cod_seal50_capture_once')
                ->constrained('checkout_cod_novapay_register_captures')->restrictOnDelete();
            $t->unsignedInteger('orders_count');
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('fee_minor');
            $t->unsignedBigInteger('bank_minor');
            $t->jsonb('snapshot_json');
            $t->char('snapshot_hmac', 64);
            $t->unsignedBigInteger('sealed_by');
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_settlement_seal_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('seal_id')->constrained('checkout_cod_settlement_seals')->restrictOnDelete();
            $t->foreignId('register_line_id')->unique('cod_seal50_register_line_once')
                ->constrained('checkout_cod_novapay_register_lines')->restrictOnDelete();
            $t->foreignId('order_id')->unique('cod_seal50_order_once')
                ->constrained('orders')->restrictOnDelete();
            $t->char('shipment_hmac', 64)->unique('cod_seal50_shipment_once');
            $t->char('external_payment_hmac', 64)->unique('cod_seal50_external_once');
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('fee_minor');
            $t->unsignedBigInteger('net_minor');
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_settlement_seals
            ADD CONSTRAINT cod_seal50_header_values CHECK (
                orders_count BETWEEN 1 AND 1000
                AND gross_minor BETWEEN 1 AND 1000000000
                AND fee_minor <= gross_minor
                AND bank_minor > 0
                AND gross_minor = fee_minor + bank_minor
                AND sealed_by > 0
                AND snapshot_hmac ~ '^[a-f0-9]{64}$'
                AND jsonb_typeof(snapshot_json) = 'object'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_settlement_seal_lines
            ADD CONSTRAINT cod_seal50_line_values CHECK (
                gross_minor > 0 AND net_minor > 0
                AND gross_minor = fee_minor + net_minor
                AND shipment_hmac ~ '^[a-f0-9]{64}$'
                AND external_payment_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_seal50_header_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD;
            BEGIN
                SELECT p.source_kind, p.source_id, p.reference_hmac, p.amount_minor,
                       p.posted_by, p.journal_draft_id,
                       r.captured_by, r.orders_count, r.gross_minor,
                       r.fee_minor, r.bank_minor
                INTO v FROM checkout_cod_bank_receipt_postings p
                JOIN checkout_cod_novapay_register_captures r
                  ON r.bank_posting_id = p.id
                WHERE p.id = NEW.posting_id AND r.id = NEW.capture_id
                FOR UPDATE OF p;
                IF NOT FOUND OR NEW.orders_count <> v.orders_count
                   OR NEW.gross_minor <> v.gross_minor
                   OR NEW.fee_minor <> v.fee_minor
                   OR NEW.bank_minor <> v.bank_minor
                   OR NEW.bank_minor <> v.amount_minor
                   OR NEW.sealed_by IN (v.posted_by, v.captured_by)
                   OR (NEW.snapshot_json ->> 'schema_version')::int <> 1
                   OR (NEW.snapshot_json ->> 'bank_posting_id')::bigint <> NEW.posting_id
                   OR (NEW.snapshot_json ->> 'register_capture_id')::bigint <> NEW.capture_id
                   OR (NEW.snapshot_json -> 'totals' ->> 'orders_count')::bigint <> NEW.orders_count
                   OR (NEW.snapshot_json -> 'totals' ->> 'gross_minor')::bigint <> NEW.gross_minor
                   OR (NEW.snapshot_json -> 'totals' ->> 'fee_minor')::bigint <> NEW.fee_minor
                   OR (NEW.snapshot_json -> 'totals' ->> 'bank_minor')::bigint <> NEW.bank_minor
                   OR (NEW.snapshot_json ->> 'novapay_origin_authenticated') IS DISTINCT FROM 'false'
                   OR (NEW.snapshot_json ->> 'shipment_ownership_verified') IS DISTINCT FROM 'false'
                   OR (NEW.snapshot_json ->> 'customer_payment_confirmed') IS DISTINCT FROM 'false'
                   OR boardriders_cod_financial_has_hold(v.source_kind, v.source_id)
                   OR boardriders_cod_refetch_negative(v.source_kind, v.source_id)
                   OR EXISTS (
                      SELECT 1 FROM checkout_cod_bank_evidence_events e
                      WHERE e.reference_hmac = v.reference_hmac AND e.event_kind = 'reversal'
                   ) OR EXISTS (
                      SELECT 1 FROM checkout_cod_gateway_reports e
                      WHERE e.reference_hmac = v.reference_hmac AND e.event_kind = 'reversal_reported'
                   ) THEN
                    RAISE EXCEPTION 'COD settlement internal allocation cannot be sealed';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_seal50_header_guard
            BEFORE INSERT ON checkout_cod_settlement_seals
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_seal50_header_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_seal50_line_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD;
            BEGIN
                SELECT r.id AS register_line_id, r.order_id, r.shipment_hmac,
                       r.external_payment_hmac, r.gross_minor, r.fee_minor,
                       r.net_minor, o.payment_status, pc.payment_method,
                       s.goods_payable_minor, s.currency_code
                INTO v FROM checkout_cod_settlement_seals h
                JOIN checkout_cod_novapay_register_lines r
                  ON r.capture_id = h.capture_id
                JOIN orders o ON o.id = r.order_id
                JOIN checkout_payment_choices pc ON pc.order_id = o.id
                JOIN order_financial_snapshots s ON s.order_id = o.id
                WHERE h.id = NEW.seal_id AND r.id = NEW.register_line_id
                FOR UPDATE OF o;
                IF NOT FOUND OR v.order_id <> NEW.order_id
                   OR v.shipment_hmac <> NEW.shipment_hmac
                   OR v.external_payment_hmac <> NEW.external_payment_hmac
                   OR v.gross_minor <> NEW.gross_minor
                   OR v.fee_minor <> NEW.fee_minor
                   OR v.net_minor <> NEW.net_minor
                   OR v.payment_status <> 'pending'
                   OR v.payment_method <> 'cash_on_delivery'
                   OR v.currency_code <> 'UAH'
                   OR v.goods_payable_minor <> NEW.gross_minor
                   OR EXISTS (SELECT 1 FROM checkout_payment_capture_receipts e
                              WHERE e.order_id = NEW.order_id)
                   OR EXISTS (SELECT 1 FROM checkout_payment_capture_decisions e
                              WHERE e.order_id = NEW.order_id)
                   OR EXISTS (SELECT 1 FROM checkout_payment_attempts e
                              WHERE e.order_id = NEW.order_id)
                   THEN
                    RAISE EXCEPTION 'COD settlement allocation line not eligible';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_seal50_line_guard
            BEFORE INSERT ON checkout_cod_settlement_seal_lines
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_seal50_line_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_seal50_complete()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE n BIGINT; g BIGINT; f BIGINT; b BIGINT;
            BEGIN
                SELECT COUNT(*), COALESCE(SUM(gross_minor), 0),
                       COALESCE(SUM(fee_minor), 0), COALESCE(SUM(net_minor), 0)
                INTO n, g, f, b
                FROM checkout_cod_settlement_seal_lines
                WHERE seal_id = NEW.id;
                IF n <> NEW.orders_count OR g <> NEW.gross_minor
                   OR f <> NEW.fee_minor OR b <> NEW.bank_minor THEN
                    RAISE EXCEPTION 'Incomplete COD settlement allocation at commit';
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER cod_seal50_complete_at_commit
            AFTER INSERT ON checkout_cod_settlement_seals
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_seal50_complete()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_seal50_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD settlement allocation seal immutable';
            END;
            $$
        SQL);
        foreach (['checkout_cod_settlement_seals', 'checkout_cod_settlement_seal_lines'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_seal50_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_seal50_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_settlement_seal_lines');
        Schema::dropIfExists('checkout_cod_settlement_seals');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_seal50_header_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_seal50_line_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_seal50_complete()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_seal50_immutable()');
    }
};
