<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Pricing\LegacyGroupDiscountReference;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User\CustomerGroup;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Read-only view of current group rules and preserved catalog price sources. */
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
        $dynamic = app(PriceCatalogResolver::class)->inheritedDiscounts()->expression($catalog, $groupId, now());
        $expressions = array_filter([$dynamic,
            app(PriceCatalogResolver::class)->legacyDiscounts()->expression($catalog, $groupId, now(), 'mega_sale'),
            app(PriceCatalogResolver::class)->legacyDiscounts()->expression($catalog, $groupId, now(), 'cigroup_template')]);
        $group = CustomerGroup::query()->find($groupId);
        if ($group && app(PrimaryGroupDiscountService::class)->percent($group) !== null) {
            $expressions[] = ['sql' => 'products.base_price', 'bindings' => []];
        }

        return Product::query()->with('translations')->where(function ($products) use ($catalog, $groupId, $expressions): void {
            $products->whereExists(function ($query) use ($catalog, $groupId): void {
                $query->selectRaw('1')->from('catalog_price_entries')->whereColumn('product_id', 'products.id')
                    ->where('price_catalog_id', $catalog->id)->whereNull('user_id')
                    ->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', PriceCatalogEntry::BASE)->whereNull('customer_group_id'))->orWhere('customer_group_id', $groupId));
            });
            foreach ($expressions as $expression) {
                $products->orWhereRaw($expression['sql'].' IS NOT NULL', $expression['bindings']);
            }
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
        $dynamic = app(PriceCatalogResolver::class)->inheritedDiscounts()->forProducts($products->pluck('id')->all(), $catalog, $groupId, $at);
        $rules = $catalog->discountRules()->whereIn('id', array_column($dynamic, 'rule_id'))->get()->keyBy('id');
        $legacyCategory = app(PriceCatalogResolver::class)->legacyDiscounts()->forProducts($products->pluck('id')->all(), $catalog, $groupId, $at, 'mega_sale');
        $legacyGroup = app(PriceCatalogResolver::class)->legacyDiscounts()->forProducts($products->pluck('id')->all(), $catalog, $groupId, $at, 'cigroup_template');
        $references = LegacyGroupDiscountReference::query()->whereIn('id', array_unique([...array_column($legacyCategory, 'reference_id'), ...array_column($legacyGroup, 'reference_id')]))->get()->keyBy('id');
        $group = CustomerGroup::query()->find($groupId);
        $primary = $group ? app(PrimaryGroupDiscountService::class)->forProducts($products->pluck('id')->all(), $catalog, $group, $at) : [];
        $rows = [];
        foreach ($products as $product) {
            $sources = $entries->get($product->id, collect());
            $current = $sources->filter(fn ($entry) => $entry->kind !== PriceCatalogEntry::GROUP_DISCOUNT && $entry->is_active
                && (! $entry->starts_at || $entry->starts_at->lt($at))
                && (! $entry->ends_at || $entry->ends_at->gt($at)));
            $effective = $current->first(fn ($entry) => $entry->minimum_quantity <= 1);
            $base = $current->first(fn ($entry) => $entry->kind === PriceCatalogEntry::BASE && $entry->minimum_quantity <= 1)?->price
                ?? (string) BigDecimal::of((string) $product->getRawOriginal('base_price'))->toScale(4);
            $sourceType = 'price_catalog_'.($effective?->kind ?? 'base');
            $sourceName = null;
            $sourceId = $effective?->id;
            $legacy = null;
            if (isset($legacyCategory[$product->id]) && ($effective === null || in_array($effective->kind, [PriceCatalogEntry::GROUP, PriceCatalogEntry::BASE], true))) {
                $legacy = $legacyCategory[$product->id];
                $sourceType = 'price_catalog_legacy_category_discount';
            } elseif (isset($legacyGroup[$product->id]) && ($effective === null || $effective->kind === PriceCatalogEntry::BASE)) {
                $legacy = $legacyGroup[$product->id];
                $sourceType = 'price_catalog_legacy_group_discount';
            } elseif (isset($primary[$product->id]) && ($effective === null || $effective->kind === PriceCatalogEntry::BASE)) {
                $effective = new PriceCatalogEntry(['kind' => PriceCatalogEntry::GROUP, 'price' => number_format($primary[$product->id], 4, '.', '')]);
                $sourceType = 'price_catalog_primary_group_default';
                $sourceName = __('Zadani popust primarne grupe');
                $sourceId = $groupId;
            }
            if ($legacy !== null) {
                $reference = $references->get($legacy['reference_id']);
                $effective = new PriceCatalogEntry(['kind' => $sourceType === 'price_catalog_legacy_category_discount' ? PriceCatalogEntry::SPECIAL : PriceCatalogEntry::GROUP,
                    'price' => number_format($legacy['price'], 4, '.', ''), 'is_active' => true]);
                $effective->setRelation('discountRule', $reference);
                $sourceName = $reference?->name;
                $sourceId = $legacy['reference_id'];
            }
            if (isset($dynamic[$product->id])) {
                $match = $dynamic[$product->id];
                $rule = $rules->get($match['rule_id']);
                $effective = new PriceCatalogEntry(['price_catalog_id' => $catalog->id, 'product_id' => $product->id,
                    'kind' => PriceCatalogEntry::GROUP_DISCOUNT, 'customer_group_id' => $groupId,
                    'discount_rule_id' => $match['rule_id'], 'price' => number_format($match['price'], 4, '.', ''),
                    'minimum_quantity' => 1, 'priority' => $rule?->priority ?? 0, 'is_active' => true,
                    'starts_at' => $rule?->starts_at, 'ends_at' => $rule?->ends_at]);
                $effective->setRelation('discountRule', $rule);
                $sourceType = 'price_catalog_group_discount';
                $sourceName = $rule?->name;
                $sourceId = $match['rule_id'];
            }
            $price = $effective?->price ?? $base;
            $percent = BigDecimal::of($base)->isGreaterThan('0')
                ? (string) BigDecimal::of($base)->minus($price)->multipliedBy('100')->dividedBy($base, 2, RoundingMode::HalfUp)
                : null;
            $rows[$product->id] = ['product' => $product, 'base_price' => $base, 'effective_price' => $price,
                'effective_entry' => $effective, 'discount_percent' => $percent, 'entries' => $sources,
                'source_type' => $sourceType, 'source_name' => $sourceName, 'source_id' => $sourceId];
        }

        return $rows;
    }
}
