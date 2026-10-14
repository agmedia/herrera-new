<?php

return [
    'max_pdf_kb' => 256000,

    // Verified on the suppliers' own catalogue pages on 2026-10-14.
    // Store links only; the seed command never downloads these catalogues.
    'official_catalogs' => [
        [
            'code' => 'braytron-general-2026', 'brand' => 'Braytron',
            'title' => 'Braytron General Catalogue 2026', 'year' => 2026,
            'language' => 'EN', 'format' => 'web',
            'external_url' => 'https://catalog.braytron.com/',
            'source_url' => 'https://braytron.com/en-gb/catalog/',
            'cover_url' => '/assets/supplier-catalogs/braytron-2026.jpg',
        ],
        [
            'code' => 'videx-led-2026', 'brand' => 'VIDEX',
            'title' => 'VIDEX LED 2026', 'year' => 2026, 'format' => 'pdf',
            'external_url' => 'https://videx.com.pl/catalog/catalogue-videx-led-2026.pdf',
            'source_url' => 'https://videx.com.pl/catalog',
            'cover_url' => '/assets/supplier-catalogs/videx-led-2026.jpg',
        ],
        [
            'code' => 'struhm-lighting-2026', 'brand' => 'Strühm / Ideus',
            'title' => 'Strühm Lighting 2026', 'year' => 2026,
            'language' => 'PL / CZ / SK', 'format' => 'pdf',
            'external_url' => 'https://www.ideus.pl/GetFile?f=.%2FDownloads%2FProducts%2FCatalogue%2FSTRUHM%20Katalog%202026%20-%20PL%20CZ%20SK%20-%20Medium%20Quality.pdf',
            'source_url' => 'https://www.ideus.pl/Page?aId=600&mId=9',
            'cover_url' => '/assets/supplier-catalogs/struhm-2026.jpg',
        ],
        [
            'code' => 'dpm-catalogue', 'brand' => 'DPM',
            'title' => 'DPM e-katalog', 'year' => null, 'format' => 'web',
            'language' => 'PL', 'external_url' => 'https://heyzine.com/flip-book/d20b149d9a.html',
            'source_url' => 'https://dpm.eu/',
            'cover_url' => '/assets/supplier-catalogs/dpm-catalogue.jpg',
        ],
        [
            'code' => 'videx-nota-2026', 'brand' => 'VIDEX',
            'title' => 'VIDEX NOTA 2026', 'year' => 2026, 'format' => 'pdf',
            'language' => 'UA / EN / PL',
            'external_url' => 'https://videx.com.pl/catalog/catalogue-videx-nota-2026.pdf',
            'source_url' => 'https://videx.com.pl/catalog',
            'cover_url' => '/assets/supplier-catalogs/videx-nota-2026.jpg',
        ],
    ],
];
