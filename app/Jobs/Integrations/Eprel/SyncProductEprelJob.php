<?php

namespace App\Jobs\Integrations\Eprel;

use App\Services\Integrations\Eprel\EprelAutomaticLookupService;
use App\Services\Integrations\Eprel\EprelBatchThrottleException;
use App\Services\Integrations\Msan\EprelHttpException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SyncProductEprelJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $uniqueFor = 21600;

    public int $maxExceptions = 3;

    public array $backoff = [60, 300, 900];

    public readonly int $retryDeadline;

    public function __construct(
        public readonly int $productId,
        public readonly string $identity,
        public readonly array $criteria,
        public readonly array $energyState,
    ) {
        $this->onConnection('eprel');
        $this->onQueue('eprel');
        $this->retryDeadline = now()->addHours(6)->getTimestamp();
    }

    public function uniqueId(): string
    {
        return 'product:'.$this->productId.':'.hash('sha256', serialize([$this->identity, $this->criteria, $this->energyState]));
    }

    public function retryUntil(): DateTimeInterface
    {
        return (new DateTimeImmutable)->setTimestamp($this->retryDeadline);
    }

    public function handle(EprelAutomaticLookupService $service): void
    {
        try {
            $service->lookup($this->productId, $this->identity, $this->criteria, $this->energyState);
        } catch (EprelBatchThrottleException $exception) {
            // The same per-request quota is shared with the manual catalogue
            // sync. Throttling may defer the job, but never beyond retryUntil.
            $this->release(max(5, min(900, $exception->retryAfter + 2)));
        } catch (EprelHttpException $exception) {
            if (in_array($exception->status, [401, 403], true)) {
                $this->fail(new RuntimeException('Automatski EPREL dohvat nije odobren. Provjerite EPREL API postavke.'));

                return;
            }

            throw $exception;
        }
    }
}
