<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Pricing\LegacyGroupDiscountReference;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only fallbacks for verified original definitions; stored final prices keep their authority. */
class LegacyCatalogDiscountService
{
    private array $references = [];

    private ?array $categoryTree = null;

    private array $sourceMappings = [];

    /** @return array{sql:string,bindings:array}|null */
    public function expression(PriceCatalog $catalog, int $groupId, Carbon $at, string $type = 'mega_sale', string $column = 'price'): ?array
    {
        $references = array_filter($this->references($catalog), fn (array $row) => $row['supported']
            && $row['reference']->source_type === $type && in_array($groupId, $row['reference']->customer_group_ids, true));
        if ($references === []) {
            return null;
        }
        $base = DB::table('catalog_price_entries as legacy_discount_base')->select('legacy_discount_base.price')
            ->where('legacy_discount_base.price_catalog_id', $catalog->id)
            ->whereColumn('legacy_discount_base.product_id', 'legacy_discount_product.id')
            ->where('legacy_discount_base.kind', PriceCatalogEntry::BASE)->where('legacy_discount_base.is_active', true)
            ->whereNull('legacy_discount_base.user_id')->whereNull('legacy_discount_base.customer_group_id')
            ->where('legacy_discount_base.minimum_quantity', '<=', 1)
            ->where(fn (Builder $q) => $q->whereNull('legacy_discount_base.starts_at')->orWhere('legacy_discount_base.starts_at', '<', $at))
            ->where(fn (Builder $q) => $q->whereNull('legacy_discount_base.ends_at')->orWhere('legacy_discount_base.ends_at', '>', $at))
            ->orderBy('legacy_discount_base.priority')->orderBy('legacy_discount_base.price')->orderBy('legacy_discount_base.id')->limit(1);
        $basis = 'COALESCE(('.$base->toSql().'), legacy_discount_product.base_price)';
        $raw = '('.$basis.' * (100.0 - legacy_discount_reference.percent) / 100.0)';
        $roundedIds = array_values(array_map(fn (array $row) => (int) $row['reference']->id,
            array_filter($references, fn (array $row) => $row['round_prices'])));
        $price = $roundedIds === [] ? 'ROUND('.$raw.', 4)'
            : 'ROUND(CASE WHEN legacy_discount_reference.id IN ('.implode(',', $roundedIds).') THEN ROUND('.$raw.' * 2, 1) / 2.0 ELSE '.$raw.' END, 4)';
        $priceBindings = $roundedIds === [] ? $base->getBindings() : [...$base->getBindings(), ...$base->getBindings()];
        $query = DB::table('catalog_legacy_group_discount_references as legacy_discount_reference')
            ->crossJoin('products as legacy_discount_product')->whereColumn('legacy_discount_product.id', 'products.id')
            ->selectRaw($column === 'reference_id' ? 'legacy_discount_reference.id' : $price, $column === 'reference_id' ? [] : $priceBindings)
            ->where('legacy_discount_reference.source_system', $catalog->source_system)
            ->where('legacy_discount_reference.source_snapshot', $catalog->source_snapshot)
            ->where('legacy_discount_reference.source_catalog_checksum', $catalog->source_checksum)
            ->where('legacy_discount_reference.source_type', $type)
            ->where(fn (Builder $q) => $q->whereNull('legacy_discount_reference.starts_at')->orWhere('legacy_discount_reference.starts_at', '<', $at))
            ->where(fn (Builder $q) => $q->whereNull('legacy_discount_reference.ends_at')->orWhere('legacy_discount_reference.ends_at', '>', $at));
        if ($type === 'mega_sale') {
            // The original generator did not create specials for a zero base.
            $query->whereRaw($basis.' > 0', $base->getBindings());
        }
        $query->where(function (Builder $q) use ($references): void {
            foreach ($references as $row) {
                $reference = $row['reference'];
                $q->orWhere(function (Builder $scope) use ($reference, $row): void {
                    $scope->where('legacy_discount_reference.id', $reference->id);
                    if ($row['manufacturer_ids'] !== []) {
                        $scope->whereIn('legacy_discount_product.manufacturer_id', $row['manufacturer_ids']);
                    }
                    if ($row['category_ids'] !== []) {
                        $scope->whereExists(fn (Builder $categories) => $categories->selectRaw('1')->from('category_product as legacy_discount_category')
                            ->whereColumn('legacy_discount_category.product_id', 'legacy_discount_product.id')->whereIn('legacy_discount_category.category_id', $row['category_ids']));
                    }
                    if ($reference->excluded_product_ids !== []) {
                        $scope->whereNotIn('legacy_discount_product.id', $reference->excluded_product_ids);
                    }
                    if ($row['source_excluded_product_ids'] !== []) {
                        // An excluded source product may not have existed in
                        // the target when the immutable archive was created.
                        // Preserve its exclusion if it is imported later.
                        $scope->whereNotIn(DB::raw('COALESCE('.$this->sourceProductIdSql().', 0)'), $row['source_excluded_product_ids']);
                    }
                });
            }
        })->orderBy('legacy_discount_reference.priority')->orderByRaw($price, $priceBindings)->orderBy('legacy_discount_reference.id')->limit(1);

        return ['sql' => '('.$query->toSql().')', 'bindings' => $query->getBindings()];
    }

