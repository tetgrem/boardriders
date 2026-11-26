<?php

declare(strict_types=1);

return [
    'enabled' => env('CATALOG71_ENABLED', false),

    'max_page_size' => 50,

    'max_variants_per_product' => 200,

    'max_images_per_product' => 20,

    'max_image_bytes' => 2 * 1024 * 1024,

    'max_image_pixels' => 16000000,
];
