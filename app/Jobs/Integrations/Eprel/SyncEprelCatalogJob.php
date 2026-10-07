<?php

namespace App\Jobs\Integrations\Eprel;

use App\Models\Integrations\Eprel\EprelCatalogSyncRun;
use App\Services\Integrations\Eprel\EprelCatalogSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncEprelCatalogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public array $backoff = [60];

    public function __construct(public readonly int $runId)
    {
        $this->onConnection('eprel');
        $this->onQueue('eprel');
    }

    public function handle(EprelCatalogSyncService $service): void
    {
        if ($run = EprelCatalogSyncRun::query()->find($this->runId)) {
            $service->processNext($run);
        }
    }

    public function failed(?Throwable $exception): void
    {
        EprelCatalogSyncRun::query()->whereKey($this->runId)->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'error_message' => 'Pozadinska EPREL obrada nije dovršena. Paket možete nastaviti.', 'completed_at' => now()]);
    }
}
