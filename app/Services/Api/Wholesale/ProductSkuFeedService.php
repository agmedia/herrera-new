<?php

namespace App\Services\Api\Wholesale;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductSkuFeedService
{
    /**
     * @param  array{
     *     per_page:int,
     *     include_option_values:bool,
     *     include_inactive:bool,
     *     updated_since:?CarbonImmutable,
     *     manufacturer_id:?int,
     *     category_id:?int,
     *     sku:?string,
     *     sort:string
     * }  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(250, (int) ($filters['per_page'] ?? 100)));
        $includeOptionValues = (bool) ($filters['include_option_values'] ?? true);
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);
        $updatedSince = $filters['updated_since'] ?? null;
        $manufacturerId = $filters['manufacturer_id'] ?? null;
        $categoryId = $filters['category_id'] ?? null;
        $sku = trim((string) ($filters['sku'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'sku_asc');

        $baseRows = DB::table('products as p')
            ->selectRaw("
                p.id as product_id,
                null as product_option_value_id,
                p.code as product_code,
                p.sku as sku,
                p.base_price as price,
                p.stock_qty as quantity,
                p.is_active as is_active,
                p.updated_at as updated_at,
                'product' as source
            ")
            ->when($categoryId, function ($query) use ($categoryId): void {
                $query->join('category_product as cp', function ($join) use ($categoryId): void {
                    $join->on('cp.product_id', '=', 'p.id')
                        ->where('cp.category_id', '=', (int) $categoryId);
                });
            })
            ->whereNotNull('p.sku')
            ->where('p.sku', '!=', '')
            ->when(! $includeInactive, fn ($query) => $query->where('p.is_active', true))
            ->when($manufacturerId, fn ($query) => $query->where('p.manufacturer_id', (int) $manufacturerId))
            ->when($sku !== '', fn ($query) => $query->where('p.sku', 'like', '%'.$sku.'%'))
            ->when($updatedSince instanceof CarbonImmutable, fn ($query) => $query->where('p.updated_at', '>=', $updatedSince));

        $unionQuery = clone $baseRows;

        if ($includeOptionValues) {
            $optionValueRows = DB::table('catalog_product_option_values as pov')
                ->join('products as p', 'p.id', '=', 'pov.product_id')
                ->selectRaw("
                    p.id as product_id,
                    pov.id as product_option_value_id,
                    p.code as product_code,
                    pov.sku as sku,
                    coalesce(pov.price_override, p.base_price) as price,
                    pov.stock_qty as quantity,
                    case when p.is_active = 1 and pov.is_active = 1 then 1 else 0 end as is_active,
                    case when pov.updated_at > p.updated_at then pov.updated_at else p.updated_at end as updated_at,
                    'option_value' as source
                ")
                ->when($categoryId, function ($query) use ($categoryId): void {
                    $query->join('category_product as cp', function ($join) use ($categoryId): void {
                        $join->on('cp.product_id', '=', 'p.id')
                            ->where('cp.category_id', '=', (int) $categoryId);
                    });
                })
                ->whereNotNull('pov.sku')
                ->where('pov.sku', '!=', '')
                ->when(! $includeInactive, fn ($query) => $query->where('p.is_active', true)->where('pov.is_active', true))
                ->when($manufacturerId, fn ($query) => $query->where('p.manufacturer_id', (int) $manufacturerId))
                ->when($sku !== '', fn ($query) => $query->where('pov.sku', 'like', '%'.$sku.'%'))
                ->when($updatedSince instanceof CarbonImmutable, function ($query) use ($updatedSince): void {
                    $query->where(function ($q) use ($updatedSince): void {
                        $q->where('p.updated_at', '>=', $updatedSince)
                            ->orWhere('pov.updated_at', '>=', $updatedSince);
                    });
                });

            $unionQuery = $baseRows->unionAll($optionValueRows);
        }

        $query = DB::query()->fromSub($unionQuery, 'sku_feed');

        match ($sort) {
            'sku_desc' => $query->orderByDesc('sku'),
            'updated_asc' => $query->orderBy('updated_at')->orderBy('sku'),
            'updated_desc' => $query->orderByDesc('updated_at')->orderBy('sku'),
            default => $query->orderBy('sku'),
        };

        return $query->paginate($perPage)->withQueryString();
    }
}
