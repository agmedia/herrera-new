<?php

namespace App\Console\Commands;

use App\Services\Integrations\Stock\StockSyncService;
use App\Support\Integrations\Stock\StockSyncRegistry;
use Illuminate\Console\Command;

class HerreraSyncStock extends Command
{
    protected $signature = 'herrera:sync-stock {supplier : videx, brock, enovalite, vayox, dpm, master, braytron or eracuni} {--cron : Record as scheduled execution and respect the automatic switch}';

    protected $description = 'Synchronize supplier or own stock and store a report visible in the integration admin';

    public function handle(StockSyncService $sync): int
    {
        try {
            $supplier = (string) $this->argument('supplier');
            StockSyncRegistry::get($supplier);
            $run = $sync->run($supplier, $this->option('cron') ? 'cron' : 'manual');
        } catch (\Throwable) {
            $this->error('Ažuriranje nije pokrenuto. Provjerite oznaku integracije, pristupne postavke i aktivna izvršavanja.');

            return self::FAILURE;
        }
        $this->line('Izvještaj #'.$run->id.': '.$run->status);
        $this->line('Dohvaćeno: '.$run->fetched_count.'; povezano: '.$run->matched_count.'; promijenjeno: '.$run->updated_count.'; nepromijenjeno: '.$run->unchanged_count.'; nepovezano: '.$run->unmatched_count);

        return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
