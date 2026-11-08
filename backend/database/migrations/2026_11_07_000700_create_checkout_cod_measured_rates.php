<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_measured_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('assessment_id')->unique()
                ->constrained('checkout_cod_package_assessments')->restrictOnDelete();
            $table->foreignId('manifest_id')
                ->constrained('checkout_cod_parcel_manifests')->restrictOnDelete();
            $table->foreignId('legacy_quote_id')->nullable()
                ->constrained('checkout_cod_shipping_quotes')->restrictOnDelete();
            $table->uuid('sender_city_ref');
            $table->uuid('recipient_city_ref');
            $table->string('service_type', 32);
            $table->unsignedBigInteger('goods_due_minor');
            $table->char('snapshot_sha256', 64);
            $table->unsignedInteger('gross_grams');
            $table->unsignedInteger('chargeable_grams');
            $table->unsignedInteger('length_mm');
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('height_mm');
            $table->unsignedBigInteger('delivery_cost_minor');
            $table->unsignedBigInteger('cod_fee_minor')->nullable();
            $table->unsignedBigInteger('legacy_cost_minor')->nullable();
            $table->bigInteger('delta_from_legacy_minor')->nullable();
            $table->char('request_sha256', 64);
            $table->char('provider_request_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_measured_rates
            ADD CONSTRAINT cod_measured_rate_values_check CHECK (
                service_type IN ('WarehouseWarehouse', 'WarehousePostomat')
                AND goods_due_minor BETWEEN 100 AND 100000000
                AND gross_grams BETWEEN 1 AND 10000000
                AND chargeable_grams BETWEEN gross_grams AND 10000000
                AND length_mm BETWEEN 1 AND 100000
                AND width_mm BETWEEN 1 AND 100000
                AND height_mm BETWEEN 1 AND 100000
                AND delivery_cost_minor <= 100000000
                AND (cod_fee_minor IS NULL OR cod_fee_minor <= 100000000)
                AND ((legacy_quote_id IS NULL AND legacy_cost_minor IS NULL
                        AND delta_from_legacy_minor IS NULL)
                     OR (legacy_quote_id IS NOT NULL AND legacy_cost_minor IS NOT NULL
                        AND delta_from_legacy_minor = delivery_cost_minor::bigint - legacy_cost_minor::bigint))
                AND snapshot_sha256 ~ '^[a-f0-9]{64}$'
                AND request_sha256 ~ '^[a-f0-9]{64}$'
                AND provider_request_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_measured_rate_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                matched RECORD;
                old_quote RECORD;
            BEGIN
                SELECT a.order_id, a.manifest_id, a.local_result,
                       a.estimated_gross_grams, a.chargeable_weight_grams,
                       a.length_mm, a.width_mm, a.height_mm,
                       a.manifest_items_sha256, a.destination_type,
                       m.gross_grams AS manifest_gross, m.items_sha256,
                       r.city_ref, s.carrier_code, s.delivery_type,
                       o.payment_status, p.bonus_units_reserved, p.expires_at,
                       f.goods_payable_minor, f.currency_code, f.snapshot_sha256
                INTO matched
                FROM checkout_cod_package_assessments a
                JOIN checkout_cod_parcel_manifests m ON m.id = a.manifest_id
                JOIN checkout_cod_recipients r ON r.id = m.recipient_id
                JOIN checkout_cod_delivery_selections s ON s.id = r.cod_delivery_selection_id
                JOIN orders o ON o.id = m.order_id
                JOIN checkout_order_preparations p ON p.order_id = m.order_id
                JOIN order_financial_snapshots f ON f.id = p.financial_snapshot_id
                WHERE a.id = NEW.assessment_id;

                IF NOT FOUND
                   OR matched.order_id <> NEW.order_id
                   OR matched.manifest_id <> NEW.manifest_id
                   OR matched.local_result <> 'within_standard_limits'
                   OR matched.manifest_gross <> NEW.gross_grams
                   OR matched.estimated_gross_grams <> NEW.gross_grams
                   OR matched.chargeable_weight_grams <> NEW.chargeable_grams
                   OR matched.length_mm <> NEW.length_mm
                   OR matched.width_mm <> NEW.width_mm
                   OR matched.height_mm <> NEW.height_mm
                   OR matched.manifest_items_sha256 <> matched.items_sha256
                   OR matched.city_ref <> NEW.recipient_city_ref
                   OR matched.carrier_code <> 'nova_poshta'
                   OR matched.destination_type <> matched.delivery_type
                   OR matched.payment_status <> 'pending'
                   OR matched.expires_at <= CURRENT_TIMESTAMP
                   OR matched.bonus_units_reserved <> 0
                   OR matched.goods_payable_minor <> NEW.goods_due_minor
                   OR matched.currency_code <> 'UAH'
                   OR matched.snapshot_sha256 <> NEW.snapshot_sha256
                   OR (matched.delivery_type = 'branch' AND NEW.service_type <> 'WarehouseWarehouse')
                   OR (matched.delivery_type = 'parcel_locker' AND NEW.service_type <> 'WarehousePostomat')
                THEN
                    RAISE EXCEPTION 'COD measured rate: invalid order or package binding';
                END IF;

                IF NEW.legacy_quote_id IS NOT NULL THEN
                    SELECT q.order_id, q.delivery_cost_minor
                    INTO old_quote
                    FROM checkout_cod_shipping_quotes q
                    WHERE q.id = NEW.legacy_quote_id;

                    IF NOT FOUND
                       OR old_quote.order_id <> NEW.order_id
                       OR old_quote.delivery_cost_minor <> NEW.legacy_cost_minor
                    THEN
                        RAISE EXCEPTION 'COD measured rate: legacy quote mismatch';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_measured_rate_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD measured rate history is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_measured_rate_insert_guard
            BEFORE INSERT ON checkout_cod_measured_rates
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_measured_rate_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_measured_rate_immutable
            BEFORE UPDATE OR DELETE ON checkout_cod_measured_rates
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_measured_rate_immutable()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_measured_rate_no_truncate
            BEFORE TRUNCATE ON checkout_cod_measured_rates
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_measured_rate_immutable()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_measured_rates');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_measured_rate_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_measured_rate_immutable()');
    }
};
