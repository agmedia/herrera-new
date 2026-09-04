<?php

namespace App\Jobs\Integrations\Msan;

use App\Models\Integrations\Msan\MsanImportRunItem;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchMsanImportChunksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const CHUNK_SIZE = 25;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(private readonly int $runId) {}

    public function uniqueId(): string
    {
        return 'msan-import-dispatch-'.$this->runId;
    }

    public function handle(): void
    {
        $run = MsanSyncRun::query()->find($this->runId);
        if (! $run || ! in_array($run->status, [MsanSyncRun::STATUS_PENDING, MsanSyncRun::STATUS_RUNNING], true)) {
            return;
        }

        $chunks = 0;
        MsanImportRunItem::query()
            ->where('msan_sync_run_id', $this->runId)
            ->whereIn('status', [
                MsanImportRunItem::STATUS_PENDING,
                MsanImportRunItem::STATUS_PROCESSING,
            ])
            ->select(['id', 'msan_product_id'])
            ->chunkById(self::CHUNK_SIZE, function ($items) use (&$chunks): void {
                ImportMsanProductsChunkJob::dispatch(
                    $this->runId,
                    $items->pluck('msan_product_id')->map(static fn ($id): int => (int) $id)->all(),
                )->onQueue('integrations');
                $chunks++;
            });

        $summary = is_array($run->summary) ? $run->summary : [];
        $summary['chunk_size'] = self::CHUNK_SIZE;
        $summary['dispatched_chunks'] = $chunks;
        $summary['dispatched_at'] = now()->toIso8601String();
        $run->forceFill(['summary' => $summary])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $message = $this->sanitizeError($exception?->getMessage() ?: 'M SAN raspoređivanje uvoza nije dovršeno.');

        DB::transaction(function () use ($message): void {
            $run = MsanSyncRun::query()->lockForUpdate()->find($this->runId);
            if (! $run || ! in_array($run->status, [MsanSyncRun::STATUS_PENDING, MsanSyncRun::STATUS_RUNNING], true)) {
                return;
            }

            $pendingItems = MsanImportRunItem::query()
                ->where('msan_sync_run_id', $this->runId)
                ->where('status', MsanImportRunItem::STATUS_PENDING);
            $pendingCount = (clone $pendingItems)->count();

            MsanProduct::query()
                ->whereIn('id', (clone $pendingItems)->select('msan_product_id'))
                ->update([
                    'import_status' => MsanProduct::IMPORT_FAILED,
                    'last_error' => $message,
                ]);
            MsanImportRunItem::query()
                ->where('msan_sync_run_id', $this->runId)
                ->where('status', MsanImportRunItem::STATUS_PENDING)
                ->update([
                    'status' => MsanImportRunItem::STATUS_FAILED,
                    'error_message' => $message,
                    'completed_at' => now(),
                ]);

            $processed = min($run->total_count, $run->processed_count + $pendingCount);
            $complete = $processed >= $run->total_count;
            $run->forceFill([
                'status' => $complete ? MsanSyncRun::STATUS_FAILED : MsanSyncRun::STATUS_RUNNING,
                'processed_count' => $processed,
                'failed_count' => $run->failed_count + $pendingCount,
                'progress' => $run->total_count > 0
                    ? min(100, (int) floor(($processed / $run->total_count) * 100))
                    : 100,
                'error_message' => $message,
                'completed_at' => $complete ? now() : null,
            ]);
            $run->save();
        }, 3);
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/(password|passphrase|pin)\s*[=:]\s*\S+/iu', '$1=[skriveno]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1500);
    }
}
