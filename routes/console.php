<?php

use App\Models\Settings\Local\Region;
use App\Models\User;
use App\Services\Front\AddressDirectoryService;
use App\Services\Import\DesktopProductImageImportService;
use App\Services\Import\KozoProductContentSyncService;
use App\Services\Import\OpenCartCatalogImportService;
use App\Services\Import\OpenCartPathProductImageImportService;
use App\Services\Import\OpenCartSizeOptionImportService;
use App\Services\Import\TermolProductAttributeImportService;
use App\Services\Import\TermolProductSnapshotImportService;
use App\Services\Integrations\Msan\MsanCatalogSyncCoordinator;
use App\Services\Integrations\Msan\MsanSettingsService;
use App\Services\Settings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::call(static function (): void {
    // schedule:work is long lived, so discard the process-local settings
    // snapshot before checking the administrator-configured cron expression.
    app(SystemSettingsService::class)->clearRuntimeCache();
    if (! app(MsanSettingsService::class)->priceStockSyncIsDue()) {
        return;
    }

    app(MsanCatalogSyncCoordinator::class)->queuePricesAndStock(scheduled: true);
})
    ->name('msan-prices-stock-sync')
    ->everyMinute()
    ->withoutOverlapping(15);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('wholesale:token {user : User ID or email} {name=wholesale-client} {--abilities=wholesale.read,products.read,manufacturers.read,categories.read,products.prices.read,products.quantities.read} {--expires=}', function (): int {
    if (! app(\App\Services\Catalog\CatalogFeatureService::class)->useApi()) {
        $this->error('Wholesale API is disabled in Catalog Features.');

        return self::FAILURE;
    }

    $selector = (string) $this->argument('user');
    $tokenName = trim((string) $this->argument('name'));
    $abilitiesRaw = trim((string) $this->option('abilities'));
    $expiresRaw = trim((string) $this->option('expires'));

    $user = User::query()
        ->when(ctype_digit($selector), fn ($query) => $query->where('id', (int) $selector), fn ($query) => $query->where('email', $selector))
        ->first();

    if (! $user) {
        $this->error('User not found.');

        return self::FAILURE;
    }
    if (! (bool) ($user->api_access_enabled ?? false)) {
        $this->error('User API access is disabled. Enable it in Settings > API first.');

        return self::FAILURE;
    }

    $abilities = collect(explode(',', $abilitiesRaw))
        ->map(fn ($ability) => trim((string) $ability))
        ->filter(fn ($ability) => $ability !== '')
        ->values()
        ->all();

    if ($abilities === []) {
        $this->error('At least one ability is required.');

        return self::FAILURE;
    }

    $expiresAt = null;
    if ($expiresRaw !== '') {
        try {
            $expiresAt = CarbonImmutable::parse($expiresRaw);
        } catch (\Throwable) {
            $this->error('Invalid --expires value. Use a parseable datetime, e.g. "2026-12-31 23:59:59".');

            return self::FAILURE;
        }
    }

    $token = $user->createToken($tokenName, $abilities, $expiresAt);

    $this->info('Token created.');
    $this->line('User: '.$user->id.' <'.$user->email.'>');
    $this->line('Name: '.$tokenName);
    $this->line('Abilities: '.implode(', ', $abilities));
    if ($expiresAt) {
        $this->line('Expires at: '.$expiresAt->toDateTimeString());
    }
    $this->newLine();
    $this->warn('Plain token (copy now):');
    $this->line($token->plainTextToken);

    return self::SUCCESS;
})->purpose('Create a wholesale API token for a user');

