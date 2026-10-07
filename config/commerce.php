<?php

return [
    // Herrera is a public catalog with purchasing reserved for approved businesses.
    'b2b_only' => env('STORE_B2B_ONLY', true),
    'b2b_display_net' => env('STORE_B2B_DISPLAY_NET', true),
    'local_safe_mode' => env('HERRERA_LOCAL_SAFE_MODE', false),
];
