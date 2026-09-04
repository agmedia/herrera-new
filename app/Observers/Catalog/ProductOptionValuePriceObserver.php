<?php

namespace App\Observers\Catalog;

use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Catalog\Product\ProductPriceHistory;

class ProductOptionValuePriceObserver
{
    public function created(ProductOptionValue $optionValue): void
    {
        if ($optionValue->price_override === null) {
            return;
        }

        $this->record($optionValue, null, $optionValue->price_override, 'created');
    }

    public function updated(ProductOptionValue $optionValue): void
    {
        if (! $optionValue->wasChanged('price_override')) {
            return;
        }

        $this->record(
            $optionValue,
            $optionValue->getOriginal('price_override'),
            $optionValue->price_override,
            'updated',
        );
    }

    public function deleting(ProductOptionValue $optionValue): void
    {
        if ($optionValue->price_override === null) {
            return;
        }

        $this->record($optionValue, $optionValue->price_override, null, 'deleted');
    }

    private function record(
        ProductOptionValue $optionValue,
        mixed $oldPrice,
        mixed $newPrice,
        string $event,
    ): void {
        ProductPriceHistory::query()->create([
            'product_id' => $optionValue->product_id,
            'product_option_value_id' => $optionValue->getKey(),
            'price_type' => 'option',
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'currency_code' => 'EUR',
            'effective_at' => now(),
            'source' => 'option_value',
            'changed_by' => auth()->id(),
            'payload' => ['event' => $event],
        ]);
    }
}