Artisan::command('local:import-regions-opencart {file : Path to OpenCart zones CSV} {--truncate : Truncate regions table before import}', function (): int {
    $file = (string) $this->argument('file');
    if (! is_file($file)) {
        $this->error('CSV file not found: '.$file);

        return self::FAILURE;
    }

    $handle = fopen($file, 'rb');
    if (! $handle) {
        $this->error('Unable to open CSV file.');

        return self::FAILURE;
    }

    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if (! is_array($header)) {
        fclose($handle);
        $this->error('CSV header missing or invalid.');

        return self::FAILURE;
    }

    $header = array_map(static fn ($value): string => trim((string) $value), $header);
    $required = ['country_name', 'zone_name', 'code', 'status'];
    $index = array_flip($header);

    foreach ($required as $column) {
        if (! array_key_exists($column, $index)) {
            fclose($handle);
            $this->error('Missing required column: '.$column);

            return self::FAILURE;
        }
    }

    $normalize = static function (string $value): string {
        $value = strtolower(trim($value));
        $value = str_replace(
            ['&', ',', '.', "'", '’', '(', ')', '-', '/'],
            ' ',
            $value
        );
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    };

    $countryLabels = app(AddressDirectoryService::class)->countries('en');
    $countryMap = [];
    foreach ($countryLabels as $country) {
        $label = (string) ($country['label'] ?? '');
        $code = strtoupper((string) ($country['code'] ?? ''));
        if ($label === '' || $code === '') {
            continue;
        }
        $countryMap[$normalize($label)] = $code;
    }

    $aliases = [
        'antigua and barbuda' => 'AG',
        'bonaire sint eustatius and saba' => 'BQ',
        'bolivia' => 'BO',
        'bosnia and herzegovina' => 'BA',
        'brunei' => 'BN',
        'brunei darussalam' => 'BN',
        'cape verde' => 'CV',
        'congo' => 'CG',
        'congo republic of the' => 'CG',
        'congo democratic republic of the' => 'CD',
        'democratic republic of congo' => 'CD',
        'cote d ivoire' => 'CI',
        'curacao' => 'CW',
        'czech republic' => 'CZ',
        'east timor' => 'TL',
        'falkland islands malvinas' => 'FK',
        'france metropolitan' => 'FR',
        'heard and mc donald islands' => 'HM',
        'hong kong' => 'HK',
        'iran islamic republic of' => 'IR',
        'iran' => 'IR',
        'korea south' => 'KR',
        'korea north' => 'KP',
        'laos' => 'LA',
        'lao peoples democratic republic' => 'LA',
        'lao people s democratic republic' => 'LA',
        "lao people's democratic republic" => 'LA',
        'libyan arab jamahiriya' => 'LY',
        'macau' => 'MO',
        'macao' => 'MO',
        'macedonia the former yugoslav republic of' => 'MK',
        'micronesia' => 'FM',
        'micronesia federated states of' => 'FM',
        'moldova republic of' => 'MD',
        'myanmar' => 'MM',
        'palestinian territory occupied' => 'PS',
        'saint barthelemy' => 'BL',
        'saint kitts and nevis' => 'KN',
        'saint lucia' => 'LC',
        'saint martin french part' => 'MF',
        'saint pierre and miquelon' => 'PM',
        'saint vincent and the grenadines' => 'VC',
        'sao tome and principe' => 'ST',
        'swaziland' => 'SZ',
        'syrian arab republic' => 'SY',
        'slovak republic' => 'SK',
        'st pierre and miquelon' => 'PM',
        'trinidad and tobago' => 'TT',
        'turkey' => 'TR',
        'turks and caicos islands' => 'TC',
        'taiwan province of china' => 'TW',
        'tanzania united republic of' => 'TZ',
        'united kingdom' => 'GB',
        'united states minor outlying islands' => 'UM',
        'usa' => 'US',
        'virgin islands u s' => 'VI',
        'vatican city state holy see' => 'VA',
        'venezuela bolivarian republic of' => 'VE',
        'viet nam' => 'VN',
        'wallis and futuna islands' => 'WF',
        'moldova republic of' => 'MD',
        'russia' => 'RU',
        'russian federation' => 'RU',
        'syria' => 'SY',
        'taiwan' => 'TW',
        'tanzania' => 'TZ',
        'venezuela' => 'VE',
        'vietnam' => 'VN',
    ];

    $records = [];
    $seen = [];
    $unknownCountries = [];
    $sortOrderByCountry = [];
    $now = Carbon::now();

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (! is_array($row) || $row === []) {
            continue;
        }

        $status = (string) ($row[$index['status']] ?? '1');
        if ($status !== '1') {
            continue;
        }

        $countryName = trim((string) ($row[$index['country_name']] ?? ''));
        $zoneName = trim((string) ($row[$index['zone_name']] ?? ''));
        $zoneCode = strtoupper(trim((string) ($row[$index['code']] ?? '')));
        if ($countryName === '' || $zoneName === '' || $zoneCode === '') {
            continue;
        }

        $normalizedCountry = $normalize($countryName);
        $countryCode = $countryMap[$normalizedCountry] ?? ($aliases[$normalizedCountry] ?? '');
        if ($countryCode === '') {
            $unknownCountries[$countryName] = true;

            continue;
        }

        $uniqueKey = $countryCode.'|'.$zoneCode;
        if (isset($seen[$uniqueKey])) {
            continue;
        }
        $seen[$uniqueKey] = true;

        $sortOrderByCountry[$countryCode] = ($sortOrderByCountry[$countryCode] ?? 0) + 1;

        $records[] = [
            'country_code' => $countryCode,
            'code' => $zoneCode,
            'name' => $zoneName,
            'is_active' => true,
            'sort_order' => $sortOrderByCountry[$countryCode],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    fclose($handle);

    if ($records === []) {
        $this->error('No importable region rows found.');

        return self::FAILURE;
    }

    DB::transaction(function () use ($records): void {
        if ($this->option('truncate')) {
            Region::query()->delete();
        }

        foreach (array_chunk($records, 1000) as $chunk) {
            Region::query()->upsert(
                $chunk,
                ['country_code', 'code'],
                ['name', 'is_active', 'sort_order', 'updated_at']
            );
        }
    });

    $this->info('Imported regions: '.count($records));
    $this->line('Unknown countries skipped: '.count($unknownCountries));
    if ($unknownCountries !== []) {
        $preview = array_slice(array_keys($unknownCountries), 0, 20);
        $this->warn('Unknown list: '.implode(', ', $preview));
    }

    return self::SUCCESS;
})->purpose('Import OpenCart zones CSV into regions table');

Artisan::command('local:import-opencart-catalog
    {source_db=kozo : Source OpenCart database name}
    {--source-host= : MySQL host (defaults to current app DB host)}
    {--source-port= : MySQL port (defaults to current app DB port)}
    {--source-user= : MySQL user (defaults to current app DB user)}
    {--source-pass= : MySQL password (defaults to current app DB password)}
    {--language-id= : OpenCart language_id to import}
    {--language-code=hr-hr : Preferred OpenCart language code when --language-id is omitted}
    {--locale= : Target Laravel locale (defaults to app locale)}
    {--default-tax-rate-id= : Force a specific target tax_rate_id}
    {--wipe-products : Delete existing products and manufacturers before import}', function (OpenCartCatalogImportService $importer): int {
    try {
        $result = $importer->import([
            'source_db' => (string) $this->argument('source_db'),
            'source_host' => $this->option('source-host'),
            'source_port' => $this->option('source-port'),
            'source_user' => $this->option('source-user'),
            'source_pass' => $this->option('source-pass'),
            'language_id' => $this->option('language-id'),
            'language_code' => $this->option('language-code'),
            'locale' => $this->option('locale'),
            'default_tax_rate_id' => $this->option('default-tax-rate-id'),
            'wipe_products' => (bool) $this->option('wipe-products'),
        ]);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());
        report($e);

        return self::FAILURE;
    }

    $this->info('OpenCart catalog import completed.');
    $this->line('Source DB: '.(string) $result['source_database']);
    $this->line('Source language_id: '.(string) $result['source_language_id']);
    $this->line('Target locale: '.(string) $result['target_locale']);
    $this->line('Catalog categories deleted: '.(string) $result['catalog_categories_deleted']);
    $this->line('Products deleted: '.(string) $result['products_deleted']);
    $this->line('Manufacturers deleted: '.(string) $result['manufacturers_deleted']);
    $this->line('Manufacturers imported: '.(string) $result['manufacturers_imported']);
    $this->line('Categories imported: '.(string) $result['categories_imported']);
    $this->line('Products imported: '.(string) $result['products_imported']);
    $this->line('Category links imported: '.(string) $result['category_links_imported']);

    return self::SUCCESS;
})->purpose('Import categories, manufacturers, and products from an OpenCart database into the local catalog');

