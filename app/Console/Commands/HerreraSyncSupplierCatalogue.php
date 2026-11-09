<?php

namespace App\Console\Commands;

use App\Jobs\Integrations\SupplierCatalog\ImportSupplierCatalogueDrafts;
use App\Models\Integrations\SupplierCatalog\SupplierCatalogItem;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogFeedClient;
use App\Services\Integrations\SupplierCatalog\SupplierCatalogService;
use Illuminate\Console\Command;
use Throwable;

class HerreraSyncSupplierCatalogue extends Command
{
    protected $signature = 'herrera:sync-supplier-catalog {supplier?} {--import-drafts : Stavi valjane nove artikle u red za neaktivne nacrte}';

    protected $description = 'Osvježi konfigurirane kataloge i opcionalno pripremi nove nacrte bez izmjena postojećih proizvoda.';

    public function handle(SupplierCatalogService $service, StockSyncSettingsService $settings): int
    {
        $selected = $this->argument('supplier');
        if ($selected && ! isset(SupplierCatalogFeedClient::SUPPLIERS[$selected])) {
            $this->error('Nepoznat dobavljač.');

            return self::FAILURE;
        }
        $failed = false;
        foreach (SupplierCatalogFeedClient::SUPPLIERS as $supplier => $label) {
            if (($selected && $selected !== $supplier) || ! $settings->configured($supplier)) {
                continue;
            }
            try {
                $count = $service->refresh($supplier);
                $this->info($label.': pregledano '.$count.' artikala.');
                if ($this->option('import-drafts')) {
                    $options = $service->validateOptions($service->options($supplier));
                    $lastId = (int) SupplierCatalogItem::query()->where('supplier', $supplier)->where('is_current', true)->max('id');
                    ImportSupplierCatalogueDrafts::dispatch($supplier, $options, null, $lastId)->onConnection('database')->onQueue('integrations');
                    $this->info($label.': uvoz valjanih novih nacrta stavljen u red.');
                }
            } catch (Throwable) {
                $this->error($label.': obrada nije dovršena; provjerite vezu, valutu, tečaj i kategorije. Postojeći proizvodi su sačuvani.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
