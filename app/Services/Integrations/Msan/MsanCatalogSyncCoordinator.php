<?php

namespace App\Services\Integrations\Msan;

use App\Jobs\Integrations\Msan\SyncEprelEnergyJob;
use App\Jobs\Integrations\Msan\SyncMsanCatalogJob;
use App\Jobs\Integrations\Msan\SyncMsanPricesAndStockJob;
use App\Jobs\Integrations\Msan\SyncMsanSpecificationsJob;
use App\Jobs\Integrations\Msan\TestMsanConnectionJob;
use App\Jobs\Integrations\Msan\TestMsanFtpConnectionJob;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MsanCatalogSyncCoordinator
{
    public function __construct(
        private readonly MsanSettingsService $settings,
    ) {}

    public function queueFullSync(?int $userId = null): MsanSyncRun
    {
        $lock = Cache::lock('integrations:msan:queue-run', 30);
        if (! $lock->get()) {
            throw new DomainException('Druga M SAN obrada upravo se pokreće.');
        }

        try {
            $this->assertNoActiveRun();
            $run = MsanSyncRun::query()->create([
                'kind' => MsanSyncRun::KIND_FULL,
                'status' => MsanSyncRun::STATUS_PENDING,
                'requested_by' => $userId,
                'progress' => 0,
            ]);

            try {
                SyncMsanCatalogJob::dispatch((int) $run->id)->onQueue('integrations');
            } catch (Throwable $exception) {
                $run->forceFill([
                    'status' => MsanSyncRun::STATUS_FAILED,
                    'error_message' => 'M SAN sinkronizaciju nije moguće staviti u red.',
                    'completed_at' => now(),
                ])->save();

                throw $exception;
            }

            return $run->refresh();
        } finally {
            $lock->release();
        }
    }

    public function queueConnectionTest(?int $userId = null): MsanSyncRun
    {
        $run = MsanSyncRun::query()->create([
            'kind' => 'connection_test',
            'status' => MsanSyncRun::STATUS_PENDING,
            'requested_by' => $userId,
            'progress' => 0,
        ]);

        try {
            TestMsanConnectionJob::dispatch((int) $run->id)->onQueue('integrations');
        } catch (Throwable $exception) {
            $this->markDispatchFailed($run, 'Provjeru M SAN veze nije moguće staviti u red.');
            throw $exception;
        }

        return $run->refresh();
    }

    public function queuePricesAndStock(?int $userId = null, bool $scheduled = false): ?MsanSyncRun
    {
        $lock = Cache::lock('integrations:msan:queue-run', 30);
        if (! $lock->get()) {
            if ($scheduled) {
                return null;
            }

            throw new DomainException('Druga M SAN obrada upravo se pokreće.');
        }

        try {
            if ($scheduled && ! $this->settings->priceStockSyncEnabled()) {
                return null;
            }

            if (! $this->settings->enabled()) {
                if ($scheduled) {
                    return null;
                }

                $this->settings->assertEnabled();
            }

            if (! MsanProduct::query()
                ->where('import_status', MsanProduct::IMPORT_IMPORTED)
                ->whereNotNull('local_product_id')
                ->exists()) {
                if ($scheduled) {
                    return null;
                }

                throw new DomainException('Nema uvezenih M SAN artikala za osvježavanje.');
            }

            if ($this->hasActiveRun()) {
                if ($scheduled) {
                    return null;
                }

                throw new DomainException('Pričekajte da trenutačna M SAN sinkronizacija ili uvoz završi.');
            }

            $run = MsanSyncRun::query()->create([
                'kind' => MsanSyncRun::KIND_PRICES,
                'status' => MsanSyncRun::STATUS_PENDING,
                'requested_by' => $userId,
                'progress' => 0,
            ]);

            try {
                SyncMsanPricesAndStockJob::dispatch((int) $run->id)->onQueue('integrations');
            } catch (Throwable $exception) {
                $this->markDispatchFailed($run, 'M SAN osvježavanje cijena i količina nije moguće staviti u red.');
                throw $exception;
            }

            return $run->refresh();
        } finally {
            $lock->release();
        }
    }

    /**
     * Backwards-compatible entry point for callers deployed before the price
     * refresh was added to the lightweight stock synchronization.
     */
    public function queueAvailability(?int $userId = null, bool $scheduled = false): ?MsanSyncRun
    {
        return $this->queuePricesAndStock($userId, $scheduled);
    }

    public function queueEprelEnergy(?int $userId = null): MsanSyncRun
    {
        $lock = Cache::lock('integrations:msan:queue-run', 30);
        if (! $lock->get()) {
            throw new DomainException('Druga M SAN obrada upravo se pokreće.');
        }

        try {
            $this->settings->assertEnabled();
            if (! $this->settings->eprelEnabled()) {
                throw new DomainException('EPREL dohvat nije uključen.');
            }
            // Also validates that the encrypted key can be read. The value is
            // intentionally discarded and never copied into a run record.
            $this->settings->eprelApiKey();
            $this->assertNoActiveRun();

            $hasCandidates = MsanProduct::query()
                ->where('is_stale', false)
                ->whereNotNull('local_product_id')
                ->where(function ($query): void {
                    $query->where('selected', true)
                        ->orWhere('import_status', MsanProduct::IMPORT_IMPORTED);
                })
                ->whereHas('categories.mapping', function ($query): void {
                    $query->whereNotNull('eprel_product_group')
                        ->where('eprel_product_group', '!=', '')
                        ->where('energy_requirement', '!=', 'not_applicable');
                })
                ->exists();
            if (! $hasCandidates) {
                throw new DomainException('Nema odabranih ili uvezenih artikala s mapiranom EPREL grupom.');
            }

            $run = MsanSyncRun::query()->create([
                'kind' => MsanSyncRun::KIND_EPREL,
                'status' => MsanSyncRun::STATUS_PENDING,
                'requested_by' => $userId,
                'progress' => 0,
            ]);

            try {
                SyncEprelEnergyJob::dispatch((int) $run->id)->onQueue('integrations');
            } catch (Throwable $exception) {
                $this->markDispatchFailed($run, 'EPREL dohvat nije moguće staviti u red.');
                throw $exception;
            }

            return $run->refresh();
        } finally {
            $lock->release();
        }
    }

    public function queueSpecifications(?int $userId = null): MsanSyncRun
    {
        $lock = Cache::lock('integrations:msan:queue-run', 30);
        if (! $lock->get()) {
            throw new DomainException('Druga M SAN obrada upravo se pokreće.');
        }

        try {
            $this->settings->assertEnabled();
            if (! $this->settings->importSpecifications()) {
                throw new DomainException('Dohvat M SAN specifikacija nije uključen.');
            }

            $hasTargets = MsanProduct::query()
                ->where('is_stale', false)
                ->when($this->settings->specificationsSelectedOnly(), fn ($query) => $query
                    ->where(fn ($selected) => $selected
                        ->where('selected', true)
                        ->orWhereNotNull('local_product_id')))
                ->exists();
            if (! $hasTargets) {
                throw new DomainException('Odaberite ili najprije uvezite barem jedan M SAN artikl.');
            }

            $this->assertNoActiveRun();
            $run = MsanSyncRun::query()->create([
                'kind' => MsanSyncRun::KIND_SPECIFICATIONS,
                'status' => MsanSyncRun::STATUS_PENDING,
                'requested_by' => $userId,
                'progress' => 0,
            ]);

            try {
                SyncMsanSpecificationsJob::dispatch((int) $run->id)->onQueue('integrations');
            } catch (Throwable $exception) {
                $this->markDispatchFailed($run, 'M SAN dohvat specifikacija nije moguće staviti u red.');
                throw $exception;
            }

            return $run->refresh();
        } finally {
            $lock->release();
        }
    }

    public function queueFtpConnectionTest(?int $userId = null): MsanSyncRun
    {
        $run = MsanSyncRun::query()->create([
            'kind' => 'ftp_connection_test',
            'status' => MsanSyncRun::STATUS_PENDING,
            'requested_by' => $userId,
            'progress' => 0,
        ]);

        try {
            TestMsanFtpConnectionJob::dispatch((int) $run->id)->onQueue('integrations');
        } catch (Throwable $exception) {
            $this->markDispatchFailed($run, 'Provjeru M SAN FTPS veze nije moguće staviti u red.');
            throw $exception;
        }

        return $run->refresh();
    }

    private function assertNoActiveRun(): void
    {
        if ($this->hasActiveRun()) {
            throw new DomainException('Pričekajte da trenutačna M SAN sinkronizacija ili uvoz završi.');
        }
    }

    private function hasActiveRun(): bool
    {
        return MsanSyncRun::query()
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
    }

    private function markDispatchFailed(MsanSyncRun $run, string $message): void
    {
        $run->forceFill([
            'status' => MsanSyncRun::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();
    }
}
