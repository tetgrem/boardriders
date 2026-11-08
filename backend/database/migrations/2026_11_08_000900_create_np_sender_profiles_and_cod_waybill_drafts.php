<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nova_poshta_sender_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('profile_key')->unique();
            $table->string('profile_code', 40);
            $table->unsignedInteger('revision');
            $table->uuid('city_ref');
            $table->uuid('counterparty_ref');
            $table->uuid('warehouse_ref');
            $table->uuid('contact_ref');
            $table->text('phone_ciphertext');
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['profile_code', 'revision'], 'np_sender_profile_code_revision_unique');
        });

        Schema::create('checkout_cod_waybill_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('draft_key')->unique();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('sender_profile_id')
                ->constrained('nova_poshta_sender_profiles')->restrictOnDelete();
            $table->foreignId('financial_preflight_id')
                ->constrained('checkout_cod_financial_preflights')->restrictOnDelete();
            $table->foreignId('measured_rate_id')
                ->constrained('checkout_cod_measured_rates')->restrictOnDelete();
            $table->foreignId('manifest_id')
                ->constrained('checkout_cod_parcel_manifests')->restrictOnDelete();
            $table->foreignId('assessment_id')
                ->constrained('checkout_cod_package_assessments')->restrictOnDelete();
            $table->foreignId('recipient_id')
                ->constrained('checkout_cod_recipients')->restrictOnDelete();
            $table->foreignId('selection_id')
                ->constrained('checkout_cod_delivery_selections')->restrictOnDelete();
            $table->string('service_type', 32);
            $table->string('payer_type', 16);
            $table->string('payment_method', 16);
            $table->string('cargo_description', 150);
            $table->uuid('sender_city_ref');
            $table->uuid('recipient_city_ref');
            $table->uuid('destination_ref');
            $table->unsignedInteger('gross_grams');
            $table->unsignedBigInteger('cod_amount_minor');
            $table->unsignedBigInteger('declared_value_minor');
            $table->char('financial_snapshot_sha256', 64);
            $table->char('request_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE nova_poshta_sender_profiles
            ADD CONSTRAINT np_sender_profile_values_check CHECK (
                revision > 0
                AND profile_code ~ '^[a-z][a-z0-9_-]{1,39}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_waybill_drafts
            ADD CONSTRAINT cod_waybill_draft_values_check CHECK (
                service_type IN ('WarehouseWarehouse', 'WarehousePostomat')
                AND payer_type IN ('Sender', 'Recipient')
                AND payment_method IN ('Cash', 'NonCash')
                AND char_length(trim(cargo_description)) BETWEEN 5 AND 150
                AND gross_grams BETWEEN 1 AND 10000000
                AND cod_amount_minor BETWEEN 100 AND 100000000
                AND declared_value_minor BETWEEN 100 AND 100000000
                AND financial_snapshot_sha256 ~ '^[a-f0-9]{64}$'
                AND request_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_waybill_draft_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE src RECORD;
            BEGIN
                SELECT f.order_id, f.measured_rate_id, f.assessment_id,
                       f.cod_delivery_selection_id, f.local_result,
                       f.cod_amount_minor, f.declared_value_minor,
                       f.financial_snapshot_sha256,
                       rt.manifest_id, rt.service_type, rt.recipient_city_ref,
                       rt.sender_city_ref AS rate_sender_city_ref,
                       m.recipient_id, m.gross_grams, m.checkout_expires_at AS manifest_expires_at,
                       r.city_ref, s.destination_ref, s.carrier_code,
                       s.delivery_type, s.checkout_expires_at AS selection_expires_at,
                       o.payment_status, p.expires_at, sender.city_ref AS sender_city_ref
                INTO src
                FROM checkout_cod_financial_preflights f
                JOIN checkout_cod_measured_rates rt ON rt.id = f.measured_rate_id
                JOIN checkout_cod_parcel_manifests m ON m.id = rt.manifest_id
                JOIN checkout_cod_recipients r ON r.id = m.recipient_id
                JOIN checkout_cod_delivery_selections s ON s.id = r.cod_delivery_selection_id
                JOIN orders o ON o.id = f.order_id
                JOIN checkout_order_preparations p ON p.order_id = o.id
                JOIN nova_poshta_sender_profiles sender ON sender.id = NEW.sender_profile_id
                WHERE f.id = NEW.financial_preflight_id;

                IF NOT FOUND
                   OR src.order_id <> NEW.order_id
                   OR src.measured_rate_id <> NEW.measured_rate_id
                   OR src.assessment_id <> NEW.assessment_id
                   OR src.cod_delivery_selection_id <> NEW.selection_id
                   OR src.manifest_id <> NEW.manifest_id
                   OR src.recipient_id <> NEW.recipient_id
                   OR src.sender_city_ref <> NEW.sender_city_ref
                   OR src.rate_sender_city_ref <> NEW.sender_city_ref
                   OR src.city_ref <> NEW.recipient_city_ref
                   OR src.recipient_city_ref <> NEW.recipient_city_ref
                   OR src.destination_ref <> NEW.destination_ref
                   OR src.service_type <> NEW.service_type
                   OR src.gross_grams <> NEW.gross_grams
                   OR src.cod_amount_minor <> NEW.cod_amount_minor
                   OR src.declared_value_minor <> NEW.declared_value_minor
                   OR src.financial_snapshot_sha256 <> NEW.financial_snapshot_sha256
                   OR src.local_result <> 'within_published_limit'
                   OR src.carrier_code <> 'nova_poshta'
                   OR (src.delivery_type = 'branch' AND NEW.service_type <> 'WarehouseWarehouse')
                   OR (src.delivery_type = 'parcel_locker' AND NEW.service_type <> 'WarehousePostomat')
                   OR src.payment_status <> 'pending'
                   OR src.expires_at <= CURRENT_TIMESTAMP
                   OR src.manifest_expires_at <= CURRENT_TIMESTAMP
                   OR src.selection_expires_at <= CURRENT_TIMESTAMP
                THEN
                    RAISE EXCEPTION 'COD waybill draft: invalid order/source binding';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_waybill_draft_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Sender profiles and COD waybill drafts are immutable';
            END;
            $$
        SQL);

        foreach (['nova_poshta_sender_profiles', 'checkout_cod_waybill_drafts'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION boardriders_cod_waybill_draft_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_waybill_draft_immutable()");
        }

        DB::statement(<<<'SQL'
            CREATE TRIGGER checkout_cod_waybill_drafts_insert_guard
            BEFORE INSERT ON checkout_cod_waybill_drafts
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_waybill_draft_guard()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_waybill_drafts');
        Schema::dropIfExists('nova_poshta_sender_profiles');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_waybill_draft_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_waybill_draft_immutable()');
    }
};
