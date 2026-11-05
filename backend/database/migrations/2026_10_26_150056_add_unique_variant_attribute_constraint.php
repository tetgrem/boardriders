<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variant_attribute_values', function (Blueprint $table) {
            $table->unique(
                ['variant_id', 'attribute_id'],
                'vav_variant_attribute_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('variant_attribute_values', function (Blueprint $table) {
            $table->dropUnique('vav_variant_attribute_unique');
        });
    }
};
