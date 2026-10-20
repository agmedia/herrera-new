<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Pricing\CustomerDiscountRule;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Admin\OrderManagerAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Customer percentages replace group prices; they never modify a published catalog. */
class CustomerDiscountService
{
    private array $scopes = [];

    private ?array $categoryTree = null;

    public function authorize(User $customer, User $actor, string $operation = 'view'): void
    {
        app(PriceCatalogService::class)->authorize($actor, $operation);
        abort_if(! $actor->isA('superadmin') && $customer->isA('superadmin'), 403);
        app(OrderManagerAccess::class)->assertCustomer($customer, $actor);
    }

    public function saveRule(User $customer, array $data, User $actor, ?int $ruleId = null): CustomerDiscountRule
    {
        $this->authorize($customer, $actor, 'update');
        if (! $customer->b2bAccount()->exists()) {
            throw ValidationException::withMessages(['customer' => 'Najprije spremite B2B profil kupca.']);
        }
        $data = $this->validated($data);
        $rule = DB::transaction(function () use ($customer, $data, $actor, $ruleId): CustomerDiscountRule {
            $rule = $ruleId ? CustomerDiscountRule::query()->where('user_id', $customer->id)->lockForUpdate()->findOrFail($ruleId)
                : new CustomerDiscountRule(['user_id' => $customer->id, 'created_by' => $actor->id]);
            $before = $rule->exists ? $rule->toArray() : null;
            if ($rule->exists && $rule->source_system !== null) {
                $rule->payload = array_replace($rule->payload ?? [], ['customer_rule_edited' => true]);
            }
            $rule->fill($data + ['updated_by' => $actor->id])->save();
            $this->audit($rule, $actor, $before ? 'customer_discount_updated' : 'customer_discount_created', $before, $rule->toArray());

            return $rule;
        });
        $this->invalidate();

        return $rule;
    }

    public function deleteRule(User $customer, int $ruleId, User $actor): void
    {
        $this->authorize($customer, $actor, 'delete');
        DB::transaction(function () use ($customer, $ruleId, $actor): void {
            $rule = CustomerDiscountRule::query()->where('user_id', $customer->id)->lockForUpdate()->findOrFail($ruleId);
            $this->audit($rule, $actor, 'customer_discount_deleted', $rule->toArray(), null);
            $rule->delete();
        });
        $this->invalidate();
    }

    /** The same scope is used for admin previews and price calculations. */
    public function products(array $data): Builder
    {
        $data += ['manufacturer_ids' => [], 'category_ids' => [], 'include_descendants' => true, 'excluded_product_ids' => []];
        $query = Product::query();
        if ($data['manufacturer_ids'] !== []) {
            $query->whereIn('manufacturer_id', $data['manufacturer_ids']);
        }
        if ($data['category_ids'] !== []) {
            $ids = $this->categoryIds($data['category_ids'], (bool) $data['include_descendants']);
            $query->whereHas('categories', fn (Builder $categories) => $categories->whereIn('categories.id', $ids));
        }
        if ($data['excluded_product_ids'] !== []) {
            $query->whereNotIn('products.id', $data['excluded_product_ids']);
        }

        return $query;
    }

    /** Original category rules, including tombstones, supersede their old generated product prices. */
    public function applyLegacyEntryExclusions(Builder|QueryBuilder $query, User $customer, string $alias = 'catalog_price_entries'): void
    {
        $entryCategory = $this->legacyIdSql($alias.'.payload', 'category_id');
        $entryCustomer = $this->legacyIdSql($alias.'.payload', 'customer_id');
        $ruleCategory = $this->legacyIdSql('replaced_customer_rule.payload', 'category_id');
        $ruleCustomer = $this->legacyIdSql('replaced_customer_rule.payload', 'customer_id');
        $sourceKey = DB::connection()->getDriverName() === 'sqlite'
            ? "('opencart:customer-category:' || ".$entryCustomer." || ':' || ".$entryCategory.')'
            : "CONCAT('opencart:customer-category:', ".$entryCustomer.", ':', ".$entryCategory.')';
        // Intentionally use the table builder: inactive/deleted rules are still
        // authoritative replacements for their original generated price rows.
        $query->whereNotExists(function (QueryBuilder $replacement) use ($customer, $alias, $entryCategory, $entryCustomer, $ruleCategory, $ruleCustomer, $sourceKey): void {
            $replacement->selectRaw('1')->from('customer_discount_rules as replaced_customer_rule')
                ->where('replaced_customer_rule.user_id', $customer->id)
                ->where('replaced_customer_rule.source_system', 'opencart')->whereNotNull('replaced_customer_rule.source_key')
                ->where($alias.'.kind', PriceCatalogEntry::CUSTOMER)
                ->whereRaw($entryCategory.' > 0')->whereRaw($entryCustomer.' > 0')
                ->where(fn (QueryBuilder $mapping) => $mapping->whereRaw('('.$ruleCategory.' = '.$entryCategory.' AND '.$ruleCustomer.' = '.$entryCustomer.')')
                    ->orWhereRaw('replaced_customer_rule.source_key = '.$sourceKey));
        });
    }

