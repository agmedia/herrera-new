<?php

namespace App\Observers;

use App\Models\Catalog\Product\Product;
use App\Services\Catalog\Clearance\ClearanceService;

class ProductClearanceObserver
{
    public function updated(Product $product): void
    {
        if ($product->wasChanged('is_active') && $product->is_active) {
            app(ClearanceService::class)->recordManualReactivation($product);
        }
        if ($product->wasChanged('stock_qty')) {
            app(ClearanceService::class)->reconcile($product);
        }
    }
}
