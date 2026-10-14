<?php

namespace App\Console\Commands;

use App\Models\Content\SupplierCatalog;
use App\Support\SupplierCatalogUrl;
use Illuminate\Console\Command;

class SeedSupplierCatalogs extends Command
{
    protected $signature = 'supplier-catalogs:seed {--publish : Publish newly created catalogues}';

    protected $description = 'Add verified official supplier catalogue links without downloading files or changing existing records';

    public function handle(): int
    {
        $created = 0;
        foreach ((array) config('supplier_catalogs.official_catalogs', []) as $index => $data) {
            if (! SupplierCatalogUrl::isSafe($data['external_url'] ?? null)
                || ! SupplierCatalogUrl::isSafe($data['source_url'] ?? null)) {
                $this->error('Invalid official catalogue URL. No files have been downloaded.');

                return self::FAILURE;
            }
            $catalog = SupplierCatalog::query()->firstOrCreate(['code' => $data['code']], $data + [
                'source_type' => 'external', 'sort_order' => ($index + 1) * 10,
                'is_active' => (bool) $this->option('publish'),
            ]);
            $created += $catalog->wasRecentlyCreated ? 1 : 0;
        }
        $this->info("Added {$created} catalogue links. Existing records were preserved. No catalogue files downloaded.");

        return self::SUCCESS;
    }
}
