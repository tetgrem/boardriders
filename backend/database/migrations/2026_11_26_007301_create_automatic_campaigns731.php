<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_campaigns731', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('price_list_id')
                ->constrained('price_lists')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('name', 160);
            $table->string('badge', 32);
            $table->string('discount_type', 16);
            $table->unsignedBigInteger('discount_value');
            $table->unsignedBigInteger('max_discount_minor')->nullable();

            $table->integer('priority')->default(0);

            $table->boolean('allow_promo')->default(false);
            $table->boolean('allow_loyalty')->default(true);
            $table->boolean('is_active')->default(false);

            $table->unsignedInteger('revision')->default(1);

            $table->timestampTz('starts_at', 6);
            $table->timestampTz('ends_at', 6);

            $table->jsonb('selection_json');

            $table->timestampsTz(6);

            $table->index([
                'price_list_id',
                'is_active',
                'starts_at',
                'ends_at',
            ], 'campaign731_active_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE marketing_campaigns731
            ADD CONSTRAINT campaign731_shape CHECK (
                discount_type IN ('percent', 'fixed')
                AND discount_value > 0
                AND (
                    discount_type <> 'percent'
                    OR discount_value <= 10000
                )
                AND (
                    max_discount_minor IS NULL
                    OR max_discount_minor > 0
                )
                AND starts_at < ends_at
                AND revision >= 1
            )
        SQL);

        Schema::create('marketing_campaign_variants731', function (Blueprint $table): void {
            $table->foreignId('campaign_id')
                ->constrained('marketing_campaigns731')
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->primary(['campaign_id', 'variant_id']);

            $table->index(['variant_id', 'campaign_id']);
        });

        // Only status, revision and updated_at may change.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_campaign731_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Campaign cannot be deleted';
                END IF;

                IF (
                    to_jsonb(NEW)
                    - ARRAY['is_active', 'revision', 'updated_at']
                ) IS DISTINCT FROM (
                    to_jsonb(OLD)
                    - ARRAY['is_active', 'revision', 'updated_at']
                ) THEN
                    RAISE EXCEPTION 'Campaign terms are immutable';
                END IF;

                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'Invalid campaign revision';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER campaign731_guard
            BEFORE UPDATE OR DELETE ON marketing_campaigns731
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_campaign731_guard()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_campaign731_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Campaign history is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER campaign731_targets_immutable
            BEFORE UPDATE OR DELETE ON marketing_campaign_variants731
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_campaign731_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER campaign731_targets_no_truncate
            BEFORE TRUNCATE ON marketing_campaign_variants731
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_campaign731_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER campaign731_no_truncate
            BEFORE TRUNCATE ON marketing_campaigns731
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_campaign731_immutable()
        SQL);

        // Extend the existing signed audit of block 73.
        DB::statement(
            'ALTER TABLE checkout_pricing_admin_events73
             DROP CONSTRAINT pricing73_event_shape'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_pricing_admin_events73
            ADD CONSTRAINT pricing73_event_shape CHECK (
                action IN (
                    'prices_save',
                    'promotion_create',
                    'promotion_toggle',
                    'code_create',
                    'code_toggle',
                    'market_create',
                    'policy_create',
                    'campaign_create',
                    'campaign_toggle'
                )
                AND event_hmac ~ '^[0-9a-f]{64}$'
            )
        SQL);

        // A single read-only source of commercial prices.
        DB::statement(<<<'SQL'
            CREATE VIEW effective_variant_prices731 AS
            SELECT
                vp.id,
                vp.variant_id,
                vp.price_list_id,
                vp.revision,
                vp.created_at,
                vp.updated_at,

                vp.price_minor AS base_price_minor,

                COALESCE(winner.effective_price, vp.price_minor)
                    AS price_minor,

                CASE
                    WHEN winner.id IS NOT NULL
                    THEN GREATEST(
                        COALESCE(vp.compare_at_minor, vp.price_minor),
                        vp.price_minor
                    )
                    ELSE vp.compare_at_minor
                END AS compare_at_minor,

                winner.id AS campaign_id,
                winner.name AS campaign_name,
                winner.badge AS campaign_badge,
                winner.ends_at AS campaign_ends_at,

                COALESCE(winner.allow_promo, TRUE) AS allow_promo,
                COALESCE(winner.allow_loyalty, TRUE) AS allow_loyalty

            FROM variant_prices vp

            LEFT JOIN LATERAL (
                SELECT
                    c.id,
                    c.name,
                    c.badge,
                    c.ends_at,
                    c.allow_promo,
                    c.allow_loyalty,
                    calculation.effective_price

                FROM marketing_campaign_variants731 target

                JOIN marketing_campaigns731 c
                    ON c.id = target.campaign_id

                CROSS JOIN LATERAL (
                    SELECT GREATEST(
                        1::numeric,
                        vp.price_minor::numeric - LEAST(
                            CASE
                                WHEN c.discount_type = 'percent'
                                THEN FLOOR(
                                    vp.price_minor::numeric
                                    * c.discount_value::numeric
                                    / 10000
                                )
                                ELSE c.discount_value::numeric
                            END,
                            COALESCE(
                                c.max_discount_minor,
                                vp.price_minor
                            )::numeric
                        )
                    )::bigint AS effective_price
                ) calculation

                WHERE target.variant_id = vp.variant_id
                  AND c.price_list_id = vp.price_list_id
                  AND c.is_active = TRUE
                  AND c.starts_at <= CURRENT_TIMESTAMP
                  AND c.ends_at > CURRENT_TIMESTAMP
                  AND calculation.effective_price < vp.price_minor

                ORDER BY
                    c.priority DESC,
                    calculation.effective_price ASC,
                    c.id ASC

                LIMIT 1
            ) winner ON TRUE
        SQL);
    }

    public function down(): void
    {
        if (DB::table('marketing_campaigns731')->exists()) {
            throw new RuntimeException(
                'Cannot remove existing campaign history'
            );
        }

        DB::statement(
            'DROP VIEW IF EXISTS effective_variant_prices731'
        );

        Schema::dropIfExists('marketing_campaign_variants731');
        Schema::dropIfExists('marketing_campaigns731');

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_campaign731_guard()'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_campaign731_immutable()'
        );

        DB::statement(
            'ALTER TABLE checkout_pricing_admin_events73
             DROP CONSTRAINT pricing73_event_shape'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_pricing_admin_events73
            ADD CONSTRAINT pricing73_event_shape CHECK (
                action IN (
                    'prices_save',
                    'promotion_create',
                    'promotion_toggle',
                    'code_create',
                    'code_toggle',
                    'market_create',
                    'policy_create'
                )
                AND event_hmac ~ '^[0-9a-f]{64}$'
            )
        SQL);
    }
};
