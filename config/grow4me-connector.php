<?php

return [
    'enabled' => env('GROW4ME_CONNECTOR_ENABLED', true),

    'route_prefix' => env('GROW4ME_CONNECTOR_ROUTE_PREFIX', 'api/v1/sync'),

    'token' => env('GROW4ME_CONNECTOR_TOKEN'),

    'cursor_secret' => env('GROW4ME_CONNECTOR_CURSOR_SECRET'),

    'resources' => [],

    'dependencies' => [],

    'webhook' => [
        'url' => env('GROW4ME_CONNECTOR_WEBHOOK_URL'),
        'secret' => env('GROW4ME_CONNECTOR_WEBHOOK_SECRET'),
        'connect_timeout' => (int) env('GROW4ME_CONNECTOR_WEBHOOK_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('GROW4ME_CONNECTOR_WEBHOOK_TIMEOUT', 10),
        'max_attempts' => (int) env('GROW4ME_CONNECTOR_WEBHOOK_MAX_ATTEMPTS', 8),
    ],

    'outbox' => [
        'connection' => env('GROW4ME_CONNECTOR_OUTBOX_CONNECTION'),
        'table' => 'grow4me_connector_outbox_events',
        'schedule_delivery' => env('GROW4ME_CONNECTOR_SCHEDULE_DELIVERY', true),
        'claim_ttl_seconds' => 300,
    ],
];
