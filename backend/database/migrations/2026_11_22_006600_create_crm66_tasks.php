<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_crm_tasks66', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->string('title', 160);
            $t->string('priority', 12)->default('normal');
            $t->string('status', 16)->default('open');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz(6);
            $t->index(['order_id', 'id'], 'crm66_order_tasks_idx');
            $t->index(['status', 'assigned_to'], 'crm66_staff_queue_idx');
        });

        Schema::create('checkout_crm_task_events66', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('task_id')->constrained('checkout_crm_tasks66')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->uuid('operation_key')->unique();
            $t->string('action', 16);
            $t->unsignedInteger('version');
            $t->string('title', 160);
            $t->string('priority', 12);
            $t->string('status', 16);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->char('event_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->unique(['task_id', 'version'], 'crm66_task_version_uniq');
        });

        DB::statement("ALTER TABLE checkout_crm_tasks66 ADD CONSTRAINT crm66_task_shape CHECK (char_length(btrim(title)) BETWEEN 4 AND 160 AND priority IN ('low','normal','high','critical') AND status IN ('open','in_progress','resolved') AND version >= 1)");
        DB::statement("ALTER TABLE checkout_crm_task_events66 ADD CONSTRAINT crm66_event_shape CHECK (action IN ('created','claimed','released','started','resolved','reopened') AND char_length(btrim(title)) BETWEEN 4 AND 160 AND priority IN ('low','normal','high','critical') AND status IN ('open','in_progress','resolved') AND version >= 1 AND event_hmac ~ '^[a-f0-9]{64}$')");

        DB::statement(<<<'SQL'
        CREATE OR REPLACE FUNCTION boardriders_crm66_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'CRM66 task event history is append-only';
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER crm66_events_immutable BEFORE UPDATE OR DELETE ON checkout_crm_task_events66 FOR EACH ROW EXECUTE FUNCTION boardriders_crm66_immutable()');
        DB::statement('CREATE TRIGGER crm66_events_no_truncate BEFORE TRUNCATE ON checkout_crm_task_events66 FOR EACH STATEMENT EXECUTE FUNCTION boardriders_crm66_immutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_crm_task_events66');
        Schema::dropIfExists('checkout_crm_tasks66');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_crm66_immutable()');
    }
};
