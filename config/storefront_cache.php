<?php

return [
    'enabled' => env('STOREFRONT_CACHE_ENABLED', false),
    'store' => env('STOREFRONT_CACHE_STORE', 'file'),
    'ttl_seconds' => (int) env('STOREFRONT_CACHE_TTL', 120),
    'max_html_bytes' => 3 * 1024 * 1024,
    'max_query_parameters' => 16,
    'max_query_bytes' => 1200,
    'max_page' => 200,

    // These pages contain no controller-owned guest session mutations.
    'routes' => [
        'home', 'shop.index', 'categories.index', 'categories.show',
        'manufacturers.index', 'manufacturers.show',
    ],
];
