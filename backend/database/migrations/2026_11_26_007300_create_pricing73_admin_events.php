<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->unsignedInteger('admin_revision')->default(0);
        });

        Schema::create('checkout_pricing_admin_events73', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_key')->unique();

            $table->foreignId('actor_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('action', 32);
            $table->text('request_json');
            $table->text('result_json');
            $table->char('event_hmac', 64);
            $table->timestampTz('created_at', 6);

            $table->index(['action', 'id']);
        });

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

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_pricing73_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Pricing73 audit is immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER pricing73_no_update_delete
            BEFORE UPDATE OR DELETE
            ON checkout_pricing_admin_events73
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_pricing73_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER pricing73_no_truncate
            BEFORE TRUNCATE
            ON checkout_pricing_admin_events73
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_pricing73_immutable()
        SQL);
    }

    public function down(): void
    {
        if (DB::table('checkout_pricing_admin_events73')->exists()) {
            throw new RuntimeException(
                'Cannot remove Pricing73 audit history'
            );
        }

        Schema::dropIfExists('checkout_pricing_admin_events73');

        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropColumn('admin_revision');
        });

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_pricing73_immutable()'
        );
    }
};
