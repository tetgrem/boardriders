<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_payment_capture_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('orders')->restrictOnDelete();

            $table->string('provider_code', 32);
            $table->string('provider_event_id', 150);
            $table->string('provider_capture_id', 150);

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->foreign('currency_code')
                ->references('code')->on('currencies')->restrictOnDelete();

            // Час фактичного capture, НЕ час приходу webhook.
            $table->timestampTz('captured_at', 6);
            $table->longText('capture_json');
            $table->char('capture_sha256', 64);
            $table->timestampTz('received_at', 6)->useCurrent();

            $table->unique(
                ['provider_code', 'provider_event_id'],
                'checkout_capture_provider_event_unique'
            );
            $table->unique(
                ['provider_code', 'provider_capture_id'],
                'checkout_capture_provider_payment_unique'
            );
            $table->index(['order_id', 'received_at'], 'checkout_capture_order_lookup_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_payment_capture_receipts
            ADD CONSTRAINT checkout_capture_receipt_integrity_check
            CHECK (
                amount_minor > 0
                AND provider_code ~ '^[a-z][a-z0-9_]{1,31}$'
                AND provider_event_id ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$'
                AND provider_capture_id ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$'
                AND capture_json IS JSON OBJECT
                AND capture_sha256 ~ '^[0-9a-f]{64}$'
                AND capture_sha256 = encode(
                    sha256(convert_to(capture_json, 'UTF8')), 'hex'
                )
                AND capture_json::jsonb ->> 'provider_code' = provider_code
                AND capture_json::jsonb ->> 'provider_event_id' = provider_event_id
                AND capture_json::jsonb ->> 'provider_capture_id' = provider_capture_id
                AND capture_json::jsonb ->> 'currency_code' = currency_code
                AND (capture_json::jsonb ->> 'amount_minor')::bigint = amount_minor
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_capture_receipt_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'payment capture receipts are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_capture_receipts_no_update_delete
            BEFORE UPDATE OR DELETE ON checkout_payment_capture_receipts
            FOR EACH ROW EXECUTE FUNCTION boardriders_block_capture_receipt_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_capture_receipts_no_truncate
            BEFORE TRUNCATE ON checkout_payment_capture_receipts
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_block_capture_receipt_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_payment_capture_receipts');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_block_capture_receipt_mutation()');
    }
};
