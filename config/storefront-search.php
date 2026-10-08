<?php

return [
    'max_characters' => (int) env('STOREFRONT_SEARCH_MAX_CHARACTERS', 100),
    'max_bytes' => (int) env('STOREFRONT_SEARCH_MAX_BYTES', 400),
    'max_terms' => (int) env('STOREFRONT_SEARCH_MAX_TERMS', 12),
    'max_wildcard_characters' => (int) env('STOREFRONT_SEARCH_MAX_WILDCARDS', 4),
    'max_search_page' => (int) env('STOREFRONT_SEARCH_MAX_PAGE', 1000),
    'count_cache_seconds' => (int) env('STOREFRONT_SEARCH_COUNT_CACHE_SECONDS', 30),

    // Explicit catalog vocabulary, not suffix stripping: related singular and
    // plural forms expand product/category names, never SKUs, codes or brands.
    'croatian_word_forms' => [
        ['majica', 'majice'],
        ['žarulja', 'žarulje'],
        ['svjetiljka', 'svjetiljke'],
        ['utičnica', 'utičnice'],
        ['grijalica', 'grijalice'],
        ['rukavica', 'rukavice'],
        ['bušilica', 'bušilice'],
        ['brusilica', 'brusilice'],
        ['pila', 'pile'],
        ['svrdlo', 'svrdla'],
        ['kabel', 'kabeli', 'kablovi'],
        ['prekidač', 'prekidači'],
        ['ventilator', 'ventilatori'],
        ['reflektor', 'reflektori'],
        ['produžetak', 'produžeci'],
        ['utikač', 'utikači'],
        ['senzor', 'senzori'],
        ['adapter', 'adapteri'],
        ['punjač', 'punjači'],
        ['baterija', 'baterije'],
        ['cijev', 'cijevi'],
        ['vijak', 'vijci'],
        ['ormar', 'ormari'],
        ['alat', 'alati'],
        ['radna', 'radne', 'radni', 'radno'],
        ['zaštitna', 'zaštitne', 'zaštitni', 'zaštitno'],
        ['pamučna', 'pamučne', 'pamučni', 'pamučno'],
    ],

    // A shared IP budget is higher for signed-in teams behind the same office router.
    'limits' => [
        'guest' => [
            'burst' => (int) env('STOREFRONT_SEARCH_GUEST_BURST', 45),
            'burst_seconds' => 10,
            'per_minute' => (int) env('STOREFRONT_SEARCH_GUEST_PER_MINUTE', 180),
        ],
        'user' => [
            'burst' => (int) env('STOREFRONT_SEARCH_USER_BURST', 60),
            'burst_seconds' => 10,
            'per_minute' => (int) env('STOREFRONT_SEARCH_USER_PER_MINUTE', 300),
        ],
        'authenticated_ip' => [
            'burst' => (int) env('STOREFRONT_SEARCH_IP_BURST', 240),
            'burst_seconds' => 10,
            'per_minute' => (int) env('STOREFRONT_SEARCH_IP_PER_MINUTE', 1200),
        ],
    ],
];
