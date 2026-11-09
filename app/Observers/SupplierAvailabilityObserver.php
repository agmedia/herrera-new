<?php

namespace App\Observers;

use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Stock\FixedSupplierAvailabilityService;

class SupplierAvailabilityObserver
{
    public function created(Product $product): void
    {
        app(FixedSupplierAvailabilityService::class)->applyToNewProduct($product);
    }
}