    /** @return array{sql:string,bindings:array}|null */
    public function expression(PriceCatalog $catalog, User $customer, Carbon $at, string $column = 'price'): ?array
    {
        $rules = $this->scopes($customer);
        if ($rules === []) {
            return null;
        }
        $selectors = [];
        $bindings = [];
        foreach ([0, 1, 2, 3] as $specificity) {
            $matchingRules = array_values(array_filter($rules, fn (array $rule): bool => $rule['specificity'] === $specificity));
            if ($matchingRules === []) {
                continue;
            }
            $query = $this->winnerQuery($matchingRules, $customer, $at, $column === 'rule_id' ? 'id' : 'percent');
            $selectors[] = '('.$query->toSql().')';
            $bindings = [...$bindings, ...$query->getBindings()];
        }
        $winner = count($selectors) === 1 ? $selectors[0] : 'COALESCE('.implode(', ', $selectors).')';
        if ($column === 'rule_id') {
            return ['sql' => $winner, 'bindings' => $bindings];
        }
        // Resolve the winning percentage before calculating its price. The
        // base-price lookup must run once per product, not once per candidate.
        $base = DB::table('catalog_price_entries as customer_discount_base')->select('customer_discount_base.price')
            ->where('customer_discount_base.price_catalog_id', $catalog->id)
            ->whereColumn('customer_discount_base.product_id', 'products.id')
            ->where('customer_discount_base.kind', PriceCatalogEntry::BASE)->where('customer_discount_base.is_active', true)
            ->whereNull('customer_discount_base.user_id')->whereNull('customer_discount_base.customer_group_id')
            ->where('customer_discount_base.minimum_quantity', '<=', 1)
            ->where(fn (QueryBuilder $q) => $q->whereNull('customer_discount_base.starts_at')->orWhere('customer_discount_base.starts_at', '<', $at))
            ->where(fn (QueryBuilder $q) => $q->whereNull('customer_discount_base.ends_at')->orWhere('customer_discount_base.ends_at', '>', $at))
            ->orderBy('customer_discount_base.priority')->orderBy('customer_discount_base.price')->orderBy('customer_discount_base.id')->limit(1);

        return [
            'sql' => 'ROUND(COALESCE(('.$base->toSql().'), products.base_price) * (100.0 - '.$winner.') / 100.0, 4)',
            'bindings' => [...$base->getBindings(), ...$bindings],
        ];
    }

    /** Start with a product's indexed category memberships instead of probing every rule's categories. */
    private function winnerQuery(array $rules, User $customer, Carbon $at, string $column): QueryBuilder
    {
        if ($rules[0]['category_ids'] !== []) {
            $targets = [];
            foreach ($rules as $rule) {
                foreach ($rule['category_ids'] as $categoryId) {
                    $targets[] = DB::query()->selectRaw((int) $rule['id'].' as rule_id, '.(int) $categoryId.' as category_id');
                }
            }
            // Keep each compound SELECT below SQLite's limit, including large
            // explicitly selected scopes and expanded descendant categories.
            do {
                $parts = [];
                foreach (array_chunk($targets, 300) as $chunk) {
                    $part = array_shift($chunk);
                    foreach ($chunk as $target) {
                        $part->unionAll($target);
                    }
                    $parts[] = $part;
                }
                $targets = count($parts) === 1 ? $parts : array_map(
                    fn (QueryBuilder $part): QueryBuilder => DB::query()->fromSub($part, 'customer_discount_scope_rows')->select(['rule_id', 'category_id']), $parts,
                );
            } while (count($targets) > 1);
            $query = DB::table('category_product as customer_discount_category')
                ->joinSub($targets[0], 'customer_discount_scope', 'customer_discount_scope.category_id', '=', 'customer_discount_category.category_id')
                ->join('customer_discount_rules as customer_discount_rule', 'customer_discount_rule.id', '=', 'customer_discount_scope.rule_id')
                ->whereColumn('customer_discount_category.product_id', 'products.id');
        } else {
            $query = DB::table('customer_discount_rules as customer_discount_rule')->whereIn('customer_discount_rule.id', array_column($rules, 'id'));
        }
        $query->select('customer_discount_rule.'.$column)
            ->where('customer_discount_rule.user_id', $customer->id)->where('customer_discount_rule.is_active', true)
            ->whereNull('customer_discount_rule.deleted_at')
            ->where(fn (QueryBuilder $q) => $q->whereNull('customer_discount_rule.starts_at')->orWhere('customer_discount_rule.starts_at', '<=', $at))
            ->where(fn (QueryBuilder $q) => $q->whereNull('customer_discount_rule.ends_at')->orWhere('customer_discount_rule.ends_at', '>', $at));
        $guarded = array_filter($rules, fn (array $rule): bool => $rule['manufacturer_ids'] !== [] || $rule['excluded_product_ids'] !== []);
        if ($guarded !== []) {
            $query->where(function (QueryBuilder $q) use ($rules): void {
                foreach ($rules as $rule) {
                    $q->orWhere(function (QueryBuilder $scope) use ($rule): void {
                        $scope->where('customer_discount_rule.id', $rule['id']);
                        if ($rule['manufacturer_ids'] !== []) {
                            $scope->whereIn('products.manufacturer_id', $rule['manufacturer_ids']);
                        }
                        if ($rule['excluded_product_ids'] !== []) {
                            $scope->whereNotIn('products.id', $rule['excluded_product_ids']);
                        }
                    });
                }
            });
        }

        return $query->orderBy('customer_discount_rule.priority')->orderBy('customer_discount_rule.id')->limit(1);
    }

