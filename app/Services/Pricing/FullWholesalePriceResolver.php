<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use Illuminate\Support\Facades\DB;

/** Full wholesale BASE, independent of customer/group/quantity discounts. */
class FullWholesalePriceResolver
{
    private array $loaded = [];

    private array $prices = [];

    public function base(Product $product, ?float $fallback = null): float
    {
        $catalog = app(PriceCatalogResolver::class)->activeCatalog();
        if (! $catalog) {
            return $fallback ?? (float) $product->base_price;
        }
        if (! isset($this->loaded[$catalog->id])) {
            // One batch covers both existing clearance prices and candidates
            // activated in this request/job. Other product prices pay no cost.
            $entries = DB::table('catalog_price_entries')->where('price_catalog_id', $catalog->id)
                ->where('kind', PriceCatalogEntry::BASE)->where('is_active', true)
                ->whereNull('user_id')->whereNull('customer_group_id')->where('minimum_quantity', '<=', 1)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->where(fn ($query) => $query->whereIn('product_id', DB::table('product_clearances')->select('product_id')->whereIn('status', ['observed', 'active']))->orWhere('product_id', $product->id))
                ->orderBy('priority')->orderBy('price')->orderBy('id')->get(['product_id', 'price']);
            foreach ($entries as $entry) {
                $this->prices[$catalog->id][$entry->product_id] ??= (float) $entry->price;
            }
            $this->loaded[$catalog->id] = true;
        }

        return $this->prices[$catalog->id][$product->id] ?? $fallback ?? (float) $product->base_price;
    }
}
