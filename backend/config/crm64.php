<?php

declare(strict_types=1);

return [
    // CRM is a read-only projection over existing payment/delivery/RMA/fiscal systems.
    'enabled' => env('CRM64_ENABLED', false),
    'max_page_size' => 100,
    'timeline_per_source' => 100,
];
