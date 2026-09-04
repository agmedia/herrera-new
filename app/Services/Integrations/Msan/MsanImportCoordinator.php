<?php

namespace App\Services\Integrations\Msan;

use App\Jobs\Integrations\Msan\DispatchMsanImportChunksJob;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanImportRunItem;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class MsanImportCoordinator
{
    private const STAGING_CHUNK_SIZE = 1000;

    private const QUEUE_LOCK_SECONDS = 900;

    public function queueSelected(?int $userId = null): MsanSyncRun
    {
        // Staging can touch a large selected catalog before its run becomes
        // visible to other database connections. Keep the cross-process lease
        // comfortably above that bounded preparation window.
        $lock = Cache::lock('integrations:msan:queue-run', self::QUEUE_LOCK_SECONDS);
        if (! $lock->get()) {
            throw new DomainException('Druga M SAN obrada upravo se pokreće.');
        }

        try {
            return $this->queueSelectedLocked($userId);
        } finally {
            $lock->release();
        }
    }

    private function queueSelectedLocked(?int $userId): MsanSyncRun
    {
        $activeRun = MsanSyncRun::query()
            ->whereIn('kind', [
                MsanSyncRun::KIND_FULL,
                MsanSyncRun::KIND_IMPORT,
                MsanSyncRun::KIND_PRICES,
                MsanSyncRun::KIND_AVAILABILITY,
                MsanSyncRun::KIND_SPECIFICATIONS,
                MsanSyncRun::KIND_EPREL,
            ])
            ->whereIn('status', [MsanSyncRun::STATUS_PENDING, MsanSyncRun::STATUS_RUNNING])
            ->exists();
        if ($activeRun) {
            throw new DomainException('Pričekajte da trenutačna M SAN sinkronizacija ili uvoz završi.');
        }

        $query = MsanProduct::query()
            ->where('selected', true)
            ->whereIn('import_status', MsanProduct::IMPORT_READY_STATUSES)
            ->where('is_stale', false)
            ->whereNotIn('match_status', [MsanProduct::MATCH_CONFLICT, MsanProduct::MATCH_IGNORED])
            ->whereHas('categories.mapping', fn ($mappingQuery) => $mappingQuery
                ->where('status', MsanCategoryMapping::STATUS_MAPPED)
                ->whereNotNull('local_category_id'));

        $run = DB::transaction(function () use ($query, $userId): MsanSyncRun {
            $run = MsanSyncRun::query()->create([
                'kind' => MsanSyncRun::KIND_IMPORT,
                'status' => MsanSyncRun::STATUS_PENDING,
                'requested_by' => $userId,
                'progress' => 0,
                'total_count' => 0,
            ]);

            $stagedCount = 0;
            $query->select('id')->chunkById(self::STAGING_CHUNK_SIZE, function ($rows) use ($run, &$stagedCount): void {
                $now = now();
                $ids = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                MsanImportRunItem::query()->insert(array_map(static fn (int $id): array => [
                    'msan_sync_run_id' => (int) $run->id,
                    'msan_product_id' => $id,
                    'status' => MsanImportRunItem::STATUS_PENDING,
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $ids));
                MsanProduct::query()->whereIn('id', $ids)->update([
                    'import_status' => MsanProduct::IMPORT_QUEUED,
                    'last_error' => null,
                ]);
                $stagedCount += count($ids);
            });

            if ($stagedCount === 0) {
                throw new DomainException('Nema odabranih artikala spremnih za novi uvoz.');
            }

            $run->forceFill([
                'total_count' => $stagedCount,
                'summary' => [
                    'chunk_size' => 25,
                    'selected_total' => MsanProduct::query()
                        ->where('selected', true)
                        ->where('is_stale', false)
                        ->count(),
                ],
            ])->save();

            return $run;
        }, 3);

        try {
            DispatchMsanImportChunksJob::dispatch((int) $run->id)->onQueue('integrations');
        } catch (Throwable $exception) {
            $message = $this->sanitizeError($exception->getMessage());
            DB::transaction(function () use ($run, $message): void {
                $items = MsanImportRunItem::query()->where('msan_sync_run_id', $run->id);
                MsanProduct::query()
                    ->whereIn('id', (clone $items)->select('msan_product_id'))
                    ->where('import_status', MsanProduct::IMPORT_QUEUED)
                    ->update([
                        'import_status' => MsanProduct::IMPORT_PENDING,
                        'last_error' => $message,
                    ]);
                $items->update([
                    'status' => MsanImportRunItem::STATUS_FAILED,
                    'error_message' => $message,
                    'completed_at' => now(),
                ]);
                $run->forceFill([
                    'status' => MsanSyncRun::STATUS_FAILED,
                    'progress' => 100,
                    'processed_count' => $run->total_count,
                    'failed_count' => $run->total_count,
                    'error_message' => $message,
                    'completed_at' => now(),
                ])->save();
            }, 3);

            throw $exception;
        }

        return $run->refresh();
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/(password|passphrase|pin)\s*[=:]\s*\S+/iu', '$1=[skriveno]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1500);
    }
}
