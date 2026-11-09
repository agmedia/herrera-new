<?php

namespace App\Console\Commands;

use App\Services\Analytics\SalesOpportunitySync;
use App\Services\Integrations\Eracuni\EracuniCompanySalesException;
use Illuminate\Console\Command;
use Throwable;

class HerreraSyncSalesOpportunities extends Command
{
    protected $signature = 'herrera:sync-sales-opportunities';

    protected $description = 'Osvježi prodajne prilike iz ukupne ERP prodaje i odobrenja, bez dupliciranja otpremnica.';

    public function handle(SalesOpportunitySync $sync): int
    {
        try {
            $result = $sync->run();
            $this->info('Potpuni ERP pregled prodajnih prilika je spremljen.');
            $this->table(['Dokumenti', 'API pozivi', 'Nepovezane stavke', 'Anonimne stavke'], [[
                $result['document_count'], $result['request_count'], $result['unmapped_lines'], $result['anonymous_lines'],
            ]]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof \RuntimeException ? $exception->getMessage() : 'ERP pregled prodajnih prilika nije dovršen.');
            if ($exception instanceof EracuniCompanySalesException && $exception->context !== []) {
                $this->line('ERP lokator: '.json_encode($exception->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return self::FAILURE;
        }
    }
}
