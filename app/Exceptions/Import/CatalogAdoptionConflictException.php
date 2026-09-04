<?php

namespace App\Exceptions\Import;

use App\Data\Import\CatalogAdoptionPlan;
use RuntimeException;

class CatalogAdoptionConflictException extends RuntimeException
{
    public function __construct(public readonly CatalogAdoptionPlan $plan)
    {
        parent::__construct(sprintf(
            'Catalog adoption for source [%s] has %d ownership or identity conflict(s).',
            $plan->source,
            count($plan->conflicts()),
        ));
    }
}
