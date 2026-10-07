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

    public function forgetActiveCatalog(): void
    {
        $this->cachedCatalog = null;
        $this->catalogLoaded = false;
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

    /** Imported absolute prices are final: never stack new formulas or promotions on them. */
    public function resolve(Product $product, ?User $user, int $quantity = 1, ?float $fallback = null, ?PriceCatalog $catalog = null, Carbon|string|null $at = null): ?ResolvedB2BPrice
    {
        $account = app(B2BAccessService::class)->approvedAccount($user);
        if (! $account) {
            return null;
        }
        $catalog ??= $this->activeCatalog();
        if (! $catalog) {
            return null;
        }
        $at = $at ? Carbon::parse($at) : now();
        $query = $catalog->entries()->where('product_id', $product->getKey())->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<', $at)->orWhere(fn (Builder $q) => $q->where('kind', PriceCatalogEntry::GROUP_DISCOUNT)->where('starts_at', '=', $at)))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
        $query->where('minimum_quantity', '<=', max(1, $quantity))
            ->where(function (Builder $q) use ($account, $user): void {
                $q->where(fn (Builder $q) => $q->whereIn('kind', [PriceCatalogEntry::GROUP_DISCOUNT, PriceCatalogEntry::SPECIAL, PriceCatalogEntry::QUANTITY, PriceCatalogEntry::GROUP])->where('customer_group_id', $account->customer_group_id)->whereNull('user_id'))
                    ->orWhere(fn (Builder $q) => $q->where('kind', PriceCatalogEntry::CUSTOMER)->where('user_id', $user->getKey())->whereNull('customer_group_id'))
                    ->orWhere(fn (Builder $q) => $q->where('kind', PriceCatalogEntry::BASE)->whereNull('user_id')->whereNull('customer_group_id'));
            })
            ->orderByRaw(PriceCatalogEntry::precedenceSql())
            ->orderByRaw(PriceCatalogEntry::manualGroupPrecedenceSql())
            ->orderByRaw("CASE WHEN kind = 'quantity' THEN minimum_quantity ELSE 0 END DESC")
            ->orderBy('priority')->orderBy('price')->orderBy('discount_rule_id')->orderBy('id');
        $entry = (clone $query)->first();
        $regular = $entry?->kind === PriceCatalogEntry::SPECIAL
            ? (clone $query)->where('kind', '!=', PriceCatalogEntry::SPECIAL)->first()
            : null;

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
}
