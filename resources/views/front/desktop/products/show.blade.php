@extends('front.desktop.layouts.store')

@php
    $translation = $product->translations->firstWhere('locale', $locale)
        ?? $product->translations->firstWhere('locale', $fallbackLocale);
    $manufacturerTranslation = $product->manufacturer?->translations?->firstWhere('locale', $locale)
        ?? $product->manufacturer?->translations?->firstWhere('locale', $fallbackLocale);
    $manufacturerEnabled = app(\App\Services\Catalog\CatalogFeatureService::class)->useManufacturers();
    $formatGrossPrice = static fn ($value): string => number_format((float) $value, 2).' €';
    $formatGrossDecimal = static fn ($value): string => number_format((float) $value, 2, '.', '');
    $formatPriceData = static function (array $priceData) use ($formatGrossPrice, $formatGrossDecimal): array {
        $oldGross = $priceData['old_gross'] ?? null;
        $lowestGross = $priceData['lowest_30_days_gross'] ?? null;
        $discountPercent = (int) ($priceData['discount_percent'] ?? 0);

        return [
            'current' => $formatGrossPrice((float) ($priceData['current_gross'] ?? 0)),
            'current_value' => $formatGrossDecimal((float) ($priceData['current_gross'] ?? 0)),
            'current_net' => $formatGrossPrice((float) ($priceData['current_net'] ?? $priceData['current_gross'] ?? 0)),
            'old' => $oldGross !== null ? $formatGrossPrice((float) $oldGross) : '',
            'discount_percent' => $discountPercent > 0 ? (string) $discountPercent : '',
            'is_b2b' => (bool) ($priceData['is_b2b_price'] ?? false),
            'lowest_30_days' => $lowestGross !== null
                ? __('ui.product.lowest_price_30_days', ['price' => $formatGrossPrice((float) $lowestGross)])
                : '',
        ];
    };
    $pricePresenter = app(\App\Services\Pricing\ProductPricePresentationService::class);
    $authUser = auth()->user();
    $productPriceData = $formatPriceData($pricePresentation);
    $optionPriceData = static function ($row) use ($pricePresenter, $product, $authUser, $formatPriceData): array {
        $storedBase = $row->price_override !== null
            ? (float) $row->price_override
            : (float) $product->base_price;

        return $formatPriceData($pricePresenter->forStoredBase($product, $storedBase, $authUser));
    };
    $currentPrice = $productPriceData['current'];
    $oldPrice = isset($pricePresentation['old_gross']) && $pricePresentation['old_gross'] !== null
        ? $formatGrossPrice((float) $pricePresentation['old_gross'])
        : null;
    $discountPercent = (int) ($pricePresentation['discount_percent'] ?? 0);
    $isB2BPrice = (bool) ($pricePresentation['is_b2b_price'] ?? false);
    $lowest30DaysPrice = isset($pricePresentation['lowest_30_days_gross']) && $pricePresentation['lowest_30_days_gross'] !== null
        ? $formatGrossPrice((float) $pricePresentation['lowest_30_days_gross'])
        : null;
    $energyLabelPresenter = app(\App\Support\ProductEnergyLabelPresenter::class);
    $energyDeclarations = $energyLabelPresenter->declarations($product);
    $primaryEnergyDeclaration = $energyLabelPresenter->primary($product);
    $primaryEnergyDocuments = $energyLabelPresenter->primaryDeclaration($product);
    $energyDocumentDeclarations = $energyDeclarations->filter(fn ($row) => (bool) ($row['has_documents'] ?? false));
    $isWishlisted = app(\App\Services\Front\WishlistService::class)->has((int) $product->id);
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);

    $mediaItems = $product->relationLoaded('media')
        ? $product->media
            ->whereIn('collection_name', ['product_main', 'product_gallery'])
            ->sortBy(static fn ($mediaItem) => (int) ($mediaItem->order_column ?? 0))
            ->values()
        : collect();
    $usableMediaItems = $mediaItems
        ->filter(fn ($mediaItem) => \App\Support\Media\MediaUrl::hasUsableSource($mediaItem, ['detail_960x960', 'thumb_100x100']))
        ->values();
    $mainMedia = $usableMediaItems->firstWhere('collection_name', 'product_main')
        ?? $usableMediaItems->firstWhere('collection_name', 'product_gallery')
        ?? $product->getMedia('*')
            ->whereIn('collection_name', ['product_main', 'product_gallery'])
            ->first(fn ($mediaItem) => \App\Support\Media\MediaUrl::hasUsableSource($mediaItem, ['detail_960x960', 'thumb_100x100']));

    $galleryItems = collect();
    if ($mainMedia) {
        $galleryItems->push($mainMedia);
    }
    foreach ($usableMediaItems as $mediaItem) {
        if ($mainMedia && (int) $mediaItem->id === (int) $mainMedia->id) {
            continue;
        }

        $galleryItems->push($mediaItem);
    }

    $gallery = $galleryItems
        ->unique(fn ($mediaItem) => (int) $mediaItem->id)
        ->map(function ($mediaItem) use ($translation, $product, $preferWebp) {
            $detailUrl = \App\Support\Media\MediaUrl::conversion($mediaItem, 'detail_960x960', $preferWebp);
            $cardUrl720 = \App\Support\Media\MediaUrl::conversionOrNull($mediaItem, 'card_720w', $preferWebp);
            $cardUrl480 = \App\Support\Media\MediaUrl::conversionOrNull($mediaItem, 'card_480w', $preferWebp);
            $thumbUrl = \App\Support\Media\MediaUrl::conversion($mediaItem, 'thumb_100x100', $preferWebp);
            $displayUrl = $cardUrl720 ?? $detailUrl;
            $displaySrcset = collect([
                $cardUrl480 ? $cardUrl480.' 480w' : null,
                $cardUrl720 ? $cardUrl720.' 720w' : null,
                $detailUrl ? $detailUrl.' 960w' : null,
            ])->filter()->unique()->implode(', ');

            return [
                'id' => (int) $mediaItem->id,
                'full' => (string) ($detailUrl ?? $mediaItem->getUrl()),
                'display' => (string) $displayUrl,
                'display_srcset' => $displaySrcset,
                'thumb' => (string) ($thumbUrl ?? $mediaItem->getUrl()),
                'alt' => (string) ($translation?->name ?? $product->code),
            ];
        })
        ->values();

    $allOptionRows = $product->visibleOptionRows();
    $availableOptionRows = $product->availableOptionRows();
    $optionRows = $availableOptionRows->isNotEmpty() ? $availableOptionRows : $allOptionRows;
    $isPurchasable = $product->storefrontIsPurchasable();
    $hasLinkedOptions = $optionRows->contains(fn ($row) => (int) ($row->parent_option_value_id ?? 0) > 0);
    $primaryOptionLabel = __('ui.cart.modal.option');
    $secondaryOptionLabel = __('ui.cart.modal.option');
    $linkedPrimaryValues = collect();
    if ($hasLinkedOptions) {
        $firstLinkedRow = $optionRows->first(fn ($row) => (int) ($row->parent_option_value_id ?? 0) > 0);
        $parentOptionTranslation = $firstLinkedRow?->parentOptionValue?->option?->translations?->firstWhere('locale', $locale)
            ?? $firstLinkedRow?->parentOptionValue?->option?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $firstLinkedRow?->parentOptionValue?->option?->translations?->first();
        $childOptionTranslation = $firstLinkedRow?->optionValue?->option?->translations?->firstWhere('locale', $locale)
            ?? $firstLinkedRow?->optionValue?->option?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $firstLinkedRow?->optionValue?->option?->translations?->first();
        $primaryOptionLabel = trim((string) ($parentOptionTranslation?->name ?? '')) ?: __('ui.cart.modal.option');
        $secondaryOptionLabel = trim((string) ($childOptionTranslation?->name ?? '')) ?: __('ui.cart.modal.option');

        $linkedPrimaryValues = $optionRows
            ->filter(fn ($row) => (int) ($row->parent_option_value_id ?? 0) > 0)
            ->map(function ($row) use ($locale, $fallbackLocale): array {
                $translation = $row->parentOptionValue?->translations?->firstWhere('locale', $locale)
                    ?? $row->parentOptionValue?->translations?->firstWhere('locale', $fallbackLocale)
                    ?? $row->parentOptionValue?->translations?->first();
                $label = trim((string) ($translation?->name ?? $row->parentOptionValue?->code ?? ''));

                return [
                    'id' => (int) ($row->parent_option_value_id ?? 0),
                    'label' => $label,
                ];
            })
            ->unique('id')
            ->values();
    }

    $firstCategory = ($productBreadcrumbCategories ?? collect())->last() ?? $product->categories->first();
    $firstCategoryTranslation = $firstCategory?->translations?->firstWhere('locale', $locale)
        ?? $firstCategory?->translations?->firstWhere('locale', $fallbackLocale);
    $fitFinderEnabled = (bool) ($storeSettings['product']['fit_finder_enabled'] ?? false);
    $fitFinderSavedSize = trim((string) ($fitFinderSelection['size_label'] ?? ''));
    $fitFinderSavedSignature = trim((string) ($fitFinderSelection['size_signature'] ?? ''));
    $desktopDefaultCols = in_array((int) ($storeSettings['product']['desktop_default_cols'] ?? 4), [4, 5], true)
        ? (int) ($storeSettings['product']['desktop_default_cols'] ?? 4)
        : 4;
    $mobileDefaultCols = in_array((int) ($storeSettings['product']['mobile_default_cols'] ?? 2), [1, 2], true)
        ? (int) ($storeSettings['product']['mobile_default_cols'] ?? 2)
        : 2;
    $requestedGridCols = request()->query('cols', request()->cookie('front_grid_cols', $desktopDefaultCols));
    $preferredGridCols = in_array((int) $requestedGridCols, [1, 2, 3, 4, 5], true)
        ? (int) $requestedGridCols
        : $desktopDefaultCols;
    $hasProductStory = ! empty($translation?->description) || ! empty($translation?->excerpt);
    $reviewSummary = $product->approvedCommentSummary([$locale, $fallbackLocale]);
    $colorVariants = $colorVariants ?? collect();
