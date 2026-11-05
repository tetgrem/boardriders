<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PricingFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('currencies')->insertOrIgnore([
            [
                'code' => 'UAH',
                'name' => 'Українська гривня',
                'symbol' => '₴',
                'minor_units' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'USD',
                'name' => 'Долар США',
                'symbol' => '$',
                'minor_units' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'EUR',
                'name' => 'Євро',
                'symbol' => '€',
                'minor_units' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('price_lists')->insertOrIgnore([
            'code' => 'UA_RETAIL',
            'name' => 'Роздрібні ціни — Україна',
            'country_code' => 'UA',
            'currency_code' => 'UAH',
            'type' => 'retail',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
