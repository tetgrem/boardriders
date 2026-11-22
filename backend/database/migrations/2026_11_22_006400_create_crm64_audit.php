<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_crm_notes64', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->uuid('operation_key')->unique();
            $t->string('category', 20);
            $t->string('body', 1000);
            $t->unsignedBigInteger('written_by');
            $t->char('note_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['order_id', 'id'], 'crm64_note_order_idx');
        });

        Schema::create('checkout_crm_access64', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->unsignedBigInteger('actor_id');
            $t->string('operation', 20);
            $t->char('event_hmac', 64);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index(['order_id', 'id'], 'crm64_access_order_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_crm_notes64 ADD CONSTRAINT crm64_note_shape CHECK (
                category IN ('service','finance','delivery','return','fiscal')
                AND char_length(body) BETWEEN 4 AND 1000
                AND written_by > 0
                AND note_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_crm_access64 ADD CONSTRAINT crm64_access_shape CHECK (
                operation IN ('card','timeline','finance')
                AND actor_id > 0
                AND event_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_crm64_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'CRM64 audit is append-only';
            END;
            $$
        SQL);

        foreach (['checkout_crm_notes64', 'checkout_crm_access64'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION boardriders_crm64_immutable()");
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION boardriders_crm64_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_crm_access64');
        Schema::dropIfExists('checkout_crm_notes64');
        DB::statement('DROP FUNCTION IF EXISTS boardriders_crm64_immutable()');
    }
};
