<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_cod_delivery_selections', function (Blueprint $table) {
            $table->id();
            $table->uuid('selection_key')->unique();

            $table->unsignedBigInteger('order_id')->unique();
            $table->foreign('order_id')
                ->references('order_id')
                ->on('checkout_cod_requests')
                ->restrictOnDelete();

            $table->foreignId('cod_request_id')->unique()
                ->constrained('checkout_cod_requests')
                ->restrictOnDelete();

            $table->string('carrier_code', 32);
            $table->string('delivery_type', 24);
            $table->uuid('destination_ref');

            $table->unsignedBigInteger('goods_due_minor');
            $table->char('currency_code', 3);
            $table->foreign('currency_code')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->char('snapshot_sha256', 64);
            $table->timestampTz('checkout_expires_at', 6);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_delivery_selections
            ADD CONSTRAINT checkout_cod_delivery_selections_valid_check
            CHECK (
                carrier_code = 'nova_poshta'
                AND delivery_type IN ('branch', 'parcel_locker')
                AND goods_due_minor >= 0
                AND snapshot_sha256 ~ '^[0-9a-f]{64}$'
                AND checkout_expires_at > created_at
            )
        SQL);

        // Direct SQL must not attach an unrelated COD request, snapshot or
        // expired/released stock. It is still NOT a carrier verification.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_validate_cod_delivery_selection()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1
                FROM orders o
                JOIN checkout_order_preparations p ON p.order_id = o.id
                JOIN checkout_cod_requests r ON r.order_id = o.id
                JOIN checkout_payment_choices c ON c.id = r.payment_choice_id
                JOIN order_financial_snapshots s ON s.id = p.financial_snapshot_id
                WHERE o.id = NEW.order_id
                    AND o.payment_status = 'pending'
                    AND r.id = NEW.cod_request_id
                    AND c.order_id = o.id
                    AND c.payment_method = 'cash_on_delivery'
                    AND c.provider_code IS NULL
                    AND r.financial_snapshot_id = s.id
                    AND r.goods_due_minor = s.goods_payable_minor
                    AND r.currency_code = s.currency_code
                    AND r.snapshot_sha256 = s.snapshot_sha256
                    AND NEW.goods_due_minor = r.goods_due_minor
                    AND NEW.currency_code = r.currency_code
                    AND NEW.snapshot_sha256 = r.snapshot_sha256
                    AND NEW.checkout_expires_at = p.expires_at
                    AND r.checkout_expires_at = p.expires_at
                    AND p.expires_at > CURRENT_TIMESTAMP
                    AND NOT EXISTS (
                        SELECT 1 FROM checkout_payment_attempts a
                        WHERE a.order_id = o.id
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM checkout_payment_capture_receipts cr
                        WHERE cr.order_id = o.id
                    )
                    AND (
                        SELECT COUNT(*)
                        FROM checkout_order_stock_reservations sr
                        WHERE sr.checkout_preparation_id = p.id
                    ) > 0
                    AND (
                        SELECT COUNT(*)
                        FROM checkout_order_stock_reservations sr
                        WHERE sr.checkout_preparation_id = p.id
                    ) = (
                        SELECT COUNT(*)
                        FROM inventory_reservations ir
                        WHERE ir.order_id = o.id
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM checkout_order_stock_reservations sr
                        LEFT JOIN inventory_reservations ir
                            ON ir.id = sr.inventory_reservation_id
                        LEFT JOIN inventory_stocks st
                            ON st.id = ir.inventory_stock_id
                        WHERE sr.checkout_preparation_id = p.id
                            AND (
                                ir.id IS NULL
                                OR ir.order_id IS DISTINCT FROM o.id
                                OR ir.quantity <> sr.quantity
                                OR ir.shipped_qty <> 0
                                OR ir.released_qty <> 0
                                OR ir.expires_at IS NULL
                                OR ir.expires_at <= CURRENT_TIMESTAMP
                                OR st.id IS NULL
                                OR st.reserved_qty < sr.quantity
                                OR st.on_hand_qty < sr.quantity
                            )
                    )
                FOR UPDATE OF o;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'COD delivery selection requires a live, consistent COD checkout';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_cod_delivery_selections_validate_insert
            BEFORE INSERT ON checkout_cod_delivery_selections
            FOR EACH ROW EXECUTE FUNCTION boardriders_validate_cod_delivery_selection()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_cod_delivery_selection_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD delivery selections are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_cod_delivery_selections_no_update_delete
            BEFORE UPDATE OR DELETE ON checkout_cod_delivery_selections
            FOR EACH ROW EXECUTE FUNCTION boardriders_block_cod_delivery_selection_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_cod_delivery_selections_no_truncate
            BEFORE TRUNCATE ON checkout_cod_delivery_selections
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_block_cod_delivery_selection_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_delivery_selections');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_validate_cod_delivery_selection()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_block_cod_delivery_selection_mutation()');
    }
};
