<?php

namespace App\Console\Commands;

use App\Services\Import\HerreraHistoricalCrmImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HerreraImportHistoricalCrm extends Command
{
    protected $signature = 'herrera:import-historical-crm {--source-connection=herrera_source} {--prefix=oc_} {--apply : Archive and map the history on isolated local rehearsal databases; default is dry-run}';

    protected $description = 'Preserve original Herrera staff sessions and their verified customer/order links';

    public function handle(HerreraHistoricalCrmImportService $importer): int
    {
        try {
            $report = $importer->import(DB::connection((string) $this->option('source-connection')), (string) $this->option('prefix'), (bool) $this->option('apply'));
            foreach ($report as $key => $value) {
                $this->line($key.': '.(is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (is_bool($value) ? (int) $value : $value)));
            }

            return ! $report['available'] || $report['conflicts'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception instanceof \Illuminate\Database\QueryException || $exception instanceof \PDOException
                ? 'Historical CRM import stopped safely; no source data was changed.' : $exception->getMessage());

            return self::FAILURE;
        }
    }
}
