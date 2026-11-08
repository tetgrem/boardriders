<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // product_variants.weight_grams already exists: do not duplicate it.
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('shipping_length_mm')->nullable();
            $table->unsignedInteger('shipping_width_mm')->nullable();
            $table->unsignedInteger('shipping_height_mm')->nullable();
            $table->unsignedInteger('shipping_measurement_revision')->default(0);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_variants
            ADD CONSTRAINT product_variants_shipping_dimensions_check CHECK (
                (
                    shipping_length_mm IS NULL
                    AND shipping_width_mm IS NULL
                    AND shipping_height_mm IS NULL
                ) OR (
                    shipping_length_mm BETWEEN 1 AND 100000
                    AND shipping_width_mm BETWEEN 1 AND 100000
                    AND shipping_height_mm BETWEEN 1 AND 100000
                )
            )
        SQL);

        Schema::create('product_variant_shipping_measurement_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('change_key')->unique();
            $table->foreignId('variant_id')
                ->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('old_revision');
            $table->unsignedInteger('new_revision');
            $table->text('previous_json');
            $table->text('current_json');
            $table->char('request_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['variant_id', 'new_revision'], 'shipping_changes_variant_revision_idx');
            $table->unique(['variant_id', 'new_revision'], 'shipping_changes_revision_unique');
        });

        Schema::create('checkout_cod_parcel_manifests', function (Blueprint $table) {
            $table->id();
            $table->uuid('manifest_key')->unique();
            $table->foreignId('order_id')->unique()
                ->constrained('orders')->restrictOnDelete();
            $table->foreignId('recipient_id')
                ->constrained('checkout_cod_recipients')->restrictOnDelete();
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')->restrictOnDelete();
            $table->unsignedInteger('packaging_grams');
            $table->unsignedInteger('goods_grams');
            $table->unsignedInteger('gross_grams');
            $table->unsignedInteger('total_units');
            $table->unsignedSmallInteger('line_count');
            $table->text('items_json');
            $table->char('items_sha256', 64);
            $table->char('request_sha256', 64);
            $table->timestampTz('checkout_expires_at', 6);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_variant_shipping_measurement_changes
            ADD CONSTRAINT shipping_changes_integrity_check CHECK (
                new_revision = old_revision + 1
                AND request_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_parcel_manifests
            ADD CONSTRAINT cod_parcel_manifest_integrity_check CHECK (
                packaging_grams BETWEEN 0 AND 20000
                AND goods_grams BETWEEN 1 AND 10000000
                AND gross_grams BETWEEN 1 AND 10000000
                AND gross_grams = goods_grams + packaging_grams
                AND total_units BETWEEN 1 AND 19800
                AND line_count BETWEEN 1 AND 200
                AND items_sha256 ~ '^[a-f0-9]{64}$'
                AND request_sha256 ~ '^[a-f0-9]{64}$'
            )
        SQL);

        // Named function survives Laravel's test-table recreation; use OR REPLACE.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_shipping_audit_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Shipping audit records are immutable';
            END;
            $$
        SQL);

        foreach ([
                     'product_variant_shipping_measurement_changes',
                     'checkout_cod_parcel_manifests',
                 ] as $name) {
            DB::statement("CREATE TRIGGER {$name}_immutable
                BEFORE UPDATE OR DELETE ON {$name}
                FOR EACH ROW EXECUTE FUNCTION boardriders_shipping_audit_immutable()");
            DB::statement("CREATE TRIGGER {$name}_no_truncate
                BEFORE TRUNCATE ON {$name}
                FOR EACH STATEMENT EXECUTE FUNCTION boardriders_shipping_audit_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_parcel_manifests');
        Schema::dropIfExists('product_variant_shipping_measurement_changes');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_shipping_audit_immutable()');
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_length_mm',
                'shipping_width_mm',
                'shipping_height_mm',
                'shipping_measurement_revision',
            ]);
        });
    }
};
