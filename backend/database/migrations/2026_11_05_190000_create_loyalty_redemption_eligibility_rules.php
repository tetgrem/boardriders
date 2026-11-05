<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_redemption_eligibility_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')
                ->constrained('loyalty_redemption_policies')
                ->restrictOnDelete();

            // all / brand / category / product / variant.
            $table->string('scope_type', 16);
            $table->string('decision', 8); // allow / deny
            $table->integer('priority')->default(0);

            // Реальні FK замість небезпечного polymorphic scope_id.
            $table->foreignId('brand_id')->nullable()
                ->constrained('brands')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()
                ->constrained('categories')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()
                ->constrained('products')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()
                ->constrained('product_variants')->restrictOnDelete();

            $table->timestampTz('created_at', 6)->useCurrent();

            $table->unique(['policy_id', 'brand_id'], 'loyalty_eligibility_brand_unique');
            $table->unique(['policy_id', 'category_id'], 'loyalty_eligibility_category_unique');
            $table->unique(['policy_id', 'product_id'], 'loyalty_eligibility_product_unique');
            $table->unique(['policy_id', 'variant_id'], 'loyalty_eligibility_variant_unique');
            $table->index(['policy_id', 'priority'], 'loyalty_eligibility_policy_priority_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loyalty_redemption_eligibility_rules
            ADD CONSTRAINT loyalty_eligibility_scope_check
            CHECK (
                decision IN ('allow', 'deny')
                AND priority BETWEEN -1000000 AND 1000000
                AND (
                    (scope_type = 'all' AND num_nonnulls(brand_id, category_id, product_id, variant_id) = 0)
                    OR (scope_type = 'brand' AND brand_id IS NOT NULL AND num_nonnulls(brand_id, category_id, product_id, variant_id) = 1)
                    OR (scope_type = 'category' AND category_id IS NOT NULL AND num_nonnulls(brand_id, category_id, product_id, variant_id) = 1)
                    OR (scope_type = 'product' AND product_id IS NOT NULL AND num_nonnulls(brand_id, category_id, product_id, variant_id) = 1)
                    OR (scope_type = 'variant' AND variant_id IS NOT NULL AND num_nonnulls(brand_id, category_id, product_id, variant_id) = 1)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX loyalty_eligibility_all_unique
            ON loyalty_redemption_eligibility_rules (policy_id)
            WHERE scope_type = 'all'
        SQL);

        // Правила можна додавати лише ДО початку дії версії політики.
        // Після активації будь-яка зміна вимагає нової версії.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_guard_loyalty_eligibility_insert()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE policy_start timestamptz;
            BEGIN
                SELECT effective_from INTO policy_start
                FROM loyalty_redemption_policies
                WHERE id = NEW.policy_id
                FOR SHARE;

                IF policy_start IS NULL OR policy_start <= clock_timestamp() THEN
                    RAISE EXCEPTION 'loyalty policy is already effective; create a new version';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_eligibility_guard_insert
            BEFORE INSERT ON loyalty_redemption_eligibility_rules
            FOR EACH ROW EXECUTE FUNCTION boardriders_guard_loyalty_eligibility_insert()
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_block_loyalty_eligibility_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'loyalty eligibility rules are append-only';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_eligibility_block_update_delete
            BEFORE UPDATE OR DELETE ON loyalty_redemption_eligibility_rules
            FOR EACH ROW EXECUTE FUNCTION boardriders_block_loyalty_eligibility_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER loyalty_eligibility_block_truncate
            BEFORE TRUNCATE ON loyalty_redemption_eligibility_rules
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_block_loyalty_eligibility_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemption_eligibility_rules');

        DB::statement('DROP FUNCTION IF EXISTS boardriders_block_loyalty_eligibility_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_guard_loyalty_eligibility_insert()');
    }
};
