<?php

namespace App\Jobs\Integrations\Msan;

use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanCatalogSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncMsanCatalogJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 2100;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [600, 600];

    public function __construct(private readonly int $runId) {}

    public function uniqueId(): string
    {
        return 'msan-full-sync';
    }

    public function handle(MsanCatalogSyncService $service): void
    {
        $run = MsanSyncRun::query()->find($this->runId);
        if (! $run || ! in_array($run->status, [
            MsanSyncRun::STATUS_PENDING,
            MsanSyncRun::STATUS_RUNNING,
        ], true)) {
            return;
        }

        $service->sync($run);
    }

    public function failed(?Throwable $exception): void
    {
        $message = preg_replace(
            '/(password|passphrase|pin)\s*[=:]\s*\S+/iu',
            '$1=[skriveno]',
            $exception?->getMessage() ?: 'M SAN sinkronizacija nije dovršena.',
        );

        MsanSyncRun::query()
            ->whereKey($this->runId)
            ->whereNotIn('status', [MsanSyncRun::STATUS_COMPLETED, MsanSyncRun::STATUS_CANCELLED])
            ->update([
                'status' => MsanSyncRun::STATUS_FAILED,
                'error_message' => mb_substr(trim((string) $message), 0, 1500),
                'completed_at' => now(),
            ]);
    }
}
