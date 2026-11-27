<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_crm_customer_events74', function (Blueprint $table): void {
            $table->id();

            $table->uuid('operation_key')->unique();

            $table->foreignId('customer_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('actor_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('kind', 24);

            $table->text('payload_ciphertext');

            $table->char('event_hmac', 64);

            $table->timestampTz('created_at', 6);

            $table->index(
                ['customer_id', 'kind', 'id'],
                'crm74_customer_kind_history_idx'
            );

            $table->index(
                ['customer_id', 'id'],
                'crm74_customer_history_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_crm_customer_events74
            ADD CONSTRAINT crm74_customer_event_shape CHECK (
                kind IN (
                    'contact',
                    'note',
                    'call',
                    'message',
                    'complaint',
                    'offer',
                    'view'
                )
                AND length(payload_ciphertext) > 0
                AND event_hmac ~ '^[0-9a-f]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_crm74_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'CRM74 customer history is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER crm74_customer_no_update_delete
            BEFORE UPDATE OR DELETE
            ON checkout_crm_customer_events74
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_crm74_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER crm74_customer_no_truncate
            BEFORE TRUNCATE
            ON checkout_crm_customer_events74
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_crm74_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX crm74_preparations_customer_idx
            ON checkout_order_preparations (user_id, order_id)
            WHERE user_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        if (DB::table('checkout_crm_customer_events74')->exists()) {
            throw new RuntimeException(
                'Cannot remove existing CRM74 customer history'
            );
        }

        DB::statement(
            'DROP INDEX IF EXISTS crm74_preparations_customer_idx'
        );

        Schema::dropIfExists('checkout_crm_customer_events74');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_crm74_immutable()'
        );
    }
};
