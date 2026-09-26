<?php

// Guristas.net data foundation - PHP 8.0 compatible build 3

declare(strict_types=1);

return [
    'base_url' => 'https://esi.evetech.net',
    'datasource' => 'tranquility',

    // Deliberately pinned. Bump only after reviewing/testing newer ESI behavior.
    'compatibility_date' => '2026-09-14',

    'user_agent' => 'Guristas.net/0.2 (+https://guristas.net)',
    'connect_timeout_seconds' => 5,
    'request_timeout_seconds' => 15,
    'default_cache_ttl_seconds' => 300,
    'stale_if_error_seconds' => 3600,
    'batch_concurrency' => 16,

    'cache_dir' => dirname(__DIR__) . '/storage/cache/esi',
    'derived_cache_dir' => dirname(__DIR__) . '/storage/cache/derived',
];
