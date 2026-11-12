@once('herrera-home-b2b-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-b2b.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-b2b.css')) }}">
    @endpush
@endonce

@php
    $herreraHomeSection = $herreraHomeSection ?? 'all';
    $herreraLocale = (string) app()->getLocale();
    $herreraFallbackLocale = (string) config('app.locale');
    $herreraWidgetPayload = \App\Support\HerreraHomeWidgets::payload($herreraWidgetBlock ?? null, $herreraWidgetTranslation ?? null);
    $herreraWidgetSuffix = isset($herreraWidgetBlock) ? '-'.$herreraWidgetBlock->id : '';
@endphp

@if (in_array($herreraHomeSection, ['all', 'hero'], true))
    @php
        $herreraSafeUrl = \App\Support\HerreraHomeWidgets::safeUrl(...);
        $herreraWidgetFields = $herreraWidgetPayload['widget_fields'] ?? [];
        $herreraAccountAction = $herreraSafeUrl($herreraWidgetFields['account_url'] ?? '', route('account.dashboard'));
        $herreraAccountUrl = auth()->check() ? $herreraAccountAction : route('front.auth.login');
        $herreraCanOrder = auth()->check() && app(\App\Services\Pricing\B2BAccessService::class)->canViewPrices(auth()->user());
        $herreraQuickOrderUrl = auth()->check() && ! $herreraCanOrder ? route('account.dashboard') : route('account.b2b.quick-order');
        $herreraHeadline = $herreraWidgetTranslation?->title ?? __('herrera.headline');
        $herreraHeadlineParts = explode('. ', $herreraHeadline, 2);
        $herreraHeadlineId = 'herrera-home-title'.$herreraWidgetSuffix;
        $herreraToolsId = 'herrera-home-tools-title'.$herreraWidgetSuffix;
        $herreraHeroEyebrow = $herreraWidgetPayload['eyebrow'] ?? __('herrera.eyebrow');
        $herreraHeroDescription = $herreraWidgetTranslation?->subtitle ?? __('herrera.description');
        $herreraHeroCtaLabel = $herreraWidgetTranslation?->cta_label ?? __('herrera.catalog');
        $herreraHeroCtaUrl = $herreraSafeUrl($herreraWidgetTranslation?->cta_url ?? '', route('categories.index'));
        $herreraRegisterUrl = $herreraSafeUrl($herreraWidgetFields['register_url'] ?? '', route('front.auth.b2b-register'));
    @endphp
    <section class="herrera-hero herrera-b2b-hero" aria-labelledby="{{ $herreraHeadlineId }}" data-herrera-b2b-hero>
        <div class="herrera-hero-copy">
            @if ($herreraHeroEyebrow !== '')<p class="herrera-eyebrow">{{ $herreraHeroEyebrow }}</p>@endif
            <h1 id="{{ $herreraHeadlineId }}" aria-label="{{ $herreraHeadline }}">@if (count($herreraHeadlineParts) === 2)<span class="herrera-hero-headline-line">{{ $herreraHeadlineParts[0] }}. </span><span class="herrera-hero-headline-line">{{ $herreraHeadlineParts[1] }}</span>@else{{ $herreraHeadline }}@endif</h1>
            @if ($herreraHeroDescription !== '')<p class="herrera-hero-description">{{ $herreraHeroDescription }}</p>@endif
            <div class="herrera-hero-actions">
                <a class="herrera-hero-primary-action" href="{{ $herreraHeroCtaUrl }}">{{ $herreraHeroCtaLabel }} <x-fa-icon name="arrow-right" /></a>
                @auth
                    <a class="herrera-hero-secondary-action" href="{{ $herreraAccountAction }}"><x-fa-icon name="user" /> {{ $herreraWidgetFields['account_label'] ?? __('herrera.account') }}</a>
                @else
                    <a class="herrera-hero-secondary-action" href="{{ $herreraRegisterUrl }}"><x-fa-icon name="user-plus" /> {{ $herreraWidgetFields['register_label'] ?? __('herrera.register') }}</a>
                @endauth
            </div>
        </div>
        <aside class="herrera-hero-panel" aria-labelledby="{{ $herreraToolsId }}">
            <h2 id="{{ $herreraToolsId }}">{{ $herreraWidgetFields['panel_title'] ?? __('herrera.panel_title') }}</h2>
            <nav class="herrera-b2b-tools" aria-label="{{ __('herrera.panel_navigation') }}">
                <a href="{{ $herreraAccountUrl }}"><x-fa-icon name="user-check" /><span>{{ $herreraWidgetFields['prices_label'] ?? __('herrera.benefit_prices') }}</span><x-fa-icon name="arrow-right" /></a>
                <a href="{{ $herreraQuickOrderUrl }}"><x-fa-icon name="list-check" /><span>{{ $herreraWidgetFields['quick_order_label'] ?? __('herrera.benefit_order') }}</span><x-fa-icon name="arrow-right" /></a>
                <a href="{{ route('account.orders') }}"><x-fa-icon name="rotate-right" /><span>{{ $herreraWidgetFields['orders_label'] ?? __('herrera.benefit_history') }}</span><x-fa-icon name="arrow-right" /></a>
            </nav>
            <p class="herrera-b2b-tools-note">{{ $herreraWidgetFields['panel_description'] ?? __('herrera.panel_description') }}</p>
        </aside>
    </section>
