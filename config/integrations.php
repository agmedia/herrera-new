<?php

return [
    // This shop does not install the inherited M SAN supplier connector.
    // Keep its code and historical records, but never let stored toggles enable it.
    'msan' => [
        'available' => false,
    ],
];
