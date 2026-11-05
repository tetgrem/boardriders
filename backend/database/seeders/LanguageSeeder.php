<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('languages')->upsert(
            [
                [
                    'code' => 'uk',
                    'name' => 'Ukrainian',
                    'native_name' => 'Українська',
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'ru',
                    'name' => 'Russian',
                    'native_name' => 'Русский',
                    'is_enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'en',
                    'name' => 'English',
                    'native_name' => 'English',
                    'is_enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['code'],
            [
                'name',
                'native_name',
                'is_enabled',
                'updated_at',
            ]
        );
    }
}
