<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->default(0);
        });

        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->unsignedBigInteger('expected_revision')->nullable();
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION boardriders_bump_inventory_stock_revision()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.on_hand_qty IS DISTINCT FROM OLD.on_hand_qty
                   OR NEW.reserved_qty IS DISTINCT FROM OLD.reserved_qty
                THEN
                    NEW.revision := OLD.revision + 1;
                ELSE
                    NEW.revision := OLD.revision;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER inventory_stocks_bump_revision
            BEFORE UPDATE ON inventory_stocks
            FOR EACH ROW
            EXECUTE FUNCTION boardriders_bump_inventory_stock_revision()
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS inventory_stocks_bump_revision
             ON inventory_stocks'
        );

        DB::statement(
            'DROP FUNCTION IF EXISTS
             boardriders_bump_inventory_stock_revision()'
        );

        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropColumn('expected_revision');
        });

        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
