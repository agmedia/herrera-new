<?php

namespace App\Jobs\Integrations\Msan;

use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanPricesAndStockSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncMsanPricesAndStockJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    /** @var list<int> */
    public array $backoff = [600, 600];

    public function __construct(private readonly int $runId) {}

    public function uniqueId(): string
    {
        // Scope the queue lock to this persisted run. A stale lock left after
        // a failed push must not silently discard a later run and orphan it
        // in the pending state.
        return 'msan-prices-stock-sync:'.$this->runId;
    }

    public function handle(MsanPricesAndStockSyncService $service): void
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
            $exception?->getMessage() ?: 'M SAN osvježavanje cijena i količina nije dovršeno.',
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
