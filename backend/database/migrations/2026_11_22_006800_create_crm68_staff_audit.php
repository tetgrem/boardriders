<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checkout_crm_staff65', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });

        Schema::create('checkout_crm_staff_events68', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_key')->unique();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('from_role', 24)->nullable();
            $table->string('to_role', 24);
            $table->boolean('from_active')->nullable();
            $table->boolean('to_active');
            $table->unsignedInteger('previous_version');
            $table->unsignedInteger('version');
            $table->char('event_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['target_user_id', 'version'], 'crm68_target_version_unique');
            $table->index(['target_user_id', 'id'], 'crm68_target_events_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_crm_staff_events68 ADD CONSTRAINT crm68_event_check CHECK (
                action IN ('granted', 'role_changed', 'deactivated', 'reactivated')
                AND (from_role IS NULL OR from_role IN ('owner','manager','support','finance','fiscal','viewer'))
                AND to_role IN ('owner','manager','support','finance','fiscal','viewer')
                AND version = previous_version + 1
                AND event_hmac ~ '^[0-9a-f]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_crm68_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'CRM68 staff audit is append-only';
            END;
            $$
        SQL);

        DB::statement('CREATE TRIGGER crm68_audit_immutable BEFORE UPDATE OR DELETE ON checkout_crm_staff_events68 FOR EACH ROW EXECUTE FUNCTION boardriders_crm68_immutable()');
        DB::statement('CREATE TRIGGER crm68_audit_no_truncate BEFORE TRUNCATE ON checkout_crm_staff_events68 FOR EACH STATEMENT EXECUTE FUNCTION boardriders_crm68_immutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_crm_staff_events68');
        Schema::table('checkout_crm_staff65', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
        DB::statement('DROP FUNCTION IF EXISTS boardriders_crm68_immutable()');
    }
};
