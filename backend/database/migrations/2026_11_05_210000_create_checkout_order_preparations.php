<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_order_preparations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('financial_snapshot_id')->unique()
                ->constrained('order_financial_snapshots')->restrictOnDelete();
            $table->foreignId('market_id')
                ->constrained('markets')->restrictOnDelete();
            $table->foreignId('price_list_id')
                ->constrained('price_lists')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('loyalty_wallet_id')->nullable()
                ->constrained('loyalty_wallets')->restrictOnDelete();
            $table->foreignId('loyalty_reservation_id')->nullable()
                ->constrained('loyalty_reservations')->restrictOnDelete();
            $table->bigInteger('bonus_units_reserved')->default(0);
            $table->char('request_sha256', 64);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_order_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_preparation_id')
                ->constrained('checkout_order_preparations')->restrictOnDelete();
            $table->foreignId('variant_id')
                ->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('inventory_reservation_id')->unique()
                ->constrained('inventory_reservations')->restrictOnDelete();
            $table->unsignedBigInteger('quantity');
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(
                ['checkout_preparation_id', 'variant_id'],
                'checkout_stock_preparation_variant_unique'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_order_preparations
            ADD CONSTRAINT checkout_preparations_valid_check
            CHECK (
                request_sha256 ~ '^[0-9a-f]{64}$'
                AND bonus_units_reserved >= 0
                AND (
                    (bonus_units_reserved = 0
                        AND loyalty_wallet_id IS NULL
                        AND loyalty_reservation_id IS NULL)
                    OR
                    (bonus_units_reserved > 0
                        AND loyalty_wallet_id IS NOT NULL
                        AND loyalty_reservation_id IS NOT NULL)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_order_stock_reservations
            ADD CONSTRAINT checkout_stock_positive_quantity_check
            CHECK (quantity > 0)
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_checkout_preparation_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'checkout preparation history is immutable';
            END;
            $$
        SQL);

        foreach (['checkout_order_preparations', 'checkout_order_stock_reservations'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_no_update_delete
                BEFORE UPDATE OR DELETE ON {$name}
                FOR EACH ROW EXECUTE FUNCTION boardriders_block_checkout_preparation_mutation()");
            DB::statement("CREATE TRIGGER {$name}_no_truncate
                BEFORE TRUNCATE ON {$name}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_block_checkout_preparation_mutation()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_order_stock_reservations');
        Schema::dropIfExists('checkout_order_preparations');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_block_checkout_preparation_mutation()');
    }
};
