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

    /** @var array<string, array<int, array{entry:?PriceCatalogEntry,regular:?PriceCatalogEntry}>> */
    private array $productBatches = [];

    /** @var array<string, array<int, true>> */
    private array $primedProductIds = [];

    public function forgetActiveCatalog(): void
    {
        $this->cachedCatalog = null;
        $this->catalogLoaded = false;
        $this->productBatches = [];
        $this->primedProductIds = [];
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
            $this->productBatches[$key][$productId] = ['entry' => null, 'regular' => null];
        }
        foreach ($entries as $entry) {
            $candidate = &$this->productBatches[$key][(int) $entry->product_id];
            $candidate['entry'] ??= $entry;
            if ($entry->kind !== PriceCatalogEntry::SPECIAL) {
                $candidate['regular'] ??= $entry;
            }
            unset($candidate);
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
        } else {
            $query = $this->eligibleEntries($catalog, $user, (int) $account->customer_group_id, $quantity, $at)
                ->where('product_id', $product->getKey());
            $entry = (clone $query)->first();
            $regular = $entry?->kind === PriceCatalogEntry::SPECIAL
                ? (clone $query)->where('kind', '!=', PriceCatalogEntry::SPECIAL)->first()
                : null;
        }

        return new ResolvedB2BPrice(
            id: (int) ($entry?->id ?? $catalog->id),
            price: (float) ($entry?->price ?? $fallback ?? $product->base_price),
            source_type: 'price_catalog_'.($entry?->kind ?? 'base'),
            customer_group_id: (int) $account->customer_group_id,
            user_id: $entry?->kind === PriceCatalogEntry::CUSTOMER ? (int) $user->getKey() : null,
            catalog_id: (int) $catalog->id,
            catalog_entry_id: $entry ? (int) $entry->id : null,
            rule_id: $entry?->discount_rule_id ? (int) $entry->discount_rule_id : null,
            is_final: true,
            previous_price: $entry?->kind === PriceCatalogEntry::SPECIAL
                ? (float) ($regular?->price ?? $fallback ?? $product->base_price)
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
                $q->where(fn (Builder $q) => $q->whereIn('kind', [PriceCatalogEntry::GROUP_DISCOUNT, PriceCatalogEntry::SPECIAL, PriceCatalogEntry::QUANTITY, PriceCatalogEntry::GROUP])->where('customer_group_id', $groupId)->whereNull('user_id'))
                    ->orWhere(fn (Builder $q) => $q->where('kind', PriceCatalogEntry::CUSTOMER)->where('user_id', $user->getKey())->whereNull('customer_group_id'))
                    ->orWhere(fn (Builder $q) => $q->where('kind', PriceCatalogEntry::BASE)->whereNull('user_id')->whereNull('customer_group_id'));
            })
            ->orderByRaw(PriceCatalogEntry::precedenceSql())
            ->orderByRaw(PriceCatalogEntry::manualGroupPrecedenceSql())
            ->orderByRaw("CASE WHEN kind = 'quantity' THEN minimum_quantity ELSE 0 END DESC")
            ->orderBy('priority')->orderBy('price')->orderBy('discount_rule_id')->orderBy('id');
    }
}
