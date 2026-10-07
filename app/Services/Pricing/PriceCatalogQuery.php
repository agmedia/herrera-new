<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class PriceCatalogQuery
{
    private ?string $queryTime = null;

    /** @return array{sql:string,bindings:array}|null */
    public function storedPrice(?User $user, bool $excludeSpecial = false): ?array
    {
        if (! app(B2BAccessService::class)->requiresApprovedAccount()) {
            return null;
        }
        $account = app(B2BAccessService::class)->approvedAccount($user);
        $catalog = $account ? app(PriceCatalogResolver::class)->activeCatalog() : null;
        if (! $account || ! $catalog) {
            return null;
        }
        $at = $this->queryTime ??= now()->format('Y-m-d H:i:s');
        $groupKinds = [PriceCatalogEntry::GROUP_DISCOUNT, PriceCatalogEntry::QUANTITY];
        if (! $excludeSpecial) {
            $groupKinds[] = PriceCatalogEntry::SPECIAL;
        }
        $candidate = DB::table('catalog_price_entries as audience_price')
            ->select('audience_price.price')
            ->where('audience_price.price_catalog_id', $catalog->id)
            ->whereColumn('audience_price.product_id', 'products.id')
            ->where('audience_price.is_active', true)->where('audience_price.minimum_quantity', '<=', 1)
            ->where(fn (QueryBuilder $q) => $q->whereNull('audience_price.starts_at')->orWhere('audience_price.starts_at', '<', $at)->orWhere(fn (QueryBuilder $q) => $q->where('audience_price.kind', PriceCatalogEntry::GROUP_DISCOUNT)->where('audience_price.starts_at', '=', $at)))
            ->where(fn (QueryBuilder $q) => $q->whereNull('audience_price.ends_at')->orWhere('audience_price.ends_at', '>', $at))
            ->orderByRaw(PriceCatalogEntry::precedenceSql('audience_price.'))
            ->orderByRaw(PriceCatalogEntry::manualGroupPrecedenceSql('audience_price.'))
            ->orderByRaw("CASE WHEN audience_price.kind = 'quantity' THEN audience_price.minimum_quantity ELSE 0 END DESC")
            ->orderBy('audience_price.priority')->orderBy('audience_price.price')->orderBy('audience_price.discount_rule_id')->orderBy('audience_price.id')->limit(1);

        // Separate audience lookups keep the existing precedence while using the
        // group/customer indexes. An OR across audiences also scans every other
        // customer's price for each product in a catalogue-wide aggregate.
        $candidates = [
            (clone $candidate)->whereIn('audience_price.kind', $groupKinds)
                ->where('audience_price.customer_group_id', $account->customer_group_id)->whereNull('audience_price.user_id'),
            (clone $candidate)->where('audience_price.kind', PriceCatalogEntry::CUSTOMER)
                ->where('audience_price.user_id', $user->id)->whereNull('audience_price.customer_group_id'),
            (clone $candidate)->where('audience_price.kind', PriceCatalogEntry::GROUP)
                ->where('audience_price.customer_group_id', $account->customer_group_id)->whereNull('audience_price.user_id'),
            (clone $candidate)->where('audience_price.kind', PriceCatalogEntry::BASE)
                ->whereNull('audience_price.user_id')->whereNull('audience_price.customer_group_id'),
        ];
        if (in_array($candidate->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $candidates[1]->forceIndex('price_entries_customer_lookup');
        }

        return [
            'sql' => 'COALESCE('.implode(', ', array_map(static fn (QueryBuilder $q): string => '('.$q->toSql().')', $candidates)).', products.base_price)',
            'bindings' => array_merge(...array_map(static fn (QueryBuilder $q): array => $q->getBindings(), $candidates)),
        ];
    }

    /** @return array{sql:string,bindings:array}|null */
    public function displayedPrice(Builder|QueryBuilder $query, ?User $user, bool $excludeSpecial = false): ?array
    {
        $stored = $this->storedPrice($user, $excludeSpecial);
        if (! $stored) {
            return null;
        }
        $displayNet = (bool) config('commerce.b2b_display_net', true);
        $storedGross = app(TaxPricingService::class)->pricesIncludeTax();
        if ($displayNet !== $storedGross) {
            return ['sql' => 'ROUND('.$stored['sql'].', 2)', 'bindings' => $stored['bindings']];
        }

        $taxAlias = 'audience_price_tax_rates';
        $baseQuery = $query instanceof Builder ? $query->getQuery() : $query;
        if (! collect($baseQuery->joins ?? [])->contains(fn ($join) => ($join->table ?? '') === 'tax_rates as '.$taxAlias)) {
            $query->leftJoin('tax_rates as '.$taxAlias, fn ($join) => $join->on('products.tax_rate_id', '=', $taxAlias.'.id')->where($taxAlias.'.is_active', true));
        }
        $default = TaxRate::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->first();
        $rate = ['sql' => 'COALESCE('.$taxAlias.'.rate, ?)', 'bindings' => [(float) ($default?->rate ?? 0)]];
        $type = ['sql' => 'COALESCE('.$taxAlias.'.rate_type, ?)', 'bindings' => [$default?->rate_type ?? 'percent']];
        $group = app(B2BAccessService::class)->approvedAccount($user)?->customerGroup;
        if (data_get($group?->payload, 'opencart.tax_scope_defined', false)) {
            $classes = array_values(array_unique(array_map('intval', (array) data_get($group->payload, 'opencart.taxable_class_ids', []))));
            if ($classes === []) {
                $rate = ['sql' => '0', 'bindings' => []];
            } else {
                $classSql = DB::connection()->getDriverName() === 'sqlite'
                    ? "json_extract(products.payload, '$.opencart.tax_class_id')"
                    : "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(products.payload, '$.opencart.tax_class_id')), 'null') AS UNSIGNED)";
                $rate = ['sql' => 'CASE WHEN '.$classSql.' IS NOT NULL AND '.$classSql.' NOT IN ('.implode(',', array_fill(0, count($classes), '?')).') THEN 0 ELSE '.$rate['sql'].' END', 'bindings' => [...$classes, ...$rate['bindings']]];
            }
        }
        $clamp = DB::connection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';
        $fixed = $stored['sql'].($displayNet ? ' - ' : ' + ').'('.$rate['sql'].')';
        $percentage = $stored['sql'].($displayNet ? ' / ' : ' * ').'(1.0 + ('.$rate['sql'].') / 100.0)';

        return [
            'sql' => 'ROUND(CASE WHEN '.$type['sql']." = 'fixed' THEN ".$clamp.'(0, '.$fixed.') ELSE '.$clamp.'(0, '.$percentage.') END, 2)',
            'bindings' => [...$type['bindings'], ...$stored['bindings'], ...$rate['bindings'], ...$stored['bindings'], ...$rate['bindings']],
        ];
    }

    public function applyPromotionFilter(Builder $query, ?User $user): bool
    {
        $current = $this->storedPrice($user);
        $regular = $this->storedPrice($user, excludeSpecial: true);
        if (! $current || ! $regular) {
            return false;
        }

        $account = app(B2BAccessService::class)->approvedAccount($user);
        $catalog = app(PriceCatalogResolver::class)->activeCatalog();
        $at = $this->queryTime;
        // Only an eligible special can change the winner when specials are
        // excluded. Start with that audience's indexed product set, then retain
        // the full price comparison for overrides and non-discounting specials.
        $specialProducts = DB::table('catalog_price_entries as promotion_candidates')
            ->select('promotion_candidates.product_id')
            ->where('promotion_candidates.price_catalog_id', $catalog->id)
            ->where('promotion_candidates.customer_group_id', $account->customer_group_id)
            ->whereNull('promotion_candidates.user_id')
            ->where('promotion_candidates.kind', PriceCatalogEntry::SPECIAL)
            ->where('promotion_candidates.is_active', true)
            ->where('promotion_candidates.minimum_quantity', '<=', 1)
            ->where(fn (QueryBuilder $q) => $q->whereNull('promotion_candidates.starts_at')->orWhere('promotion_candidates.starts_at', '<', $at))
            ->where(fn (QueryBuilder $q) => $q->whereNull('promotion_candidates.ends_at')->orWhere('promotion_candidates.ends_at', '>', $at));
        $query->whereIn('products.id', $specialProducts);
        $query->whereRaw('('.$current['sql'].') < ('.$regular['sql'].') - 0.0001', [...$current['bindings'], ...$regular['bindings']]);

        return true;
    }
}
