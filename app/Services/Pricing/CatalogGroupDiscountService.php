<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Pricing\CatalogGroupDiscountRule;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogAudit;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Compiles group discounts into the same immutable absolute-price snapshot used by every sales channel. */
class CatalogGroupDiscountService
{
    public function previewRule(PriceCatalog $catalog, array $data, User $actor): array
    {
        app(PriceCatalogService::class)->authorize($actor);
        $data = $this->validated($data);
        $query = $this->products($data);
        $count = (clone $query)->count();
        $samples = (clone $query)->orderBy('products.id')->limit(5)->get(['products.id', 'products.sku', 'products.base_price']);
        $bases = $this->basePrices($catalog, $samples->pluck('id')->all());

        return [
            'product_count' => $count,
            'group_count' => count($data['customer_group_ids']),
            'entry_count' => $count * count($data['customer_group_ids']),
            'samples' => $samples->map(fn ($product) => ['product_id' => $product->id, 'sku' => $product->sku, 'base_price' => $bases[$product->id] ?? (string) $product->getRawOriginal('base_price'), 'price' => $this->discountedPrice($bases[$product->id] ?? (string) $product->getRawOriginal('base_price'), $data['percent'])])->all(),
        ];
    }

    public function saveRule(PriceCatalog $catalog, array $data, User $actor, ?int $ruleId = null): CatalogGroupDiscountRule
    {
        app(PriceCatalogService::class)->authorize($actor, 'update');
        $data = $this->validated($data);

        return DB::transaction(function () use ($catalog, $data, $actor, $ruleId): CatalogGroupDiscountRule {
            $catalog = $this->lockedDraft($catalog);
            $rule = $ruleId ? $catalog->discountRules()->findOrFail($ruleId) : new CatalogGroupDiscountRule(['price_catalog_id' => $catalog->id, 'created_by' => $actor->id]);
            $before = $rule->exists ? $rule->toArray() : null;
            $rule->fill($data + ['updated_by' => $actor->id])->save();
            $this->materialize($catalog, $rule);
            $this->audit($catalog, $actor, $before ? 'group_discount_updated' : 'group_discount_created', ['rule_id' => $rule->id, 'before' => $before, 'after' => $rule->toArray()]);

            return $rule;
        });
    }

    public function deleteRule(PriceCatalog $catalog, int $ruleId, User $actor): void
    {
        app(PriceCatalogService::class)->authorize($actor, 'delete');
        DB::transaction(function () use ($catalog, $ruleId, $actor): void {
            $catalog = $this->lockedDraft($catalog);
            $rule = $catalog->discountRules()->findOrFail($ruleId);
            $this->audit($catalog, $actor, 'group_discount_deleted', ['rule' => $rule->toArray()]);
            $rule->entries()->delete();
            $rule->delete();
        });
    }

    /** Called inside the catalog publication transaction, while its draft row is locked. */
    public function rebuildForPublication(PriceCatalog $catalog, User $actor): void
    {
        if ($catalog->status !== PriceCatalog::DRAFT) {
            throw ValidationException::withMessages(['catalog' => 'Popusti se mogu pripremiti samo u radnoj kopiji.']);
        }
        $rules = $catalog->discountRules()->orderBy('id')->get();
        foreach ($rules as $rule) {
            $this->validated($rule->toArray());
            $this->materialize($catalog, $rule);
        }
        if ($rules->isNotEmpty()) {
            $this->audit($catalog, $actor, 'group_discounts_materialized', ['rule_count' => $rules->count(), 'entry_count' => $rules->sum('materialized_entry_count')]);
        }
    }

    /** Copy definitions only; PriceCatalogService copies their exact compiled prices and remaps IDs. */
    public function cloneRules(PriceCatalog $source, PriceCatalog $draft, User $actor): array
    {
        $ids = [];
        foreach ($source->discountRules()->orderBy('id')->get() as $rule) {
            $copy = $rule->replicate();
            $copy->price_catalog_id = $draft->id;
            $copy->created_by = $actor->id;
            $copy->updated_by = $actor->id;
            $copy->save();
            $ids[$rule->id] = $copy->id;
        }

        return $ids;
    }

    /** Four-decimal, half-up arithmetic without floats or an optional BCMath runtime dependency. */
    public function discountedPrice(string $base, string $percent): string
    {
        if (! preg_match('/^\d{1,16}(?:\.\d{1,4})?$/', $base) || ! preg_match('/^\d{1,3}(?:\.\d{1,4})?$/', $percent) || (float) $percent <= 0 || (float) $percent > 100) {
            throw ValidationException::withMessages(['percent' => 'Osnovna cijena ili postotak nisu valjani.']);
        }

        return (string) BigDecimal::of($base)->multipliedBy(BigDecimal::of('100')->minus($percent))->dividedBy('100', 4, RoundingMode::HalfUp);
    }

