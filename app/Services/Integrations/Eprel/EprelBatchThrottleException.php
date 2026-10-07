<?php

namespace App\Services\Integrations\Eprel;

use App\Services\Integrations\Msan\EprelException;

class EprelBatchThrottleException extends EprelException
{
    public function __construct(public readonly int $retryAfter = 60)
    {
        parent::__construct('EPREL obrada čeka sljedeći dopušteni termin zahtjeva.');
    }
}
