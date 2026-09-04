<?php

namespace App\Jobs\Integrations\Msan;

use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSpecificationSnapshot;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanSpecificationPublisher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

class RepublishMsanSpecificationDefinitionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 100;

    public int $timeout = 3600;

    public int $uniqueFor = 7200;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(private readonly int $definitionId) {}

    public function uniqueId(): string
    {
        return 'msan-specification-definition-'.$this->definitionId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('msan-specification-local-republish'))
                ->releaseAfter(30)
                ->expireAfter(7200),
        ];
    }

    public function handle(MsanSpecificationPublisher $publisher): void
    {
        $lock = Cache::lock(
            MsanSpecificationPublisher::PUBLISH_LOCK_KEY,
            MsanSpecificationPublisher::PUBLISH_LOCK_SECONDS,
        );
        if (! $lock->get()) {
            $this->release(120);

            return;
        }

        try {
            $this->republish($publisher);
        } finally {
            $lock->release();
        }
    }

    private function republish(MsanSpecificationPublisher $publisher): void
    {
        $specificationSyncActive = MsanSyncRun::query()
            ->where('kind', MsanSyncRun::KIND_SPECIFICATIONS)
            ->whereIn('status', [MsanSyncRun::STATUS_PENDING, MsanSyncRun::STATUS_RUNNING])
            ->exists();
        if ($specificationSyncActive) {
            $this->release(120);

            return;
        }

        $snapshot = MsanSpecificationSnapshot::query()
            ->where('status', MsanSpecificationSnapshot::STATUS_ACTIVE)
            ->latest('id')
            ->first();
        if (! $snapshot) {
            return;
        }

        MsanProduct::query()
            ->select(['id', 'external_code', 'local_product_id'])
            ->whereNotNull('local_product_id')
            ->whereHas('specifications', function (Builder $query) use ($snapshot): void {
                $query
                    ->where('snapshot_id', $snapshot->id)
                    ->where('definition_id', $this->definitionId);
            })
            ->orderBy('id')
            ->chunkById(100, function ($sources) use ($publisher, $snapshot): void {
                foreach ($sources as $source) {
                    $publisher->publishProductFromSnapshot($source, $snapshot);
                }
            });
    }
}
