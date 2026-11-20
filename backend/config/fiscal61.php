<?php

declare(strict_types=1);

return [
    // Entire preparation boundary. Production defaults to OFF.
    'enabled' => env('FISCAL61_ENABLED', false),

    // Internal validation only. Does not issue fiscal receipts.
    'preview_enabled' => env(
        'FISCAL61_PREVIEW_ENABLED',
        false
    ),

    // Intentionally hard-disabled until a real provider integration
    // and fiscal compliance review are implemented.
    'mode' => 'preview_only',
    'provider' => null,
    'tax_mapping' => 'not_configured',
];
