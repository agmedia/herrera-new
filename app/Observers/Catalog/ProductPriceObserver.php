<?php

namespace App\Observers\Catalog;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductPriceHistory;

class ProductPriceObserver
{
    public function created(Product $product): void
    {
        $this->record($product, null, $product->base_price);
    }

    public function updated(Product $product): void
    {
        if (! $product->wasChanged('base_price')) {
            return;
        }

        $this->record($product, $product->getOriginal('base_price'), $product->base_price);
    }

    private function record(Product $product, mixed $oldPrice, mixed $newPrice): void
    {
        ProductPriceHistory::query()->create([
            'product_id' => $product->getKey(),
            'price_type' => 'base',
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'currency_code' => 'EUR',
            'effective_at' => now(),
            'source' => 'product',
            'changed_by' => auth()->id(),
        ]);
    }
}
