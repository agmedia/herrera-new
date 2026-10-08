@once('herrera-home-popular-products')
@php
    $popularLocale = (string) app()->getLocale();
    $popularFallbackLocale = (string) config('app.locale');
    $popularProducts = app(\App\Services\Front\PopularProductsService::class)->forStorefront($popularLocale, $popularFallbackLocale);
    $popularCount = $popularProducts->count();
    $popularPrevious = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $popularNext = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $popularOptions = [
        'type' => 'slide', 'rewind' => false, 'perPage' => min(6, max(1, $popularCount)),
        'perMove' => 1, 'gap' => '0rem', 'drag' => $popularCount > 1, 'snap' => true,
        'pagination' => false, 'arrows' => $popularCount > 1, 'updateOnMove' => true, 'speed' => 420,
        'i18n' => ['prev' => $popularPrevious, 'next' => $popularNext],
        'breakpoints' => [
            1279 => ['perPage' => min(4, max(1, $popularCount))],
            767 => ['perPage' => min(2, max(1, $popularCount))],
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
    <section class="herrera-home-section herrera-b2b-products herrera-popular-products" aria-labelledby="herrera-popular-products-title" data-herrera-popular-products>
        <div class="splide herrera-home-product-carousel" data-herrera-popular-products-splide data-herrera-home-carousel data-continuous-card-carousel data-splide='@json($popularOptions)' aria-labelledby="herrera-popular-products-title" style="--herrera-product-columns: {{ $popularOptions['perPage'] }}; --herrera-product-tablet-columns: {{ $popularOptions['breakpoints'][1279]['perPage'] }}; --herrera-product-mobile-columns: {{ $popularOptions['breakpoints'][767]['perPage'] }};">
            <div class="herrera-home-heading">
                <div>
                    <p class="herrera-home-section-eyebrow">{{ __('herrera.popular_products_eyebrow') }}</p>
                    <h2 id="herrera-popular-products-title">{{ __('herrera.popular_products') }}</h2>
                </div>
                <div class="herrera-home-product-actions">
                    <a class="herrera-home-products-link" href="{{ route('categories.index') }}">{{ __('herrera.all_products') }} <x-fa-icon name="arrow-right" /></a>
                    @if ($popularCount > 1)
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
@endonce
