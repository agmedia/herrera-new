<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Read-only group view of the authoritative absolute prices, not inferred discount rules. */
class PriceCatalogGroupOverview
{
    public function counts(PriceCatalog $catalog): Collection
    {
        return $catalog->entries()->whereNotNull('customer_group_id')->whereNull('user_id')
            ->selectRaw('customer_group_id, kind, COUNT(*) AS entry_count')
            ->groupBy('customer_group_id', 'kind')->get()->groupBy('customer_group_id')
            ->map(fn ($rows) => $rows->pluck('entry_count', 'kind')->map(fn ($count) => (int) $count)->all());
    }

    public function products(PriceCatalog $catalog, int $groupId, string $search = ''): Builder
    {
        return Product::query()->with('translations')->whereExists(function ($query) use ($catalog, $groupId): void {
            $query->selectRaw('1')->from('catalog_price_entries')->whereColumn('product_id', 'products.id')
                ->where('price_catalog_id', $catalog->id)->whereNull('user_id')
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', PriceCatalogEntry::BASE)->whereNull('customer_group_id'))->orWhere('customer_group_id', $groupId));
        })->when(trim($search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('sku', 'like', '%'.trim($search).'%')
            ->orWhere('code', 'like', '%'.trim($search).'%')
            ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.trim($search).'%'))))
            ->orderBy('sku')->orderBy('products.id');
    }

    /** Current price for one unit, without customer-specific exceptions; all other conditions remain visible. */
    public function rows(PriceCatalog $catalog, int $groupId, Collection $products): array
    {
        $entries = $catalog->entries()->with('discountRule')->whereIn('product_id', $products->pluck('id'))
            ->whereNull('user_id')->where(fn ($q) => $q->where('customer_group_id', $groupId)
            ->orWhere(fn ($q) => $q->where('kind', PriceCatalogEntry::BASE)->whereNull('customer_group_id')))
            ->orderByRaw(PriceCatalogEntry::precedenceSql())
            ->orderByRaw(PriceCatalogEntry::manualGroupPrecedenceSql())
            ->orderByRaw("CASE WHEN kind = 'quantity' THEN minimum_quantity ELSE 0 END DESC")
            ->orderBy('priority')->orderBy('price')->orderBy('discount_rule_id')->orderBy('id')->get()->groupBy('product_id');
        $at = now();
        $rows = [];
        foreach ($products as $product) {
            $sources = $entries->get($product->id, collect());
            $current = $sources->filter(fn ($entry) => $entry->is_active
                && (! $entry->starts_at || ($entry->kind === PriceCatalogEntry::GROUP_DISCOUNT ? $entry->starts_at->lte($at) : $entry->starts_at->lt($at)))
                && (! $entry->ends_at || $entry->ends_at->gt($at)));
            $effective = $current->first(fn ($entry) => $entry->minimum_quantity <= 1);
            $base = $current->firstWhere('kind', PriceCatalogEntry::BASE)?->price
                ?? (string) BigDecimal::of((string) $product->getRawOriginal('base_price'))->toScale(4);
            $price = $effective?->price ?? $base;
            $percent = BigDecimal::of($base)->isGreaterThan('0')
                ? (string) BigDecimal::of($base)->minus($price)->multipliedBy('100')->dividedBy($base, 2, RoundingMode::HalfUp)
                : null;
            $rows[$product->id] = ['product' => $product, 'base_price' => $base, 'effective_price' => $price,
                'effective_entry' => $effective, 'discount_percent' => $percent, 'entries' => $sources];
        }

        return $rows;
    }
}
