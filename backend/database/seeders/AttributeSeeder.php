<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttributeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        /*
         * Базові характеристики каталогу.
         *
         * is_variant = true:
         * характеристика визначає конкретний SKU.
         *
         * is_variant = false:
         * характеристика належить самому товару.
         */

        $attributes = [
            'color' => [
                'names' => [
                    'uk' => 'Колір',
                    'ru' => 'Цвет',
                    'en' => 'Color',
                ],
                'is_variant' => true,
                'options' => [
                    'black' => [
                        'uk' => 'Чорний',
                        'ru' => 'Чёрный',
                        'en' => 'Black',
                    ],
                    'white' => [
                        'uk' => 'Білий',
                        'ru' => 'Белый',
                        'en' => 'White',
                    ],
                    'blue' => [
                        'uk' => 'Синій',
                        'ru' => 'Синий',
                        'en' => 'Blue',
                    ],
                    'red' => [
                        'uk' => 'Червоний',
                        'ru' => 'Красный',
                        'en' => 'Red',
                    ],
                    'beige' => [
                        'uk' => 'Бежевий',
                        'ru' => 'Бежевый',
                        'en' => 'Beige',
                    ],
                ],
            ],

            'clothing_size' => [
                'names' => [
                    'uk' => 'Розмір одягу',
                    'ru' => 'Размер одежды',
                    'en' => 'Clothing size',
                ],
                'is_variant' => true,
                'options' => [
                    'xs' => ['uk' => 'XS', 'ru' => 'XS', 'en' => 'XS'],
                    's' => ['uk' => 'S', 'ru' => 'S', 'en' => 'S'],
                    'm' => ['uk' => 'M', 'ru' => 'M', 'en' => 'M'],
                    'l' => ['uk' => 'L', 'ru' => 'L', 'en' => 'L'],
                    'xl' => ['uk' => 'XL', 'ru' => 'XL', 'en' => 'XL'],
                    'xxl' => ['uk' => 'XXL', 'ru' => 'XXL', 'en' => 'XXL'],
                ],
            ],

            'shoe_size_eu' => [
                'names' => [
                    'uk' => 'Розмір взуття EU',
                    'ru' => 'Размер обуви EU',
                    'en' => 'EU shoe size',
                ],
                'is_variant' => true,
                'options' => [
                    '35' => ['uk' => '35', 'ru' => '35', 'en' => '35'],
                    '36' => ['uk' => '36', 'ru' => '36', 'en' => '36'],
                    '37' => ['uk' => '37', 'ru' => '37', 'en' => '37'],
                    '38' => ['uk' => '38', 'ru' => '38', 'en' => '38'],
                    '39' => ['uk' => '39', 'ru' => '39', 'en' => '39'],
                    '40' => ['uk' => '40', 'ru' => '40', 'en' => '40'],
                    '41' => ['uk' => '41', 'ru' => '41', 'en' => '41'],
                    '42' => ['uk' => '42', 'ru' => '42', 'en' => '42'],
                    '43' => ['uk' => '43', 'ru' => '43', 'en' => '43'],
                    '44' => ['uk' => '44', 'ru' => '44', 'en' => '44'],
                    '45' => ['uk' => '45', 'ru' => '45', 'en' => '45'],
                    '46' => ['uk' => '46', 'ru' => '46', 'en' => '46'],
                    '47' => ['uk' => '47', 'ru' => '47', 'en' => '47'],
                    '48' => ['uk' => '48', 'ru' => '48', 'en' => '48'],
                ],
            ],

            'material' => [
                'names' => [
                    'uk' => 'Матеріал',
                    'ru' => 'Материал',
                    'en' => 'Material',
                ],
                'is_variant' => false,
                'options' => [
                    'cotton' => [
                        'uk' => 'Бавовна',
                        'ru' => 'Хлопок',
                        'en' => 'Cotton',
                    ],
                    'polyester' => [
                        'uk' => 'Поліестер',
                        'ru' => 'Полиэстер',
                        'en' => 'Polyester',
                    ],
                    'leather' => [
                        'uk' => 'Шкіра',
                        'ru' => 'Кожа',
                        'en' => 'Leather',
                    ],
                    'suede' => [
                        'uk' => 'Замша',
                        'ru' => 'Замша',
                        'en' => 'Suede',
                    ],
                ],
            ],
        ];

        DB::transaction(function () use ($attributes, $now) {
            $attributeOrder = 0;

            foreach ($attributes as $code => $config) {
                $attributeOrder += 10;

                // Створюємо або оновлюємо характеристику.
                DB::table('attributes')->upsert(
                    [[
                        'code' => $code,
                        'type' => 'select',
                        'is_variant' => $config['is_variant'],
                        'is_filterable' => true,
                        'is_active' => true,
                        'sort_order' => $attributeOrder,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['code'],
                    [
                        'type',
                        'is_variant',
                        'is_filterable',
                        'is_active',
                        'sort_order',
                        'updated_at',
                    ]
                );

                $attributeId = DB::table('attributes')
                    ->where('code', $code)
                    ->value('id');

                // Переклади назви характеристики.
                $translationRows = [];

                foreach ($config['names'] as $locale => $name) {
                    $translationRows[] = [
                        'attribute_id' => $attributeId,
                        'locale' => $locale,
                        'name' => $name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('attribute_translations')->upsert(
                    $translationRows,
                    ['attribute_id', 'locale'],
                    ['name', 'updated_at']
                );

                // Значення характеристики.
                $optionOrder = 0;

                foreach ($config['options'] as $optionCode => $names) {
                    $optionOrder += 10;

                    DB::table('attribute_options')->upsert(
                        [[
                            'attribute_id' => $attributeId,
                            'code' => $optionCode,
                            'sort_order' => $optionOrder,
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]],
                        ['attribute_id', 'code'],
                        [
                            'sort_order',
                            'is_active',
                            'updated_at',
                        ]
                    );

                    $optionId = DB::table('attribute_options')
                        ->where('attribute_id', $attributeId)
                        ->where('code', $optionCode)
                        ->value('id');

                    $optionTranslations = [];

                    foreach ($names as $locale => $name) {
                        $optionTranslations[] = [
                            'attribute_option_id' => $optionId,
                            'locale' => $locale,
                            'name' => $name,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    DB::table('attribute_option_translations')
                        ->upsert(
                            $optionTranslations,
                            ['attribute_option_id', 'locale'],
                            ['name', 'updated_at']
                        );
                }
            }
        });
    }
}
