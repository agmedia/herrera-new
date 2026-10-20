<?php

namespace App\Services\Pricing;

use App\Data\Pricing\ResolvedB2BPrice;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PriceCatalogResolver
{
    private ?PriceCatalog $cachedCatalog = null;

    private bool $catalogLoaded = false;

    /** @var array<string, array<int, array{entry:?PriceCatalogEntry,regular:?PriceCatalogEntry,inherited:?array,customer_discount:?array,primary_default:?float}>> */
    private array $productBatches = [];

    private ?CatalogGroupDiscountInheritance $inheritedDiscounts = null;

    private ?CustomerDiscountService $customerDiscounts = null;

    private ?LegacyCatalogDiscountService $legacyDiscounts = null;

    /** @var array<string, array<int, true>> */
    private array $primedProductIds = [];

    public function forgetActiveCatalog(): void
    {
        $this->cachedCatalog = null;
        $this->catalogLoaded = false;
        $this->productBatches = [];
        $this->primedProductIds = [];
        $this->inheritedDiscounts = null;
        $this->customerDiscounts = null;
        $this->legacyDiscounts = null;
    }

    public function inheritedDiscounts(): CatalogGroupDiscountInheritance
    {
        return $this->inheritedDiscounts ??= new CatalogGroupDiscountInheritance;
    }

    public function customerDiscounts(): CustomerDiscountService
    {
        return $this->customerDiscounts ??= new CustomerDiscountService;
    }

    public function legacyDiscounts(): LegacyCatalogDiscountService
    {
        return $this->legacyDiscounts ??= new LegacyCatalogDiscountService;
    }

    public function activeCatalog(): ?PriceCatalog
    {
        if ($this->catalogLoaded) {
            return $this->cachedCatalog;
        }
        $id = DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id');
        $this->catalogLoaded = true;

        return $this->cachedCatalog = $id ? PriceCatalog::query()->where('status', PriceCatalog::ACTIVE)->find($id) : null;
    }

    /** @param iterable<Product> $products */
    public function preloadProducts(iterable $products, ?User $user, int $quantity = 1): void
    {
        $productIds = [];
        foreach ($products as $product) {
            if ($product instanceof Product && $product->getKey()) {
                $productIds[] = (int) $product->getKey();
            }
        }
        $productIds = array_values(array_unique($productIds));
        $access = app(B2BAccessService::class);
        if ($productIds === [] || ! $access->requiresApprovedAccount()) {
            return;
        }
        $account = $access->approvedAccount($user);
        $catalog = $account ? $this->activeCatalog() : null;
        if (! $account || ! $catalog) {
            return;
        }
        $this->preloadIds($productIds, $catalog, $user, (int) $account->customer_group_id, $quantity, now());
    }

    /** @param array<int, int> $productIds */
    private function preloadIds(array $productIds, PriceCatalog $catalog, User $user, int $groupId, int $quantity, Carbon $at): void
    {
        $context = $this->batchContext($catalog, $user, $groupId, $quantity);
        foreach ($productIds as $productId) {
            $this->primedProductIds[$context][$productId] = true;
        }
        $key = $this->batchKey($catalog, $user, $groupId, $quantity, $at);
        $missingIds = array_values(array_diff($productIds, array_keys($this->productBatches[$key] ?? [])));
        if ($missingIds === []) {
            return;
        }
        $entries = $this->eligibleEntries($catalog, $user, $groupId, $quantity, $at)
            ->whereIn('product_id', $missingIds)->get();
        foreach ($missingIds as $productId) {
            $this->productBatches[$key][$productId] = ['entry' => null, 'regular' => null, 'inherited' => null, 'customer_discount' => null, 'primary_default' => null, 'legacy_category' => null, 'legacy_group' => null];
        }
        foreach ($entries as $entry) {
            $candidate = &$this->productBatches[$key][(int) $entry->product_id];
            $candidate['entry'] ??= $entry;
            if ($entry->kind !== PriceCatalogEntry::SPECIAL) {
                $candidate['regular'] ??= $entry;
            }
            unset($candidate);
        }
        foreach ($this->inheritedDiscounts()->forProducts($missingIds, $catalog, $groupId, $at) as $productId => $price) {
            $this->productBatches[$key][$productId]['inherited'] = $price;
        }
        foreach ($this->customerDiscounts()->forProducts($missingIds, $catalog, $user, $at) as $productId => $price) {
            $this->productBatches[$key][$productId]['customer_discount'] = $price;
        }
        foreach (['legacy_category' => 'mega_sale', 'legacy_group' => 'cigroup_template'] as $field => $type) {
            foreach ($this->legacyDiscounts()->forProducts($missingIds, $catalog, $groupId, $at, $type) as $productId => $price) {
                $this->productBatches[$key][$productId][$field] = $price;
            }
        }
        $group = app(B2BAccessService::class)->approvedAccount($user)?->customerGroup;
        if ($group) {
            foreach (app(PrimaryGroupDiscountService::class)->forProducts($missingIds, $catalog, $group, $at) as $productId => $price) {
                $this->productBatches[$key][$productId]['primary_default'] = $price;
            }
        }
    }

    /** Imported absolute prices are final: never stack new formulas or promotions on them. */
    public function resolve(Product $product, ?User $user, int $quantity = 1, ?float $fallback = null, ?PriceCatalog $catalog = null, Carbon|string|null $at = null): ?ResolvedB2BPrice
    {
        $account = app(B2BAccessService::class)->approvedAccount($user);
        if (! $account) {
            return null;
        }
        $useBatch = $catalog === null && $at === null;
        $catalog ??= $this->activeCatalog();
        if (! $catalog) {
            return null;
        }
        $at = $at ? Carbon::parse($at) : now();
        $key = $this->batchKey($catalog, $user, (int) $account->customer_group_id, $quantity, $at);
        $context = $this->batchContext($catalog, $user, (int) $account->customer_group_id, $quantity);
        if ($useBatch && isset($this->primedProductIds[$context][(int) $product->getKey()])
            && ! array_key_exists((int) $product->getKey(), $this->productBatches[$key] ?? [])) {
            // A page can render across a schedule boundary. Refresh its entire
            // primed set once rather than returning to one query per card.
            $this->preloadIds(array_keys($this->primedProductIds[$context]), $catalog, $user,
                (int) $account->customer_group_id, $quantity, $at);
        }
        $candidate = $useBatch ? ($this->productBatches[$key][(int) $product->getKey()] ?? null) : null;
        if ($candidate !== null) {
            $entry = $candidate['entry'];
            $regular = $candidate['regular'];
            $inherited = $candidate['inherited'];
            $customerDiscount = $candidate['customer_discount'];
            $primaryDefault = $candidate['primary_default'];
            $legacyCategory = $candidate['legacy_category'];
            $legacyGroup = $candidate['legacy_group'];
        } else {
            $customerDiscount = $this->customerDiscounts()->forProducts([(int) $product->getKey()], $catalog, $user, $at)[(int) $product->getKey()] ?? null;
            $query = $this->eligibleEntries($catalog, $user, (int) $account->customer_group_id, $quantity, $at)
                ->where('product_id', $product->getKey());
            $entry = (clone $query)->first();
            $regular = $entry?->kind === PriceCatalogEntry::SPECIAL
                ? (clone $query)->where('kind', '!=', PriceCatalogEntry::SPECIAL)->first()
                : null;
            $inherited = $this->inheritedDiscounts()->forProducts([(int) $product->getKey()], $catalog,
                (int) $account->customer_group_id, $at)[(int) $product->getKey()] ?? null;
            $primaryDefault = app(PrimaryGroupDiscountService::class)->forProducts([(int) $product->getKey()], $catalog,
                $account->customerGroup, $at)[(int) $product->getKey()] ?? null;
            $legacyCategory = $this->legacyDiscounts()->forProducts([(int) $product->getKey()], $catalog,
                (int) $account->customer_group_id, $at, 'mega_sale')[(int) $product->getKey()] ?? null;
            $legacyGroup = $this->legacyDiscounts()->forProducts([(int) $product->getKey()], $catalog,
                (int) $account->customer_group_id, $at, 'cigroup_template')[(int) $product->getKey()] ?? null;
        }

        if ($customerDiscount !== null) {
            return new ResolvedB2BPrice(
                id: $customerDiscount['rule_id'],
                price: $customerDiscount['price'],
                source_type: 'price_catalog_customer_discount',
                customer_group_id: (int) $account->customer_group_id,
                user_id: (int) $user->getKey(),
                catalog_id: (int) $catalog->id,
                rule_id: $customerDiscount['rule_id'],
                is_final: true,
            );
        }

        if ($inherited !== null) {
            return new ResolvedB2BPrice(
                id: $inherited['rule_id'],
                price: $inherited['price'],
                source_type: 'price_catalog_group_discount',
                customer_group_id: (int) $account->customer_group_id,
                catalog_id: (int) $catalog->id,
                rule_id: $inherited['rule_id'],
                is_final: true,
            );
        }

        // Original source definitions fill gaps only. Existing final actions,
        // quantity agreements and individual prices remain authoritative.
        $useLegacyCategory = $legacyCategory !== null && ($entry === null || in_array($entry->kind, [PriceCatalogEntry::GROUP, PriceCatalogEntry::BASE], true));
        $useLegacyGroup = $legacyGroup !== null && ($entry === null || $entry->kind === PriceCatalogEntry::BASE);
        if ($useLegacyCategory || $useLegacyGroup) {
            $legacy = $useLegacyCategory ? $legacyCategory : $legacyGroup;

            return new ResolvedB2BPrice(
                id: $legacy['reference_id'], price: $legacy['price'],
                source_type: $useLegacyCategory ? 'price_catalog_legacy_category_discount' : 'price_catalog_legacy_group_discount',
                customer_group_id: (int) $account->customer_group_id, catalog_id: (int) $catalog->id,
                rule_id: $legacy['reference_id'], is_final: true,
                previous_price: $useLegacyCategory
                    ? (float) ($entry?->kind === PriceCatalogEntry::GROUP ? $entry->price : ($legacyGroup['price'] ?? $primaryDefault ?? $entry?->price ?? $fallback ?? $product->base_price))
                    : null,
            );
        }

        $usePrimaryDefault = $primaryDefault !== null && ($entry === null || $entry->kind === PriceCatalogEntry::BASE);

        return new ResolvedB2BPrice(
            id: (int) ($usePrimaryDefault ? $catalog->id : ($entry?->id ?? $catalog->id)),
            price: $usePrimaryDefault ? $primaryDefault : (float) ($entry?->price ?? $fallback ?? $product->base_price),
            source_type: 'price_catalog_'.($usePrimaryDefault ? 'primary_group_default' : ($entry?->kind ?? 'base')),
            customer_group_id: (int) $account->customer_group_id,
            user_id: $entry?->kind === PriceCatalogEntry::CUSTOMER ? (int) $user->getKey() : null,
            catalog_id: (int) $catalog->id,
            catalog_entry_id: ! $usePrimaryDefault && $entry ? (int) $entry->id : null,
            rule_id: $entry?->discount_rule_id ? (int) $entry->discount_rule_id : null,
            is_final: true,
            previous_price: $entry?->kind === PriceCatalogEntry::SPECIAL
                ? (float) (($regular === null || $regular->kind === PriceCatalogEntry::BASE)
                    ? ($legacyGroup['price'] ?? $primaryDefault ?? $regular?->price ?? $fallback ?? $product->base_price)
                    : $regular->price)
                : null,
        );
    }

    private function batchKey(PriceCatalog $catalog, User $user, int $groupId, int $quantity, Carbon $at): string
    {
        // Match the timestamp precision used when the query binds Carbon values.
        $dateFormat = $catalog->getConnection()->getQueryGrammar()->getDateFormat();

        return $this->batchContext($catalog, $user, $groupId, $quantity).':'.$at->format($dateFormat);
    }

    private function batchContext(PriceCatalog $catalog, User $user, int $groupId, int $quantity): string
    {
        return implode(':', [$catalog->getKey(), $user->getKey(), $groupId, max(1, $quantity)]);
    }

    private function eligibleEntries(PriceCatalog $catalog, User $user, int $groupId, int $quantity, Carbon $at): Builder
    {
        return $catalog->entries()->getQuery()->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<', $at)->orWhere(fn (Builder $q) => $q->where('kind', PriceCatalogEntry::GROUP_DISCOUNT)->where('starts_at', '=', $at)))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->where('minimum_quantity', '<=', max(1, $quantity))
            ->where(function (Builder $q) use ($groupId, $user): void {
                // Compiled rule entries remain an audit snapshot. Current
                // membership is evaluated by inheritedDiscounts() above.
                $q->where(fn (Builder $q) => $q->whereIn('kind', [PriceCatalogEntry::SPECIAL, PriceCatalogEntry::QUANTITY, PriceCatalogEntry::GROUP])->where('customer_group_id', $groupId)->whereNull('user_id'))
                    ->orWhere(fn (Builder $q) => $q->where('kind', PriceCatalogEntry::CUSTOMER)->where('user_id', $user->getKey())->whereNull('customer_group_id'))
                    ->orWhere(fn (Builder $q) => $q->where('kind', PriceCatalogEntry::BASE)->whereNull('user_id')->whereNull('customer_group_id'));
            })
            ->tap(fn (Builder $query) => $this->customerDiscounts()->applyLegacyEntryExclusions($query, $user))
            ->orderByRaw(PriceCatalogEntry::precedenceSql())
            ->orderByRaw(PriceCatalogEntry::manualGroupPrecedenceSql())
            ->orderByRaw("CASE WHEN kind = 'quantity' THEN minimum_quantity ELSE 0 END DESC")
            ->orderBy('priority')->orderBy('price')->orderBy('discount_rule_id')->orderBy('id');
    }
}