@endphp

@section('title', $translation?->name ?? 'Product')
@section('main_class', 'w-full px-0 py-8')

@section('content')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/product-detail.css') }}?v={{ filemtime(public_path('front-theme/styles/product-detail.css')) }}">
    @endpush

    <div class="product-detail-shell">
        @if ($topBlocks->isNotEmpty())
            <section class="mb-8">
                @include('components.content-placement', ['items' => $topBlocks])
            </section>
        @endif

        <nav aria-label="Breadcrumb" class="product-detail-breadcrumb">
            <ol>
                <li><a href="{{ route('home') }}">{{ __('ui.front.desktop.footer.home') }}</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('shop.index') }}">{{ __('ui.shop.page_title') }}</a></li>
                @foreach (($productBreadcrumbCategories ?? collect()) as $breadcrumbCategory)
                    @php
                        $breadcrumbTranslation = $breadcrumbCategory->translations->firstWhere('locale', $locale)
                            ?? $breadcrumbCategory->translations->firstWhere('locale', $fallbackLocale)
                            ?? $breadcrumbCategory->translations->first();
                    @endphp
                    @if ($breadcrumbTranslation)
                        <li aria-hidden="true">/</li>
                        <li>
                            <a href="{{ route('categories.show', ['slug' => $breadcrumbTranslation->slug]) }}">
                                {{ $breadcrumbTranslation->name }}
                            </a>
                        </li>
                    @endif
                @endforeach
                <li aria-hidden="true">/</li>
                @php $breadcrumbProductName = (string) ($translation?->name ?? $product->code); @endphp
                <li aria-current="page" title="{{ $breadcrumbProductName }}">
                    {{ \Illuminate\Support\Str::limit($breadcrumbProductName, 25, '...') }}
                </li>
            </ol>
        </nav>

    <section class="product-detail-layout product-detail-hero" data-product-detail>
        <div class="product-gallery">
            @if ($gallery->isNotEmpty())
                @php
                    $displayGallery = $gallery->values();
                @endphp

                <div id="product-gallery-{{ $product->id }}" class="product-gallery-main splide" data-product-splide>
                    <div class="splide__track">
                        <ul class="splide__list">
                            @foreach ($displayGallery as $index => $image)
                                <li class="splide__slide">
                                    <button
                                        type="button"
                                        class="product-gallery-slide-button"
                                        data-gallery-open="{{ $index }}"
                                        aria-label="{{ $image['alt'] }}"
                                    >
                                        <img
                                            @if ($loop->first)
                                                src="{{ $image['display'] }}"
                                                @if ($image['display_srcset'] !== '') srcset="{{ $image['display_srcset'] }}" @endif
                                                loading="eager"
                                                fetchpriority="high"
                                            @else
                                                data-splide-lazy="{{ $image['display'] }}"
                                                @if ($image['display_srcset'] !== '') data-splide-lazy-srcset="{{ $image['display_srcset'] }}" @endif
                                            @endif
                                            sizes="(max-width: 900px) calc(100vw - 2rem), 720px"
                                            alt="{{ $image['alt'] }}"
                                            class="product-gallery-slide-image"
                                            width="960"
                                            height="960"
                                            decoding="async"
                                        >
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                @if ($displayGallery->count() > 1)
                    <div
                        class="product-gallery-thumbnails"
                        data-product-gallery-thumbnails
                        aria-label="{{ __('ui.product.product_images') }}"
                    >
                        @foreach ($displayGallery as $index => $image)
                            <button
                                type="button"
                                class="product-gallery-thumbnail {{ $loop->first ? 'is-active' : '' }}"
                                data-gallery-thumb
                                data-index="{{ $index }}"
                                data-full="{{ $image['full'] }}"
                                data-alt="{{ $image['alt'] }}"
                                data-gallery-nav="{{ $index }}"
                                aria-label="{{ __('ui.product.slide_aria', ['index' => $index + 1]) }}"
                                aria-current="{{ $loop->first ? 'true' : 'false' }}"
                            >
                                <img
                                    src="{{ $image['thumb'] }}"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                >
                            </button>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="flex min-h-[420px] items-center justify-center border border-slate-200 bg-slate-100 text-sm font-semibold uppercase tracking-wide text-slate-500">
                    {{ __('ui.product.no_image') }}
                </div>
            @endif
        </div>

        <aside class="product-detail-card">
            <div>
                <div>
                    @if ((int) ($reviewSummary['count'] ?? 0) > 0)
                        <div class="mb-2">
                            @include('front.partials.product-review-summary', [
                                'count' => (int) ($reviewSummary['count'] ?? 0),
                                'average' => (float) ($reviewSummary['avg'] ?? 0),
                                'href' => '#product-comments',
                            ])
                        </div>
                    @endif
                    <h1 class="text-2xl font-extrabold leading-tight text-slate-900">{{ $translation?->name ?? $product->code }}</h1>
                    <p class="mt-1 text-xs text-slate-500">{{ __('ui.product.sku') }}: <span data-product-sku-value>{{ $product->sku ?: $product->code ?: 'n/a' }}</span></p>
                    @if ($manufacturerTranslation && $manufacturerEnabled)
                        <p class="mt-1 text-xs text-slate-600">
                            <a href="{{ route('manufacturers.show', ['slug' => $manufacturerTranslation->slug]) }}" class="font-semibold text-slate-700 hover:text-slate-900">{{ $manufacturerTranslation->name }}</a>
                        </p>
                    @endif
                </div>
                <div class="mt-3">
                    <p class="{{ $isB2BPrice ? '' : 'hidden' }} mb-1 text-xs font-semibold text-cyan-800" data-product-price-b2b>
                        {{ __('ui.product.b2b_contract_price') }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xl font-semibold text-slate-900" data-product-price-current>{{ $currentPrice }}</p>
                        <x-front.energy-label-arrow :declaration="$primaryEnergyDocuments" :compact="false" />
                        <span class="{{ $discountPercent > 0 ? 'inline-flex' : 'hidden' }} h-7 items-center border border-rose-600 bg-rose-600 px-2 text-xs font-bold text-white" data-product-price-discount>
                            @if ($discountPercent > 0)
                                -{{ $discountPercent }}%
                            @endif
                        </span>
                    </div>
                    @if ($vatRate !== null)
                        <p class="product-detail-tax-note">
                            {{ __('ui.product.vat_included', ['rate' => rtrim(rtrim(number_format($vatRate, 2, $locale === 'hr' ? ',' : '.', ''), '0'), $locale === 'hr' ? ',' : '.')]) }}
                        </p>
                    @endif
                    <p class="product-detail-net-price">
                        {{ __('ui.product.price_excluding_vat') }}: <span data-product-price-net>{{ $productPriceData['current_net'] }}</span>
                    </p>
                    <p class="{{ $oldPrice ? '' : 'hidden' }} mt-1 text-sm text-slate-500 line-through" data-product-price-old>{{ $oldPrice ?: '' }}</p>
                    <p class="{{ $lowest30DaysPrice ? '' : 'hidden' }} mt-1 text-xs text-slate-600" data-product-price-lowest>{{ $lowest30DaysPrice ? __('ui.product.lowest_price_30_days', ['price' => $lowest30DaysPrice]) : '' }}</p>
                    <x-front.energy-information-sheet-link :declaration="$primaryEnergyDocuments" :compact="false" class="mt-2" />

                    @if ($energyDocumentDeclarations->count() > 1 || ($energyDocumentDeclarations->isNotEmpty() && ! $primaryEnergyDeclaration))
                        <details class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2" data-product-energy-declarations>
                            <summary class="cursor-pointer text-xs font-semibold text-slate-800">{{ __('ui.product.energy_documents') }}</summary>
                            <div class="mt-3 space-y-3">
                                @foreach ($energyDocumentDeclarations as $energyDeclaration)
                                    <div class="border-t border-slate-200 pt-3 first:border-0 first:pt-0">
                                        <p class="text-xs font-semibold text-slate-800">{{ $energyDeclaration['label'] }}</p>
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <x-front.energy-label-arrow :declaration="$energyDeclaration" />
                                            @if (! empty($energyDeclaration['energy_label_url']) && empty($energyDeclaration['is_complete']))
                                                <a href="{{ $energyDeclaration['energy_label_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-blue-700 underline underline-offset-2">{{ __('ui.product.energy_label') }}</a>
                                            @endif
                                            @if (! empty($energyDeclaration['product_information_sheet_url']))
                                                <a href="{{ $energyDeclaration['product_information_sheet_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-blue-700 underline underline-offset-2">{{ __('ui.product.product_information_sheet') }}</a>
                                            @endif
                                        </div>
                                        @if (! empty($energyDeclaration['eprel_registration_number']))
                                            <p class="mt-1 text-[11px] text-slate-500">{{ __('ui.product.eprel_registration_number') }}: {{ $energyDeclaration['eprel_registration_number'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            </div>

            @if ($colorVariants->isNotEmpty())
                <div class="mt-5 border-y border-slate-200 py-4" data-product-color-variants>
                    <p class="text-sm font-extrabold text-slate-900">{{ __('ui.product.color_variants') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($colorVariants as $variant)
                            <a
                                href="{{ $variant['url'] }}"
                                class="product-color-variant-link"
                                title="{{ $variant['label'] }}"
                                aria-label="{{ $variant['label'] }}"
                                @if ($variant['is_current']) aria-current="true" @endif
                                data-color-variant-link
                                data-color-variant-label="{{ $variant['label'] }}"
                            >
                                <span
                                    class="product-color-variant-swatch"
                                    data-color-variant-swatch
                                    data-swatch-image="{{ $variant['swatch_image_url'] ?? '' }}"
                                    data-swatch-style="{{ $variant['swatch_style'] ?? '' }}"
                                    aria-hidden="true"
                                ></span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <form
                id="product-detail-cart-form-{{ $product->id }}"
                method="POST"
                action="{{ route('cart.items.store') }}"
                class="mt-6 space-y-4"
                data-product-detail-form
                data-ga4-add-to-cart-form
                data-ga4-item-id="{{ (string) ($product->sku ?: $product->id) }}"
                data-ga4-item-name="{{ $translation?->name ?? $product->code }}"
                data-ga4-item-price="{{ $productPriceData['current_value'] }}"
                data-ga4-item-brand="{{ (string) ($manufacturerTranslation?->name ?? '') }}"
                data-ga4-item-category="{{ (string) ($firstCategoryTranslation?->name ?? '') }}"
                data-ga4-currency="EUR"
                data-product-base-sku="{{ (string) ($product->sku ?: $product->code ?: '') }}"
                data-product-fallback-id="{{ (int) $product->id }}"
                data-product-name="{{ $translation?->name ?? $product->code }}"
                data-product-image="{{ (string) (($gallery->first()['full'] ?? '') ?: '') }}"
                data-cart-url="{{ route('cart.index') }}"
                data-modal-title="{{ __('ui.cart.modal.title') }}"
                data-modal-close="{{ __('Close') }}"
                data-modal-continue="{{ __('ui.cart.modal.continue') }}"
                data-modal-go-cart="{{ __('ui.cart.modal.go_to_cart') }}"
                data-modal-option="{{ __('ui.cart.modal.option') }}"
                data-modal-quantity="{{ __('ui.cart.modal.quantity') }}"
                data-option-error-required="{{ __('ui.cart.errors.select_size') }}"
                data-option-error-unavailable="{{ __('ui.cart.status.unavailable') }}"
                data-product-default-price-current="{{ $productPriceData['current'] }}"
                data-product-default-price-current-value="{{ $productPriceData['current_value'] }}"
                data-product-default-price-net="{{ $productPriceData['current_net'] }}"
                data-product-default-price-old="{{ $productPriceData['old'] }}"
                data-product-default-price-discount="{{ $productPriceData['discount_percent'] }}"
                data-product-default-price-lowest="{{ $productPriceData['lowest_30_days'] }}"
                data-product-default-price-b2b="{{ $productPriceData['is_b2b'] ? '1' : '0' }}"
            >
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">

                @if ($optionRows->isNotEmpty())
                    <div>
                        <div class="mb-4 flex flex-col items-start gap-2 sm:mb-3 sm:flex-row sm:items-center sm:justify-between">
                            <label class="text-xs font-semibold uppercase tracking-wide text-slate-900">{{ $hasLinkedOptions ? __('ui.product.select_options') : __('ui.product.select_size') }} <span class="text-rose-600">*</span></label>
                            <div class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 sm:w-auto sm:justify-end">
                                @if ($fitFinderEnabled && $optionRows->count() > 1)
                                    @if ($fitFinderSavedSize !== '')
                                        <button type="button" class="inline-flex items-baseline gap-1 text-xs font-normal tracking-normal text-slate-600 underline underline-offset-2 hover:text-slate-900 sm:text-sm" data-fit-finder-open>
                                            <span>{{ __('ui.product.fit_finder.saved_size_prefix') }}</span>
                                            <span class="font-semibold text-slate-800">{{ $fitFinderSavedSize }}</span>
                                        </button>
                                    @else
                                        <button type="button" class="text-xs font-semibold uppercase tracking-wide text-slate-700 underline underline-offset-2 hover:text-slate-900" data-fit-finder-open>
                                            {{ __('ui.product.fit_finder.trigger') }}
                                        </button>
                                    @endif
                                @endif
                                @if (!empty($sizeGuide))
                                    <button type="button" class="text-xs font-semibold uppercase tracking-wide text-slate-700 underline underline-offset-2 hover:text-slate-900" data-size-guide-open>
                                        {{ __('ui.product.size_guide') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                        @if ($hasLinkedOptions)
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $primaryOptionLabel }}</label>
                                    <select class="h-10 w-full border border-slate-300 bg-white px-3 text-sm text-slate-700" data-linked-option-primary>
                                        <option value="">{{ __('ui.shop.filters.select_option') }}</option>
                                        @foreach ($linkedPrimaryValues as $primaryValue)
                                            <option value="{{ $primaryValue['id'] }}">{{ $primaryValue['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $secondaryOptionLabel }}</label>
                                    <select name="product_option_value_id" class="h-10 w-full border border-slate-300 bg-white px-3 text-sm text-slate-700" data-linked-option-secondary disabled>
                                        <option value="">{{ __('ui.shop.filters.select_option') }}</option>
                                        @foreach ($optionRows as $row)
                                            @php
                                                $valueTranslation = $row->optionValue?->translations?->firstWhere('locale', $locale)
                                                    ?? $row->optionValue?->translations?->firstWhere('locale', $fallbackLocale)
                                                    ?? $row->optionValue?->translations?->first();
                                                $secondaryLabel = trim((string) ($valueTranslation?->name ?? $row->optionValue?->code ?? ''));
                                                $rowPriceData = $optionPriceData($row);
                                            @endphp
                                            <option
                                                value="{{ $row->id }}"
                                                data-parent-id="{{ (int) ($row->parent_option_value_id ?? 0) }}"
                                                data-option-sku="{{ (string) ($row->sku ?: '') }}"
                                                data-option-price-current="{{ $rowPriceData['current'] }}"
                                                data-option-price-current-value="{{ $rowPriceData['current_value'] }}"
                                                data-option-price-net="{{ $rowPriceData['current_net'] }}"
                                                data-option-price-old="{{ $rowPriceData['old'] }}"
                                                data-option-price-discount="{{ $rowPriceData['discount_percent'] }}"
                                                data-option-price-lowest="{{ $rowPriceData['lowest_30_days'] }}"
                                                data-option-price-b2b="{{ $rowPriceData['is_b2b'] ? '1' : '0' }}"
                                            >{{ $secondaryLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @else
                            <div class="flex flex-wrap gap-2">
                                @foreach ($optionRows as $row)
                                    @php
                                        $valueTranslation = $row->optionValue?->translations?->firstWhere('locale', $locale)
                                            ?? $row->optionValue?->translations?->firstWhere('locale', $fallbackLocale)
                                            ?? $row->optionValue?->translations?->first();
                                        $label = trim((string) ($valueTranslation?->name ?? $row->optionValue?->code ?? ''));
                                        $inputId = 'product-detail-pov-'.$product->id.'-'.$row->id;
                                        $rowPriceData = $optionPriceData($row);
                                    @endphp
                                    <span class="inline-flex">
                                        <input
                                            id="{{ $inputId }}"
                                            type="radio"
                                            name="product_option_value_id"
                                            value="{{ $row->id }}"
                                            class="sr-only product-size-radio"
                                            data-size-label="{{ $label }}"
                                            data-option-sku="{{ (string) ($row->sku ?: '') }}"
                                            data-option-price-current="{{ $rowPriceData['current'] }}"
                                            data-option-price-current-value="{{ $rowPriceData['current_value'] }}"
                                            data-option-price-net="{{ $rowPriceData['current_net'] }}"
                                            data-option-price-old="{{ $rowPriceData['old'] }}"
                                            data-option-price-discount="{{ $rowPriceData['discount_percent'] }}"
                                            data-option-price-lowest="{{ $rowPriceData['lowest_30_days'] }}"
                                            data-option-price-b2b="{{ $rowPriceData['is_b2b'] ? '1' : '0' }}"
                                        >
                                        <label for="{{ $inputId }}" class="product-size-label inline-flex h-10 min-w-10 cursor-pointer items-center justify-center border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-slate-900 hover:bg-slate-100">
                                            <span>{{ $label }}</span>
                                        </label>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        <p class="hidden mt-2 text-xs font-semibold text-rose-600" data-option-error>
                            {{ __('ui.cart.errors.select_size') }}
                        </p>
                    </div>
                @endif

                <div class="product-detail-purchase-controls">
                    @if ($isPurchasable)
                        <div class="product-detail-quantity-control" data-qty-control>
                            <button type="button" data-qty-dec aria-label="Decrease quantity">-</button>
                            <input type="text" name="quantity" value="1" inputmode="numeric" readonly aria-label="{{ __('ui.cart.modal.quantity') }}" data-qty-input>
                            <button type="button" data-qty-inc aria-label="Increase quantity">+</button>
                        </div>

                        <button type="submit" class="product-detail-action product-detail-cart-button" aria-label="{{ __('ui.product.add_to_cart') }}">
                            <x-fa-icon name="bag-shopping" class="h-5 w-5 shrink-0" />
                            <span class="text-center leading-tight sm:truncate">{{ __('ui.product.add_to_cart') }}</span>
                        </button>
                    @else
                        <div></div>
                        <button type="button" disabled class="product-detail-cart-button product-detail-cart-button-disabled" aria-label="{{ __('ui.product.unavailable') }}">
                            <span class="text-center leading-tight sm:truncate">{{ __('ui.product.unavailable') }}</span>
                        </button>
                    @endif

                    <button
                        type="submit"
                        form="wishlist-product-{{ $product->id }}"
                        class="product-card-wishlist product-detail-wishlist {{ $isWishlisted ? 'is-active' : '' }}"
                        aria-label="{{ $isWishlisted ? __('ui.wishlist.remove') : __('ui.wishlist.add') }}"
                        data-wishlist-button
                    >
                        <svg class="fa6-icon h-5 w-5" fill="currentColor" aria-hidden="true" focusable="false">
                            <use href="{{ asset('front-theme/fonts/storefront-sprites/'.($isWishlisted ? 'solid' : 'regular').'.svg') }}#heart"></use>
                        </svg>
                    </button>
                </div>
            </form>

            @if ($fitFinderEnabled && $optionRows->count() > 1)
                <div
                    class="fixed inset-0 z-[90] hidden flex items-center justify-center overflow-y-auto bg-black/55 p-4 backdrop-blur-[2px]"
                    data-fit-finder-modal
                    data-text-error-height="{{ __('ui.product.fit_finder.errors.height') }}"
                    data-text-error-weight="{{ __('ui.product.fit_finder.errors.weight') }}"
                    data-text-error-age="{{ __('ui.product.fit_finder.errors.age') }}"
                    data-text-step-template="{{ __('ui.product.fit_finder.step_of', ['current' => '__CURRENT__', 'total' => '__TOTAL__']) }}"
                    data-text-recommendation-ready="{{ __('ui.product.fit_finder.recommendation_ready') }}"
                    data-text-summary-template="{{ __('ui.product.fit_finder.summary', ['size' => '__SIZE__', 'percent' => '__PERCENT__']) }}"
                    data-text-cta-template="{{ __('ui.product.fit_finder.add_cta', ['size' => '__SIZE__']) }}"
                    data-text-trigger="{{ __('ui.product.fit_finder.trigger') }}"
                    data-text-saved-prefix="{{ __('ui.product.fit_finder.saved_size_prefix') }}"
                    data-fit-save-url="{{ route('products.fit_finder.preferences') }}"
                    data-fit-product-id="{{ (int) $product->id }}"
                    data-fit-initial-height="{{ (string) ($fitFinderSelection['height'] ?? '') }}"
                    data-fit-initial-weight="{{ (string) ($fitFinderSelection['weight'] ?? '') }}"
                    data-fit-initial-age="{{ (string) ($fitFinderSelection['age'] ?? '') }}"
                    data-fit-initial-fit="{{ (string) ($fitFinderSelection['fit'] ?? 'average') }}"
                    data-fit-initial-chest="{{ (string) ($fitFinderSelection['chest'] ?? 'average') }}"
                    data-fit-initial-belly="{{ (string) ($fitFinderSelection['belly'] ?? 'average') }}"
                    data-fit-initial-size="{{ $fitFinderSavedSize }}"
                    data-fit-initial-size-signature="{{ $fitFinderSavedSignature }}"
                    aria-hidden="true"
                >
                    <div class="mx-auto flex w-full max-w-2xl flex-col overflow-hidden border border-slate-200 bg-white shadow-2xl">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ui.product.fit_finder.title') }}</p>
                                <div class="flex items-center gap-3">
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500 opacity-0 transition-opacity" data-fit-save-indicator aria-live="polite">Spremljeno</span>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center border border-slate-300 text-sm font-bold text-slate-700 hover:bg-slate-100"
                                        data-fit-finder-help-toggle
                                        aria-expanded="false"
                                        aria-controls="fit-finder-help-panel-{{ $product->id }}"
                                        aria-label="{{ __('ui.product.fit_finder.help_toggle') }}"
                                    >
                                        <span data-fit-help-icon-closed>?</span>
                                        <span class="hidden text-xs leading-none" data-fit-help-icon-open>▴</span>
                                    </button>
                                    <button type="button" class="inline-flex h-8 w-8 items-center justify-center border border-slate-300 text-base text-slate-700 hover:bg-slate-100" data-fit-finder-close aria-label="{{ __('ui.product.size_guide_close') }}">
                                        ×
                                    </button>
                                </div>
                            </div>
                            <div id="fit-finder-help-panel-{{ $product->id }}" class="mt-3 hidden border border-slate-200 bg-white p-3 text-left" data-fit-finder-help-panel>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-700">{{ __('ui.product.fit_finder.help_title') }}</p>
                                <p class="mt-1 text-xs leading-relaxed text-slate-600">{{ __('ui.product.fit_finder.help_body') }}</p>
                            </div>

                            <div class="mt-2" data-fit-finder-timeline>
                                <div class="flex flex-col items-center" data-fit-timeline-item>
                                    <span data-fit-timeline-point></span>
                                    <span data-fit-timeline-label>Mjere</span>
                                </div>
                                <div class="flex flex-col items-center" data-fit-timeline-item>
                                    <span data-fit-timeline-point></span>
                                    <span data-fit-timeline-label>Dob</span>
                                </div>
                                <div class="flex flex-col items-center" data-fit-timeline-item>
                                    <span data-fit-timeline-point></span>
                                    <span data-fit-timeline-label>Fit</span>
                                </div>
                                <div class="flex flex-col items-center" data-fit-timeline-item>
                                    <span data-fit-timeline-point></span>
                                    <span data-fit-timeline-label>Prsa</span>
                                </div>
                                <div class="flex flex-col items-center" data-fit-timeline-item>
                                    <span data-fit-timeline-point></span>
                                    <span data-fit-timeline-label>Trbuh</span>
                                </div>
                                <div class="flex flex-col items-center" data-fit-timeline-item>
                                    <span data-fit-timeline-point></span>
                                    <span data-fit-timeline-label>Rezultat</span>
                                </div>
                            </div>
                            <div>
                                <p class="sr-only" data-fit-finder-progress>{{ __('ui.product.fit_finder.step_of', ['current' => 1, 'total' => 5]) }}</p>
                            </div>
                        </div>

                        <div class="grid gap-7 p-5 lg:grid-cols-[220px_minmax(0,1fr)] lg:items-stretch">
                            <div class="hidden lg:block lg:h-[290px]">
                                @if ($gallery->isNotEmpty())
                                    <img src="{{ (string) (($gallery->first()['display'] ?? $gallery->first()['full'] ?? '') ?: '') }}" alt="{{ $translation?->name ?? $product->code }}" class="h-full w-full border border-slate-200 bg-slate-50 object-cover object-top">
                                @endif
                            </div>

                            <div class="flex flex-col lg:h-[290px]">
                                <div class="flex-1" data-fit-steps-wrap>
                                    <section data-fit-step="0">
                                    <h3 class="text-base font-semibold uppercase tracking-[0.08em] text-slate-900">{{ __('ui.product.fit_finder.measurements_title') }}</h3>
                                    <p class="mb-4 text-xs text-slate-600">{{ __('ui.product.fit_finder.measurements_desc') }}</p>
                                    <div class="grid gap-5 sm:grid-cols-2">
                                        <label class="space-y-2">
                                            <div class="flex items-center justify-between text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                <span>{{ __('ui.product.fit_finder.height') }}</span>
                                                <span class="text-slate-800" data-fit-height-value>130</span>
                                            </div>
                                            <input type="range" min="130" max="230" step="1" class="fit-finder-range w-full appearance-none bg-transparent p-0 focus:outline-none" data-fit-height>
                                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                                <span>130</span>
                                                <span>230</span>
                                            </div>
                                        </label>
                                        <label class="space-y-2">
                                            <div class="flex items-center justify-between text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                <span>{{ __('ui.product.fit_finder.weight') }}</span>
                                                <span class="text-slate-800" data-fit-weight-value>35</span>
                                            </div>
                                            <input type="range" min="35" max="220" step="1" class="fit-finder-range w-full appearance-none bg-transparent p-0 focus:outline-none" data-fit-weight>
                                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                                <span>35</span>
                                                <span>220</span>
                                            </div>
                                        </label>
                                    </div>
                                    <p class="hidden text-xs font-semibold text-rose-600" data-fit-error></p>
                                    </section>

                                    <section data-fit-step="1" class="hidden">
                                    <h3 class="text-base font-semibold uppercase tracking-[0.08em] text-slate-900">{{ __('ui.product.fit_finder.age_title') }}</h3>
                                    <label class="mt-3 block max-w-[280px] space-y-2">
                                        <div class="flex items-center justify-between text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                            <span>{{ __('ui.product.fit_finder.age') }}</span>
                                            <span class="text-slate-800" data-fit-age-value>12</span>
                                        </div>
                                        <input type="range" min="12" max="100" step="1" class="fit-finder-range w-full appearance-none bg-transparent p-0 focus:outline-none" data-fit-age>
                                        <div class="flex items-center justify-between text-[10px] text-slate-400">
                                            <span>12</span>
                                            <span>100</span>
                                        </div>
                                    </label>
                                    <p class="mt-4 mb-2 text-xs text-slate-600">{{ __('ui.product.fit_finder.age_desc') }}</p>
                                    <p class="hidden text-xs font-semibold text-rose-600" data-fit-error></p>
                                    </section>

                                    <section data-fit-step="2" class="hidden">
                                    <h3 class="text-base font-semibold uppercase tracking-[0.08em] text-slate-900">{{ __('ui.product.fit_finder.fit_title') }}</h3>
                                    <p class="mb-4 text-xs text-slate-600">{{ __('ui.product.fit_finder.fit_desc') }}</p>
                                    <div class="grid gap-2 sm:grid-cols-3">
                                        <button type="button" class="h-10 border border-slate-300 text-xs font-semibold text-slate-700 hover:border-slate-900" data-fit-finder-option data-fit-fit="tighter">{{ __('ui.product.fit_finder.fit_tighter') }}</button>
                                        <button type="button" class="h-10 border border-slate-300 text-xs font-semibold text-slate-700 hover:border-slate-900" data-fit-finder-option data-fit-fit="average" aria-pressed="true">{{ __('ui.product.fit_finder.fit_average') }}</button>
                                        <button type="button" class="h-10 border border-slate-300 text-xs font-semibold text-slate-700 hover:border-slate-900" data-fit-finder-option data-fit-fit="looser">{{ __('ui.product.fit_finder.fit_looser') }}</button>
                                    </div>
                                    </section>

                                    <section data-fit-step="3" class="hidden">
                                    <h3 class="text-base font-semibold uppercase tracking-[0.08em] text-slate-900">{{ __('ui.product.fit_finder.chest_title') }}</h3>
                                    <p class="mb-4 text-xs text-slate-600">{{ __('ui.product.fit_finder.chest_desc') }}</p>
                                    <div class="grid gap-2 sm:grid-cols-3">
                                        <button type="button" class="h-10 border border-slate-300 text-xs font-semibold text-slate-700 hover:border-slate-900" data-fit-finder-option data-fit-chest="slimmer">{{ __('ui.product.fit_finder.chest_slimmer') }}</button>
                                        <button type="button" class="h-10 border border-slate-300 text-xs font-semibold text-slate-700 hover:border-slate-900" data-fit-finder-option data-fit-chest="average" aria-pressed="true">{{ __('ui.product.fit_finder.chest_average') }}</button>
                                        <button type="button" class="h-10 border border-slate-300 text-xs font-semibold text-slate-700 hover:border-slate-900" data-fit-finder-option data-fit-chest="broader">{{ __('ui.product.fit_finder.chest_broader') }}</button>
                                    </div>
                                    </section>

                                    <section data-fit-step="4" class="hidden">
                                    <h3 class="text-base font-semibold uppercase tracking-[0.08em] text-slate-900">{{ __('ui.product.fit_finder.belly_title') }}</h3>
                                    <p class="mb-4 text-xs text-slate-600">{{ __('ui.product.fit_finder.belly_desc') }}</p>
                                    <div class="grid gap-2 sm:grid-cols-3">
                                        <button type="button" class="h-10 border border-slate-300 text-xs font-semibold text-slate-700 hover:border-slate-900" data-fit-finder-option data-fit-belly="flatter">{{ __('ui.product.fit_finder.belly_flatter') }}</button>
                                        <button type="button" class="h-10 border border-slate-300 text-xs font-semibold text-slate-700 hover:border-slate-900" data-fit-finder-option data-fit-belly="average" aria-pressed="true">{{ __('ui.product.fit_finder.belly_average') }}</button>
                                        <button type="button" class="h-10 border border-slate-300 text-xs font-semibold text-slate-700 hover:border-slate-900" data-fit-finder-option data-fit-belly="rounder">{{ __('ui.product.fit_finder.belly_rounder') }}</button>
                                    </div>
                                    </section>

                                    <section data-fit-step="5" class="hidden">
                                    <h3 class="text-base font-semibold uppercase tracking-[0.08em] text-slate-900">{{ __('ui.product.fit_finder.result_title') }}</h3>
                                    <p class="mb-4 text-xs text-slate-600">{{ __('ui.product.fit_finder.result_desc') }}</p>
                                    <div class="space-y-2">
                                        <div class="border border-slate-300 p-3" data-fit-finder-result-row="0">
                                            <div class="flex items-center justify-between">
                                                <p class="text-base font-bold text-slate-900" data-fit-finder-result-size="0"></p>
                                                <p class="text-xs font-semibold text-emerald-700" data-fit-finder-result-percent="0"></p>
                                            </div>
                                            <div class="mt-2 h-2 bg-slate-200">
                                                <div class="h-2 bg-emerald-700" data-fit-finder-result-bar="0"></div>
                                            </div>
                                        </div>
                                        <div class="border border-slate-300 p-3" data-fit-finder-result-row="1">
                                            <div class="flex items-center justify-between">
                                                <p class="text-base font-bold text-slate-700" data-fit-finder-result-size="1"></p>
                                                <p class="text-xs font-semibold text-slate-600" data-fit-finder-result-percent="1"></p>
                                            </div>
                                            <div class="mt-2 h-2 bg-slate-200">
                                                <div class="h-2 bg-slate-400" data-fit-finder-result-bar="1"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="mt-4 text-xs text-slate-600" data-fit-finder-summary></p>
                                    </section>
                                </div>

                                <div class="mt-4 flex items-center justify-between gap-2 border-t border-slate-200 pt-5">
                                    <button type="button" class="inline-flex h-10 items-center border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-100" data-fit-prev>
                                        {{ __('ui.product.fit_finder.actions.back') }}
                                    </button>
                                    <button type="button" class="inline-flex h-10 items-center border border-slate-900 bg-slate-900 px-4 text-xs font-semibold text-white hover:bg-slate-700" data-fit-next>
                                        {{ __('ui.product.fit_finder.actions.continue') }}
                                    </button>
                                    <button type="button" class="hidden h-10 items-center border border-slate-900 bg-slate-900 px-4 text-xs font-semibold text-white hover:bg-slate-700" data-fit-apply>
                                        {{ __('ui.product.fit_finder.add_cta', ['size' => '']) }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <form
                id="wishlist-product-{{ $product->id }}"
                method="POST"
                action="{{ route('wishlist.items.toggle', ['product' => $product->id]) }}"
                class="hidden"
                data-wishlist-form
                data-wishlisted="{{ $isWishlisted ? '1' : '0' }}"
                data-label-add="{{ __('ui.wishlist.add') }}"
                data-label-remove="{{ __('ui.wishlist.remove') }}"
                data-msg-failed="{{ __('ui.wishlist.status.failed') }}"
            >
                @csrf
            </form>

            @include('front.partials.product-purchase-information', [
                'product' => $product,
                'manufacturerTranslation' => $manufacturerTranslation,
                'firstCategoryTranslation' => $firstCategoryTranslation,
                'shippingMethods' => $shippingMethods ?? collect(),
                'paymentMethods' => $paymentMethods ?? collect(),
            ])
        </aside>
    </section>

    @include('front.partials.product-detail-content', [
        'product' => $product,
        'translation' => $translation,
        'locale' => $locale,
        'fallbackLocale' => $fallbackLocale,
        'hasProductStory' => $hasProductStory,
        'comments' => $comments ?? collect(),
    ])

    @include('front.partials.product-floating-cart', [
        'product' => $product,
        'translation' => $translation,
        'gallery' => $gallery,
        'productPriceData' => $productPriceData,
        'isPurchasable' => $isPurchasable,
    ])

    @if (!empty($sizeGuide) && $optionRows->isNotEmpty())
        <div class="fixed inset-0 z-[80] hidden flex items-center justify-center overflow-y-auto bg-black/50 p-4" data-size-guide-modal aria-hidden="true">
            <div class="mx-auto max-h-[86vh] w-full max-w-5xl overflow-hidden bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-extrabold text-slate-900">{{ $sizeGuide['title'] ?: __('ui.product.size_guide') }}</h2>
                    <button type="button" class="inline-flex h-9 min-w-9 items-center justify-center border border-slate-300 bg-white px-3 text-xs font-semibold uppercase tracking-wide text-slate-700 hover:bg-slate-100" data-size-guide-close>
                        {{ __('ui.product.size_guide_close') }}
                    </button>
                </div>
                <div class="product-size-guide-scroll overflow-y-auto px-5 py-4">
                    <div class="content-richtext">{!! $sizeGuide['body_html'] !!}</div>
                </div>
            </div>
        </div>
    @endif

    @if ($related->isNotEmpty())
        <section class="product-products-widget">
            <x-storefront-section-heading class="mb-7">
                {{ __('ui.product.related') }}
            </x-storefront-section-heading>
            @if ($related->count() > $mobileDefaultCols)
                @include('front.partials.carousel-swipe-hint')
            @endif
            <div
                id="related-products-carousel-{{ $product->id }}"
                class="splide"
                data-related-products-splide
                data-desktop-cols="5"
                data-mobile-cols="{{ $mobileDefaultCols }}"
            >
                <div class="splide__track">
                    <ul class="splide__list">
                        @foreach ($related as $relatedProduct)
                            <li class="splide__slide">
                                @include('front.desktop.partials.product-card', [
                                    'product' => $relatedProduct,
                                    'locale' => $locale,
                                    'fallbackLocale' => $fallbackLocale,
                                    'flat' => true,
                                    'lined' => true,
                                ])
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>
    @endif

    @if (($recentlyViewed ?? collect())->isNotEmpty())
        <section class="product-products-widget">
            <x-storefront-section-heading class="mb-7">
                {{ __('ui.product.recently_viewed') }}
            </x-storefront-section-heading>
            @if ($recentlyViewed->count() > $mobileDefaultCols)
                @include('front.partials.carousel-swipe-hint')
            @endif
            <div
                id="recently-viewed-products-carousel-{{ $product->id }}"
                class="splide"
                data-related-products-splide
                data-desktop-cols="5"
                data-mobile-cols="{{ $mobileDefaultCols }}"
            >
                <div class="splide__track">
                    <ul class="splide__list">
                        @foreach ($recentlyViewed as $recentlyViewedProduct)
                            <li class="splide__slide">
                                @include('front.desktop.partials.product-card', [
                                    'product' => $recentlyViewedProduct,
                                    'locale' => $locale,
                                    'fallbackLocale' => $fallbackLocale,
                                    'flat' => true,
                                    'lined' => true,
                                ])
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>
    @endif

    @if ($bottomBlocks->isNotEmpty())
        <section class="mt-10">
            @include('components.content-placement', ['items' => $bottomBlocks])
        </section>
    @endif
    </div>
@endsection

@push('scripts')
    @include('front.partials.splide-assets')
    @include('front.partials.cart-modal-script')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lightgallery@2.7.2/css/lightgallery-bundle.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/lightgallery@2.7.2/lightgallery.min.js"></script>
    <script defer src="{{ asset('front-theme/scripts/product-detail.js') }}?v={{ filemtime(public_path('front-theme/scripts/product-detail.js')) }}"></script>
    <script defer src="{{ asset('front-theme/scripts/product-fit-finder.js') }}?v={{ filemtime(public_path('front-theme/scripts/product-fit-finder.js')) }}"></script>
    <script defer src="{{ asset('front-theme/scripts/product-page.js') }}?v={{ filemtime(public_path('front-theme/scripts/product-page.js')) }}"></script>
@endpush