    /** @return array<int, array{price:float,reference_id:int}> */
    public function forProducts(array $productIds, PriceCatalog $catalog, int $groupId, Carbon $at, string $type = 'mega_sale'): array
    {
        if ($productIds === [] || ! ($price = $this->expression($catalog, $groupId, $at, $type))) {
            return [];
        }
        $reference = $this->expression($catalog, $groupId, $at, $type, 'reference_id');
        $rows = Product::query()->whereKey($productIds)->select('products.id')
            ->selectRaw($price['sql'].' as legacy_discount_price', $price['bindings'])
            ->selectRaw($reference['sql'].' as legacy_discount_reference_id', $reference['bindings'])->get();
        $prices = [];
        foreach ($rows as $row) {
            if ($row->legacy_discount_reference_id !== null) {
                $prices[(int) $row->id] = ['price' => (float) $row->legacy_discount_price, 'reference_id' => (int) $row->legacy_discount_reference_id];
            }
        }

        return $prices;
    }

    public function supportReport(PriceCatalog $catalog, ?int $groupId = null): array
    {
        $rows = array_filter($this->references($catalog), fn (array $row) => $groupId === null || in_array($groupId, $row['reference']->customer_group_ids, true));

        return ['supported' => count(array_filter($rows, fn ($row) => $row['supported'])),
            'skipped' => array_values(array_map(fn ($row) => ['id' => $row['reference']->id, 'source_key' => $row['reference']->source_key, 'reason' => $row['reason']], array_filter($rows, fn ($row) => ! $row['supported']))),
            'rounded' => count(array_filter($rows, fn ($row) => $row['supported'] && $row['round_prices'])),
            'preserve_existing_specials' => count(array_filter($rows, fn ($row) => $row['supported'] && (int) ($row['reference']->source_flags['remove_individual_specials'] ?? 0) === 1))];
    }

