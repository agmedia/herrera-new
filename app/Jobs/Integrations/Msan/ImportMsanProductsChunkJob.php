<?php

namespace App\Jobs\Integrations\Msan;

use App\Models\Integrations\Msan\MsanImportRunItem;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanProductImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportMsanProductsChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param list<int> $productIds */
    public function __construct(
        private readonly int $runId,
        private readonly array $productIds,
    ) {}

    public function handle(MsanProductImportService $importer): void
    {
        $run = MsanSyncRun::query()->find($this->runId);
        if (! $run || in_array($run->status, [
            MsanSyncRun::STATUS_COMPLETED,
            MsanSyncRun::STATUS_CANCELLED,
            MsanSyncRun::STATUS_FAILED,
        ], true)) {
            return;
        }

        foreach ($this->productIds as $productId) {
            if (! $this->claim((int) $productId)) {
                continue;
            }

            try {
                $result = $importer->import((int) $productId, $run->requested_by ? (int) $run->requested_by : null);
                $this->completeItem(
                    (int) $productId,
                    $result === 'skipped' ? MsanImportRunItem::STATUS_SKIPPED : MsanImportRunItem::STATUS_SUCCEEDED,
                );
            } catch (Throwable $exception) {
                $this->completeItem(
                    (int) $productId,
                    MsanImportRunItem::STATUS_FAILED,
                    $this->sanitizeError($exception->getMessage()),
                );
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = $this->sanitizeError($exception?->getMessage() ?: 'M SAN posao uvoza nije dovršen.');

        foreach ($this->productIds as $productId) {
            $this->completeItem((int) $productId, MsanImportRunItem::STATUS_FAILED, $message);
        }
    }

    private function claim(int $productId): bool
    {
        return DB::transaction(function () use ($productId): bool {
            $run = MsanSyncRun::query()->lockForUpdate()->find($this->runId);
            if (! $run || ! in_array($run->status, [MsanSyncRun::STATUS_PENDING, MsanSyncRun::STATUS_RUNNING], true)) {
                return false;
            }

            $item = MsanImportRunItem::query()
                ->where('msan_sync_run_id', $this->runId)
                ->where('msan_product_id', $productId)
                ->lockForUpdate()
                ->first();
            if (! $item || in_array($item->status, [
                MsanImportRunItem::STATUS_SUCCEEDED,
                MsanImportRunItem::STATUS_FAILED,
                MsanImportRunItem::STATUS_SKIPPED,
            ], true)) {
                return false;
            }

            if ($item->status === MsanImportRunItem::STATUS_PROCESSING
                && $item->started_at?->isAfter(now()->subMinutes(10))
            ) {
                return false;
            }

            if ($run->status === MsanSyncRun::STATUS_PENDING) {
                $run->forceFill([
                    'status' => MsanSyncRun::STATUS_RUNNING,
                    'started_at' => $run->started_at ?: now(),
                ])->save();
            }

            $item->forceFill([
                'status' => MsanImportRunItem::STATUS_PROCESSING,
                'attempts' => $item->attempts + 1,
                'error_message' => null,
                'started_at' => now(),
                'completed_at' => null,
            ])->save();
            MsanProduct::query()->whereKey($productId)->update([
                'import_status' => MsanProduct::IMPORT_IMPORTING,
                'last_error' => null,
            ]);

            return true;
        }, 3);
    }

    private function completeItem(int $productId, string $status, ?string $error = null): void
    {
        DB::transaction(function () use ($productId, $status, $error): void {
            $item = MsanImportRunItem::query()
                ->where('msan_sync_run_id', $this->runId)
                ->where('msan_product_id', $productId)
                ->lockForUpdate()
                ->first();
            if (! $item || in_array($item->status, [
                MsanImportRunItem::STATUS_SUCCEEDED,
                MsanImportRunItem::STATUS_FAILED,
                MsanImportRunItem::STATUS_SKIPPED,
            ], true)) {
                return;
            }

            $run = MsanSyncRun::query()->lockForUpdate()->find($this->runId);
            if (! $run) {
                return;
            }

            $item->forceFill([
                'status' => $status,
                'error_message' => $error,
                'completed_at' => now(),
            ])->save();

            if ($status === MsanImportRunItem::STATUS_FAILED) {
                MsanProduct::query()->whereKey($productId)->update([
                    'import_status' => MsanProduct::IMPORT_FAILED,
                    'last_error' => $error,
                ]);
            } elseif ($status === MsanImportRunItem::STATUS_SKIPPED) {
                MsanProduct::query()->whereKey($productId)->update([
                    'import_status' => MsanProduct::IMPORT_SKIPPED,
                    'last_error' => null,
                ]);
            } elseif ($status === MsanImportRunItem::STATUS_SUCCEEDED) {
                MsanProduct::query()->whereKey($productId)->update([
                    'selected' => false,
                ]);
            }

            if ($run->status === MsanSyncRun::STATUS_FAILED) {
                return;
            }

            $processed = min($run->total_count, $run->processed_count + 1);
            $failed = $run->failed_count + ($status === MsanImportRunItem::STATUS_FAILED ? 1 : 0);
            $complete = $processed >= $run->total_count;
            $run->forceFill([
                'status' => $complete
                    ? ($failed > 0 ? MsanSyncRun::STATUS_FAILED : MsanSyncRun::STATUS_COMPLETED)
                    : MsanSyncRun::STATUS_RUNNING,
                'processed_count' => $processed,
                'succeeded_count' => $run->succeeded_count + ($status === MsanImportRunItem::STATUS_SUCCEEDED ? 1 : 0),
                'failed_count' => $failed,
                'skipped_count' => $run->skipped_count + ($status === MsanImportRunItem::STATUS_SKIPPED ? 1 : 0),
                'progress' => $run->total_count > 0 ? min(100, (int) floor(($processed / $run->total_count) * 100)) : 100,
                'error_message' => $complete && $failed > 0
                    ? ($run->error_message ?: 'Jedan ili više M SAN artikala nije uvezeno.')
                    : $run->error_message,
                'completed_at' => $complete ? now() : null,
            ])->save();
        }, 3);
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/(password|passphrase|pin)\s*[=:]\s*\S+/iu', '$1=[skriveno]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1500);
    }
}
