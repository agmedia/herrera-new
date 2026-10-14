<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only extension of published rules to new products; the published price snapshot stays untouched. */
class CatalogGroupDiscountInheritance
{
    private array $rules = [];

    private ?array $categoryTree = null;

    /** @return array{sql:string,bindings:array}|null */
    public function expression(PriceCatalog $catalog, int $groupId, Carbon $at, string $column = 'price'): ?array
    {
        // A draft is always explicitly rebuilt at publication. Legacy catalogs
        // without a publication timestamp cannot safely identify new products.
        $ceiling = $catalog->metadata['publication_product_id_ceiling'] ?? null;
        if ($catalog->status === PriceCatalog::DRAFT || ($ceiling === null && ! $catalog->activated_at)) {
            return null;
        }
        $rules = $this->rules($catalog, $groupId);
        if ($rules === []) {
            return null;
        }
        $price = 'ROUND(inherited_product.base_price * (100.0 - inherited_rule.percent) / 100.0, 4)';
        $query = DB::table('catalog_group_discount_rules as inherited_rule')
            ->crossJoin('products as inherited_product')->whereColumn('inherited_product.id', 'products.id')
            ->selectRaw($column === 'rule_id' ? 'inherited_rule.id' : $price)
            ->where('inherited_rule.price_catalog_id', $catalog->id)
            ->where('inherited_rule.is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('inherited_rule.starts_at')->orWhere('inherited_rule.starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('inherited_rule.ends_at')->orWhere('inherited_rule.ends_at', '>', $at));
        if ($ceiling !== null) {
            $query->where('inherited_product.id', '>', (int) $ceiling);
        } else {
            $query->where('inherited_product.created_at', '>', $catalog->activated_at);
        }
        // Even an expired/inactive or different-audience stored price marks a
        // product as part of the original snapshot. Never recalculate it.
        $query->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_price_entries as inherited_snapshot')
            ->where('inherited_snapshot.price_catalog_id', $catalog->id)->whereColumn('inherited_snapshot.product_id', 'inherited_product.id'));
        $query->where(function (Builder $q) use ($rules): void {
            foreach ($rules as $rule) {
                $q->orWhere(function (Builder $scope) use ($rule): void {
                    $scope->where('inherited_rule.id', $rule['id']);
                    if ($rule['manufacturer_ids'] !== []) {
                        $scope->whereIn('inherited_product.manufacturer_id', $rule['manufacturer_ids']);
                    }
                    if ($rule['category_ids'] !== []) {
                        $scope->whereExists(fn (Builder $categories) => $categories->selectRaw('1')->from('category_product as inherited_category')
                            ->whereColumn('inherited_category.product_id', 'inherited_product.id')->whereIn('inherited_category.category_id', $rule['category_ids']));
                    }
                    if ($rule['excluded_product_ids'] !== []) {
                        $scope->whereNotIn('inherited_product.id', $rule['excluded_product_ids']);
                    }
                });
            }
        })->orderBy('inherited_rule.priority')->orderByRaw($price)->orderBy('inherited_rule.id')->limit(1);

        return ['sql' => '('.$query->toSql().')', 'bindings' => $query->getBindings()];
    }

    /** @return array<int, array{price:float,rule_id:int}> */
    public function forProducts(array $productIds, PriceCatalog $catalog, int $groupId, Carbon $at): array
    {
        if ($productIds === []) {
            return [];
        }
        $price = $this->expression($catalog, $groupId, $at);
        if ($price === null) {
            return [];
        }
        $rule = $this->expression($catalog, $groupId, $at, 'rule_id');
        $products = Product::query()->whereKey($productIds)->select('products.id')
            ->selectRaw($price['sql'].' as inherited_price', $price['bindings'])
            ->selectRaw($rule['sql'].' as inherited_rule_id', $rule['bindings'])->get();
        $prices = [];
        foreach ($products as $product) {
            if ($product->inherited_rule_id !== null) {
                $prices[(int) $product->id] = ['price' => (float) $product->inherited_price, 'rule_id' => (int) $product->inherited_rule_id];
            }
        }

        return $prices;
    }

    private function rules(PriceCatalog $catalog, int $groupId): array
    {
        $key = $catalog->id.':'.$groupId;
        if (array_key_exists($key, $this->rules)) {
            return $this->rules[$key];
        }
        $rules = $catalog->discountRules()->where('is_active', true)->get()->filter(
            fn ($rule) => in_array($groupId, $rule->customer_group_ids, true)
        );
        $scopes = [];
        foreach ($rules as $rule) {
            $ids = $rule->category_ids;
            if ($ids !== [] && $rule->include_descendants) {
                $this->categoryTree ??= Category::query()->where('scope', Category::SCOPE_CATALOG)->get(['id', 'parent_id'])->toArray();
                do {
                    $before = count($ids);
                    foreach ($this->categoryTree as $category) {
                        if (in_array((int) $category['parent_id'], $ids, true)) {
                            $ids[] = (int) $category['id'];
                        }
                    }
                    $ids = array_values(array_unique($ids));
                } while (count($ids) > $before);
            }
            $scopes[] = ['id' => $rule->id, 'manufacturer_ids' => $rule->manufacturer_ids,
                'category_ids' => $ids, 'excluded_product_ids' => $rule->excluded_product_ids];
        }

        return $this->rules[$key] = $scopes;
    }
}