Artisan::command('local:attach-desktop-product-images
    {source_dir=/Users/tomek/Desktop/products/products : Directory containing one folder per product code}
    {--locale=hr : Translation locale used for alt labels}
    {--no-clear : Keep existing product media instead of replacing it}', function (DesktopProductImageImportService $importer): int {
    try {
        $result = $importer->import(
            sourceDir: (string) $this->argument('source_dir'),
            locale: (string) $this->option('locale'),
            clearExisting: ! (bool) $this->option('no-clear'),
        );
    } catch (\Throwable $e) {
        $this->error($e->getMessage());
        report($e);

        return self::FAILURE;
    }

    $this->info('Desktop product image import completed.');
    $this->line('Source dir: '.(string) $result['source_dir']);
    $this->line('Folders scanned: '.(string) $result['folders_scanned']);
    $this->line('Matched products: '.(string) $result['matched_products']);
    $this->line('Unmatched folders: '.(string) $result['unmatched_folders']);
    $this->line('Folders without images: '.(string) $result['folders_without_images']);
    $this->line('Main images attached: '.(string) $result['main_images_attached']);
    $this->line('Gallery images attached: '.(string) $result['gallery_images_attached']);

    return self::SUCCESS;
})->purpose('Attach product images from a Desktop folder tree to product_main and product_gallery collections');

