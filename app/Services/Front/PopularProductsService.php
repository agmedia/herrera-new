<?php

namespace App\Services\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Services\Catalog\CatalogFeatureService;
use App\Support\ProductMaterialLabel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PopularProductsService
{
    /** @return Collection<int, Product> */
    public function forStorefront(string $locale, string $fallbackLocale, int $limit = 8): Collection
    {
        $end = now();
        $start = $end->copy()->subDays(30);
        $locales = array_unique([$locale, $fallbackLocale]);

        // Imported orders retain their original placement date and status codes.
        $orders = Order::query()->select('orders.id')->withoutCancelled()
            ->whereBetween(DB::raw('COALESCE(orders.placed_at, orders.created_at)'), [$start, $end])
            ->where(function ($query): void {
                $query->whereNotNull('orders.paid_at')
                    ->orWhereHas('status', function ($status): void {
                        $status->where('is_paid', true)
                            ->orWhereIn('code', [
                                'herrera-oc-status-3', 'herrera-oc-status-5',
                                'shipped', 'completed', 'delivered', 'sent',
                            ]);
                    });
            });

        // Distinct orders avoid letting a single large wholesale order dominate.
        $ranking = DB::table('order_items')
            ->select('product_id')
            ->selectRaw('COUNT(DISTINCT order_id) as order_count, SUM(quantity) as ordered_quantity')
            ->whereIn('order_id', $orders)
            ->where('quantity', '>', 0)
            ->whereNotNull('product_id')
            ->groupBy('product_id');

        return Product::query()
            ->select('products.*')
            ->joinSub($ranking, 'popularity', fn ($join) => $join->on('products.id', '=', 'popularity.product_id'))
            ->visibleOnStorefront(app(CatalogFeatureService::class)->hideOutOfStockProducts())
            ->whereHas('translations', fn ($query) => $query->whereIn('locale', $locales)->whereRaw("TRIM(COALESCE(slug, '')) <> ''"))
            ->orderByDesc('popularity.order_count')
            ->orderByDesc('popularity.ordered_quantity')
            ->orderBy('products.id')
            ->limit(max(1, min(24, $limit)))
            ->withStorefrontEnergyData()
            ->withApprovedCommentSummary($locales)
            ->with([
                'translations' => fn ($query) => $query->whereIn('locale', $locales)->whereRaw("TRIM(COALESCE(slug, '')) <> ''"),
                'media', 'taxRate', 'manufacturer.translations', 'categories.translations',
                'attributes' => ProductMaterialLabel::eagerLoadAttributes($locale, $fallbackLocale),
                'optionValues' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('id')->with([
                    'optionValue.option:id,payload',
                    'optionValue.translations' => fn ($query) => $query->whereIn('locale', $locales),
                    'parentOptionValue.option:id,payload',
                    'parentOptionValue.translations' => fn ($query) => $query->whereIn('locale', $locales),
                ]),
            ])
            ->get();
    }
}