    private function references(PriceCatalog $catalog): array
    {
        if (! $catalog->source_system || ! $catalog->source_snapshot || ! preg_match('/^[a-f0-9]{64}$/', (string) $catalog->source_checksum)) {
            return [];
        }
        $key = implode(':', [$catalog->source_system, $catalog->source_snapshot, $catalog->source_checksum]);
        if (isset($this->references[$key])) {
            return $this->references[$key];
        }
        $rows = [];
        foreach (LegacyGroupDiscountReference::query()->where('source_system', $catalog->source_system)
            ->where('source_snapshot', $catalog->source_snapshot)->where('source_catalog_checksum', $catalog->source_checksum)->get() as $reference) {
            $reason = $this->unsupportedReason($reference);
            $definition = $reference->definition;
            $categorySourceIds = $reason !== null ? [] : ($reference->source_type === 'mega_sale' ? array_column($definition['category_ids'] ?? [], 'category_id')
                : (($definition['setting']['filter_type'] ?? 'all') === 'custom_category' ? ($definition['setting']['categories'] ?? []) : []));
            $manufacturerSourceIds = $reason !== null ? [] : ($reference->source_type === 'mega_sale' ? array_column($definition['manufacturer_ids'] ?? [], 'manufacturer_id')
                : (($definition['setting']['filter_type'] ?? 'all') === 'custom_manufacturer' ? ($definition['setting']['manufacturers'] ?? []) : []));
            $sourceExcludedIds = $reason !== null ? [] : ($reference->source_type === 'mega_sale' ? array_column($definition['excluded_product_ids'] ?? [], 'product_id')
                : ($definition['setting']['filter_exclude_products'] ?? []));
            $ids = $reason === null ? $this->positiveScope($categorySourceIds, $reference->category_ids, 'category', $reference->source_system) : [];
            $manufacturerIds = $reason === null ? $this->positiveScope($manufacturerSourceIds, $reference->manufacturer_ids, 'manufacturer', $reference->source_system) : [];
            if ($reason === null && $ids !== [] && $reference->source_type === 'mega_sale' && $reference->include_descendants) {
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
            $rows[] = ['reference' => $reference, 'supported' => $reason === null, 'reason' => $reason,
                'category_ids' => $ids, 'manufacturer_ids' => $manufacturerIds,
                'source_excluded_product_ids' => $reason === null ? array_values(array_unique(array_map('intval', $sourceExcludedIds))) : [],
                'round_prices' => $reference->source_type === 'mega_sale' && (int) ($reference->source_flags['round_prices'] ?? 0) === 1];
        }

        return $this->references[$key] = $rows;
    }

    private function unsupportedReason(LegacyGroupDiscountReference $reference): ?string
    {
        if ($reference->customer_group_ids === [] || $reference->percent === null || ! is_numeric($reference->percent)
            || (float) $reference->percent < 0 || (float) $reference->percent > 100) {
            return 'Missing mapped group or unsupported percentage.';
        }
        foreach ($reference->warnings as $warning) {
            if (str_contains($warning, 'datum nije valjan') || str_contains($warning, 'razdoblje nije valjano')) {
                return 'Invalid source dates.';
            }
        }
        if ($reference->starts_at && $reference->ends_at && $reference->ends_at->lte($reference->starts_at)) {
            return 'Invalid date range.';
        }
        $definition = $reference->definition;
        if ($reference->source_type === 'mega_sale') {
            if (($definition['sale']['discount_type'] ?? null) !== 'percent'
                || (float) ($definition['sale']['discount_value'] ?? -1) !== (float) $reference->percent
                || (float) $reference->percent <= 0 || ! array_key_exists('filter_ids', $definition) || ! empty($definition['filter_ids'])) {
                return 'Unsupported sale calculation or product filters.';
            }
            foreach (['customer_group_ids' => 'customer_group_id', 'manufacturer_ids' => 'manufacturer_id', 'category_ids' => 'category_id', 'excluded_product_ids' => 'product_id'] as $field => $sourceField) {
                if (! isset($definition[$field]) || ! is_array($definition[$field])
                    || count(array_filter($definition[$field], fn ($record) => is_array($record) && array_key_exists($sourceField, $record))) !== count($definition[$field])) {
                    return 'Incomplete '.$field.' mapping.';
                }
                $sourceIds = array_column($definition[$field], $sourceField);
                if (! $this->validIds($sourceIds) || ! $this->validIds($reference->{$field})) {
                    return 'Malformed '.$field.' IDs.';
                }
                if ($field === 'customer_group_ids' && ! $this->completeMapping($sourceIds, $reference->{$field})) {
                    return 'Incomplete customer group mapping.';
                }
                if (in_array($field, ['manufacturer_ids', 'category_ids'], true)
                    && $this->positiveScope($sourceIds, $reference->{$field}, $field === 'category_ids' ? 'category' : 'manufacturer', $reference->source_system) === null) {
                    return 'No proven '.$field.' mapping.';
                }
            }
            foreach (['round_prices', 'remove_individual_specials', 'exclude_child'] as $flag) {
                if (! array_key_exists($flag, $definition['sale']) || ! in_array((string) $definition['sale'][$flag], ['0', '1'], true)
                    || (int) ($reference->source_flags[$flag] ?? -1) !== (int) $definition['sale'][$flag]) {
                    return 'Unsupported sale flag.';
                }
            }
            if ($reference->include_descendants !== ! (bool) $definition['sale']['exclude_child']) {
                return 'Category descendant scope does not match source.';
            }

            return null;
        }
        if ($reference->source_type !== 'cigroup_template') {
            return 'Unsupported original rule type.';
        }
        $setting = $definition['setting'] ?? [];
        $flags = $reference->source_flags;
        if (! is_array($setting) || ($definition['template']['type'] ?? null) !== 'product' || ! isset($flags['status'])
            || (string) ($flags['basedon'] ?? '') !== '1' || ($flags['type'] ?? '') !== 'P' || ($flags['action'] ?? '') !== '-'
            || ! is_numeric($flags['value'] ?? null) || (float) $flags['value'] !== (float) $reference->percent) {
            return 'Unsupported template base or calculation.';
        }
        if (empty($definition['source_customer_group_id']) || count($reference->customer_group_ids) !== 1) {
            return 'Incomplete template group mapping.';
        }
        $filter = $setting['filter_type'] ?? 'all';
        if (! in_array($filter, ['all', 'custom_manufacturer', 'custom_category'], true)) {
            return 'Unsupported template product filter.';
        }
        $sourceField = $filter === 'custom_manufacturer' ? 'manufacturers' : 'categories';
        $targetField = $filter === 'custom_manufacturer' ? 'manufacturer_ids' : 'category_ids';
        if ($filter !== 'all' && (empty($setting[$sourceField]) || ! is_array($setting[$sourceField])
            || $this->positiveScope($setting[$sourceField], $reference->{$targetField}, $filter === 'custom_category' ? 'category' : 'manufacturer', $reference->source_system) === null)) {
            return 'Incomplete template scope mapping.';
        }
        foreach (['category_ids' => ['custom_category', 'categories', 'category'], 'manufacturer_ids' => ['custom_manufacturer', 'manufacturers', 'manufacturer']] as $targetField => [$filterType, $settingField, $entity]) {
            $sourceIds = $filter === $filterType ? ($setting[$settingField] ?? []) : [];
            if (! is_array($sourceIds) || $this->positiveScope($sourceIds, $reference->{$targetField}, $entity, $reference->source_system) === null) {
                return 'Incomplete template scope mapping.';
            }
        }
        if (! is_array($setting['filter_exclude_products'] ?? []) || ! $this->validIds($setting['filter_exclude_products'] ?? []) || ! $this->validIds($reference->excluded_product_ids)) {
            return 'Malformed excluded product IDs.';
        }

        return null;
    }

    private function completeMapping(array $sourceIds, array $targetIds): bool
    {
        return $this->validIds($sourceIds) && $this->validIds($targetIds)
            && count(array_unique(array_map('intval', $sourceIds))) === count(array_unique(array_map('intval', $targetIds)));
    }

    private function validIds(array $ids): bool
    {
        return count(array_filter($ids, fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)) === count($ids);
    }

    /** A missing positive OR branch may narrow a scope, but must never turn it into "all". */
    private function positiveScope(array $sourceIds, array $archivedTargetIds, string $entity, string $source): ?array
    {
        if (! $this->validIds($sourceIds) || ! $this->validIds($archivedTargetIds)) {
            return null;
        }
        if ($this->completeMapping($sourceIds, $archivedTargetIds)) {
            return array_values(array_unique(array_map('intval', $archivedTargetIds)));
        }
        if ($sourceIds === []) {
            return null;
        }
        $key = $source.':'.$entity;
        $this->sourceMappings[$key] ??= DB::table('herrera_import_maps')->where('source', $source)->where('entity', $entity)
            ->where('target_id', '>', 0)
            ->pluck('target_id', 'source_id')->all();
        $proven = [];
        foreach ($sourceIds as $id) {
            if (isset($this->sourceMappings[$key][(string) $id])) {
                $proven[] = (int) $this->sourceMappings[$key][(string) $id];
            }
        }

        return $proven === [] ? null : array_values(array_unique($proven));
    }

    private function sourceProductIdSql(): string
    {
        $column = DB::connection()->getQueryGrammar()->wrap('legacy_discount_product.payload');

        return DB::connection()->getDriverName() === 'sqlite'
            ? 'CAST(json_extract('.$column.", '$.opencart.product_id') AS INTEGER)"
            : 'CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT('.$column.", '$.opencart.product_id')), 'null') AS UNSIGNED)";
    }
}
