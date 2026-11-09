<?php

namespace App\Console\Commands;

use App\Services\Integrations\SupplierCatalog\SupplierProcurementPriceImport;
use Illuminate\Console\Command;
use RuntimeException;

class HerreraImportSupplierProcurementPrices extends Command
{
    protected $signature = 'herrera:supplier-prices {supplier} {file : Privatni lokalni CSV sku,source_price,currency} {--apply : Spremi dopunu nakon pregleda}';

    protected $description = 'Pregled ili dopuna nedostajućih nabavnih cijena bez promjene postojećih proizvoda.';

    public function handle(SupplierProcurementPriceImport $import): int
    {
        try {
            $result = $import->run($this->argument('supplier'), $this->argument('file'), (bool) $this->option('apply'));
            $this->info(($result['applied'] ? 'Spremljeno' : 'Pregled bez spremanja').': '.$result['updated'].' dopuna; sačuvano izvornih cijena: '.$result['preserved_feed_prices'].'.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
