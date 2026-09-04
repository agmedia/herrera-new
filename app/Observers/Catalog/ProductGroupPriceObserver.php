<?php

namespace App\Observers\Catalog;

use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Catalog\Product\ProductPriceHistory;

class ProductGroupPriceObserver
{
    public function created(ProductGroupPrice $price): void
    {
        $this->record($price, null, $price->price, 'created');
    }

    public function updated(ProductGroupPrice $price): void
    {
        if (! $price->wasChanged('price')) {
            return;
        }

        $this->record($price, $price->getOriginal('price'), $price->price, 'updated');
    }

    public function deleted(ProductGroupPrice $price): void
    {
        $this->record($price, $price->price, null, 'deleted');
    }

    private function record(
        ProductGroupPrice $price,
        mixed $oldPrice,
        mixed $newPrice,
        string $event,
    ): void {
        ProductPriceHistory::query()->create([
            'product_id' => $price->product_id,
            'customer_group_id' => $price->customer_group_id,
            'product_package_id' => $price->product_package_id,
            'price_type' => 'b2b',
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'currency_code' => $price->currency_code ?: 'EUR',
            'effective_at' => $price->starts_at ?? now(),
            'source' => 'group_price',
            'changed_by' => auth()->id(),
            'payload' => [
                'event' => $event,
                'minimum_quantity' => (int) $price->minimum_quantity,
            ],
        ]);
    }
}