Artisan::command('local:attach-opencart-path-product-images
    {source_db=kozo : Source OpenCart database name}
    {base_dir=/Users/tomek/Desktop/products : Base directory that contains products, products_2020, products_2021, products_2024}
    {--locale=hr : Translation locale used for alt labels}
    {--no-clear : Keep existing product media instead of replacing exact matched source-path images}', function (OpenCartPathProductImageImportService $importer): int {
    try {
        $result = $importer->import(
            sourceDatabase: (string) $this->argument('source_db'),
            baseDir: (string) $this->argument('base_dir'),
            locale: (string) $this->option('locale'),
            clearExisting: ! (bool) $this->option('no-clear'),
        );
    } catch (\Throwable $e) {
        $this->error($e->getMessage());
        report($e);

        return self::FAILURE;
    }

    $this->info('OpenCart path-based product image import completed.');
    $this->line('Source DB: '.(string) $result['source_database']);
    $this->line('Base dir: '.(string) $result['base_dir']);
    $this->line('Indexed files: '.(string) $result['indexed_files']);
    $this->line('Source products: '.(string) $result['source_products']);
    $this->line('Matched products: '.(string) $result['matched_products']);
    $this->line('Updated products: '.(string) $result['updated_products']);
    $this->line('Main images attached: '.(string) $result['main_images_attached']);
    $this->line('Gallery images attached: '.(string) $result['gallery_images_attached']);
    $this->line('Unmatched products: '.(string) $result['unmatched_products']);
    $this->line('Products without any source path: '.(string) $result['products_without_any_source_path']);
    $this->line('Products without resolved images: '.(string) $result['products_without_resolved_images']);
    $this->line('Missing main paths: '.(string) $result['missing_main_paths']);
    $this->line('Missing gallery paths: '.(string) $result['missing_gallery_paths']);

    return self::SUCCESS;
})->purpose('Attach product images using exact OpenCart image paths from the source database');

