<?php

namespace App\Console\Commands;

use App\Services\Integrations\Stock\FixedSupplierAvailabilityService;
use Illuminate\Console\Command;
use RuntimeException;

class HerreraImportManualSupplierAvailability extends Command
{
    protected $signature = 'herrera:supplier-availability {file : Lokalni CSV sku,quantity} {--apply : Spremi pregledane količine}';

    protected $description = 'Pregled ili ručni import količina dobavljača bez XML-a; vlastita zaliha se ne mijenja.';

    public function handle(FixedSupplierAvailabilityService $service): int
    {
        try {
            $result = $service->importCsv($this->argument('file'), (bool) $this->option('apply'));
            $this->info(($result['applied'] ? 'Spremljeno' : 'Pregled bez spremanja').': '.$result['products'].' artikala.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
