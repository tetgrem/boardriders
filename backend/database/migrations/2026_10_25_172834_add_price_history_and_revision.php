<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variant_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->default(0);
        });

        Schema::create('variant_price_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('change_key')->unique();

            $table->foreignId('variant_price_id')
                ->constrained('variant_prices')
                ->restrictOnDelete();

            $table->unsignedBigInteger('previous_price_minor')->nullable();
            $table->unsignedBigInteger('new_price_minor');

            $table->unsignedBigInteger('previous_compare_at_minor')->nullable();
            $table->unsignedBigInteger('new_compare_at_minor')->nullable();

            $table->unsignedBigInteger('previous_revision');
            $table->unsignedBigInteger('new_revision');

            $table->string('reference', 150);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['variant_price_id', 'created_at'],
                'variant_price_changes_history_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE variant_price_changes
            ADD CONSTRAINT variant_price_changes_valid_check
            CHECK (
                new_price_minor >= 0
                AND (
                    previous_price_minor IS NULL
                    OR previous_price_minor >= 0
                )
                AND (
                    new_compare_at_minor IS NULL
                    OR new_compare_at_minor > new_price_minor
                )
                AND (
                    previous_compare_at_minor IS NULL
                    OR previous_compare_at_minor >= 0
                )
                AND new_revision = previous_revision + 1
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_price_changes');

        Schema::table('variant_prices', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