    private function materialize(PriceCatalog $catalog, CatalogGroupDiscountRule $rule): void
    {
        // Regeneration changes this rule only. Imported final prices remain unmodified and available as fallback.
        $rule->entries()->delete();
        $count = 0;
        $timestamp = now();
        $data = $rule->toArray();
        $this->products($data)->select(['products.id', 'products.base_price'])->chunkById(250, function ($products) use ($catalog, $rule, $timestamp, &$count): void {
            $bases = $this->basePrices($catalog, $products->pluck('id')->all());
            $rows = [];
            foreach ($products as $product) {
                $base = $bases[$product->id] ?? (string) $product->getRawOriginal('base_price');
                $price = $this->discountedPrice($base, $rule->percent);
                $payload = json_encode(['group_discount' => ['percent' => $rule->percent, 'basis_price' => $base, 'basis' => isset($bases[$product->id]) ? 'catalog_base' : 'product_base']], JSON_THROW_ON_ERROR);
                foreach ($rule->customer_group_ids as $groupId) {
                    $rows[] = ['price_catalog_id' => $catalog->id, 'discount_rule_id' => $rule->id, 'source_key' => 'group-discount:'.$rule->id.':'.$product->id.':'.$groupId, 'product_id' => $product->id, 'kind' => PriceCatalogEntry::GROUP_DISCOUNT, 'customer_group_id' => $groupId, 'user_id' => null, 'minimum_quantity' => 1, 'price' => $price, 'priority' => $rule->priority, 'starts_at' => $rule->starts_at, 'ends_at' => $rule->ends_at, 'is_active' => $rule->is_active, 'payload' => $payload, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                }
            }
            foreach (array_chunk($rows, 500) as $batch) {
                DB::table('catalog_price_entries')->insert($batch);
            }
            $count += $products->count();
        });
        $rule->forceFill(['materialized_product_count' => $count, 'materialized_entry_count' => $count * count($rule->customer_group_ids), 'materialized_at' => $timestamp])->save();
    }

    private function basePrices(PriceCatalog $catalog, array $productIds): array
    {
        // The verified catalog list price is the basis, not any previously discounted group/special entry.
        return $catalog->entries()->where('kind', PriceCatalogEntry::BASE)->where('is_active', true)->whereIn('product_id', $productIds)
            ->whereNull('user_id')->whereNull('customer_group_id')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('priority')->orderBy('price')->orderBy('id')->get(['product_id', 'price'])->unique('product_id')->pluck('price', 'product_id')->all();
    }

    private function products(array $data): Builder
    {
        $query = Product::query();
        if ($data['manufacturer_ids'] !== []) {
            $query->whereIn('manufacturer_id', $data['manufacturer_ids']);
        }
        if ($data['category_ids'] !== []) {
            $ids = $data['category_ids'];
            if ($data['include_descendants']) {
                // Parent traversal tolerates imported/rebuilt nested-set indexes and remains bounded by the category tree.
                $tree = Category::query()->where('scope', Category::SCOPE_CATALOG)->get(['id', 'parent_id']);
                do {
                    $before = count($ids);
                    $ids = array_values(array_unique([...$ids, ...$tree->whereIn('parent_id', $ids)->pluck('id')->all()]));
                } while (count($ids) > $before);
            }
            $query->whereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $ids));
        }
        if ($data['excluded_product_ids'] !== []) {
            $query->whereNotIn('products.id', $data['excluded_product_ids']);
        }

        return $query;
    }

    private function validated(array $data): array
    {
        foreach (['customer_group_ids', 'manufacturer_ids', 'category_ids', 'excluded_product_ids'] as $field) {
            $data[$field] ??= [];
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            $data[$field] = ($data[$field] ?? '') === '' ? null : $data[$field];
        }
        $data += ['include_descendants' => true, 'priority' => 0, 'is_active' => true];
        $data['name'] = trim((string) ($data['name'] ?? ''));
        $validated = Validator::make($data, [
            'name' => 'required|string|max:191',
            'percent' => ['required', 'numeric', 'gt:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'customer_group_ids' => 'required|array|min:1|max:1000',
            'customer_group_ids.*' => ['required', 'integer', 'distinct', Rule::exists('customer_groups', 'id')->where('is_active', true)],
            'manufacturer_ids' => 'array|max:1000',
            'manufacturer_ids.*' => ['required', 'integer', 'distinct', Rule::exists('catalog_manufacturers', 'id')->where('is_active', true)],
            'category_ids' => 'array|max:1000',
            'category_ids.*' => ['required', 'integer', 'distinct', Rule::exists('categories', 'id')->where('scope', Category::SCOPE_CATALOG)],
            'excluded_product_ids' => 'array|max:10000',
            'excluded_product_ids.*' => 'required|integer|distinct|exists:products,id',
            'include_descendants' => 'required|boolean',
            'priority' => 'required|integer|min:-2147483648|max:2147483647',
            'is_active' => 'required|boolean',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date',
        ])->validate();
        if ($validated['starts_at'] && $validated['ends_at'] && Carbon::parse($validated['ends_at'])->lte(Carbon::parse($validated['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'Završetak mora biti nakon početka.']);
        }
        foreach (['customer_group_ids', 'manufacturer_ids', 'category_ids', 'excluded_product_ids'] as $field) {
            $validated[$field] = array_map('intval', $validated[$field]);
            sort($validated[$field]);
        }

        return $validated;
    }

    private function lockedDraft(PriceCatalog $catalog): PriceCatalog
    {
        $catalog = PriceCatalog::query()->lockForUpdate()->findOrFail($catalog->id);
        if ($catalog->status !== PriceCatalog::DRAFT) {
            throw ValidationException::withMessages(['catalog' => 'Objavljeni cjenik je zaključan. Napravite radnu kopiju.']);
        }

        return $catalog;
    }

    private function audit(PriceCatalog $catalog, User $actor, string $event, array $payload): void
    {
        PriceCatalogAudit::query()->create(['price_catalog_id' => $catalog->id, 'actor_id' => $actor->id, 'event' => $event, 'payload' => $payload, 'created_at' => now()]);
    }
}
