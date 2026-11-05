<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL is deliberate: older receipts and unbound external captures
        // must still be stored for audit and human review.
        Schema::table('checkout_payment_capture_receipts', function (Blueprint $table) {
            $table->string('provider_payment_id', 150)->nullable();
            $table->index(
                ['provider_code', 'provider_payment_id'],
                'checkout_capture_receipt_provider_intent_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_payment_capture_receipts
            ADD CONSTRAINT checkout_capture_provider_intent_integrity_check
            CHECK (
                (
                    provider_payment_id IS NULL
                    OR provider_payment_id ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$'
                )
                AND (
                    (capture_json::jsonb ->> 'provider_payment_id')
                    IS NOT DISTINCT FROM provider_payment_id
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_payment_capture_receipts
            DROP CONSTRAINT IF EXISTS checkout_capture_provider_intent_integrity_check
        SQL);

        Schema::table('checkout_payment_capture_receipts', function (Blueprint $table) {
            $table->dropIndex('checkout_capture_receipt_provider_intent_idx');
            $table->dropColumn('provider_payment_id');
        });
    }
};
