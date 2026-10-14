<?php

return [
    'vayox' => [
        // Reuses the encrypted Vayox feed connection configured for stock sync.
        'scheduled' => (bool) env('VAYOX_DOCUMENTS_SCHEDULED', false),
        'timezone' => 'Europe/Zagreb',
        'document_hosts' => ['www.bodex.net.pl', 'bodex.net.pl'],
        'feed_max_bytes' => 12 * 1024 * 1024,
    ],
];
