<?php

declare(strict_types=1);

return [
    // Operational triage only. No fiscal or payment POST calls.
    'enabled' => env('FISCAL63_ENABLED', false),

    'provider_poll_enabled' => env(
        'FISCAL63_PROVIDER_POLL_ENABLED',
        false
    ),

    'system_actor_id' => env(
        'FISCAL63_SYSTEM_ACTOR_ID'
    ),

    'transmitting_minutes' => 10,
    'uncertain_minutes' => 15,
    'pending_minutes' => 60,
    'prepared_minutes' => 1440,

    'poll_cooldown_seconds' => 120,
];
