@once('herrera-home-b2b-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-b2b.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-b2b.css')) }}">
    @endpush
@endonce

@php
    $herreraHomeSection = $herreraHomeSection ?? 'all';
    $herreraLocale = (string) app()->getLocale();
    $herreraFallbackLocale = (string) config('app.locale');
@endphp

@if (in_array($herreraHomeSection, ['all', 'hero'], true))
    @php
        $herreraAccountUrl = auth()->check() ? route('account.dashboard') : route('front.auth.login');
        $herreraCanOrder = auth()->check() && app(\App\Services\Pricing\B2BAccessService::class)->canViewPrices(auth()->user());
        $herreraQuickOrderUrl = auth()->check() && ! $herreraCanOrder ? route('account.dashboard') : route('account.b2b.quick-order');
        $herreraHeadline = __('herrera.headline');
        $herreraHeadlineParts = explode('. ', $herreraHeadline, 2);
    @endphp
    <section class="herrera-hero herrera-b2b-hero" aria-labelledby="herrera-home-title" data-herrera-b2b-hero>
        <div class="herrera-hero-copy">
            <p class="herrera-eyebrow">{{ __('herrera.eyebrow') }}</p>
            <h1 id="herrera-home-title" aria-label="{{ $herreraHeadline }}">@if (count($herreraHeadlineParts) === 2)<span class="herrera-hero-headline-line">{{ $herreraHeadlineParts[0] }}. </span><span class="herrera-hero-headline-line">{{ $herreraHeadlineParts[1] }}</span>@else{{ $herreraHeadline }}@endif</h1>
            <p class="herrera-hero-description">{{ __('herrera.description') }}</p>
            <div class="herrera-hero-actions">
                <a class="herrera-hero-primary-action" href="{{ route('categories.index') }}">{{ __('herrera.catalog') }} <x-fa-icon name="arrow-right" /></a>
                @auth
                    <a class="herrera-hero-secondary-action" href="{{ route('account.dashboard') }}"><x-fa-icon name="user" /> {{ __('herrera.account') }}</a>
                @else
                    <a class="herrera-hero-secondary-action" href="{{ route('front.auth.b2b-register') }}"><x-fa-icon name="user-plus" /> {{ __('herrera.register') }}</a>
                @endauth
            </div>
        </div>
        <aside class="herrera-hero-panel" aria-labelledby="herrera-home-tools-title">
            <h2 id="herrera-home-tools-title">{{ __('herrera.panel_title') }}</h2>
            <nav class="herrera-b2b-tools" aria-label="{{ __('herrera.panel_navigation') }}">
                <a href="{{ $herreraAccountUrl }}"><x-fa-icon name="user-check" /><span>{{ __('herrera.benefit_prices') }}</span><x-fa-icon name="arrow-right" /></a>
                <a href="{{ $herreraQuickOrderUrl }}"><x-fa-icon name="list-check" /><span>{{ __('herrera.benefit_order') }}</span><x-fa-icon name="arrow-right" /></a>
                <a href="{{ route('account.orders') }}"><x-fa-icon name="rotate-right" /><span>{{ __('herrera.benefit_history') }}</span><x-fa-icon name="arrow-right" /></a>
            </nav>
            <p class="herrera-b2b-tools-note">{{ __('herrera.panel_description') }}</p>
        </aside>
    </section>
@endif

@if (in_array($herreraHomeSection, ['all', 'categories'], true))
    @include('front.partials.herrera-home-categories')
@endif

@if (in_array($herreraHomeSection, ['all', 'products'], true))
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
        $herreraSelectedProductIds = collect($herreraCuratedCodes)->map(fn ($code) => $herreraProductIds->get($code))->filter()->values();

        // Keep the selection varied if a curated product has been removed or deactivated.
        if ($herreraSelectedProductIds->count() < 8) {
            $herreraSelectedManufacturerIds = $herreraProductQuery()->whereIn('id', $herreraSelectedProductIds)->whereNotNull('manufacturer_id')->pluck('manufacturer_id');
            $herreraRepresentativeIds = $herreraProductQuery()
                ->whereNotIn('id', $herreraSelectedProductIds)
                ->where(fn ($query) => $query->whereNull('manufacturer_id')->orWhereNotIn('manufacturer_id', $herreraSelectedManufacturerIds))
                ->selectRaw('MIN(id) as id')
                ->groupBy('manufacturer_id')
                ->orderBy('id')
                ->limit(8 - $herreraSelectedProductIds->count())
                ->pluck('id');
            $herreraSelectedProductIds = $herreraSelectedProductIds->concat($herreraRepresentativeIds)->values();
        }

        if ($herreraSelectedProductIds->count() < 8) {
            $herreraSelectedProductIds = $herreraSelectedProductIds->concat(
                $herreraProductQuery()->whereNotIn('id', $herreraSelectedProductIds)->orderBy('id')->limit(8 - $herreraSelectedProductIds->count())->pluck('id')
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
        $herreraProductCount = $herreraProducts->count();
        $herreraProductPreviousLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
        $herreraProductNextLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
        $herreraProductCarouselOptions = [
            'type' => $herreraProductCount > 1 ? 'loop' : 'slide',
            'rewind' => false,
            'perPage' => min(6, max(1, $herreraProductCount)),
            'perMove' => 1,
            'gap' => '0rem',
            'drag' => $herreraProductCount > 1,
            'snap' => true,
            'pagination' => false,
            'arrows' => $herreraProductCount > 1,
            'updateOnMove' => true,
            'speed' => 420,
            'i18n' => ['prev' => $herreraProductPreviousLabel, 'next' => $herreraProductNextLabel, 'first' => $herreraProductNextLabel, 'last' => $herreraProductPreviousLabel],
            'breakpoints' => [
                1279 => ['perPage' => min(4, max(1, $herreraProductCount))],
                767 => ['perPage' => min(2, max(1, $herreraProductCount))],
            ],
        ];
    @endphp

    @if ($herreraProducts->isNotEmpty())
        <section class="herrera-home-section herrera-b2b-products" aria-labelledby="herrera-home-products-title" data-herrera-home-products>
            @include('front.partials.splide-assets')
            <div class="splide herrera-home-product-carousel" data-herrera-home-products-splide data-herrera-home-carousel data-continuous-card-carousel data-splide='@json($herreraProductCarouselOptions)' aria-labelledby="herrera-home-products-title" style="--herrera-product-columns: {{ $herreraProductCarouselOptions['perPage'] }}; --herrera-product-tablet-columns: {{ $herreraProductCarouselOptions['breakpoints'][1279]['perPage'] }}; --herrera-product-mobile-columns: {{ $herreraProductCarouselOptions['breakpoints'][767]['perPage'] }};">
                <div class="herrera-home-heading">
                    <div>
                        <p class="herrera-home-section-eyebrow">{{ __('herrera.products_eyebrow') }}</p>
                        <h2 id="herrera-home-products-title">{{ __('herrera.products') }}</h2>
                    </div>
                    <div class="herrera-home-product-actions">
                        <a class="herrera-home-products-link" href="{{ route('categories.index') }}">{{ __('herrera.all_products') }} <x-fa-icon name="arrow-right" /></a>
                        @if ($herreraProductCount > 1)
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
