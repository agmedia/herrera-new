<?php

namespace App\Console\Commands;

use App\Services\Integrations\Media\VayoxDocumentImportService;
use Illuminate\Console\Command;
use Throwable;

class SyncVayoxDocuments extends Command
{
    protected $signature = 'herrera:sync-vayox-documents {--dry-run : Inspect links and exact EAN matches without writing metadata} {--product=* : Limit to local product IDs} {--limit=100 : Maximum external links to synchronize, 1–100}';

    protected $description = 'Synchronize external Vayox PDF links without downloading or storing document files';

    public function handle(VayoxDocumentImportService $service): int
    {
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit)) {
            $this->error('Najveći broj poveznica mora biti između 1 i 100.');

            return self::FAILURE;
        }
        try {
            $report = $service->sync((bool) $this->option('dry-run'), (array) $this->option('product'), (int) $limit);
            $this->table(['Provjera', 'Broj'], collect($report)->except('dry_run')->map(fn ($count, $name) => [$name, $count])->values()->all());
            $this->info($report['dry_run'] ? 'Pregled dovršen; poveznice nisu mijenjane.' : 'Sinkronizacija vanjskih poveznica dovršena.');

            return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->error('Sinkronizacija Vayox poveznica nije uspjela. Provjerite postavke izvora dobavljača.');

            return self::FAILURE;
        }
    }
}
