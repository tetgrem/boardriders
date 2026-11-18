<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_order_return_cases', function (Blueprint $t): void {
            $t->id();
            $t->uuid('return_key')->unique();
            $t->uuid('operation_key')->unique();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('source_lifecycle_event_id')->nullable()
                ->constrained('checkout_cod_ttn_lifecycle_events')->restrictOnDelete();
            $t->string('reason_code', 32);
            $t->unsignedInteger('requested_qty');
            $t->char('financial_snapshot_sha256', 64);
            $t->char('request_hmac', 64);
            $t->unsignedBigInteger('opened_by');
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['order_id', 'id']);
        });

        Schema::create('checkout_order_return_units', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')->constrained('checkout_order_return_cases')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $t->unsignedSmallInteger('unit_index');
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('promotion_minor');
            $t->unsignedBigInteger('loyalty_minor');
            $t->unsignedBigInteger('cash_minor');
            $t->unique(['order_id', 'variant_id', 'unit_index'], 'cod_return52_one_claim_per_unit');
            $t->index(['case_id', 'id']);
        });

        Schema::create('checkout_order_return_inspections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')->unique()->constrained('checkout_order_return_cases')->restrictOnDelete();
            $t->uuid('operation_key')->unique();
            $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->char('receipt_reference_sha256', 64);
            $t->longText('items_json');
            $t->char('items_sha256', 64);
            $t->char('inspection_hmac', 64);
            $t->unsignedBigInteger('inspected_by');
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_order_return_decisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('case_id')->unique()->constrained('checkout_order_return_cases')->restrictOnDelete();
            $t->foreignId('inspection_id')->unique()->constrained('checkout_order_return_inspections')->restrictOnDelete();
            $t->uuid('operation_key')->unique();
            $t->string('decision', 16);
            $t->unsignedInteger('accepted_qty');
            $t->unsignedBigInteger('cash_candidate_minor');
            $t->unsignedBigInteger('loyalty_candidate_minor');
            $t->unsignedBigInteger('promotion_allocated_minor');
            $t->longText('decision_json');
            $t->char('decision_sha256', 64);
            $t->char('decision_hmac', 64);
            $t->unsignedBigInteger('decided_by');
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_order_return_cases ADD CONSTRAINT cod_return52_case_values CHECK (
                requested_qty BETWEEN 1 AND 20000 AND opened_by > 0
                AND reason_code IN ('customer_return','merchant_return','damaged','wrong_item',
                    'carrier_refusal','carrier_unclaimed','carrier_return')
                AND financial_snapshot_sha256 ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_order_return_units ADD CONSTRAINT cod_return52_unit_values CHECK (
                unit_index BETWEEN 1 AND 99
                AND gross_minor = promotion_minor + loyalty_minor + cash_minor
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_order_return_inspections ADD CONSTRAINT cod_return52_inspection_values CHECK (
                inspected_by > 0 AND items_json IS JSON ARRAY
                AND receipt_reference_sha256 ~ '^[a-f0-9]{64}$'
                AND items_sha256 ~ '^[a-f0-9]{64}$'
                AND items_sha256 = encode(sha256(convert_to(items_json, 'UTF8')), 'hex')
                AND inspection_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_order_return_decisions ADD CONSTRAINT cod_return52_decision_values CHECK (
                decided_by > 0 AND decision IN ('approved','rejected')
                AND decision_json IS JSON OBJECT
                AND decision_sha256 ~ '^[a-f0-9]{64}$'
                AND decision_sha256 = encode(sha256(convert_to(decision_json, 'UTF8')), 'hex')
                AND decision_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_return52_case_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD;
            BEGIN
                SELECT o.payment_status, s.snapshot_sha256 INTO v
                  FROM orders o JOIN order_financial_snapshots s ON s.order_id = o.id
                  JOIN checkout_order_preparations p ON p.order_id = o.id
                 WHERE o.id = NEW.order_id FOR UPDATE OF o;
                IF NOT FOUND OR v.payment_status NOT IN ('pending','review','paid')
                   OR v.snapshot_sha256 <> NEW.financial_snapshot_sha256
                   OR EXISTS (SELECT 1 FROM checkout_order_cancellations x WHERE x.order_id = NEW.order_id)
                THEN RAISE EXCEPTION 'Return requires existing noncanceled checkout'; END IF;
                IF NEW.reason_code IN ('carrier_refusal','carrier_unclaimed','carrier_return') THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM checkout_cod_ttn_lifecycle_events e
                        WHERE e.id = NEW.source_lifecycle_event_id AND e.order_id = NEW.order_id
                          AND e.effective_state = CASE NEW.reason_code
                            WHEN 'carrier_refusal' THEN 'refused'
                            WHEN 'carrier_unclaimed' THEN 'unclaimed'
                            ELSE 'return_requested' END
                    ) THEN RAISE EXCEPTION 'Return carrier reason requires matching observed event'; END IF;
                ELSIF NEW.source_lifecycle_event_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Manual return cannot attach carrier reason evidence';
                END IF;
                RETURN NEW;
            END; $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_return52_case_guard BEFORE INSERT ON checkout_order_return_cases
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_return52_case_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_return52_unit_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD; u JSONB;
            BEGIN
                SELECT c.order_id INTO v FROM checkout_order_return_cases c
                  WHERE c.id = NEW.case_id;
                IF NOT FOUND OR v.order_id <> NEW.order_id THEN
                    RAISE EXCEPTION 'Return unit belongs to another order';
                END IF;
                SELECT x.item INTO u
                  FROM order_financial_snapshots s
                  CROSS JOIN LATERAL jsonb_array_elements(s.snapshot_json::jsonb -> 'lines') l(line)
                  CROSS JOIN LATERAL jsonb_array_elements(l.line -> 'units') x(item)
                 WHERE s.order_id = NEW.order_id
                   AND (l.line ->> 'variant_id')::bigint = NEW.variant_id
                   AND (x.item ->> 'unit_index')::int = NEW.unit_index;
                IF u IS NULL OR (u ->> 'gross_minor')::bigint <> NEW.gross_minor
                   OR (u ->> 'promotion_discount_minor')::bigint <> NEW.promotion_minor
                   OR (u ->> 'loyalty_discount_minor')::bigint <> NEW.loyalty_minor
                   OR (u ->> 'cash_minor')::bigint <> NEW.cash_minor THEN
                    RAISE EXCEPTION 'Return unit is not from immutable checkout prices';
                END IF;
                RETURN NEW;
            END; $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_return52_unit_guard BEFORE INSERT ON checkout_order_return_units
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_return52_unit_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_return52_case_complete()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT COUNT(*) FROM checkout_order_return_units WHERE case_id = NEW.id) <> NEW.requested_qty
                THEN RAISE EXCEPTION 'Return claim has incomplete units'; END IF;
                RETURN NULL;
            END; $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER cod_return52_complete_at_commit
            AFTER INSERT ON checkout_order_return_cases DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_return52_case_complete()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_return52_inspection_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE opener BIGINT; expected_count BIGINT; seen_count BIGINT; unique_count BIGINT;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM warehouses w WHERE w.id = NEW.warehouse_id AND w.kind = 'owned' AND w.is_active = TRUE) THEN
                    RAISE EXCEPTION 'Return inspection requires active owned receiving warehouse';
                END IF;
                SELECT c.opened_by, c.requested_qty INTO opener, expected_count
                  FROM checkout_order_return_cases c WHERE c.id = NEW.case_id;
                IF NOT FOUND OR NEW.inspected_by = opener THEN
                    RAISE EXCEPTION 'Return inspection requires independent warehouse employee';
                END IF;
                SELECT COUNT(*), COUNT(DISTINCT x.return_unit_id) INTO seen_count, unique_count
                  FROM jsonb_to_recordset(NEW.items_json::jsonb) AS x(return_unit_id bigint, condition text)
                  JOIN checkout_order_return_units u ON u.id = x.return_unit_id
                 WHERE u.case_id = NEW.case_id
                   AND x.condition IN ('resellable','damaged','not_received');
                IF seen_count <> expected_count OR unique_count <> expected_count
                   OR jsonb_array_length(NEW.items_json::jsonb) <> expected_count THEN
                    RAISE EXCEPTION 'Return inspection must cover each unit exactly once';
                END IF;
                RETURN NEW;
            END; $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_return52_inspection_guard BEFORE INSERT ON checkout_order_return_inspections
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_return52_inspection_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_return52_decision_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v RECORD; accepted BIGINT; cash BIGINT; loyalty BIGINT; promo BIGINT;
            BEGIN
                SELECT c.opened_by, i.inspected_by, i.case_id INTO v
                  FROM checkout_order_return_inspections i
                  JOIN checkout_order_return_cases c ON c.id = i.case_id
                 WHERE i.id = NEW.inspection_id;
                IF NOT FOUND OR v.case_id <> NEW.case_id
                   OR NEW.decided_by IN (v.opened_by, v.inspected_by) THEN
                    RAISE EXCEPTION 'Return decision requires independent reviewer';
                END IF;
                SELECT COUNT(*), COALESCE(SUM(u.cash_minor),0),
                       COALESCE(SUM(u.loyalty_minor),0), COALESCE(SUM(u.promotion_minor),0)
                  INTO accepted, cash, loyalty, promo
                  FROM checkout_order_return_inspections i
                  CROSS JOIN LATERAL jsonb_to_recordset(i.items_json::jsonb)
                       AS x(return_unit_id bigint, condition text)
                  JOIN checkout_order_return_units u ON u.id = x.return_unit_id
                 WHERE i.id = NEW.inspection_id AND u.case_id = NEW.case_id
                   AND x.condition IN ('resellable','damaged');
                IF NEW.decision = 'approved' THEN
                    IF accepted = 0 OR accepted <> NEW.accepted_qty
                       OR cash <> NEW.cash_candidate_minor
                       OR loyalty <> NEW.loyalty_candidate_minor
                       OR promo <> NEW.promotion_allocated_minor THEN
                        RAISE EXCEPTION 'Return review approval amounts inconsistent';
                    END IF;
                ELSIF NEW.accepted_qty <> 0 OR NEW.cash_candidate_minor <> 0
                   OR NEW.loyalty_candidate_minor <> 0 OR NEW.promotion_allocated_minor <> 0 THEN
                    RAISE EXCEPTION 'Rejected return cannot authorize refund amounts';
                END IF;
                RETURN NEW;
            END; $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_return52_decision_guard BEFORE INSERT ON checkout_order_return_decisions
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_return52_decision_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_return52_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Return workflow evidence is immutable'; END; $$
        SQL);
        foreach (['checkout_order_return_cases','checkout_order_return_units',
                     'checkout_order_return_inspections','checkout_order_return_decisions'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_return52_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_return52_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_order_return_decisions');
        Schema::dropIfExists('checkout_order_return_inspections');
        Schema::dropIfExists('checkout_order_return_units');
        Schema::dropIfExists('checkout_order_return_cases');
        foreach (['case_guard','unit_guard','case_complete','inspection_guard','decision_guard','immutable'] as $name) {
            DB::statement("DROP FUNCTION IF EXISTS boardriders_cod_return52_{$name}()");
        }
    }
};
