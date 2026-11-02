<?php

namespace App\Observers\Catalog;

use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Eprel\EprelAutomaticLookupService;

class ProductEprelObserver
{
    public function created(Product $product): void
    {
        $this->schedule($product);
    }

    public function updated(Product $product): void
    {
        // Energy-data writes and ordinary stock/price updates must not enqueue
        // another lookup. Supplier imports remain drafts until publication.
        if ($product->wasChanged('is_active')) {
            $this->schedule($product);
        }
    }

    private function schedule(Product $product): void
    {
        if (! $product->is_active) {
            return;
        }

        $productId = (int) $product->getKey();
        $product->getConnection()->afterCommit(static function () use ($productId): void {
            // Categories, packages and translations are saved after the product
            // itself. Read the lookup criteria only once their transaction ends.
            app(EprelAutomaticLookupService::class)->dispatchFor($productId);
        });
    }
}
