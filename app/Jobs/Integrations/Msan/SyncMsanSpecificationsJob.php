<?php

namespace App\Jobs\Integrations\Msan;

use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanSpecificationPublisher;
use App\Services\Integrations\Msan\MsanSpecificationSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncMsanSpecificationsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 100;

    /** Released overlap attempts do not count as endpoint failures. */
    public int $maxExceptions = 2;

    public int $timeout = 9000;

    public int $uniqueFor = 10800;

    /** @var list<int> */
    public array $backoff = [3660];

    public function __construct(private readonly int $runId) {}

    public function uniqueId(): string
    {
        return 'msan-specifications-sync';
    }

    public function handle(MsanSpecificationSyncService $service): void
    {
        $run = MsanSyncRun::query()->find($this->runId);
        if (! $run || ! in_array($run->status, [
            MsanSyncRun::STATUS_PENDING,
            MsanSyncRun::STATUS_RUNNING,
        ], true)) {
            return;
        }

        $lock = Cache::lock(
            MsanSpecificationPublisher::PUBLISH_LOCK_KEY,
            MsanSpecificationPublisher::PUBLISH_LOCK_SECONDS,
        );
        if (! $lock->get()) {
            $this->release(120);

            return;
        }

        try {
            $service->sync($run);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(MsanSpecificationSyncService::class)->recoverFailedRun($this->runId);
        } catch (Throwable $recoveryException) {
            report($recoveryException);
        }

        $message = preg_replace(
            '/(api[-_ ]?key|authorization|password|passphrase|pin)\s*[=:]\s*\S+/iu',
            '$1=[skriveno]',
            $exception?->getMessage() ?: 'M SAN sinkronizacija specifikacija nije dovršena.',
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
