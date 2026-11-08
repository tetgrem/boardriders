<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * PostgreSQL CHECK considers UNKNOWN (NULL) acceptable.
         *
         * Every dimension must therefore explicitly be NOT NULL
         * when any dimension is present.
         *
         * The original constraint is retained.
         * This additional constraint enforces completeness.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE product_variants
            ADD CONSTRAINT product_variants_shipping_dimensions_complete_check
            CHECK (
                (
                    shipping_length_mm IS NULL
                    AND shipping_width_mm IS NULL
                    AND shipping_height_mm IS NULL
                )
                OR
                (
                    shipping_length_mm IS NOT NULL
                    AND shipping_width_mm IS NOT NULL
                    AND shipping_height_mm IS NOT NULL

                    AND weight_grams IS NOT NULL

                    AND shipping_length_mm BETWEEN 1 AND 100000
                    AND shipping_width_mm BETWEEN 1 AND 100000
                    AND shipping_height_mm BETWEEN 1 AND 100000
                    AND weight_grams BETWEEN 1 AND 10000000
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE product_variants
            DROP CONSTRAINT IF EXISTS
            product_variants_shipping_dimensions_complete_check
        SQL);
    }
};
