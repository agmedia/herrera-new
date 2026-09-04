@extends('front.desktop.layouts.store')

@section('body_class', ($isShopPage ?? false) ? 'catalog-category-page catalog-shop-page' : 'catalog-category-page')

@php
    $isShopPage = (bool) ($isShopPage ?? false);
    $isManufacturerPage = (bool) ($isManufacturerPage ?? false);
    $manufacturerPageModel = $isManufacturerPage ? $manufacturer : null;
    $showManufacturers = ! $isManufacturerPage
        && app(\App\Services\Catalog\CatalogFeatureService::class)->useManufacturers();
    $translation = match (true) {
        $isManufacturerPage => ($manufacturerPageModel->translations->firstWhere('locale', $locale)
            ?? $manufacturerPageModel->translations->firstWhere('locale', $fallbackLocale)
            ?? $manufacturerPageModel->translations->first()),
        $isShopPage => null,
        default => ($category->translations->firstWhere('locale', $locale)
            ?? $category->translations->firstWhere('locale', $fallbackLocale)),
    };
    $catalogCategories = ($isShopPage || $isManufacturerPage)
        ? ($categories ?? collect())
        : ($subcategories ?? collect());
    $hasSubcategories = $catalogCategories->isNotEmpty();
    $catalogBaseUrl = match (true) {
        $isManufacturerPage => route('manufacturers.show', ['slug' => $translation?->slug ?? $manufacturerPageModel->id]),
        $isShopPage => route('shop.index'),
        default => route('categories.show', ['slug' => $translation?->slug ?? $category->id]),
    };
    $catalogUrl = static function (array $query = []) use ($isShopPage, $isManufacturerPage, $translation, $category, $manufacturerPageModel): string {
        if ($isManufacturerPage) {
            return route('manufacturers.show', [
                'slug' => $translation?->slug ?? $manufacturerPageModel->id,
                ...array_merge(request()->query(), $query),
            ]);
        }

        if ($isShopPage) {
            return route('shop.index', array_merge(request()->query(), $query));
        }

        return route('categories.show', [
            'slug' => $translation?->slug ?? $category->id,
            ...array_merge(request()->query(), $query),
        ]);
    };
    $catalogHeading = match (true) {
        $isManufacturerPage => ($translation?->name ?? $manufacturerPageModel->code),
        $isShopPage => __('ui.shop.title'),
        default => ($translation?->name ?? $category->code),
    };
    $catalogCurrentCategoryLabel = ($isShopPage || $isManufacturerPage)
        ? __('ui.shop.filters.all_categories')
        : ($translation?->name ?? $category->code);
    $defaultSort = ($isShopPage || $isManufacturerPage) ? 'newest' : 'default';
    $catalogCategoryUrl = static function ($catalogCategory) use ($isManufacturerPage, $translation, $locale, $fallbackLocale): string {
        $catalogCategoryTranslation = $catalogCategory->translations->firstWhere('locale', $locale)
            ?? $catalogCategory->translations->firstWhere('locale', $fallbackLocale);

        return route('categories.show', array_filter([
            'slug' => $catalogCategoryTranslation?->slug ?? $catalogCategory->id,
            'manufacturer' => $isManufacturerPage ? ($translation?->slug ?? null) : null,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    };
    $mobileDefaultCols = in_array((int) ($storeSettings['product']['mobile_default_cols'] ?? 2), [1, 2], true)
        ? (int) ($storeSettings['product']['mobile_default_cols'] ?? 2)
        : 2;
    $desktopDefaultCols = in_array((int) ($storeSettings['product']['desktop_default_cols'] ?? 4), [4, 5], true)
        ? (int) ($storeSettings['product']['desktop_default_cols'] ?? 4)
        : 4;
    $showCategoryFilters = (bool) ($showCategoryFilters ?? true);
    $showCategoryProducts = (bool) ($showCategoryProducts ?? true);
    $filterPanelSettings = is_array($storeSettings['product']['filter_panel_settings'] ?? null)
        ? $storeSettings['product']['filter_panel_settings']
        : [];
    $resolveFilterPanel = static function (string $key) use ($filterPanelSettings): array {
        $settings = is_array($filterPanelSettings[$key] ?? null) ? $filterPanelSettings[$key] : [];
        $maxHeight = (int) ($settings['max_height'] ?? 286);

        return [
            'visible' => array_key_exists('visible', $settings) ? (bool) $settings['visible'] : true,
            'default_open' => array_key_exists('default_open', $settings) ? (bool) $settings['default_open'] : true,
            'max_height' => in_array($maxHeight, [160, 220, 286, 360], true) ? $maxHeight : 286,
        ];
    };
    $categoryFilterPanel = $resolveFilterPanel('category');
    $manufacturerFilterPanel = $resolveFilterPanel('manufacturer');
    $priceFilterPanel = $resolveFilterPanel('price');
    $currentCols = (int) ($filters['cols'] ?? $desktopDefaultCols);
    if (request()->query('cols') === null && in_array($currentCols, [1, 2], true)) {
        $currentCols = $desktopDefaultCols;
    }
    $mobileCols = in_array($currentCols, [1, 2], true) ? $currentCols : $mobileDefaultCols;
    $paginationMode = (string) ($storeSettings['product']['catalog_pagination_mode'] ?? 'pagination');
    $useAsyncPagination = in_array($paginationMode, ['load_more', 'infinite'], true);
    $isInfinitePagination = $paginationMode === 'infinite';
    $desktopFilterSelectCount = ($hasSubcategories ? 1 : 0) + ($showManufacturers ? 1 : 0) + count($optionFilters ?? []) + count($attributeFilters ?? []) + 1;
    $hasActiveFilters = trim((string) ($filters['q'] ?? '')) !== ''
        || (! $isManufacturerPage && trim((string) ($filters['manufacturer'] ?? '')) !== '')
        || trim((string) ($filters['price_min'] ?? '')) !== ''
        || trim((string) ($filters['price_max'] ?? '')) !== ''
        || (bool) ($filters['available_only'] ?? false)
        || (bool) ($filters['promo_only'] ?? false)
        || collect(array_keys(request()->query()))
            ->contains(fn ($key): bool => str_starts_with((string) $key, 'opt_') || str_starts_with((string) $key, 'attr_'))
        || (string) ($filters['sort'] ?? $defaultSort) !== $defaultSort;
    $priceMinValue = trim((string) ($filters['price_min'] ?? ''));
    $priceMaxValue = trim((string) ($filters['price_max'] ?? ''));
    $availableOnlyEnabled = (bool) ($filters['available_only'] ?? false);
    $promoOnlyEnabled = (bool) ($filters['promo_only'] ?? false);
    $promoToggleDisabled = ! (bool) ($promoFilterAvailable ?? false) && ! $promoOnlyEnabled;
    $hasPriceFilter = $priceMinValue !== '' || $priceMaxValue !== '';
    $hasPricePanelFilter = $hasPriceFilter || $promoOnlyEnabled;
    $priceTriggerLabel = __('ui.shop.filters.price');
    $resolvedPriceBoundsMin = isset($priceBounds['min']) && is_numeric($priceBounds['min'] ?? null)
        ? (float) $priceBounds['min']
        : null;
    $resolvedPriceBoundsMax = isset($priceBounds['max']) && is_numeric($priceBounds['max'] ?? null)
        ? (float) $priceBounds['max']
        : null;
    $priceSliderMin = $resolvedPriceBoundsMin !== null
        ? (int) floor($resolvedPriceBoundsMin)
        : ($priceMinValue !== '' ? (int) floor((float) $priceMinValue) : 0);
    $priceSliderMax = $resolvedPriceBoundsMax !== null
        ? (int) ceil($resolvedPriceBoundsMax)
        : ($priceMaxValue !== '' ? (int) ceil((float) $priceMaxValue) : max($priceSliderMin, 100));

    if ($priceSliderMax <= $priceSliderMin) {
        $priceSliderMax = $priceSliderMin + ($priceSliderMin > 0 ? 10 : 100);
    }

    $priceSliderSelectedMin = $priceMinValue !== ''
        ? max($priceSliderMin, min($priceSliderMax, (int) floor((float) $priceMinValue)))
        : $priceSliderMin;
    $priceSliderSelectedMax = $priceMaxValue !== ''
        ? max($priceSliderMin, min($priceSliderMax, (int) ceil((float) $priceMaxValue)))
        : $priceSliderMax;

    if ($priceSliderSelectedMin > $priceSliderSelectedMax) {
        [$priceSliderSelectedMin, $priceSliderSelectedMax] = [$priceSliderSelectedMax, $priceSliderSelectedMin];
    }

    $sizeFilterLabel = mb_strtolower(trim((string) __('ui.shop.filters.size')));
    $sizeOptionFilter = null;
    $compositionAttributeFilter = null;
    $orderedCategoryFilters = collect();

    foreach (($optionFilters ?? []) as $filterOption) {
        $queryKey = (string) ($filterOption['query_key'] ?? '');
        $label = mb_strtolower(trim((string) ($filterOption['label'] ?? '')));

        if ($queryKey === 'size' || $label === $sizeFilterLabel) {
            $sizeOptionFilter = $filterOption;
            continue;
        }

        $orderedCategoryFilters->push($filterOption);
    }

    foreach (($attributeFilters ?? []) as $attributeFilter) {
        if (in_array((string) ($attributeFilter['query_key'] ?? ''), ['attr_sastav', 'attr_material'], true)) {
            $compositionAttributeFilter = $attributeFilter;
            continue;
        }

        $orderedCategoryFilters->push($attributeFilter);
    }

    if ($compositionAttributeFilter !== null) {
        if ($sizeOptionFilter !== null) {
            $orderedCategoryFilters->prepend($compositionAttributeFilter);
            $orderedCategoryFilters->prepend($sizeOptionFilter);
        } else {
            $orderedCategoryFilters->prepend($compositionAttributeFilter);
        }
    } elseif ($sizeOptionFilter !== null) {
        $orderedCategoryFilters->prepend($sizeOptionFilter);
    }

    $gridClass = match ($currentCols) {
        1 => 'grid grid-cols-1',
        2 => 'grid grid-cols-2',
        3 => 'grid '.($mobileDefaultCols === 2 ? 'grid-cols-2 ' : '').'sm:grid-cols-2 lg:grid-cols-3',
        5 => 'grid '.($mobileDefaultCols === 2 ? 'grid-cols-2 ' : '').'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5',
        default => 'grid '.($mobileDefaultCols === 2 ? 'grid-cols-2 ' : '').'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
    };
    $mobileGridIcons = [1 => 'list', 2 => 'table-columns'];
    $desktopGridIcons = [3 => 'table-cells-large', 4 => 'table-cells', 5 => 'grip'];
@endphp

@section('title', $isShopPage ? __('ui.shop.page_title') : (($translation?->name ?? __('ui.category.fallback_name')).' '.__('ui.category.products_suffix')))
@section('main_class', 'w-full px-0 pt-3 pb-4 sm:pt-3 sm:pb-6')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/category-catalog.css') }}?v={{ filemtime(public_path('front-theme/styles/category-catalog.css')) }}">
@endpush

@section('content')

    <section class="storefront-container px-3 sm:px-4 lg:px-6">
        <div class="front-soft-hero px-4 py-4 text-center sm:px-6 sm:py-5">
        <nav aria-label="Breadcrumb" class="mb-2">
            <ol class="flex flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                <li>
                    <a href="{{ route('home') }}" class="inline-flex items-center justify-center text-slate-500 hover:text-slate-700">{{ __('ui.front.desktop.footer.home') }}</a>
                </li>
                @if ($isShopPage)
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">{{ __('ui.shop.page_title') }}</li>
                @elseif ($isManufacturerPage)
                    <li class="text-slate-400">/</li>
                    <li>
                        <a href="{{ route('manufacturers.index') }}" class="hover:text-slate-700">{{ __('ui.shop.search_autocomplete.groups.manufacturers') }}</a>
                    </li>
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">{{ $catalogHeading }}</li>
                @else
                    @foreach (($breadcrumbCategories ?? collect()) as $breadcrumbCategory)
                        @php
                            $breadcrumbTranslation = $breadcrumbCategory->translations->firstWhere('locale', $locale)
                                ?? $breadcrumbCategory->translations->firstWhere('locale', $fallbackLocale);
                            $breadcrumbLabel = $breadcrumbTranslation?->name ?? $breadcrumbCategory->code;
                        @endphp
                        <li class="text-slate-400">/</li>
                        @if ($loop->last)
                            <li class="text-slate-700">{{ $breadcrumbLabel }}</li>
                        @else
                            <li>
                                <a href="{{ route('categories.show', ['slug' => $breadcrumbTranslation?->slug ?? $breadcrumbCategory->id]) }}" class="hover:text-slate-700">{{ $breadcrumbLabel }}</a>
                            </li>
                        @endif
                    @endforeach
                @endif
            </ol>
        </nav>
        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ $catalogHeading }}</h1>
        @if ($showCategoryProducts)
            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ (int) $products->total() }} {{ __('ui.cart.summary.total') }}</p>
        @endif
        </div>
    </section>

    @if ($topBlocks->isNotEmpty())
        <section class="storefront-container mb-8 px-3 sm:px-4 lg:px-6">
            @include('components.content-placement', ['items' => $topBlocks])
        </section>
    @endif

    @if ($showCategoryFilters)
        <section class="catalog-filter-section storefront-container relative z-20 px-3 pt-3 pb-4 sm:px-4 lg:px-6">
            <div class="catalog-filter-sticky-shell">
            <div class="catalog-filter-sticky-bar border-b border-slate-200/90 pb-4">
        <div class="catalog-mobile-filter-rail max-[1024px]:block min-[1025px]:hidden" data-mobile-filter-root>
            <div class="catalog-mobile-filter-toolbar catalog-mobile-filter-toolbar--square">
                <button
                    type="button"
                    class="catalog-mobile-filter-trigger catalog-mobile-filter-trigger--square {{ $hasActiveFilters ? 'is-active' : '' }}"
                    data-mobile-filter-toggle
                    aria-expanded="false"
                    aria-controls="category-mobile-filter-drawer"
                    aria-label="{{ __('ui.shop.filters.open') }}"
                >
                    <x-fa-icon name="sliders" class="h-5 w-5" />
                    <span class="sr-only">{{ __('ui.shop.filters.open') }}</span>
                    @if ($hasActiveFilters)
                        <span class="catalog-mobile-filter-active-dot" aria-hidden="true"></span>
                    @endif
                </button>
                <div class="catalog-mobile-grid-group">
                @foreach ([1, 2] as $cols)
                    <a
                        href="{{ $catalogUrl(['cols' => $cols]) }}"
                        class="catalog-mobile-grid-toggle {{ $mobileCols === $cols ? 'is-active' : '' }}"
                        aria-label="{{ __('ui.shop.filters.grid') }} {{ $cols }}"
                    >
                        <x-fa-icon name="{{ $mobileGridIcons[$cols] }}" class="h-4 w-4" />
                    </a>
                @endforeach
                </div>
            </div>
            <div class="catalog-mobile-filter-drawer hidden" data-mobile-filter-drawer id="category-mobile-filter-drawer">
                <button type="button" class="catalog-mobile-filter-drawer-backdrop" data-mobile-filter-close aria-label="{{ __('ui.front.desktop.close_navigation') }}"></button>
                <div class="catalog-mobile-filter-drawer-panel">
                    <div class="catalog-mobile-filter-drawer-header">
                        <h2 class="catalog-mobile-filter-drawer-title">{{ __('ui.shop.filters.open') }}</h2>
                        <button type="button" class="catalog-mobile-filter-drawer-close" data-mobile-filter-close aria-label="{{ __('ui.front.desktop.close_navigation') }}">
                            <x-fa-icon name="xmark" class="h-5 w-5" />
                        </button>
                    </div>
                    <form method="GET" action="{{ $catalogBaseUrl }}" class="catalog-mobile-filter-panel" data-mobile-filter-panel id="category-mobile-filter-panel">
                        <input type="hidden" name="q" value="{{ $filters['q'] ?? '' }}">
                        <div class="catalog-mobile-filter-content">
                        @if ($hasSubcategories && $categoryFilterPanel['visible'])
                            <details class="catalog-mobile-filter-section" @if ($categoryFilterPanel['default_open']) open @endif>
                                <summary class="catalog-mobile-filter-section-heading">
                                    <span>{{ __('ui.shop.filters.category') }}</span>
                                    <x-fa-icon name="chevron-down" class="catalog-mobile-filter-section-chevron" />
                                </summary>
                                <nav class="catalog-mobile-filter-options catalog-mobile-filter-max-height-{{ $categoryFilterPanel['max_height'] }}" aria-label="{{ __('ui.shop.filters.category') }}">
                                    <a
                                        href="{{ $catalogBaseUrl }}"
                                        class="catalog-mobile-filter-option is-selected"
                                        aria-current="page"
                                    >
                                        <span class="catalog-mobile-filter-check" aria-hidden="true">
                                            <x-fa-icon name="check" />
                                        </span>
                                        <span class="catalog-mobile-filter-option-label">{{ $catalogCurrentCategoryLabel }}</span>
                                        <span class="catalog-mobile-filter-option-count">{{ (int) $products->total() }}</span>
                                    </a>
                                    @foreach ($catalogCategories as $subCategory)
                                        @php
                                            $subCategoryTranslation = $subCategory->translations->firstWhere('locale', $locale)
                                                ?? $subCategory->translations->firstWhere('locale', $fallbackLocale);
                                        @endphp
                                        <a
                                            href="{{ $catalogCategoryUrl($subCategory) }}"
                                            class="catalog-mobile-filter-option"
                                        >
                                            <span class="catalog-mobile-filter-check" aria-hidden="true">
                                                <x-fa-icon name="check" />
                                            </span>
                                            <span class="catalog-mobile-filter-option-label">{{ $subCategoryTranslation?->name ?? $subCategory->code }}</span>
                                            <span class="catalog-mobile-filter-option-count">{{ (int) $subCategory->products_count }}</span>
                                        </a>
                                    @endforeach
                                </nav>
                            </details>
                        @endif
                        @if ($showManufacturers && $manufacturerFilterPanel['visible'])
                            <details class="catalog-mobile-filter-section" @if ($manufacturerFilterPanel['default_open'] || trim((string) ($filters['manufacturer'] ?? '')) !== '') open @endif>
                                <summary class="catalog-mobile-filter-section-heading">
                                    <span>{{ __('ui.shop.filters.manufacturer') }}</span>
                                    <x-fa-icon name="chevron-down" class="catalog-mobile-filter-section-chevron" />
                                </summary>
                                <div class="catalog-mobile-filter-options catalog-mobile-filter-max-height-{{ $manufacturerFilterPanel['max_height'] }}">
                                    @foreach ($manufacturers as $manufacturer)
                                        @php
                                            $manufacturerTranslation = $manufacturer->translations->firstWhere('locale', $locale)
                                                ?? $manufacturer->translations->firstWhere('locale', $fallbackLocale);
                                            $manufacturerSlugValue = (string) ($manufacturerTranslation?->slug ?? '');
                                        @endphp
                                        <label class="catalog-mobile-filter-option">
                                            <input
                                                type="checkbox"
                                                name="manufacturer"
                                                value="{{ $manufacturerSlugValue }}"
                                                @checked(($filters['manufacturer'] ?? '') === $manufacturerSlugValue)
                                                data-exclusive-filter
                                            >
                                            <span class="catalog-mobile-filter-check" aria-hidden="true">
                                                <x-fa-icon name="check" />
                                            </span>
                                            <span class="catalog-mobile-filter-option-label">{{ $manufacturerTranslation?->name ?? $manufacturer->code }}</span>
                                            <span class="catalog-mobile-filter-option-count">{{ (int) $manufacturer->products_count }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                        @foreach ($orderedCategoryFilters as $filterOption)
                            @php
                                $mobileFilterPanel = $resolveFilterPanel((string) ($filterOption['query_key'] ?? ''));
                                $mobileFilterHasSelection = trim((string) ($filterOption['selected'] ?? '')) !== '';
                            @endphp
                            <details class="catalog-mobile-filter-section" @if ($mobileFilterPanel['default_open'] || $mobileFilterHasSelection) open @endif>
                                <summary class="catalog-mobile-filter-section-heading">
                                    <span>{{ $filterOption['label'] }}</span>
                                    <x-fa-icon name="chevron-down" class="catalog-mobile-filter-section-chevron" />
                                </summary>
                                <div class="catalog-mobile-filter-options catalog-mobile-filter-max-height-{{ $mobileFilterPanel['max_height'] }}">
                                    @foreach (($filterOption['values'] ?? []) as $value)
                                        <label class="catalog-mobile-filter-option">
                                            <input
                                                type="checkbox"
                                                name="{{ $filterOption['query_key'] }}"
                                                value="{{ $value['id'] }}"
                                                @checked((string) ($filterOption['selected'] ?? '') === (string) $value['id'])
                                                data-exclusive-filter
                                            >
                                            <span class="catalog-mobile-filter-check" aria-hidden="true">
                                                <x-fa-icon name="check" />
                                            </span>
                                            <span class="catalog-mobile-filter-option-label">{{ $value['label'] }}</span>
                                            @if (isset($value['count']))
                                                <span class="catalog-mobile-filter-option-count">{{ (int) $value['count'] }}</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                        <div class="catalog-mobile-filter-section catalog-mobile-filter-section--standalone">
                            <label class="catalog-mobile-filter-option catalog-mobile-filter-option--featured">
                                <input type="checkbox" name="available_only" value="1" @checked($availableOnlyEnabled)>
                                <span class="catalog-mobile-filter-check" aria-hidden="true">
                                    <x-fa-icon name="check" />
                                </span>
                                <span class="catalog-mobile-filter-option-label">{{ __('ui.shop.filters.available_only') }}</span>
                            </label>
                        </div>
                        @if ($priceFilterPanel['visible'])
                            <details class="catalog-mobile-filter-section" @if ($priceFilterPanel['default_open'] || $hasPricePanelFilter) open @endif>
                                <summary class="catalog-mobile-filter-section-heading">
                                    <span>{{ __('ui.shop.filters.price') }}</span>
                                    <x-fa-icon name="chevron-down" class="catalog-mobile-filter-section-chevron" />
                                </summary>
                                <div
                                    class="catalog-mobile-price-card"
                                    data-price-range-root
                                    data-price-min-bound="{{ $priceSliderMin }}"
                                    data-price-max-bound="{{ $priceSliderMax }}"
                                    data-price-manual-submit
                                >
                                    <input type="hidden" name="price_min" value="{{ $priceMinValue }}" data-price-range-hidden-min>
                                    <input type="hidden" name="price_max" value="{{ $priceMaxValue }}" data-price-range-hidden-max>
                                    <div class="catalog-price-range-values">
                                        <div class="catalog-price-range-value">
                                            <span class="catalog-price-range-value-label">{{ __('ui.shop.filters.price_from') }}</span>
                                            <span class="catalog-price-range-value-amount" data-price-range-current-min>{{ $priceSliderSelectedMin }} €</span>
                                        </div>
                                        <div class="catalog-price-range-value text-right">
                                            <span class="catalog-price-range-value-label">{{ __('ui.shop.filters.price_to') }}</span>
                                            <span class="catalog-price-range-value-amount" data-price-range-current-max>{{ $priceSliderSelectedMax }} €</span>
                                        </div>
                                    </div>
                                    <div class="catalog-price-range-slider">
                                        <div class="catalog-price-range-track"></div>
                                        <div class="catalog-price-range-progress" data-price-range-progress></div>
                                        <input type="range" min="{{ $priceSliderMin }}" max="{{ $priceSliderMax }}" step="1" value="{{ $priceSliderSelectedMin }}" aria-label="{{ __('ui.shop.filters.price_from') }}" data-price-range-min>
                                        <input type="range" min="{{ $priceSliderMin }}" max="{{ $priceSliderMax }}" step="1" value="{{ $priceSliderSelectedMax }}" aria-label="{{ __('ui.shop.filters.price_to') }}" data-price-range-max>
                                    </div>
                                    <div class="catalog-price-range-scale">
                                        <span>{{ $priceSliderMin }} €</span>
                                        <span>{{ $priceSliderMax }} €</span>
                                    </div>
                                    <label class="catalog-mobile-filter-option catalog-mobile-filter-option--promo">
                                        <input type="checkbox" name="promo_only" value="1" @checked($promoOnlyEnabled) @disabled($promoToggleDisabled) data-price-range-promo>
                                        <span class="catalog-mobile-filter-check" aria-hidden="true">
                                            <x-fa-icon name="check" />
                                        </span>
                                        <span class="catalog-price-promo-copy">
                                            <span class="catalog-price-promo-label">{{ __('ui.shop.filters.promotion_only') }}</span>
                                            <span class="catalog-price-promo-hint">{{ __('ui.shop.filters.promotion_only_hint') }}</span>
                                        </span>
                                    </label>
                                    <button type="button" class="catalog-price-reset" data-price-filter-reset @disabled(! $hasPricePanelFilter)>
                                        <x-fa-icon name="rotate-left" class="h-3.5 w-3.5" />
                                        <span>{{ __('ui.shop.filters.reset') }}</span>
                                    </button>
                                </div>
                            </details>
                        @endif
                        <details class="catalog-mobile-filter-section">
                            <summary class="catalog-mobile-filter-section-heading">
                                <span>{{ __('ui.shop.filters.sort') }}</span>
                                <x-fa-icon name="chevron-down" class="catalog-mobile-filter-section-chevron" />
                            </summary>
                            <div class="catalog-mobile-filter-options">
                                @foreach ([
                                    'default' => __('ui.shop.filters.default'),
                                    'newest' => __('ui.shop.filters.newest'),
                                    'oldest' => __('ui.shop.filters.oldest'),
                                    'price_low' => __('ui.shop.filters.price_low'),
                                    'price_high' => __('ui.shop.filters.price_high'),
                                    'stock_high' => __('ui.shop.filters.stock_high'),
                                ] as $sortValue => $sortLabel)
                                    <label class="catalog-mobile-filter-option">
                                        <input
                                            type="checkbox"
                                            name="sort"
                                            value="{{ $sortValue }}"
                                            @checked((string) ($filters['sort'] ?? 'default') === $sortValue)
                                            data-exclusive-filter
                                        >
                                        <span class="catalog-mobile-filter-check" aria-hidden="true">
                                            <x-fa-icon name="check" />
                                        </span>
                                        <span class="catalog-mobile-filter-option-label">{{ $sortLabel }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </details>
                        </div>
                        <input type="hidden" name="cols" value="{{ $mobileCols }}">
                        <div class="catalog-mobile-filter-actions">
                            @if ($hasActiveFilters)
                                <a href="{{ $catalogBaseUrl }}" class="catalog-mobile-filter-clear">
                                    <x-fa-icon name="rotate-left" class="h-3.5 w-3.5" />
                                    <span>{{ __('ui.shop.filters.reset') }}</span>
                                </a>
                            @endif
                            <button type="submit" class="catalog-mobile-filter-apply">
                                <span>{{ __('ui.shop.filters.apply') }}</span>
                                <x-fa-icon name="arrow-right" class="h-4 w-4" />
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="catalog-desktop-toolbar hidden min-[1025px]:flex">
            <div class="catalog-desktop-toolbar-toggles">
                <label class="catalog-toolbar-toggle">
                    <span>{{ __('ui.shop.filters.available_only') }}</span>
                    <span class="catalog-switch">
                        <input
                            type="checkbox"
                            name="available_only"
                            value="1"
                            form="category-desktop-filter-form"
                            @checked($availableOnlyEnabled)
                            data-auto-submit-filter
                            aria-label="{{ __('ui.shop.filters.available_only') }}"
                        >
                        <span class="catalog-switch-track" aria-hidden="true"></span>
                    </span>
                </label>
                <label class="catalog-toolbar-toggle">
                    <span>{{ __('ui.shop.filters.promotion_only') }}</span>
                    <span class="catalog-switch">
                        <input
                            type="checkbox"
                            name="promo_only"
                            value="1"
                            form="category-desktop-filter-form"
                            @checked($promoOnlyEnabled)
                            @disabled($promoToggleDisabled)
                            data-auto-submit-filter
                            aria-label="{{ __('ui.shop.filters.promotion_only') }}"
                        >
                        <span class="catalog-switch-track" aria-hidden="true"></span>
                    </span>
                </label>
            </div>
            <div class="catalog-desktop-toolbar-actions">
                <div class="catalog-filter-sort-wrap w-[180px]">
                    <select
                        id="shop-sort"
                        name="sort"
                        form="category-desktop-filter-form"
                        class="catalog-filter-select catalog-filter-inline-select h-9 w-full rounded-none border-slate-300 text-sm"
                        aria-label="{{ __('ui.shop.filters.sort') }}"
                        data-auto-submit-filter
                    >
                        <option value="default" @selected(($filters['sort'] ?? 'default') === 'default')>{{ __('ui.shop.filters.default') }}</option>
                        <option value="newest" @selected(($filters['sort'] ?? '') === 'newest')>{{ __('ui.shop.filters.newest') }}</option>
                        <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>{{ __('ui.shop.filters.oldest') }}</option>
                        <option value="price_low" @selected(($filters['sort'] ?? '') === 'price_low')>{{ __('ui.shop.filters.price_low') }}</option>
                        <option value="price_high" @selected(($filters['sort'] ?? '') === 'price_high')>{{ __('ui.shop.filters.price_high') }}</option>
                        <option value="stock_high" @selected(($filters['sort'] ?? '') === 'stock_high')>{{ __('ui.shop.filters.stock_high') }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    @foreach ([3, 4, 5] as $cols)
                        <a
                            href="{{ $catalogUrl(['cols' => $cols]) }}"
                            class="catalog-grid-toggle {{ $cols === 5 ? 'catalog-grid-toggle--five' : '' }} {{ $currentCols === $cols ? 'is-active' : '' }}"
                            data-catalog-grid-cols="{{ $cols }}"
                            aria-label="{{ __('ui.shop.filters.grid') }} {{ $cols }}"
                        >
                            <x-fa-icon name="{{ $desktopGridIcons[$cols] }}" class="h-4 w-4" />
                        </a>
                    @endforeach
                </div>
                @if ($hasActiveFilters)
                    <a href="{{ $catalogBaseUrl }}" class="catalog-reset-button whitespace-nowrap">
                        <x-fa-icon name="rotate-left" class="h-3.5 w-3.5" />
                        <span>{{ __('ui.shop.filters.reset') }}</span>
                    </a>
                @endif
            </div>
        </div>
            </div>
            </div>
        </section>
    @endif

    @if ($showCategoryProducts)
        <section class="storefront-container px-3 pt-3 pb-6 sm:px-4 lg:px-6">
            <div class="catalog-products-layout">
                @if ($showCategoryFilters)
                    <aside class="catalog-desktop-sidebar hidden min-[1025px]:block">
                        <div class="catalog-desktop-sidebar-inner">
                            @if ($hasActiveFilters)
                                <div class="catalog-sidebar-active">
                                    <span>{{ __('ui.shop.filters.open') }}</span>
                                    <a href="{{ $catalogBaseUrl }}">
                                        {{ __('ui.shop.filters.reset') }}
                                    </a>
                                </div>
                            @endif

                            @if ($categoryFilterPanel['visible'])
                                <details class="catalog-sidebar-section" @if ($categoryFilterPanel['default_open']) open @endif>
                                    <summary class="catalog-sidebar-heading">
                                        <span>{{ __('ui.shop.filters.category') }}</span>
                                        <x-fa-icon name="chevron-down" class="catalog-sidebar-chevron" />
                                    </summary>
                                    <nav class="catalog-sidebar-options catalog-sidebar-category-options catalog-sidebar-max-height-{{ $categoryFilterPanel['max_height'] }}" aria-label="{{ __('ui.shop.filters.category') }}">
                                        <a
                                            href="{{ $catalogBaseUrl }}"
                                            class="catalog-sidebar-category is-current"
                                            aria-current="page"
                                        >
                                            <span>{{ $catalogCurrentCategoryLabel }}</span>
                                            <span>{{ (int) $products->total() }}</span>
                                        </a>
                                        @foreach ($catalogCategories as $subCategory)
                                            @php
                                                $subCategoryTranslation = $subCategory->translations->firstWhere('locale', $locale)
                                                    ?? $subCategory->translations->firstWhere('locale', $fallbackLocale);
                                            @endphp
                                            <a
                                                href="{{ $catalogCategoryUrl($subCategory) }}"
                                                class="catalog-sidebar-category"
                                            >
                                                <span>{{ $subCategoryTranslation?->name ?? $subCategory->code }}</span>
                                                <span>{{ (int) $subCategory->products_count }}</span>
                                            </a>
                                        @endforeach
                                    </nav>
                                </details>
                            @endif

                            <form
                                id="category-desktop-filter-form"
                                method="GET"
                                action="{{ $catalogBaseUrl }}"
                                data-desktop-filter-form
                            >
                                <input type="hidden" name="q" value="{{ $filters['q'] ?? '' }}">
                                <input type="hidden" name="cols" value="{{ $currentCols }}">

                                @if ($showManufacturers && $manufacturerFilterPanel['visible'])
                                    <details class="catalog-sidebar-section" @if ($manufacturerFilterPanel['default_open'] || trim((string) ($filters['manufacturer'] ?? '')) !== '') open @endif>
                                        <summary class="catalog-sidebar-heading">
                                            <span>{{ __('ui.shop.filters.manufacturer') }}</span>
                                            <x-fa-icon name="chevron-down" class="catalog-sidebar-chevron" />
                                        </summary>
                                        <div class="catalog-sidebar-options catalog-sidebar-max-height-{{ $manufacturerFilterPanel['max_height'] }}">
                                            @foreach ($manufacturers as $manufacturer)
                                                @php
                                                    $manufacturerTranslation = $manufacturer->translations->firstWhere('locale', $locale)
                                                        ?? $manufacturer->translations->firstWhere('locale', $fallbackLocale);
                                                    $manufacturerSlugValue = (string) ($manufacturerTranslation?->slug ?? '');
                                                @endphp
                                                <label class="catalog-sidebar-option">
                                                    <input
                                                        type="radio"
                                                        name="manufacturer"
                                                        value="{{ $manufacturerSlugValue }}"
                                                        @checked(($filters['manufacturer'] ?? '') === $manufacturerSlugValue)
                                                        data-auto-submit-filter
                                                    >
                                                    <span class="catalog-sidebar-check" aria-hidden="true">
                                                        <x-fa-icon name="check" />
                                                    </span>
                                                    <span class="catalog-sidebar-option-label">{{ $manufacturerTranslation?->name ?? $manufacturer->code }}</span>
                                                    <span class="catalog-sidebar-option-count">{{ (int) $manufacturer->products_count }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif

                                @if ($priceFilterPanel['visible'])
                                <details class="catalog-sidebar-section" data-price-filter-root @if ($priceFilterPanel['default_open'] || $hasPricePanelFilter) open @endif>
                                    <summary class="catalog-sidebar-heading">
                                        <span>{{ __('ui.shop.filters.price') }}</span>
                                        <x-fa-icon name="chevron-down" class="catalog-sidebar-chevron" />
                                    </summary>
                                    <div class="catalog-sidebar-price catalog-sidebar-scroll catalog-sidebar-max-height-{{ $priceFilterPanel['max_height'] }}" data-price-range-root data-price-min-bound="{{ $priceSliderMin }}" data-price-max-bound="{{ $priceSliderMax }}">
                                        <input type="hidden" name="price_min" value="{{ $priceMinValue }}" data-price-range-hidden-min>
                                        <input type="hidden" name="price_max" value="{{ $priceMaxValue }}" data-price-range-hidden-max>
                                        <div class="catalog-price-range-values">
                                            <div class="catalog-price-range-value">
                                                <span class="catalog-price-range-value-label">{{ __('ui.shop.filters.price_from') }}</span>
                                                <span class="catalog-price-range-value-amount" data-price-range-current-min>{{ $priceSliderSelectedMin }} €</span>
                                            </div>
                                            <div class="catalog-price-range-value text-right">
                                                <span class="catalog-price-range-value-label">{{ __('ui.shop.filters.price_to') }}</span>
                                                <span class="catalog-price-range-value-amount" data-price-range-current-max>{{ $priceSliderSelectedMax }} €</span>
                                            </div>
                                        </div>
                                        <div class="catalog-price-range-slider">
                                            <div class="catalog-price-range-track"></div>
                                            <div class="catalog-price-range-progress" data-price-range-progress></div>
                                            <input type="range" min="{{ $priceSliderMin }}" max="{{ $priceSliderMax }}" step="1" value="{{ $priceSliderSelectedMin }}" aria-label="{{ __('ui.shop.filters.price_from') }}" data-price-range-min>
                                            <input type="range" min="{{ $priceSliderMin }}" max="{{ $priceSliderMax }}" step="1" value="{{ $priceSliderSelectedMax }}" aria-label="{{ __('ui.shop.filters.price_to') }}" data-price-range-max>
                                        </div>
                                        <div class="catalog-price-range-scale">
                                            <span>{{ $priceSliderMin }} €</span>
                                            <span>{{ $priceSliderMax }} €</span>
                                        </div>
                                        <button type="button" class="catalog-price-reset" data-price-filter-reset @disabled(! $hasPriceFilter)>
                                            <x-fa-icon name="rotate-left" class="h-3.5 w-3.5" />
                                            <span>{{ __('ui.shop.filters.reset') }}</span>
                                        </button>
                                    </div>
                                </details>
                                @endif

                                @foreach ($orderedCategoryFilters as $filterOption)
                                    @php
                                        $isColorFilter = (string) ($filterOption['kind'] ?? 'default') === 'color';
                                        $filterPanel = $resolveFilterPanel((string) ($filterOption['query_key'] ?? ''));
                                        $filterHasSelection = trim((string) ($filterOption['selected'] ?? '')) !== '';
                                    @endphp
                                    <details class="catalog-sidebar-section" @if ($filterPanel['default_open'] || $filterHasSelection) open @endif>
                                        <summary class="catalog-sidebar-heading">
                                            <span>{{ $filterOption['label'] }}</span>
                                            <x-fa-icon name="chevron-down" class="catalog-sidebar-chevron" />
                                        </summary>
                                        <div class="catalog-sidebar-options catalog-sidebar-max-height-{{ $filterPanel['max_height'] }}">
                                            @foreach (($filterOption['values'] ?? []) as $value)
                                                <label class="catalog-sidebar-option">
                                                    <input
                                                        type="radio"
                                                        name="{{ $filterOption['query_key'] }}"
                                                        value="{{ $value['id'] }}"
                                                        @checked((string) ($filterOption['selected'] ?? '') === (string) $value['id'])
                                                        data-auto-submit-filter
                                                        @if ($isColorFilter)
                                                            data-filter-kind="color"
                                                            data-filter-count="{{ (int) ($value['count'] ?? 0) }}"
                                                            @if (! empty($value['swatch_image_url']))
                                                                data-filter-swatch="{{ $value['swatch_image_url'] }}"
                                                            @endif
                                                        @endif
                                                    >
                                                    <span class="catalog-sidebar-check" aria-hidden="true">
                                                        <x-fa-icon name="check" />
                                                    </span>
                                                    <span class="catalog-sidebar-option-label">{{ $value['label'] }}</span>
                                                    @if (isset($value['count']))
                                                        <span class="catalog-sidebar-option-count">{{ (int) $value['count'] }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>
                                    </details>
                                @endforeach
                            </form>
                        </div>
                    </aside>
                @endif

                <div class="catalog-products-main">
                    @if ($products->isEmpty())
                        <div class="border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">{{ __('ui.category.empty') }}</div>
                    @else
                        <div class="catalog-lined-grid {{ $gridClass }}" data-catalog-grid>
                            @foreach ($products as $product)
                                @include('front.desktop.partials.product-card', [
                                    'product' => $product,
                                    'locale' => $locale,
                                    'fallbackLocale' => $fallbackLocale,
                                    'flat' => true,
                                    'lined' => true,
                                    'priorityImage' => $loop->first,
                                    'headingLevel' => 2,
                                ])
                            @endforeach
                        </div>

                        @if ($useAsyncPagination)
                            <div class="mt-8 flex items-center justify-center" data-catalog-load-more-root data-catalog-load-mode="{{ $isInfinitePagination ? 'infinite' : 'load_more' }}">
                                @if ($products->hasMorePages())
                                    <a href="{{ $products->nextPageUrl() }}" class="hidden" data-catalog-next-url>{{ __('ui.shop.load_more') }}</a>
                                    <button type="button" class="{{ $isInfinitePagination ? 'hidden' : 'inline-flex' }} h-10 items-center justify-center border border-slate-900 bg-slate-900 px-5 text-sm font-semibold text-white hover:bg-slate-700" data-catalog-load-more-button>
                                        {{ __('ui.shop.load_more') }}
                                    </button>
                                @endif
                                <div class="h-8 flex items-center justify-center">
                                    <div class="hidden inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500" data-catalog-load-more-loader data-loading-label="{{ __('ui.shop.loading') }}" data-end-label="{{ __('ui.shop.end_of_list') }}">
                                        <span class="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-400 border-t-transparent" data-catalog-load-more-spinner aria-hidden="true"></span>
                                        <span data-catalog-load-more-loader-text>{{ __('ui.shop.loading') }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="sr-only" data-catalog-pagination-seo aria-hidden="true">
                                {{ $products->onEachSide(0)->links() }}
                            </div>
                            <noscript>
                                <div class="mt-14">
                                    {{ $products->onEachSide(0)->links() }}
                                </div>
                            </noscript>
                        @else
                            <div class="mt-14" data-catalog-pagination>
                                {{ $products->onEachSide(0)->links() }}
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if ($bottomBlocks->isNotEmpty())
        <section class="storefront-container mt-10 px-3 sm:px-4 lg:px-6">
            @include('components.content-placement', ['items' => $bottomBlocks])
        </section>
    @endif
@endsection

@push('scripts')
    @if ($showCategoryFilters)
        <script defer src="{{ asset('front-theme/scripts/category-select-redirect.js') }}?v={{ filemtime(public_path('front-theme/scripts/category-select-redirect.js')) }}"></script>
        <script defer src="{{ asset('front-theme/scripts/catalog-custom-select.js') }}?v={{ filemtime(public_path('front-theme/scripts/catalog-custom-select.js')) }}"></script>
    @endif
    @if ($showCategoryProducts && $useAsyncPagination)
        <script defer src="{{ asset('front-theme/scripts/catalog-load-more.js') }}?v={{ filemtime(public_path('front-theme/scripts/catalog-load-more.js')) }}"></script>
    @endif
    @if ($showCategoryFilters)
        <script defer src="{{ asset('front-theme/scripts/category-catalog.js') }}?v={{ filemtime(public_path('front-theme/scripts/category-catalog.js')) }}"></script>
    @endif
@endpush