@endif

@if (in_array($herreraHomeSection, ['all', 'categories'], true))
    @include('front.partials.herrera-home-categories')
@endif

@if (in_array($herreraHomeSection, ['all', 'products'], true))
    @php
        $herreraItemsLimit = max(1, min(50, (int) ($herreraWidgetPayload['items_limit'] ?? 8)));
        $herreraProductTitle = $herreraWidgetTranslation?->title ?? __('herrera.products');
        $herreraProductSubtitle = $herreraWidgetTranslation?->subtitle ?? '';
        $herreraProductEyebrow = $herreraWidgetPayload['eyebrow'] ?? __('herrera.products_eyebrow');
        $herreraProductCtaLabel = $herreraWidgetTranslation?->cta_label ?? __('herrera.all_products');
        $herreraProductCtaUrl = \App\Support\HerreraHomeWidgets::safeUrl($herreraWidgetTranslation?->cta_url ?? '', route('categories.index'));
        $herreraProductHeadingId = 'herrera-home-products-title'.$herreraWidgetSuffix;
        $herreraProducts = $herreraManualProducts ?? null;
    @endphp
    @if ($herreraProducts === null)
        @php
            // A representative selection across eight product families, using stable imported codes.
            $herreraCuratedCodes = [
                'herrera-oc-product-5165',  // Braytron lighting.
                'herrera-oc-product-6851',  // Wago electrical installation.
                'herrera-oc-product-32121', // Verkatto tools.
                'herrera-oc-product-6357',  // EMO cable reels.
                'herrera-oc-product-5791',  // Pawbol switches.
                'herrera-oc-product-6550',  // Camelion batteries.
                'herrera-oc-product-22251', // Portwest protective equipment.
                'herrera-oc-product-28143', // Midea small appliances.
            ];
            $herreraProductQuery = fn () => \App\Models\Catalog\Product\Product::query()->where('is_active', true);
            $herreraProductIds = $herreraProductQuery()->whereIn('code', $herreraCuratedCodes)->pluck('id', 'code');
            $herreraSelectedProductIds = collect($herreraCuratedCodes)->map(fn ($code) => $herreraProductIds->get($code))->filter()->take($herreraItemsLimit)->values();

            // Keep the selection varied if a curated product has been removed or deactivated.
            if ($herreraSelectedProductIds->count() < $herreraItemsLimit) {
                $herreraSelectedManufacturerIds = $herreraProductQuery()->whereIn('id', $herreraSelectedProductIds)->whereNotNull('manufacturer_id')->pluck('manufacturer_id');
                $herreraRepresentativeIds = $herreraProductQuery()
                    ->whereNotIn('id', $herreraSelectedProductIds)
                    ->where(fn ($query) => $query->whereNull('manufacturer_id')->orWhereNotIn('manufacturer_id', $herreraSelectedManufacturerIds))
                    ->selectRaw('MIN(id) as id')
                    ->groupBy('manufacturer_id')
                    ->orderBy('id')
                    ->limit($herreraItemsLimit - $herreraSelectedProductIds->count())
                    ->pluck('id');
                $herreraSelectedProductIds = $herreraSelectedProductIds->concat($herreraRepresentativeIds)->values();
            }

            if ($herreraSelectedProductIds->count() < $herreraItemsLimit) {
                $herreraSelectedProductIds = $herreraSelectedProductIds->concat(
                    $herreraProductQuery()->whereNotIn('id', $herreraSelectedProductIds)->orderBy('id')->limit($herreraItemsLimit - $herreraSelectedProductIds->count())->pluck('id')
                )->values();
            }

            $herreraProductsById = $herreraProductQuery()
                ->whereIn('id', $herreraSelectedProductIds)
                ->withStorefrontEnergyData()
                ->withApprovedCommentSummary([$herreraLocale, $herreraFallbackLocale])
                ->with([
                    'translations', 'media', 'taxRate', 'manufacturer.translations', 'categories.translations',
                    'attributes' => \App\Support\ProductMaterialLabel::eagerLoadAttributes($herreraLocale, $herreraFallbackLocale),
                    'optionValues' => fn ($q) => $q
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->with([
                            'optionValue.option:id,payload',
                            'optionValue.translations' => fn ($q) => $q->whereIn('locale', [$herreraLocale, $herreraFallbackLocale]),
                            'parentOptionValue.option:id,payload',
                            'parentOptionValue.translations' => fn ($q) => $q->whereIn('locale', [$herreraLocale, $herreraFallbackLocale]),
                        ]),
                ])
                ->get()->keyBy('id');
            $herreraProducts = $herreraSelectedProductIds->map(fn ($id) => $herreraProductsById->get($id))->filter();
        @endphp
    @endif
    @php
        $herreraProducts = $herreraProducts->take($herreraItemsLimit);
        $herreraProductCount = $herreraProducts->count();
        $herreraProductPreviousLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
        $herreraProductNextLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
        $herreraProductCarouselOptions = [
            'type' => $herreraProductCount > 1 ? 'loop' : 'slide',
            'rewind' => false,
            'perPage' => 6,
            'destroy' => $herreraProductCount <= 6,
            'perMove' => 1,
            'gap' => '0rem',
            'drag' => $herreraProductCount > 1,
            'snap' => true,
            'pagination' => false,
            'arrows' => $herreraProductCount > 6,
            'updateOnMove' => true,
            'speed' => 420,
            'i18n' => ['prev' => $herreraProductPreviousLabel, 'next' => $herreraProductNextLabel, 'first' => $herreraProductNextLabel, 'last' => $herreraProductPreviousLabel],
            'breakpoints' => [
                1279 => ['perPage' => 4, 'destroy' => $herreraProductCount <= 4, 'arrows' => $herreraProductCount > 4],
                767 => ['perPage' => 2, 'destroy' => $herreraProductCount <= 2, 'arrows' => $herreraProductCount > 2],
            ],
        ];
    @endphp

    @if ($herreraProducts->isNotEmpty())
        <section class="herrera-home-section herrera-b2b-products" aria-labelledby="{{ $herreraProductHeadingId }}" data-herrera-home-products>
            @include('front.partials.splide-assets')
            <div class="splide herrera-home-product-carousel" data-herrera-home-products-splide data-herrera-home-carousel data-continuous-card-carousel data-splide='@json($herreraProductCarouselOptions)' aria-labelledby="{{ $herreraProductHeadingId }}" style="--herrera-product-columns: {{ $herreraProductCarouselOptions['perPage'] }}; --herrera-product-tablet-columns: {{ $herreraProductCarouselOptions['breakpoints'][1279]['perPage'] }}; --herrera-product-mobile-columns: {{ $herreraProductCarouselOptions['breakpoints'][767]['perPage'] }};">
                <div class="herrera-home-heading">
                    <div>
                        @if ($herreraProductEyebrow !== '')<p class="herrera-home-section-eyebrow">{{ $herreraProductEyebrow }}</p>@endif
                        <h2 id="{{ $herreraProductHeadingId }}">{{ $herreraProductTitle }}</h2>
                        @if ($herreraProductSubtitle !== '')<p class="mt-2 text-sm">{{ $herreraProductSubtitle }}</p>@endif
                    </div>
                    <div class="herrera-home-product-actions">
                        <a class="herrera-home-products-link" href="{{ $herreraProductCtaUrl }}">{{ $herreraProductCtaLabel }} <x-fa-icon name="arrow-right" /></a>
                        @if ($herreraProductCount > 2)
                            <div class="splide__arrows">
                                <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $herreraProductPreviousLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                                <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $herreraProductNextLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="splide__track">
                    <div class="splide__list">
                        @foreach ($herreraProducts as $herreraProduct)
                            <div class="splide__slide">
                                <x-front.desktop.product-card :product="$herreraProduct" :locale="$herreraLocale" :fallback-locale="$herreraFallbackLocale" :lined="true" :flat="true" />
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        @include('front.partials.herrera-home-carousel-script')
    @endif
@endif

@if (in_array($herreraHomeSection, ['all', 'brands'], true))
    @include('front.partials.herrera-brand-strip')
@endif
