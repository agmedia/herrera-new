<?php

namespace App\Console\Commands;

use App\Models\Content\SupplierCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillSupplierCatalogCovers extends Command
{
    protected $signature = 'supplier-catalogs:covers {--apply : Fill missing covers on unchanged official external catalogue records}';

    protected $description = 'Add bundled official covers only where neither an uploaded nor a custom cover exists';

    public function handle(): int
    {
        $filled = 0;
        foreach ((array) config('supplier_catalogs.official_catalogs', []) as $data) {
            $cover = (string) ($data['cover_url'] ?? '');
            if (! preg_match('#^/assets/supplier-catalogs/[a-zA-Z0-9_-]+\.jpg$#D', $cover)) {
                continue;
            }
            $file = public_path(ltrim($cover, '/'));
            if (! is_file($file) || filesize($file) > 200 * 1024 || ! getimagesize($file)) {
                $this->warn('Missing or invalid bundled cover: '.$cover);

                continue;
            }
            $query = SupplierCatalog::query()->where('code', $data['code'])
                ->where('source_type', 'external')->where('external_url', $data['external_url'])
                ->where(fn (Builder $q) => $q->whereNull('cover_url')->orWhere('cover_url', ''))
                ->where(fn (Builder $q) => $q->whereNull('cover_path')->orWhere('cover_path', ''));
            $filled += $this->option('apply') ? $query->update(['cover_url' => $cover]) : $query->count();
        }
        $this->info($this->option('apply')
            ? "Added {$filled} missing catalogue covers. Custom covers and catalogue content were preserved."
            : "{$filled} official catalogues can receive a missing cover. Run with --apply to save. No records changed.");

        return self::SUCCESS;
    }
}
