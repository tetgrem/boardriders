<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('attempt_key')->unique();
            // v1: один платіжний намір на одне підготовлене замовлення.
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('financial_snapshot_id')->unique()
                ->constrained('order_financial_snapshots')->restrictOnDelete();
            $table->string('provider_code', 32);
            $table->string('provider_payment_id', 150);
            $table->unique(['provider_code', 'provider_payment_id'],
                'checkout_payment_attempt_provider_payment_unique');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')
                ->on('currencies')->restrictOnDelete();
            $table->char('financial_snapshot_sha256', 64);
            $table->longText('request_json');
            $table->char('request_sha256', 64);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        // SQL перевіряє SHA точних JSON-байтів та їх відповідність колонкам.
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_payment_attempts
            ADD CONSTRAINT checkout_payment_attempt_integrity_check
            CHECK (
                amount_minor > 0
                AND provider_code ~ '^[a-z][a-z0-9_]{1,31}$'
                AND provider_payment_id ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$'
                AND financial_snapshot_sha256 ~ '^[0-9a-f]{64}$'
                AND request_sha256 ~ '^[0-9a-f]{64}$'
                AND expires_at > created_at
                AND request_json IS JSON OBJECT
                AND request_sha256 = encode(sha256(convert_to(request_json, 'UTF8')), 'hex')
                AND (request_json::jsonb ->> 'schema_version')::integer = 1
                AND (request_json::jsonb ->> 'attempt_key')::uuid = attempt_key
                AND (request_json::jsonb ->> 'order_id')::bigint = order_id
                AND (request_json::jsonb ->> 'financial_snapshot_id')::bigint = financial_snapshot_id
                AND (request_json::jsonb ->> 'provider_code') = provider_code
                AND (request_json::jsonb ->> 'provider_payment_id') = provider_payment_id
                AND (request_json::jsonb ->> 'amount_minor')::bigint = amount_minor
                AND (request_json::jsonb ->> 'currency_code') = currency_code
                AND (request_json::jsonb ->> 'financial_snapshot_sha256') = financial_snapshot_sha256
                AND (request_json::jsonb ->> 'expires_at')::timestamptz = expires_at
            )
        SQL);

        // Не дозволяємо прив'язати чужий snapshot навіть через прямий SQL.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_validate_checkout_payment_attempt()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1
                FROM orders o
                JOIN checkout_order_preparations p ON p.order_id = o.id
                JOIN order_financial_snapshots s ON s.id = p.financial_snapshot_id
                WHERE o.id = NEW.order_id
                    AND o.order_key::text = (NEW.request_json::jsonb ->> 'order_key')
                    AND o.payment_status = 'pending'
                    AND p.financial_snapshot_id = NEW.financial_snapshot_id
                    AND p.expires_at >= NEW.expires_at
                    AND p.expires_at > CURRENT_TIMESTAMP
                    AND s.goods_payable_minor = NEW.amount_minor
                    AND s.currency_code = NEW.currency_code
                    AND s.snapshot_sha256 = NEW.financial_snapshot_sha256
                    AND NOT EXISTS (
                        SELECT 1 FROM checkout_payment_capture_receipts r
                        WHERE r.order_id = o.id
                    )
                FOR SHARE OF o;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'payment attempt must match a live pending checkout snapshot';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_payment_attempts_validate_insert
            BEFORE INSERT ON checkout_payment_attempts
            FOR EACH ROW EXECUTE FUNCTION boardriders_validate_checkout_payment_attempt()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_checkout_payment_attempt_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'checkout payment attempts are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_payment_attempts_no_update_delete
            BEFORE UPDATE OR DELETE ON checkout_payment_attempts
            FOR EACH ROW EXECUTE FUNCTION boardriders_block_checkout_payment_attempt_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_payment_attempts_no_truncate
            BEFORE TRUNCATE ON checkout_payment_attempts
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_block_checkout_payment_attempt_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_payment_attempts');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_validate_checkout_payment_attempt()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_block_checkout_payment_attempt_mutation()');
    }
};
