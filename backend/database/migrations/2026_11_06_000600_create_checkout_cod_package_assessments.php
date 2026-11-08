<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_package_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('assessment_key')->unique();

            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();

            $table->foreignId('manifest_id')->unique()
                ->constrained('checkout_cod_parcel_manifests')
                ->restrictOnDelete();

            $table->foreignId('cod_delivery_selection_id')
                ->constrained('checkout_cod_delivery_selections')
                ->restrictOnDelete();

            $table->string('ruleset', 80);
            $table->string('destination_type', 24);

            $table->unsignedInteger('length_mm');
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('height_mm');

            $table->unsignedBigInteger('estimated_gross_grams');
            $table->unsignedBigInteger('volume_weight_grams');
            $table->unsignedBigInteger('chargeable_weight_grams');

            $table->string('local_result', 32);
            $table->text('reasons_json');
            $table->char('manifest_items_sha256', 64);
            $table->char('request_sha256', 64);

            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_package_assessments
            ADD CONSTRAINT cod_package_assessment_values_check CHECK (
                length_mm BETWEEN 1 AND 100000
                AND width_mm BETWEEN 1 AND 100000
                AND height_mm BETWEEN 1 AND 100000
                AND estimated_gross_grams BETWEEN 1 AND 10000000
                AND volume_weight_grams = (
                    (length_mm::bigint * width_mm * height_mm + 3999) / 4000
                )
                AND chargeable_weight_grams = GREATEST(
                    estimated_gross_grams, volume_weight_grams
                )
                AND destination_type IN ('branch', 'parcel_locker')
                AND local_result IN (
                    'within_standard_limits', 'manual_review', 'blocked'
                )
                AND manifest_items_sha256 ~ '^[0-9a-f]{64}$'
                AND request_sha256 ~ '^[0-9a-f]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_package_assessment_insert_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                original RECORD;
            BEGIN
                SELECT m.order_id, m.gross_grams, m.items_sha256,
                       r.cod_delivery_selection_id, s.delivery_type,
                       s.carrier_code, o.payment_status
                INTO original
                FROM checkout_cod_parcel_manifests m
                JOIN checkout_cod_recipients r ON r.id = m.recipient_id
                JOIN checkout_cod_delivery_selections s
                     ON s.id = r.cod_delivery_selection_id
                JOIN orders o ON o.id = m.order_id
                WHERE m.id = NEW.manifest_id;

                IF NOT FOUND
                   OR original.order_id <> NEW.order_id
                   OR original.gross_grams <> NEW.estimated_gross_grams
                   OR original.items_sha256 <> NEW.manifest_items_sha256
                   OR original.cod_delivery_selection_id <> NEW.cod_delivery_selection_id
                   OR original.delivery_type <> NEW.destination_type
                   OR original.carrier_code <> 'nova_poshta'
                   OR original.payment_status <> 'pending'
                THEN
                    RAISE EXCEPTION
                        'COD package assessment binding mismatch';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_package_assessment_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD package assessment history is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_package_assessment_insert_guard
            BEFORE INSERT ON checkout_cod_package_assessments
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_package_assessment_insert_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_package_assessment_immutable
            BEFORE UPDATE OR DELETE ON checkout_cod_package_assessments
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_cod_package_assessment_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_package_assessment_no_truncate
            BEFORE TRUNCATE ON checkout_cod_package_assessments
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_cod_package_assessment_immutable()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_package_assessments');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_package_assessment_insert_guard()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_cod_package_assessment_immutable()'
        );
    }
};