Artisan::command('local:import-opencart-size-options
    {source_db=kozo : Source OpenCart database name}
    {--source-host= : MySQL host (defaults to current app DB host)}
    {--source-port= : MySQL port (defaults to current app DB port)}
    {--source-user= : MySQL user (defaults to current app DB user)}
    {--source-pass= : MySQL password (defaults to current app DB password)}
    {--language-id= : OpenCart language_id to import}
    {--language-code=hr-hr : Preferred OpenCart language code when --language-id is omitted}
    {--source-option-id=13 : Source OpenCart option_id for size}
    {--source-option-name=Veličina : Source OpenCart option label fallback when --source-option-id is omitted}
    {--target-option-code=size : Target catalog option code in shop}
    {--target-locale=hr : Target locale used for Croatian labels}
    {--fallback-locale=en : Secondary locale used for fallback labels}', function (OpenCartSizeOptionImportService $importer): int {
    try {
        $result = $importer->import([
            'source_db' => (string) $this->argument('source_db'),
            'source_host' => $this->option('source-host'),
            'source_port' => $this->option('source-port'),
            'source_user' => $this->option('source-user'),
            'source_pass' => $this->option('source-pass'),
            'language_id' => $this->option('language-id'),
            'language_code' => $this->option('language-code'),
            'source_option_id' => $this->option('source-option-id'),
            'source_option_name' => $this->option('source-option-name'),
            'target_option_code' => $this->option('target-option-code'),
            'target_locale' => $this->option('target-locale'),
            'fallback_locale' => $this->option('fallback-locale'),
        ]);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());
        report($e);

        return self::FAILURE;
    }

    $this->info('OpenCart size option import completed.');
    $this->line('Source DB: '.(string) $result['source_database']);
    $this->line('Source language_id: '.(string) $result['source_language_id']);
    $this->line('Source option: '.(string) $result['source_option_name'].' (#'.(string) $result['source_option_id'].')');
    $this->line('Target option code: '.(string) $result['target_option_code']);
    $this->line('Target option id: '.(string) $result['target_option_id']);
    $this->line('Values imported: '.(string) $result['values_imported']);
    $this->line('Source products with option: '.(string) $result['source_products_with_option']);
    $this->line('Matched products: '.(string) $result['matched_products']);
    $this->line('Unmatched products: '.(string) $result['unmatched_products']);
    $this->line('Product links imported: '.(string) $result['product_links_imported']);
    $this->line('Product option values imported: '.(string) $result['product_option_values_imported']);
    $this->line('Inactive option rows: '.(string) $result['inactive_option_rows']);
    $this->line('Duplicate source rows skipped: '.(string) $result['duplicate_source_rows_skipped']);

    return self::SUCCESS;
})->purpose('Import OpenCart size option groups and product size rows into the local catalog');

Artisan::command('local:import-termol-product-snapshot
    {file=/tmp/termol-small-appliances.json : Browser-exported Termol product snapshot}
    {--no-images : Import catalog data without attaching downloaded images}', function (TermolProductSnapshotImportService $importer): int {
    try {
        $result = $importer->import(
            snapshotFile: (string) $this->argument('file'),
            importImages: ! (bool) $this->option('no-images'),
        );
    } catch (\Throwable $e) {
        $this->error($e->getMessage());
        report($e);

        return self::FAILURE;
    }

    $this->info('Termol product snapshot import completed.');
    $this->line('Snapshot: '.(string) $result['snapshot_file']);
    $this->line('Source products: '.(string) $result['source_products']);
    $this->line('Products imported: '.(string) $result['products_imported']);
    $this->line('Category links: '.(string) $result['categories_linked']);
    $this->line('Main images attached: '.(string) $result['main_images_attached']);
    $this->line('Images skipped: '.(string) $result['images_skipped']);
    $this->line('Documents attached: '.(string) $result['documents_attached']);
    $this->line('Documents skipped: '.(string) $result['documents_skipped']);
    $this->line('Prices include tax: '.((bool) $result['prices_include_tax'] ? 'yes' : 'no'));
    $this->line('Manufacturers linked: '.(string) $result['manufacturers_linked']);
    if ((int) $result['manufacturer_id'] > 0) {
        $this->line('Manufacturer ID: '.(string) $result['manufacturer_id']);
    }
    $this->line('Tax rate ID: '.(string) $result['tax_rate_id']);

    return self::SUCCESS;
})->purpose('Import a browser-exported Termol product snapshot into the local catalog');

Artisan::command('local:sync-termol-product-attributes', function (TermolProductAttributeImportService $importer): int {
    try {
        $result = $importer->sync();
    } catch (\Throwable $e) {
        $this->error($e->getMessage());
        report($e);

        return self::FAILURE;
    }

    $this->info('Termol product attributes synchronized from descriptions.');
    $this->line('Products scanned: '.(string) $result['products_scanned']);
    $this->line('Products updated: '.(string) $result['products_updated']);
    $this->line('Attributes created: '.(string) $result['attributes_created']);
    $this->line('Attribute links: '.(string) $result['attribute_links']);
    $this->line('Storefront filter groups: '.implode(', ', $result['filter_groups']));

    return self::SUCCESS;
})->purpose('Extract reusable Termol product attributes from imported descriptions');

