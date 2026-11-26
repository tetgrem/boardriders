<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['products', 'brands', 'categories'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedInteger('catalog_revision')->default(0);
            });
        }

        Schema::create('checkout_catalog_events71', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 16);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedInteger('revision');
            $table->foreignId('actor_id')
                ->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->text('payload_json');
            $table->char('event_hmac', 64);
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['entity_type', 'entity_id', 'revision'],
                'catalog71_entity_revision_unique'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_catalog_events71
            ADD CONSTRAINT catalog71_event_check CHECK (
                entity_type IN ('product', 'brand', 'category')
                AND entity_id > 0
                AND revision > 0
                AND event_hmac ~ '^[a-f0-9]{64}$'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_catalog71_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Catalog71 audit is append-only';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER catalog71_event_immutable
            BEFORE UPDATE OR DELETE ON checkout_catalog_events71
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_catalog71_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER catalog71_event_no_truncate
            BEFORE TRUNCATE ON checkout_catalog_events71
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_catalog71_immutable()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_catalog_events71');

        foreach (['products', 'brands', 'categories'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('catalog_revision');
            });
        }

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_catalog71_immutable()'
        );
    }
};
