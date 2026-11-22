<?php

declare(strict_types=1);

return [
    'enabled' => env('FISCAL62_ENABLED', false),

    'live_enabled' => env('FISCAL62_LIVE_ENABLED', false),

    // Hardcoded release gate.
    // Accountant/provider review must precede changing this.
    'receipt_contract_certified' => false,

    'merchant_tax_reviewed' => env(
        'FISCAL62_MERCHANT_TAX_REVIEWED',
        false
    ),

    'mono_auto_fiscalization_disabled' => env(
        'FISCAL62_MONO_AUTO_FISCALIZATION_DISABLED',
        false
    ),

    'checkbox' => [
        'token' => env('FISCAL62_CHECKBOX_TOKEN'),
    ],
];

