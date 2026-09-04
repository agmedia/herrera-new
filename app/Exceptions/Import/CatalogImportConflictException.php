<?php

namespace App\Exceptions\Import;

use App\Data\Import\CatalogImportPlan;
use RuntimeException;

class CatalogImportConflictException extends RuntimeException
{
    public function __construct(public readonly CatalogImportPlan $plan)
    {
        parent::__construct(sprintf(
            'Catalog import for source [%s] has %d ownership or uniqueness conflict(s).',
            $plan->source,
            count($plan->conflicts()),
        ));
    }
}
