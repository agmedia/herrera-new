<?php

namespace App\Jobs\Integrations;

use App\Services\Analytics\SalesOpportunitySync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSalesOpportunities implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 7000;

    public int $tries = 1;

    public int $uniqueFor = 7200;

    public function __construct()
    {
        $this->onConnection('database')->onQueue('integrations');
    }

    public function handle(SalesOpportunitySync $sync): void
    {
        $sync->run();
    }
}
