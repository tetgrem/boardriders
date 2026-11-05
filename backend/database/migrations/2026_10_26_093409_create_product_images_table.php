<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            // Шлях відносно storage/app/public.
            $table->string('path', 500);

            $table->string('alt_uk')->nullable();
            $table->string('alt_ru')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(
                ['product_id', 'path'],
                'product_images_unique_path'
            );

            $table->index(
                ['product_id', 'sort_order', 'id'],
                'product_images_sort_idx'
            );
        });

        // У товару може бути лише одне головне фото.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX product_images_one_primary_idx
            ON product_images (product_id)
            WHERE is_primary = TRUE
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE product_images
            ADD CONSTRAINT product_images_path_check
            CHECK (
                path LIKE 'products/%'
                AND path NOT LIKE '%..%'
                AND length(trim(path)) > 9
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
