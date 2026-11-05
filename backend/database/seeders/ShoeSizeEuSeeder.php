<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShoeSizeEuSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();

            $attribute = DB::table('attributes')
                ->where('code', 'shoe_size_eu')
                ->first();

            // У тестовій базі атрибут може ще не існувати.
            if ($attribute === null) {
                $attributeId = DB::table('attributes')
                    ->insertGetId([
                        'code' => 'shoe_size_eu',
                        'type' => 'select',
                        'is_variant' => true,
                        'is_filterable' => true,
                        'is_active' => true,
                        'sort_order' => 30,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            } else {
                if (
                    $attribute->type !== 'select'
                    || ! $attribute->is_variant
                ) {
                    throw new RuntimeException(
                        'Атрибут shoe_size_eu має невірний тип.'
                    );
                }

                $attributeId = (int) $attribute->id;
            }

            foreach (range(35, 48) as $size) {
                $code = (string) $size;

                $option = DB::table('attribute_options')
                    ->where('attribute_id', $attributeId)
                    ->where('code', $code)
                    ->first();

                if ($option === null) {
                    $optionId = DB::table('attribute_options')
                        ->insertGetId([
                            'attribute_id' => $attributeId,
                            'code' => $code,
                            'sort_order' => $size,
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                } else {
                    $optionId = (int) $option->id;

                    DB::table('attribute_options')
                        ->where('id', $optionId)
                        ->update([
                            'sort_order' => $size,
                            'is_active' => true,
                            'updated_at' => $now,
                        ]);
                }

                foreach (['uk', 'ru'] as $locale) {
                    // Не порушуємо зовнішній ключ до languages.
                    if (! DB::table('languages')
                        ->where('code', $locale)
                        ->exists()) {
                        continue;
                    }

                    $exists = DB::table(
                        'attribute_option_translations'
                    )
                        ->where('attribute_option_id', $optionId)
                        ->where('locale', $locale)
                        ->exists();

                    if (! $exists) {
                        DB::table(
                            'attribute_option_translations'
                        )->insert([
                            'attribute_option_id' => $optionId,
                            'locale' => $locale,
                            'name' => $code,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        });

        $this->command?->info(
            'Розміри взуття EU 35–48 готові.'
        );
    }
}