    /** @return array<int, array{price:float,rule_id:int}> */
    public function forProducts(array $productIds, PriceCatalog $catalog, User $customer, Carbon $at): array
    {
        if ($productIds === [] || ! ($price = $this->expression($catalog, $customer, $at))) {
            return [];
        }
        $rule = $this->expression($catalog, $customer, $at, 'rule_id');
        $products = Product::query()->whereKey($productIds)->select('products.id')
            ->selectRaw($price['sql'].' as customer_discount_price', $price['bindings'])
            ->selectRaw($rule['sql'].' as customer_discount_rule_id', $rule['bindings'])->get();
        $prices = [];
        foreach ($products as $product) {
            if ($product->customer_discount_rule_id !== null) {
                $prices[(int) $product->id] = ['price' => (float) $product->customer_discount_price, 'rule_id' => (int) $product->customer_discount_rule_id];
            }
        }

        return $prices;
    }

    private function scopes(User $customer): array
    {
        if (array_key_exists($customer->id, $this->scopes)) {
            return $this->scopes[$customer->id];
        }
        $rules = CustomerDiscountRule::query()->where('user_id', $customer->id)->where('is_active', true)->get();

        return $this->scopes[$customer->id] = $rules->map(fn (CustomerDiscountRule $rule) => [
            'id' => $rule->id, 'manufacturer_ids' => $rule->manufacturer_ids,
            'category_ids' => $this->categoryIds($rule->category_ids, $rule->include_descendants),
            'excluded_product_ids' => $rule->excluded_product_ids,
            'specificity' => $rule->category_ids !== [] ? ($rule->manufacturer_ids !== [] ? 0 : 1) : ($rule->manufacturer_ids !== [] ? 2 : 3),
        ])->all();
    }

    private function categoryIds(array $ids, bool $includeDescendants): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids !== [] && $includeDescendants) {
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

        return $ids;
    }

    private function validated(array $data): array
    {
        foreach (['manufacturer_ids', 'category_ids', 'excluded_product_ids'] as $field) {
            $data[$field] ??= [];
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            $data[$field] = ($data[$field] ?? '') === '' ? null : $data[$field];
        }
        $data += ['include_descendants' => true, 'priority' => 0, 'is_active' => true];
        $data['name'] = trim((string) ($data['name'] ?? ''));
        $validated = Validator::make($data, [
            'name' => 'required|string|max:191',
            'percent' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'manufacturer_ids' => 'array|max:1000',
            'manufacturer_ids.*' => ['required', 'integer', 'distinct', Rule::exists('catalog_manufacturers', 'id')->where('is_active', true)],
            'category_ids' => 'array|max:1000',
            'category_ids.*' => ['required', 'integer', 'distinct', Rule::exists('categories', 'id')->where('scope', Category::SCOPE_CATALOG)],
            'excluded_product_ids' => 'array|max:10000',
            'excluded_product_ids.*' => 'required|integer|distinct|exists:products,id',
            'include_descendants' => 'required|boolean', 'priority' => 'required|integer|min:-2147483648|max:2147483647',
            'is_active' => 'required|boolean', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date',
        ])->validate();
        if ($validated['starts_at'] && $validated['ends_at'] && Carbon::parse($validated['ends_at'])->lte(Carbon::parse($validated['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'Završetak mora biti nakon početka.']);
        }
        foreach (['manufacturer_ids', 'category_ids', 'excluded_product_ids'] as $field) {
            $validated[$field] = array_map('intval', $validated[$field]);
            sort($validated[$field]);
        }

        return $validated;
    }

    private function invalidate(): void
    {
        $this->scopes = [];
        $this->categoryTree = null;
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
    }

    private function legacyIdSql(string $column, string $key): string
    {
        $column = DB::connection()->getQueryGrammar()->wrap($column);

        return DB::connection()->getDriverName() === 'sqlite'
            ? 'CAST(json_extract('.$column.", '$.opencart.".$key."') AS INTEGER)"
            : 'CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT('.$column.", '$.opencart.".$key."')), 'null') AS UNSIGNED)";
    }

    private function audit(CustomerDiscountRule $rule, User $actor, string $event, ?array $before, ?array $after): void
    {
        activity('catalog_b2b_prices')->performedOn($rule)->causedBy($actor)->event($event)
            ->withProperties(['user_id' => $rule->user_id, 'rule_id' => $rule->id, 'before' => $before, 'after' => $after])
            ->log($event);
    }
}
