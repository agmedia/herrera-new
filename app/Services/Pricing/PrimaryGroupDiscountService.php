<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User\CustomerGroup;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A declared primary-group default fills missing audience prices without rewriting exact agreements. */
class PrimaryGroupDiscountService
{
    public function percent(CustomerGroup $group): ?string
    {
        $percent = $group->default_discount_percent;

        return $group->is_active && ! $group->isBusinessSegment() && $percent !== null
            && is_numeric($percent) && (float) $percent >= 0 && (float) $percent <= 100 ? (string) $percent : null;
    }

    /** @return array{sql:string,bindings:array}|null */
    public function expression(PriceCatalog $catalog, CustomerGroup $group, Carbon $at): ?array
    {
        $percent = $this->percent($group);
        if ($percent === null) {
            return null;
        }
        $base = DB::table('catalog_price_entries as primary_group_base')->select('primary_group_base.price')
            ->where('primary_group_base.price_catalog_id', $catalog->id)
            ->whereColumn('primary_group_base.product_id', 'products.id')
            ->where('primary_group_base.kind', PriceCatalogEntry::BASE)->where('primary_group_base.is_active', true)
            ->whereNull('primary_group_base.user_id')->whereNull('primary_group_base.customer_group_id')
            ->where('primary_group_base.minimum_quantity', '<=', 1)
            ->where(fn (Builder $q) => $q->whereNull('primary_group_base.starts_at')->orWhere('primary_group_base.starts_at', '<', $at))
            ->where(fn (Builder $q) => $q->whereNull('primary_group_base.ends_at')->orWhere('primary_group_base.ends_at', '>', $at))
            ->orderBy('primary_group_base.priority')->orderBy('primary_group_base.price')->orderBy('primary_group_base.id')->limit(1);

        return [
            'sql' => 'ROUND(COALESCE(('.$base->toSql().'), products.base_price) * (100.0 - ?) / 100.0, 4)',
            'bindings' => [...$base->getBindings(), $percent],
        ];
    }

    /** @return array<int,float> */
    public function forProducts(array $productIds, PriceCatalog $catalog, CustomerGroup $group, Carbon $at): array
    {
        if ($productIds === [] || ! ($price = $this->expression($catalog, $group, $at))) {
            return [];
        }

        return Product::query()->whereKey($productIds)->select('products.id')
            ->selectRaw($price['sql'].' as primary_group_default_price', $price['bindings'])->get()
            ->mapWithKeys(fn (Product $product): array => [(int) $product->id => (float) $product->primary_group_default_price])->all();
    }
}