Artisan::command('local:import-termol-product-galleries
    {file=/tmp/termol-small-appliance-galleries.json : Browser-exported Termol product gallery snapshot}
    {--no-clear : Keep existing product gallery images}', function (TermolProductSnapshotImportService $importer): int {
    try {
        $result = $importer->importGalleries(
            snapshotFile: (string) $this->argument('file'),
            clearExisting: ! (bool) $this->option('no-clear'),
        );
    } catch (\Throwable $e) {
        $this->error($e->getMessage());
        report($e);

        return self::FAILURE;
    }

    $this->info('Termol product gallery import completed.');
    $this->line('Snapshot: '.(string) $result['snapshot_file']);
    $this->line('Source products: '.(string) $result['source_products']);
    $this->line('Source images: '.(string) $result['source_images']);
    $this->line('Matched products: '.(string) $result['matched_products']);
    $this->line('Products with galleries: '.(string) $result['products_with_galleries']);
    $this->line('Galleries cleared: '.(string) $result['galleries_cleared']);
    $this->line('Gallery images attached: '.(string) $result['gallery_images_attached']);
    $this->line('Images skipped: '.(string) $result['images_skipped']);

    return self::SUCCESS;
})->purpose('Attach square Termol product gallery images to imported products');

Artisan::command('local:sync-kozo-proizvodi-content
    {source_db=kozo : Source database name}
    {--source-host= : MySQL host (defaults to current app DB host)}
    {--source-port= : MySQL port (defaults to current app DB port)}
    {--source-user= : MySQL user (defaults to current app DB user)}
    {--source-pass= : MySQL password (defaults to current app DB password)}
    {--locale=hr : Target shop translation locale}
    {--dry-run : Inspect and report only without writing to the shop database}', function (KozoProductContentSyncService $sync): int {
    try {
        $result = $sync->sync([
            'source_db' => (string) $this->argument('source_db'),
            'source_host' => $this->option('source-host'),
            'source_port' => $this->option('source-port'),
            'source_user' => $this->option('source-user'),
            'source_pass' => $this->option('source-pass'),
            'locale' => (string) $this->option('locale'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);
    } catch (\Throwable $e) {
        $this->error($e->getMessage());
        report($e);

        return self::FAILURE;
    }

    $this->info($result['dry_run'] ? 'Kozo product content dry-run completed.' : 'Kozo product content sync completed.');
    $this->line('Source DB: '.(string) $result['source_database']);
    $this->line('Locale: '.(string) $result['locale']);
    $this->line('Source rows: '.(string) $result['source_rows']);
    $this->line('Matched products: '.(string) $result['matched_products']);
    $this->line('Unmatched source rows: '.(string) $result['unmatched_source_rows']);
    $this->line('Duplicate source SKUs: '.(string) $result['duplicate_source_skus']);
    $this->line('Attribute values: '.json_encode($result['attribute_values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $this->line('Remaining unresolved ? tokens: '.(string) $result['remaining_question_mark_count']);
    if (! empty($result['remaining_question_mark_tokens'])) {
        $this->warn('Unresolved tokens sample: '.implode(', ', array_slice((array) $result['remaining_question_mark_tokens'], 0, 12)));
    }
    if (! $result['dry_run']) {
        $this->line('Translations updated: '.(string) $result['translations_updated']);
        $this->line('Products updated: '.(string) $result['products_updated']);
        $this->line('Attribute records created: '.(string) $result['attribute_records_created']);
        $this->line('Attribute records updated: '.(string) $result['attribute_records_updated']);
        $this->line('Product attribute links synced: '.(string) $result['product_attribute_links_synced']);
    }

    return self::SUCCESS;
})->purpose('Sync product names, descriptions, and grouped attributes from kozo.proizvodi into the local shop catalog by SKU');
