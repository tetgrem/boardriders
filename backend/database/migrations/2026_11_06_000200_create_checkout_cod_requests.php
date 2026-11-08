<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_cod_requests', function (Blueprint $table) {
            $table->id();

            $table->uuid('request_key')->unique();

            $table->unsignedBigInteger('order_id')->unique();
            $table->foreign('order_id')
                ->references('order_id')
                ->on('checkout_order_preparations')
                ->restrictOnDelete();

            $table->foreignId('payment_choice_id')->unique()
                ->constrained('checkout_payment_choices')
                ->restrictOnDelete();

            $table->foreignId('financial_snapshot_id')->unique()
                ->constrained('order_financial_snapshots')
                ->restrictOnDelete();

            // Вартість товарів після знижок.
            // Доставка та післяплата тут ще не розраховані.
            $table->unsignedBigInteger('goods_due_minor');

            $table->char('currency_code', 3);
            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->char('snapshot_sha256', 64);

            $table->timestampTz('checkout_expires_at', 6);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_requests
            ADD CONSTRAINT checkout_cod_requests_valid_check
            CHECK (
                snapshot_sha256 ~ '^[0-9a-f]{64}$'
                AND checkout_expires_at > created_at
            )
        SQL);

        // Захист від створення COD-заявки на оплачений,
        // скасований або чужий checkout навіть через прямий SQL.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_validate_checkout_cod_request()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                PERFORM 1
                FROM orders o
                JOIN checkout_order_preparations p
                    ON p.order_id = o.id
                JOIN checkout_payment_choices c
                    ON c.order_id = o.id
                JOIN order_financial_snapshots s
                    ON s.id = p.financial_snapshot_id
                WHERE o.id = NEW.order_id
                    AND o.payment_status = 'pending'
                    AND c.id = NEW.payment_choice_id
                    AND c.payment_method = 'cash_on_delivery'
                    AND c.provider_code IS NULL
                    AND s.id = NEW.financial_snapshot_id
                    AND s.goods_payable_minor = NEW.goods_due_minor
                    AND s.currency_code = NEW.currency_code
                    AND s.snapshot_sha256 = NEW.snapshot_sha256
                    AND p.expires_at = NEW.checkout_expires_at
                    AND p.expires_at > CURRENT_TIMESTAMP
                    AND NOT EXISTS (
                        SELECT 1
                        FROM checkout_payment_attempts a
                        WHERE a.order_id = o.id
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM checkout_payment_capture_receipts r
                        WHERE r.order_id = o.id
                    )
                FOR UPDATE OF o;

                IF NOT FOUND THEN
                    RAISE EXCEPTION
                        'COD request requires a valid unpaid checkout';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_cod_requests_validate_insert
            BEFORE INSERT ON checkout_cod_requests
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_validate_checkout_cod_request()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_cod_request_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'COD requests are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_cod_requests_no_update_delete
            BEFORE UPDATE OR DELETE ON checkout_cod_requests
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_block_cod_request_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_cod_requests_no_truncate
            BEFORE TRUNCATE ON checkout_cod_requests
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_block_cod_request_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_requests');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_validate_checkout_cod_request()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_block_cod_request_mutation()'
        );
    }
};
