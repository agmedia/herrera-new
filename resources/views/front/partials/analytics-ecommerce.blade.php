@php
    $analytics = $storeSettings['analytics'] ?? [];
    $analyticsEnabled = (bool) ($analytics['enabled'] ?? false);
    $ga4Id = trim((string) ($analytics['ga4_measurement_id'] ?? ''));
    $metaPixelId = '2376960792811713';
    $hasGa4 = $analyticsEnabled && $ga4Id !== '';
    $hasMetaPixel = $metaPixelId !== '';
@endphp

@if ($hasGa4 || $hasMetaPixel)
    @php
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $currency = 'EUR';
        $taxPricing = app(\App\Services\Pricing\TaxPricingService::class);
        $routeName = (string) (request()->route()?->getName() ?? '');
    @endphp

    @if (request()->routeIs('products.show') && isset($product))
        @php
            $productTranslation = $product->translations->firstWhere('locale', $locale)
                ?? $product->translations->firstWhere('locale', $fallbackLocale);
            $firstCategory = $product->categories->first();
            $firstCategoryTranslation = $firstCategory?->translations?->firstWhere('locale', $locale)
                ?? $firstCategory?->translations?->firstWhere('locale', $fallbackLocale);
            $manufacturerTranslation = $product->manufacturer?->translations?->firstWhere('locale', $locale)
                ?? $product->manufacturer?->translations?->firstWhere('locale', $fallbackLocale);
            $viewItemPayload = [
                'currency' => $currency,
                'value' => round((float) $taxPricing->grossFromStored((float) $product->base_price, $product), 2),
                'items' => [[
                    'item_id' => (string) ($product->sku ?: $product->id),
                    'item_name' => (string) ($productTranslation?->name ?: $product->code),
                    'item_brand' => (string) ($manufacturerTranslation?->name ?? ''),
                    'item_category' => (string) ($firstCategoryTranslation?->name ?? ''),
                    'price' => round((float) $taxPricing->grossFromStored((float) $product->base_price, $product), 2),
                    'quantity' => 1,
                ]],
            ];
            $viewItemOnceKey = 'view-item:'.$routeName.':'.(string) $product->id;
        @endphp
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (!window.ShopAnalytics) {
                    return;
                }

                window.ShopAnalytics.trackOnce(@js($viewItemOnceKey), 'view_item', @js($viewItemPayload));
            });
        </script>
    @elseif (request()->routeIs('cart.index') && isset($lines) && isset($summary))
        @php
            $cartItems = collect($lines)->map(function (array $line) {
                $product = $line['product'];
                $translation = $line['translation'];

                return [
                    'item_id' => (string) ($line['sku'] ?: $product->sku ?: $product->id),
                    'item_name' => (string) ($translation?->name ?? $product->code),
                    'price' => round((float) ($line['display_unit_price'] ?? $line['unit_price'] ?? 0), 2),
                    'quantity' => (int) ($line['quantity'] ?? 1),
                ];
            })->values()->all();
            $viewCartPayload = [
                'currency' => $currency,
                'value' => round((float) ($summary['grand_total'] ?? 0), 2),
                'items' => $cartItems,
            ];
            $viewCartOnceKey = 'view-cart:'.md5((string) request()->fullUrl());
        @endphp
        @if ($cartItems !== [])
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    if (!window.ShopAnalytics) {
                        return;
                    }

                    window.ShopAnalytics.trackOnce(@js($viewCartOnceKey), 'view_cart', @js($viewCartPayload));
                });
            </script>
        @endif
    @elseif ((request()->routeIs('shop.index') || request()->routeIs('categories.show') || request()->routeIs('manufacturers.show')) && isset($products))
        @php
            $viewListItems = collect($products instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $products->items() : (array) $products)
                ->take(30)
                ->values()
                ->map(function ($row, $index) use ($locale, $fallbackLocale, $taxPricing) {
                    $translation = $row->translations?->firstWhere('locale', $locale)
                        ?? $row->translations?->firstWhere('locale', $fallbackLocale)
                        ?? $row->translations?->first();

                    return [
                        'item_id' => (string) ($row->sku ?: $row->id),
                        'item_name' => (string) ($translation?->name ?: $row->code),
                        'price' => round((float) $taxPricing->grossFromStored((float) $row->base_price, $row), 2),
                        'index' => $index + 1,
                    ];
                })
                ->all();
            $listName = request()->routeIs('categories.show')
                ? ((string) (($category->translations->firstWhere('locale', $locale)?->name ?? $category->translations->firstWhere('locale', $fallbackLocale)?->name ?? __('ui.shop.page_title')) ?? __('ui.shop.page_title')))
                : (request()->routeIs('manufacturers.show')
                    ? ((string) (($manufacturer->translations->firstWhere('locale', $locale)?->name ?? $manufacturer->translations->firstWhere('locale', $fallbackLocale)?->name ?? __('ui.shop.page_title')) ?? __('ui.shop.page_title')))
                    : __('ui.shop.page_title'));
            $viewListPayload = [
                'item_list_id' => $routeName !== '' ? $routeName : 'catalog.list',
                'item_list_name' => $listName,
                'items' => $viewListItems,
            ];
            $pageNumber = $products instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? (int) $products->currentPage() : 1;
            $viewListOnceKey = 'view-item-list:'.$routeName.':'.$pageNumber.':'.md5((string) request()->fullUrl());
        @endphp
        @if ($viewListItems !== [])
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    if (!window.ShopAnalytics) {
                        return;
                    }

                    window.ShopAnalytics.trackOnce(@js($viewListOnceKey), 'view_item_list', @js($viewListPayload));
                });
            </script>
        @endif
    @endif
@endif
