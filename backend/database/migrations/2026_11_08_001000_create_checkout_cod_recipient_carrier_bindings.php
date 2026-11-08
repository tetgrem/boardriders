<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_recipient_carrier_bindings', function (Blueprint $table) {
            $table->id();
            $table->uuid('binding_key')->unique();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('draft_id')->unique()
                ->constrained('checkout_cod_waybill_drafts')->restrictOnDelete();
            $table->foreignId('recipient_id')->unique()
                ->constrained('checkout_cod_recipients')->restrictOnDelete();
            $table->text('counterparty_ref_ciphertext');
            $table->text('contact_ref_ciphertext');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_recipient_carrier_bindings
            ADD CONSTRAINT cod_recipient_carrier_binding_values_check CHECK (
                length(counterparty_ref_ciphertext) > 24
                AND length(contact_ref_ciphertext) > 24
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_recipient_carrier_binding_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE context_row RECORD;
            BEGIN
                SELECT d.order_id, d.recipient_id, o.payment_status,
                       p.expires_at, s.checkout_expires_at AS selection_expires_at
                INTO context_row
                FROM checkout_cod_waybill_drafts d
                JOIN orders o ON o.id = d.order_id
                JOIN checkout_order_preparations p ON p.order_id = d.order_id
                JOIN checkout_cod_delivery_selections s ON s.id = d.selection_id
                WHERE d.id = NEW.draft_id;

                IF NOT FOUND
                   OR context_row.order_id <> NEW.order_id
                   OR context_row.recipient_id <> NEW.recipient_id
                   OR context_row.payment_status <> 'pending'
                   OR context_row.expires_at <= CURRENT_TIMESTAMP
                   OR context_row.selection_expires_at <= CURRENT_TIMESTAMP
                THEN
                    RAISE EXCEPTION 'COD recipient carrier binding: invalid source';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_recipient_carrier_binding_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD recipient carrier binding is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_recipient_carrier_binding_insert_guard
            BEFORE INSERT ON checkout_cod_recipient_carrier_bindings
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_recipient_carrier_binding_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_recipient_carrier_binding_immutable
            BEFORE UPDATE OR DELETE ON checkout_cod_recipient_carrier_bindings
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_recipient_carrier_binding_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_recipient_carrier_binding_no_truncate
            BEFORE TRUNCATE ON checkout_cod_recipient_carrier_bindings
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_recipient_carrier_binding_immutable()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_recipient_carrier_bindings');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_recipient_carrier_binding_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_recipient_carrier_binding_immutable()');
    }
};
