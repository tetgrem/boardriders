<?php

declare(strict_types=1);

return [
    'enabled' => env('PRICING73_ENABLED', false),

    'max_price_lines' => 100,

    'max_amount_minor' => 100000000000,

    'policy_activation_delay_seconds' => 120,

    'max_policy_rules' => 50,
];
