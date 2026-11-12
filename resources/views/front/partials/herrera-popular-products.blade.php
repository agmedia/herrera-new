@php
    $popularWidgetPayload = \App\Support\HerreraHomeWidgets::payload($herreraWidgetBlock ?? null, $herreraWidgetTranslation ?? null);
    $popularHeadingId = 'herrera-popular-products-title'.(isset($herreraWidgetBlock) ? '-'.$herreraWidgetBlock->id : '');
    $popularLocale = (string) app()->getLocale();
    $popularFallbackLocale = (string) config('app.locale');
    $popularDays = max(1, min(365, (int) ($popularWidgetPayload['days'] ?? 30)));
    $popularTitle = $herreraWidgetTranslation?->title ?? '';
    if ($popularTitle === '' || $popularTitle === __('herrera.popular_products', locale: $herreraWidgetTranslation?->locale ?? $popularLocale)) {
        $popularTitle = __('herrera.popular_products_period', ['days' => $popularDays]);
    }
    $popularSubtitle = $herreraWidgetTranslation?->subtitle ?? '';
    $popularEyebrow = $popularWidgetPayload['eyebrow'] ?? __('herrera.popular_products_eyebrow');
    $popularCtaLabel = $herreraWidgetTranslation?->cta_label ?? __('herrera.all_products');
    $popularCtaUrl = \App\Support\HerreraHomeWidgets::safeUrl($herreraWidgetTranslation?->cta_url ?? '', route('categories.index'));
@endphp
@once('herrera-home-popular-products-'.$popularHeadingId)
@php
    $popularProducts = app(\App\Services\Front\PopularProductsService::class)->forStorefront(
        $popularLocale, $popularFallbackLocale, (int) ($popularWidgetPayload['items_limit'] ?? 8), $popularDays,
    );
    $popularCount = $popularProducts->count();
    $popularPrevious = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $popularNext = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $popularOptions = [
        'type' => $popularCount > 1 ? 'loop' : 'slide', 'rewind' => false, 'perPage' => 6, 'destroy' => $popularCount <= 6,
        'perMove' => 1, 'gap' => '0rem', 'drag' => $popularCount > 1, 'snap' => true,
        'pagination' => false, 'arrows' => $popularCount > 6, 'updateOnMove' => true, 'speed' => 420,
        'i18n' => ['prev' => $popularPrevious, 'next' => $popularNext, 'first' => $popularNext, 'last' => $popularPrevious],
        'breakpoints' => [
            1279 => ['perPage' => 4, 'destroy' => $popularCount <= 4, 'arrows' => $popularCount > 4],
            767 => ['perPage' => 2, 'destroy' => $popularCount <= 2, 'arrows' => $popularCount > 2],
        ],
    ];
@endphp

@if ($popularProducts->isNotEmpty())
    @once('herrera-home-b2b-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-b2b.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-b2b.css')) }}">
        @endpush
    @endonce
    @include('front.partials.splide-assets')
    <section class="herrera-home-section herrera-b2b-products herrera-popular-products" aria-labelledby="{{ $popularHeadingId }}" data-herrera-popular-products>
        <div class="splide herrera-home-product-carousel" data-herrera-popular-products-splide data-herrera-home-carousel data-continuous-card-carousel data-splide='@json($popularOptions)' aria-labelledby="{{ $popularHeadingId }}" style="--herrera-product-columns: {{ $popularOptions['perPage'] }}; --herrera-product-tablet-columns: {{ $popularOptions['breakpoints'][1279]['perPage'] }}; --herrera-product-mobile-columns: {{ $popularOptions['breakpoints'][767]['perPage'] }};">
            <div class="herrera-home-heading">
                <div>
                    @if ($popularEyebrow !== '')<p class="herrera-home-section-eyebrow">{{ $popularEyebrow }}</p>@endif
                    <h2 id="{{ $popularHeadingId }}">{{ $popularTitle }}</h2>
                    @if ($popularSubtitle !== '')<p class="mt-2 text-sm">{{ $popularSubtitle }}</p>@endif
                </div>
                <div class="herrera-home-product-actions">
                    <a class="herrera-home-products-link" href="{{ $popularCtaUrl }}">{{ $popularCtaLabel }} <x-fa-icon name="arrow-right" /></a>
                    @if ($popularCount > 2)
                        <div class="splide__arrows">
                            <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $popularPrevious }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                            <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $popularNext }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                        </div>
                    @endif
                </div>
            </div>
            <div class="splide__track">
                <div class="splide__list">
                    @foreach ($popularProducts as $popularProduct)
                        <div class="splide__slide">
                            <x-front.desktop.product-card :product="$popularProduct" :locale="$popularLocale" :fallback-locale="$popularFallbackLocale" :lined="true" :flat="true" />
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
@unless (isset($herreraWidgetBlock))
    @include('front.partials.herrera-home-registration')
@endunless
@endonce
