<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_carrier_contact_observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('draft_id')->constrained('checkout_cod_waybill_drafts')->restrictOnDelete();
            $table->foreignId('binding_id')->constrained('checkout_cod_recipient_carrier_bindings')->restrictOnDelete();
            $table->foreignId('sender_profile_id')->constrained('nova_poshta_sender_profiles')->restrictOnDelete();
            $table->string('sender_result', 40);
            $table->string('recipient_result', 40);
            $table->boolean('sender_contact_seen');
            $table->boolean('recipient_contact_seen');
            $table->char('context_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['order_id', 'id'], 'cod_carrier_contact_order_history_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_carrier_contact_observations
            ADD CONSTRAINT cod_carrier_contact_observation_values_check CHECK (
                sender_result IN (
                    'observed_once', 'not_observed_in_response',
                    'duplicate_reference', 'response_inconclusive'
                )
                AND recipient_result IN (
                    'observed_once', 'not_observed_in_response',
                    'duplicate_reference', 'response_inconclusive'
                )
                AND sender_contact_seen = (sender_result = 'observed_once')
                AND recipient_contact_seen = (recipient_result = 'observed_once')
                AND context_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_carrier_contact_observation_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE source RECORD;
            BEGIN
                SELECT d.order_id AS draft_order_id,
                       d.sender_profile_id AS draft_sender_profile_id,
                       d.recipient_id AS draft_recipient_id,
                       b.order_id AS binding_order_id,
                       b.draft_id AS binding_draft_id,
                       b.recipient_id AS binding_recipient_id,
                       p.expires_at, o.payment_status
                INTO source
                FROM checkout_cod_waybill_drafts d
                JOIN checkout_cod_recipient_carrier_bindings b ON b.id = NEW.binding_id
                JOIN orders o ON o.id = d.order_id
                JOIN checkout_order_preparations p ON p.order_id = d.order_id
                WHERE d.id = NEW.draft_id;

                IF NOT FOUND
                   OR source.draft_order_id <> NEW.order_id
                   OR source.draft_sender_profile_id <> NEW.sender_profile_id
                   OR source.binding_order_id <> NEW.order_id
                   OR source.binding_draft_id <> NEW.draft_id
                   OR source.draft_recipient_id <> source.binding_recipient_id
                   OR source.payment_status <> 'pending'
                   OR source.expires_at <= CURRENT_TIMESTAMP
                THEN
                    RAISE EXCEPTION 'COD carrier contact observation: invalid binding';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_carrier_contact_observation_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD carrier contact observations are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_carrier_contact_observation_insert_guard
            BEFORE INSERT ON checkout_cod_carrier_contact_observations
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_carrier_contact_observation_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_carrier_contact_observation_immutable
            BEFORE UPDATE OR DELETE ON checkout_cod_carrier_contact_observations
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_carrier_contact_observation_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_carrier_contact_observation_no_truncate
            BEFORE TRUNCATE ON checkout_cod_carrier_contact_observations
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_carrier_contact_observation_immutable()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_carrier_contact_observations');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_carrier_contact_observation_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_carrier_contact_observation_immutable()');
    }
};
