<?php

namespace App\Jobs\Integrations\Media;

use App\Services\Integrations\Media\MediaImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportLegacyProductMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 30;

    public function __construct(public readonly int $runId) {}

    public function handle(MediaImportService $service): void
    {
        $run = $service->process($this->runId);
        if (in_array($run->status, ['queued', 'running'], true)) {
            $service->enqueue($run->id);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MediaImportService::class)->fail($this->runId);
    }
}
