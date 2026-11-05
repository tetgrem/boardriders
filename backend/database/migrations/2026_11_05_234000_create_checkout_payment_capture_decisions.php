<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_payment_capture_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->unique()
                ->constrained('checkout_payment_capture_receipts')->restrictOnDelete();
            $table->foreignId('order_id')
                ->constrained('orders')->restrictOnDelete();
            $table->string('decision', 12);
            $table->string('reason_code', 48);
            $table->foreignId('promotion_reservation_id')->nullable()
                ->constrained('promotion_reservations')->restrictOnDelete();
            $table->foreignId('loyalty_reservation_id')->nullable()
                ->constrained('loyalty_reservations')->restrictOnDelete();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['order_id', 'created_at'], 'checkout_capture_decisions_order_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_payment_capture_decisions
            ADD CONSTRAINT checkout_capture_decisions_valid_check
            CHECK (
                (decision = 'paid' AND reason_code = 'accepted')
                OR (decision = 'review' AND reason_code <> 'accepted')
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX checkout_capture_decisions_one_paid_order
            ON checkout_payment_capture_decisions (order_id)
            WHERE decision = 'paid'
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_payment_decision_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'payment capture decisions are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_capture_decisions_no_update_delete
            BEFORE UPDATE OR DELETE ON checkout_payment_capture_decisions
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_block_payment_decision_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_capture_decisions_no_truncate
            BEFORE TRUNCATE ON checkout_payment_capture_decisions
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_block_payment_decision_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_payment_capture_decisions');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_block_payment_decision_mutation()');
    }
};
