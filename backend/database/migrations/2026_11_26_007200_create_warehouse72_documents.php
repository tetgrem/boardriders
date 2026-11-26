<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->unsignedInteger('admin_revision')->default(0);
        });

        Schema::create('checkout_warehouse_documents72', function (Blueprint $table): void {
            $table->id();

            $table->uuid('operation_key')->unique();

            $table->foreignId('actor_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('kind', 24);

            $table->foreignId('warehouse_id')
                ->nullable()
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->string('reference', 150)->nullable();

            $table->text('request_json');
            $table->text('result_json');
            $table->char('event_hmac', 64);

            $table->timestampTz('created_at', 6);

            $table->index(['warehouse_id', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_warehouse_documents72
            ADD CONSTRAINT warehouse72_document_shape CHECK (
                kind IN (
                    'receipt',
                    'transfer',
                    'stocktake',
                    'writeoff',
                    'warehouse_create',
                    'warehouse_update'
                )
                AND event_hmac ~ '^[a-f0-9]{64}$'
                AND (
                    (
                        kind IN ('receipt','transfer','stocktake','writeoff')
                        AND warehouse_id IS NOT NULL
                        AND reference IS NOT NULL
                    )
                    OR (
                        kind IN ('warehouse_create','warehouse_update')
                        AND reference IS NULL
                    )
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX warehouse72_document_reference_unique
            ON checkout_warehouse_documents72 (
                kind, warehouse_id, reference
            )
            WHERE reference IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_warehouse72_immutable()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Warehouse72 documents are immutable';
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER warehouse72_document_immutable
            BEFORE UPDATE OR DELETE
            ON checkout_warehouse_documents72
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_warehouse72_immutable()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER warehouse72_document_no_truncate
            BEFORE TRUNCATE
            ON checkout_warehouse_documents72
            FOR EACH STATEMENT
            EXECUTE FUNCTION boardriders_warehouse72_immutable()
        SQL);
    }

    public function down(): void
    {
        if (DB::table('checkout_warehouse_documents72')->exists()) {
            throw new RuntimeException(
                'Cannot remove Warehouse72 document history'
            );
        }

        Schema::dropIfExists('checkout_warehouse_documents72');

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropColumn('admin_revision');
        });

        DB::statement(
            'DROP FUNCTION IF EXISTS boardriders_warehouse72_immutable()'
        );
    }
};
