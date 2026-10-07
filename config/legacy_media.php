<?php

return [
    // Temporary browser-side fallback. PHP never fetches a remote image.
    'enabled' => env('LEGACY_MEDIA_ENABLED', false),
    'origin' => env('LEGACY_MEDIA_ORIGIN', 'https://www.herrera.hr'),
    // Originals copied here at the final media-transfer step win over live URLs.
    'local_root' => public_path('image'),
];
