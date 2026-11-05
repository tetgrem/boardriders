<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Welcome10PromotionSeeder extends Seeder
{
    public function run(): void
    {
        // Тестові промокоди створюємо лише
        // в локальному або тестовому середовищі.
        if (!app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'WELCOME10 test seeder is allowed only locally.'
            );
        }

        DB::transaction(function (): void {

            // Не створюємо промокод повторно.
            $exists = DB::table('promotion_codes')
                ->where('code', 'WELCOME10')
                ->exists();

            if ($exists) {
                $this->command?->info(
                    'WELCOME10 already exists. Nothing changed.'
                );

                return;
            }

            // Знаходимо український прайс-лист.
            $priceList = DB::table('price_lists')
                ->where('code', 'UA_RETAIL')
                ->where('is_active', true)
                ->first();

            if ($priceList === null) {
                throw new RuntimeException(
                    'Active UA_RETAIL price list not found.'
                );
            }

            $now = now();

            // ========================================
            // СТВОРЮЄМО АКЦІЮ
            // ========================================

            $promotionId = DB::table('promotions')
                ->insertGetId([

                    'price_list_id' => $priceList->id,

                    'name' => '[TEST] WELCOME10 — 10%',

                    'type' => 'percent',

                    // 1000 базисних пунктів = 10%.
                    'value' => 1000,

                    'min_subtotal_minor' => 0,

                    'max_discount_minor' => null,

                    'usage_limit' => 100,

                    'per_customer_limit' => null,

                    'starts_at' => $now->copy()->subMinute(),

                    'ends_at' => $now->copy()->addDays(7),

                    'is_active' => true,

                    'created_at' => $now,

                    'updated_at' => $now,
                ]);

            // ========================================
            // СТВОРЮЄМО ПРОМОКОД
            // ========================================

            DB::table('promotion_codes')->insert([

                'promotion_id' => $promotionId,

                'code' => 'WELCOME10',

                'is_active' => true,

                'usage_limit' => 100,

                'created_at' => $now,

                'updated_at' => $now,
            ]);

            $this->command?->info(
                'WELCOME10 created successfully.'
            );
        });
    }
}
