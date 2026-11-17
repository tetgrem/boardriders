<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_ttn_lifecycle_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tracking_case_id')->constrained('checkout_cod_ttn_tracking_cases')->restrictOnDelete();
            $t->foreignId('observation_id')->unique('cod_life51_one_per_observation')
                ->constrained('checkout_cod_ttn_tracking_observations')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->string('source_outcome', 24);
            $t->string('source_status_code', 3)->nullable();
            $t->timestampTz('source_observed_at', 6);
            $t->string('decision', 32);
            $t->string('effective_state', 32);
            $t->string('effective_status_code', 3)->nullable();
            $t->timestampTz('effective_observed_at', 6)->nullable();
            $t->boolean('needs_review');
            $t->string('action_code', 32);
            $t->longText('snapshot_json');
            $t->char('previous_hmac', 64)->nullable();
            $t->char('event_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['tracking_case_id', 'id'], 'cod_life51_case_history');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_ttn_lifecycle_events ADD CONSTRAINT cod_life51_values CHECK (
                source_outcome IN ('observed','inconclusive','provider_failure')
                AND (source_status_code IS NULL OR source_status_code ~ '^[0-9]{1,3}$')
                AND (effective_status_code IS NULL OR effective_status_code ~ '^[0-9]{1,3}$')
                AND decision IN ('advanced','unchanged','conflict','ignored_stale',
                    'ignored_regression','ignored_unknown','ignored_failure','ignored_inconclusive')
                AND effective_state IN ('awaiting_evidence','label_created','processing',
                    'in_transit','awaiting_pickup','received','received_reverse_created',
                    'refused','unclaimed','return_requested','ttn_unverified')
                AND action_code IN ('monitor','investigate_ttn','contact_recipient',
                    'verify_cod_collection','follow_up_return','manual_carrier_review')
                AND (previous_hmac IS NULL OR previous_hmac ~ '^[a-f0-9]{64}$')
                AND event_hmac ~ '^[a-f0-9]{64}$'
                AND snapshot_json IS JSON OBJECT
                AND (snapshot_json::jsonb ->> 'tracking_case_id')::bigint = tracking_case_id
                AND (snapshot_json::jsonb ->> 'observation_id')::bigint = observation_id
                AND (snapshot_json::jsonb ->> 'order_id')::bigint = order_id
                AND snapshot_json::jsonb ->> 'decision' = decision
                AND snapshot_json::jsonb ->> 'effective_state' = effective_state
                AND snapshot_json::jsonb ->> 'action_code' = action_code
                AND (snapshot_json::jsonb ->> 'needs_review')::boolean = needs_review
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_life51_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE src RECORD; prev RECORD;
            BEGIN
                SELECT c.id AS case_id, i.order_id, o.outcome, o.status_code,
                       o.observed_started_at
                  INTO src
                  FROM checkout_cod_ttn_tracking_observations o
                  JOIN checkout_cod_ttn_tracking_cases c ON c.id = o.tracking_case_id
                  JOIN checkout_cod_ttn_submission_intents i ON i.id = c.intent_id
                 WHERE o.id = NEW.observation_id
                 FOR UPDATE OF i;
                IF NOT FOUND OR src.case_id <> NEW.tracking_case_id
                   OR src.order_id <> NEW.order_id
                   OR src.outcome <> NEW.source_outcome
                   OR src.status_code IS DISTINCT FROM NEW.source_status_code
                   OR src.observed_started_at <> NEW.source_observed_at THEN
                    RAISE EXCEPTION 'COD lifecycle source observation mismatch';
                END IF;

                SELECT observation_id, event_hmac INTO prev
                  FROM checkout_cod_ttn_lifecycle_events
                 WHERE tracking_case_id = NEW.tracking_case_id
                 ORDER BY id DESC LIMIT 1;
                IF FOUND THEN
                    IF NEW.observation_id <= prev.observation_id
                       OR NEW.previous_hmac IS DISTINCT FROM prev.event_hmac THEN
                        RAISE EXCEPTION 'COD lifecycle chain order mismatch';
                    END IF;
                ELSIF NEW.previous_hmac IS NOT NULL THEN
                    RAISE EXCEPTION 'COD lifecycle first event cannot have predecessor';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_life51_source_guard
            BEFORE INSERT ON checkout_cod_ttn_lifecycle_events
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_life51_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_life51_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'COD TTN lifecycle audit is immutable'; END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_life51_no_update_delete
            BEFORE UPDATE OR DELETE ON checkout_cod_ttn_lifecycle_events
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_life51_immutable()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_life51_no_truncate
            BEFORE TRUNCATE ON checkout_cod_ttn_lifecycle_events
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_life51_immutable()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_ttn_lifecycle_events');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_life51_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_life51_immutable()');
    }
};
