<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_ttn_submission_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('intent_key')->unique();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('draft_id')->unique()
                ->constrained('checkout_cod_waybill_drafts')->restrictOnDelete();
            $table->foreignId('binding_id')
                ->constrained('checkout_cod_recipient_carrier_bindings')->restrictOnDelete();
            $table->foreignId('observation_id')
                ->constrained('checkout_cod_carrier_contact_observations')->restrictOnDelete();
            $table->foreignId('sender_profile_id')
                ->constrained('nova_poshta_sender_profiles')->restrictOnDelete();
            $table->char('context_hmac', 64);
            $table->char('request_hmac', 64);
            $table->unsignedBigInteger('prepared_by');
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        Schema::create('checkout_cod_ttn_submission_safety_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->foreignId('intent_id')
                ->constrained('checkout_cod_ttn_submission_intents')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('reason_code', 40);
            $table->unsignedBigInteger('recorded_by');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['intent_id', 'id'], 'cod_ttn_safety_event_history_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_submission_intents
            ADD CONSTRAINT cod_ttn_intent_values_check CHECK (
                prepared_by > 0
                AND context_hmac ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_submission_safety_events
            ADD CONSTRAINT cod_ttn_safety_event_values_check CHECK (
                recorded_by > 0 AND request_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (event_type = 'safety_hold' AND reason_code IN (
                        'manual_review', 'stale_details', 'security_hold'
                    )) OR
                    (event_type = 'cancel_before_submission' AND reason_code IN (
                        'customer_cancelled', 'operator_cancelled', 'order_invalidated'
                    )) OR
                    (event_type = 'reconciliation_required' AND reason_code IN (
                        'reported_timeout', 'reported_unknown_outcome', 'possible_duplicate'
                    ))
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_intent_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE ctx RECORD;
            BEGIN
                SELECT d.order_id AS draft_order_id,
                       d.sender_profile_id AS draft_sender_id,
                       b.order_id AS binding_order_id,
                       b.draft_id AS binding_draft_id,
                       ob.order_id AS observation_order_id,
                       ob.draft_id AS observation_draft_id,
                       ob.binding_id AS observation_binding_id,
                       ob.sender_profile_id AS observation_sender_id,
                       ob.sender_contact_seen,
                       ob.recipient_contact_seen,
                       o.payment_status, p.expires_at
                INTO ctx
                FROM checkout_cod_waybill_drafts d
                JOIN checkout_cod_recipient_carrier_bindings b ON b.id = NEW.binding_id
                JOIN checkout_cod_carrier_contact_observations ob ON ob.id = NEW.observation_id
                JOIN orders o ON o.id = d.order_id
                JOIN checkout_order_preparations p ON p.order_id = d.order_id
                WHERE d.id = NEW.draft_id;

                IF NOT FOUND
                   OR ctx.draft_order_id IS DISTINCT FROM NEW.order_id
                   OR ctx.draft_sender_id IS DISTINCT FROM NEW.sender_profile_id
                   OR ctx.binding_order_id IS DISTINCT FROM NEW.order_id
                   OR ctx.binding_draft_id IS DISTINCT FROM NEW.draft_id
                   OR ctx.observation_order_id IS DISTINCT FROM NEW.order_id
                   OR ctx.observation_draft_id IS DISTINCT FROM NEW.draft_id
                   OR ctx.observation_binding_id IS DISTINCT FROM NEW.binding_id
                   OR ctx.observation_sender_id IS DISTINCT FROM NEW.sender_profile_id
                   OR ctx.sender_contact_seen IS DISTINCT FROM TRUE
                   OR ctx.recipient_contact_seen IS DISTINCT FROM TRUE
                   OR ctx.payment_status IS DISTINCT FROM 'pending'
                   OR ctx.expires_at <= CURRENT_TIMESTAMP
                THEN
                    RAISE EXCEPTION 'COD TTN submission intent: invalid context';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_safety_event_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM checkout_cod_ttn_submission_safety_events
                    WHERE intent_id = NEW.intent_id
                      AND event_type = 'cancel_before_submission'
                ) THEN
                    RAISE EXCEPTION 'Cancelled COD TTN intent cannot accept more events';
                END IF;
                IF NEW.event_type = 'cancel_before_submission' AND EXISTS (
                    SELECT 1 FROM checkout_cod_ttn_submission_safety_events
                    WHERE intent_id = NEW.intent_id
                      AND event_type = 'reconciliation_required'
                ) THEN
                    RAISE EXCEPTION 'Unknown remote outcome requires reconciliation before cancellation';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_ttn_submission_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD TTN submission safety journal is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_ttn_intent_insert_guard
            BEFORE INSERT ON checkout_cod_ttn_submission_intents
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_intent_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_ttn_safety_event_insert_guard
            BEFORE INSERT ON checkout_cod_ttn_submission_safety_events
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_safety_event_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cod_ttn_one_cancellation_per_intent
            ON checkout_cod_ttn_submission_safety_events(intent_id)
            WHERE event_type = 'cancel_before_submission'
        SQL);

        foreach (['checkout_cod_ttn_submission_intents', 'checkout_cod_ttn_submission_safety_events'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_ttn_submission_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_ttn_submission_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_ttn_submission_safety_events');
        Schema::dropIfExists('checkout_cod_ttn_submission_intents');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_intent_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_safety_event_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_ttn_submission_immutable()');
    }
};
