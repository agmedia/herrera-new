<?php

namespace App\Jobs\Integrations;

use App\Services\Catalog\Clearance\CompanySalesClearanceSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCompanySalesForClearance implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7000;

    public int $tries = 1;

    public function __construct(public ?int $actorId = null)
    {
        $this->onConnection('database')->onQueue('integrations');
    }

    public function handle(CompanySalesClearanceSync $sync): void
    {
        $sync->run(true, false, $this->actorId);
    }
}
