<?php

namespace App\Console\Commands;

use App\Services\Import\HerreraOpenCartImportService;
use Illuminate\Console\Command;

class HerreraImport extends Command
{
    protected $signature = 'herrera:import {--source-connection=herrera_source} {--prefix=oc_} {--only= : Comma-separated catalog,content,customers,billing_addresses,pricing,orders,seo,relations,archive; base_prices, order_identity and isolated supplier_stock are explicit repairs, not default stages} {--dry-run : Count source records without writing anything} {--final-sync : Preserve native changes and report ownership conflicts; local snapshots only}';

    protected $description = 'Import an isolated Herrera OpenCart snapshot locally, without downloading media or modifying the source';

    public function handle(HerreraOpenCartImportService $importer): int
    {
        try {
            $summary = $importer->import(
                (string) $this->option('source-connection'),
                (string) $this->option('prefix'),
                (bool) $this->option('dry-run'),
                array_filter(explode(',', (string) $this->option('only'))),
                fn (string $stage) => $this->line('Stage: '.$stage),
                finalSync: (bool) $this->option('final-sync'),
            );
            $this->table(['Record set', 'Count'], collect($summary)->map(fn ($value, $key) => [$key, is_scalar($value) ? $value : json_encode($value)])->all());
            $this->info($this->option('dry-run') ? 'Dry run complete. No records were changed.' : 'Local import complete. Price catalogs are published only by an explicit activation.');

            if ((int) ($summary['final_sync_conflicts'] ?? 0) > 0) {
                $this->error('Review the private import conflicts before refreshing a hosted database. No price catalog was activated.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Do not expose SQL or individual customer values on the console.
            $this->error('Import stopped safely: '.(get_class($exception) === \RuntimeException::class ? $exception->getMessage() : get_class($exception)));
            \Illuminate\Support\Facades\Log::error('Herrera local import stopped', ['exception_class' => get_class($exception)]);

            return self::FAILURE;
        }
    }
}
