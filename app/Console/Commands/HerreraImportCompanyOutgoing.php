<?php

namespace App\Console\Commands;

use App\Services\Catalog\Clearance\CompanyOutgoingImport;
use Illuminate\Console\Command;
use RuntimeException;

class HerreraImportCompanyOutgoing extends Command
{
    protected $signature = 'herrera:company-outgoing {file : Privatni CSV e-Računi ukupnih izlaza} {--apply} {--confirmed-company-scope : Izvještaj pokriva ukupne izlaze/prodaju tvrtke}';

    protected $description = 'Pregled i potvrđeni import datuma ukupnih ERP izlaza, bez automatskog zaključivanja iz webshop narudžbi.';

    public function handle(CompanyOutgoingImport $import): int
    {
        try {
            $result = $import->run($this->argument('file'), (bool) $this->option('apply'), (bool) $this->option('confirmed-company-scope'));
            $this->info(($result['applied'] ? 'Spremljeno' : 'Pregled bez spremanja').': '.$result['products'].' artikala; svježe: '.$result['fresh'].'; starije: '.$result['stale'].'.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
