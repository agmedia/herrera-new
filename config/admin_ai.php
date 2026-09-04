<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin AI Tool Registry
    |--------------------------------------------------------------------------
    |
    | Keep all executable admin-agent tools behind this allowlist.
    | Future domain tools can be added here and then implemented in
    | App\Services\AdminAi\AdminAgentService.
    |
    */
    'tools' => [
        'ensure_category_path' => true,
        'upsert_category_translation' => true,
        'attach_products_by_filter' => true,
        'set_category_state' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Domains
    |--------------------------------------------------------------------------
    |
    | Domain = a bounded business area that the admin agent can operate in.
    | The current implementation supports one domain focused on category
    | operations.
    |
    */
    'domains' => [
        'category_management' => [
            'title' => 'Category Management',
            'summary' => 'Create or update category paths, translation text, state, and optional product attachment by safe filters.',
            'functions' => [
                'ensure_category_path',
                'upsert_category_translation',
                'set_category_state',
                'attach_products_by_filter',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Function Catalog
    |--------------------------------------------------------------------------
    |
    | Human-readable function metadata for Admin Agent preview UI and help.
    |
    */
    'functions' => [
        'ensure_category_path' => [
            'title' => 'Ensure Category Path',
            'description' => 'Resolves category hierarchy path and creates missing nodes when allowed.',
            'params' => [
                ['key' => 'scope', 'value' => 'catalog | blog | page'],
                ['key' => 'locale', 'value' => 'Locale code used for translation matching/creation.'],
                ['key' => 'path_segments', 'value' => 'Ordered path array, from parent to target leaf.'],
                ['key' => 'create_missing', 'value' => 'If true, missing segments are created.'],
            ],
        ],
        'upsert_category_translation' => [
            'title' => 'Upsert Category Translation',
            'description' => 'Creates or updates localized category text (name, description, meta fields via existing flow).',
            'params' => [
                ['key' => 'scope', 'value' => 'Category scope.'],
                ['key' => 'locale', 'value' => 'Locale to update.'],
                ['key' => 'name', 'value' => 'Target category display name.'],
                ['key' => 'description', 'value' => 'Category description text.'],
            ],
        ],
        'set_category_state' => [
            'title' => 'Set Category State',
            'description' => 'Toggles active/inactive state for the resolved target category.',
            'params' => [
                ['key' => 'is_active', 'value' => 'Boolean target state.'],
            ],
        ],
        'attach_products_by_filter' => [
            'title' => 'Attach Products By Filter',
            'description' => 'Attaches products to target category using a constrained filter set.',
            'params' => [
                ['key' => 'filter', 'value' => 'Currently supported: created_today'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Modal Domain Help
    |--------------------------------------------------------------------------
    |
    | Structured text manual used by the "Domain Functions" help button in
    | the Admin AI modal.
    |
    */
    'help' => [
        'title' => 'AI Domain Functions',
        'summary' => 'Admin Agent executes safe, domain-scoped functions. You write intent in plain language; the system builds a tool plan, shows preview, and executes only after confirmation.',
        'sections' => [
            [
                'title' => 'How To Think About Domains',
                'subtitle' => 'A domain is a safety boundary around business operations.',
                'explanation' => [
                    'The agent does not run arbitrary actions. It maps your prompt to domain functions that are explicitly allowlisted.',
                    'Each function has known inputs and predictable side effects.',
                    'Preview step exists to make function plan readable before any writes happen.',
                ],
            ],
            [
                'title' => 'Execution Workflow',
                'subtitle' => 'Use this sequence on every AI request.',
                'explanation' => [
                    'Step 1: Write request in natural language (Croatian or English).',
                    'Step 2: Click Preview and review summary/actions/functions.',
                    'Step 3: Confirm only when plan matches expected scope.',
                    'Step 4: On success, open redirected entity and verify final state.',
                ],
            ],
            [
                'title' => 'Current Domain',
                'subtitle' => 'What is currently implemented and safe to use.',
                'explanation' => [
                    'Category Management domain is active.',
                    'It supports category path creation/upsert, translation updates, state toggling, and optional product attach by constrained filters.',
                    'If request is outside this domain, preview returns fallback guidance instead of forcing unsafe execution.',
                ],
            ],
            [
                'title' => 'Prompt Patterns',
                'subtitle' => 'Examples that parse reliably.',
                'explanation' => [
                    'Croatian: "Napravi mi kategoriju Ugljikohidrati unutar Prehrane, dodaj opis i dodaj danas dodane artikle."',
                    'English: "Create category Carbs under Nutrition, add description and attach today products."',
                    'Path format: "Parent > Child > Leaf" is supported for explicit hierarchy.',
                    'Locale hint: "locale hr" or "jezik hr". Scope hint: mention blog or page.',
                ],
            ],
            [
                'title' => 'Safety Rules',
                'subtitle' => 'What protects data from accidental broad changes.',
                'explanation' => [
                    'No execution happens without manual Confirm.',
                    'Only allowlisted functions can run.',
                    'Filters are constrained by implementation (e.g. products created today).',
                    'Every execution is logged to admin activity.',
                ],
            ],
            [
                'title' => 'When Request Is Unsupported',
                'subtitle' => 'Expected behavior for out-of-domain prompts.',
                'explanation' => [
                    'Preview returns clear error/fallback notice with developer contact.',
                    'No partial execution is performed when plan cannot be built safely.',
                    'Use unsupported request as input for future domain expansion planning.',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Unsupported Request Fallback
    |--------------------------------------------------------------------------
    */
    'fallback' => [
        'notice' => 'If this action is not possible, contact developers for estimate on delivery time and cost.',
        'contact' => env('ADMIN_AI_DEV_CONTACT', 'dev@agshop.local'),
    ],
];
