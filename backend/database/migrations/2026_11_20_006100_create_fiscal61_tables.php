<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_fiscal_documents61', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('capture_receipt_id')->constrained('checkout_payment_capture_receipts')->restrictOnDelete();
            $t->foreignId('sale_document_id')->nullable();
            $t->foreign('sale_document_id', 'fiscal61_sale_document_fk')
                ->references('id')->on('checkout_fiscal_documents61')->restrictOnDelete();
            $t->string('kind', 12);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency_code', 3);
            $t->longText('payload_json');
            $t->char('payload_sha256', 64);
            $t->char('source_sha256', 64);
            $t->unsignedBigInteger('prepared_by');
            $t->char('document_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['kind', 'source_id'], 'fiscal61_unique_source');
            $t->index(['order_id', 'id'], 'fiscal61_order_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_fiscal_documents61
            ADD CONSTRAINT fiscal61_doc_shape CHECK (
              kind IN ('sale','refund') AND amount_minor > 0
              AND currency_code = 'UAH' AND prepared_by > 0
              AND ((kind = 'sale' AND sale_document_id IS NULL AND source_id = capture_receipt_id)
                OR (kind = 'refund' AND sale_document_id IS NOT NULL))
            )
        SQL);

        Schema::create('checkout_fiscal_simulations61', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('document_id')->unique()
                ->constrained('checkout_fiscal_documents61')->restrictOnDelete();
            $t->char('input_sha256', 64);
            $t->char('output_sha256', 64);
            $t->longText('output_json');
            $t->unsignedBigInteger('simulated_by');
            $t->char('simulation_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_fiscal_incidents61', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('document_id')->constrained('checkout_fiscal_documents61')->restrictOnDelete();
            $t->string('reason_code', 48);
            $t->string('note', 500);
            $t->unsignedBigInteger('raised_by');
            $t->char('incident_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['document_id', 'id']);
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_fiscal61_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Fiscal61 records are append-only';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_fiscal61_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_TABLE_NAME = 'checkout_fiscal_documents61' THEN
                IF NOT EXISTS (
                  SELECT 1 FROM checkout_payment_capture_receipts r
                  JOIN orders o ON o.id = r.order_id
                  WHERE r.id = NEW.capture_receipt_id
                  AND r.order_id = NEW.order_id AND r.provider_code = 'mono'
                  AND r.currency_code = 'UAH' AND o.payment_status = 'paid'
                ) THEN RAISE EXCEPTION 'Fiscal61 requires paid Mono capture'; END IF;
                IF NEW.kind = 'refund' AND NOT EXISTS (
                  SELECT 1 FROM checkout_fiscal_documents61 s
                  JOIN checkout_mono_refund_journal56 j ON j.id = NEW.source_id
                  WHERE s.id = NEW.sale_document_id AND s.kind = 'sale'
                  AND s.order_id = NEW.order_id
                  AND s.capture_receipt_id = NEW.capture_receipt_id
                  AND j.order_id = NEW.order_id
                  AND j.capture_receipt_id = NEW.capture_receipt_id
                  AND j.amount_minor = NEW.amount_minor
                ) THEN RAISE EXCEPTION 'Fiscal61 refund/sale linkage mismatch'; END IF;
              END IF;
              RETURN NEW;
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER fiscal61_source_guard BEFORE INSERT ON checkout_fiscal_documents61 FOR EACH ROW EXECUTE FUNCTION boardriders_fiscal61_guard()');

        foreach (['checkout_fiscal_documents61', 'checkout_fiscal_simulations61', 'checkout_fiscal_incidents61'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION boardriders_fiscal61_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION boardriders_fiscal61_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_fiscal_incidents61');
        Schema::dropIfExists('checkout_fiscal_simulations61');
        Schema::dropIfExists('checkout_fiscal_documents61');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_fiscal61_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_fiscal61_immutable()');
    }
};
