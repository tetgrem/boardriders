<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_crm_task_sla69', function (Blueprint $table): void {
            $table->foreignId('task_id')->primary()->constrained('checkout_crm_tasks66')->restrictOnDelete();
            $table->timestampTz('due_at', 6)->nullable();
            $table->unsignedInteger('revision');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz(6);
            $table->index('due_at', 'crm69_due_idx');
        });

        Schema::create('checkout_crm_sla_events69', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('checkout_crm_tasks66')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('from_due_at', 6)->nullable();
            $table->timestampTz('to_due_at', 6)->nullable();
            $table->unsignedInteger('revision');
            $table->char('event_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['task_id', 'revision'], 'crm69_sla_version_uniq');
        });

        Schema::create('checkout_crm_task_notes69', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('checkout_crm_tasks66')->restrictOnDelete();
            $table->uuid('operation_key')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->char('event_hmac', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['task_id', 'id'], 'crm69_notes_idx');
        });

        DB::statement("ALTER TABLE checkout_crm_task_sla69 ADD CONSTRAINT crm69_sla_revision CHECK (revision >= 1)");
        DB::statement("ALTER TABLE checkout_crm_sla_events69 ADD CONSTRAINT crm69_sla_events_shape CHECK (revision >= 1 AND event_hmac ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE checkout_crm_task_notes69 ADD CONSTRAINT crm69_notes_shape CHECK (char_length(btrim(body)) BETWEEN 2 AND 500 AND event_hmac ~ '^[a-f0-9]{64}$')");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_crm69_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'CRM69 audit history is append-only';
            END;
            $$
        SQL);
        foreach (['checkout_crm_sla_events69', 'checkout_crm_task_notes69'] as $table) {
            DB::statement("CREATE TRIGGER crm69_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION boardriders_crm69_immutable()");
            DB::statement("CREATE TRIGGER crm69_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION boardriders_crm69_immutable()");
        }

        // Assignment changes must remain visible in CRM66's signed task history.
        DB::statement('ALTER TABLE checkout_crm_task_events66 DROP CONSTRAINT crm66_event_shape');
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_crm_task_events66 ADD CONSTRAINT crm66_event_shape CHECK (
                action IN ('created','claimed','released','started','resolved','reopened','assigned','reassigned','unassigned')
                AND char_length(btrim(title)) BETWEEN 4 AND 160
                AND priority IN ('low','normal','high','critical')
                AND status IN ('open','in_progress','resolved')
                AND version >= 1 AND event_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
    }

    public function down(): void
    {
        if (DB::table('checkout_crm_task_events66')
            ->whereIn('action', ['assigned', 'reassigned', 'unassigned'])->exists()) {
            throw new RuntimeException('Cannot roll back CRM69 with signed assignment events');
        }
        DB::statement('ALTER TABLE checkout_crm_task_events66 DROP CONSTRAINT crm66_event_shape');
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_crm_task_events66 ADD CONSTRAINT crm66_event_shape CHECK (
                action IN ('created','claimed','released','started','resolved','reopened')
                AND char_length(btrim(title)) BETWEEN 4 AND 160
                AND priority IN ('low','normal','high','critical')
                AND status IN ('open','in_progress','resolved')
                AND version >= 1 AND event_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
        Schema::dropIfExists('checkout_crm_task_notes69');
        Schema::dropIfExists('checkout_crm_sla_events69');
        Schema::dropIfExists('checkout_crm_task_sla69');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_crm69_immutable()');
    }
};
