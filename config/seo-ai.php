<?php

return [
    // Optional model overrides; otherwise use the installed SDK provider model.
    'models' => [
        'anthropic' => env('SEO_AI_ANTHROPIC_MODEL'),
        'xai' => env('SEO_AI_XAI_MODEL'),
    ],
];
