<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_cod_financial_preflights', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('measured_rate_id')->unique()
                ->constrained('checkout_cod_measured_rates')->restrictOnDelete();
            $table->foreignId('assessment_id')
                ->constrained('checkout_cod_package_assessments')->restrictOnDelete();
            $table->foreignId('cod_delivery_selection_id')
                ->constrained('checkout_cod_delivery_selections')->restrictOnDelete();
            $table->foreignId('financial_snapshot_id')
                ->constrained('order_financial_snapshots')->restrictOnDelete();
            $table->string('policy_version', 80);
            $table->string('payout_channel', 24);
            $table->unsignedBigInteger('payout_limit_minor');
            $table->unsignedBigInteger('cod_amount_minor');
            $table->unsignedBigInteger('declared_value_minor');
            $table->unsignedBigInteger('delivery_cost_minor');
            $table->unsignedBigInteger('estimated_cod_fee_minor')->nullable();
            $table->string('local_result', 40);
            $table->char('financial_snapshot_sha256', 64);
            $table->char('request_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_cod_financial_preflights
            ADD CONSTRAINT cod_financial_preflight_values_check CHECK (
                policy_version = 'np-cod-payout-limits-2026-11-v1'
                AND payout_channel IN ('cash_branch', 'novapay_card', 'other_ua_card')
                AND payout_limit_minor = CASE payout_channel
                    WHEN 'cash_branch' THEN 39999900
                    WHEN 'novapay_card' THEN 10000000
                    WHEN 'other_ua_card' THEN 2999900
                    ELSE 0 END
                AND cod_amount_minor BETWEEN 100 AND 100000000
                AND declared_value_minor BETWEEN 100 AND 100000000
                AND delivery_cost_minor <= 100000000
                AND (estimated_cod_fee_minor IS NULL
                     OR estimated_cod_fee_minor <= 100000000)
                AND (
                    (cod_amount_minor > payout_limit_minor
                     AND local_result = 'blocked_payout_limit')
                    OR (cod_amount_minor <= payout_limit_minor
                        AND estimated_cod_fee_minor IS NULL
                        AND local_result = 'missing_fee')
                    OR (cod_amount_minor <= payout_limit_minor
                        AND estimated_cod_fee_minor IS NOT NULL
                        AND local_result = 'within_published_limit')
                )
                AND financial_snapshot_sha256 ~ '^[0-9a-f]{64}$'
                AND request_sha256 ~ '^[0-9a-f]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_financial_preflight_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                source_row RECORD;
            BEGIN
                SELECT r.order_id, r.assessment_id, r.goods_due_minor,
                       r.delivery_cost_minor, r.cod_fee_minor,
                       r.snapshot_sha256 AS rate_sha,
                       a.cod_delivery_selection_id,
                       a.local_result AS package_result,
                       s.carrier_code, s.goods_due_minor AS selection_due,
                       s.snapshot_sha256 AS selection_sha,
                       f.id AS financial_id, f.goods_payable_minor,
                       f.currency_code, f.snapshot_sha256 AS financial_sha,
                       p.bonus_units_reserved, p.expires_at, o.payment_status
                INTO source_row
                FROM checkout_cod_measured_rates r
                JOIN checkout_cod_package_assessments a ON a.id = r.assessment_id
                JOIN checkout_cod_delivery_selections s
                    ON s.id = a.cod_delivery_selection_id
                JOIN checkout_order_preparations p ON p.order_id = r.order_id
                JOIN order_financial_snapshots f ON f.id = p.financial_snapshot_id
                JOIN orders o ON o.id = r.order_id
                WHERE r.id = NEW.measured_rate_id;

                IF NOT FOUND
                   OR source_row.order_id <> NEW.order_id
                   OR source_row.assessment_id <> NEW.assessment_id
                   OR source_row.cod_delivery_selection_id <> NEW.cod_delivery_selection_id
                   OR source_row.financial_id <> NEW.financial_snapshot_id
                   OR source_row.goods_due_minor <> NEW.cod_amount_minor
                   OR source_row.selection_due <> NEW.cod_amount_minor
                   OR source_row.goods_payable_minor <> NEW.cod_amount_minor
                   OR source_row.delivery_cost_minor <> NEW.delivery_cost_minor
                   OR source_row.cod_fee_minor IS DISTINCT FROM NEW.estimated_cod_fee_minor
                   OR source_row.rate_sha <> NEW.financial_snapshot_sha256
                   OR source_row.selection_sha <> NEW.financial_snapshot_sha256
                   OR source_row.financial_sha <> NEW.financial_snapshot_sha256
                   OR source_row.currency_code <> 'UAH'
                   OR source_row.package_result <> 'within_standard_limits'
                   OR source_row.carrier_code <> 'nova_poshta'
                   OR source_row.payment_status <> 'pending'
                   OR source_row.bonus_units_reserved <> 0
                   OR source_row.expires_at <= CURRENT_TIMESTAMP
                THEN
                    RAISE EXCEPTION 'COD financial preflight: inconsistent order binding';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_cod_financial_preflight_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'COD financial preflight history is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_financial_preflight_insert_guard
            BEFORE INSERT ON checkout_cod_financial_preflights
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_financial_preflight_guard()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_financial_preflight_immutable
            BEFORE UPDATE OR DELETE ON checkout_cod_financial_preflights
            FOR EACH ROW EXECUTE FUNCTION boardriders_cod_financial_preflight_immutable()
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cod_financial_preflight_no_truncate
            BEFORE TRUNCATE ON checkout_cod_financial_preflights
            FOR EACH STATEMENT EXECUTE FUNCTION boardriders_cod_financial_preflight_immutable()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_cod_financial_preflights');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_financial_preflight_guard()');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_cod_financial_preflight_immutable()');
    }
};
