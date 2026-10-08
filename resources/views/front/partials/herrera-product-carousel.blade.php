@php
    $productCount = $products->count();
    $desktopPerPage = $kind === 'recently-viewed' && $productCount === 1 ? 6 : min(6, $productCount);
    $headingId = $carouselId.'-heading';
    $previousLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $nextLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $carouselOptions = [
        'type' => $productCount > 1 ? 'loop' : 'slide',
        'rewind' => false,
        'perPage' => $desktopPerPage,
        'perMove' => 1,
        'gap' => '0rem',
        'drag' => $productCount > 1,
        'snap' => true,
        'pagination' => false,
        'arrows' => $productCount > 1,
        'updateOnMove' => true,
        'speed' => 420,
        'i18n' => ['prev' => $previousLabel, 'next' => $nextLabel, 'first' => $nextLabel, 'last' => $previousLabel],
        // Data options override every intermediate breakpoint in the shared initializer.
        'breakpoints' => [
            1280 => ['perPage' => $desktopPerPage, 'arrows' => $productCount > 1],
            1279 => ['perPage' => min(4, $productCount)],
            1024 => ['perPage' => min(4, $productCount), 'arrows' => $productCount > 1],
            860 => ['perPage' => min(4, $productCount), 'arrows' => $productCount > 1],
            767 => ['perPage' => min(2, $productCount)],
            640 => ['perPage' => min(2, $productCount), 'arrows' => $productCount > 1, 'pagination' => false],
        ],
    ];
@endphp

@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-product-related.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-product-related.css')) }}">
    @endpush
@endonce

<section class="product-products-widget herrera-product-carousel-widget">
    <div
        id="{{ $carouselId }}"
        class="splide herrera-product-carousel"
        data-related-products-splide
        data-continuous-card-carousel
        data-desktop-cols="6"
        data-mobile-cols="2"
        data-herrera-product-widget-splide
        @if ($kind === 'related') data-herrera-related-products-splide @else data-herrera-recently-viewed-products-splide @endif
        data-product-count="{{ $productCount }}"
        data-splide='@json($carouselOptions)'
        aria-labelledby="{{ $headingId }}"
    >
        <div class="herrera-product-carousel-heading">
            <h2 id="{{ $headingId }}">{{ $title }}</h2>
            @if ($productCount > 1)
                <div class="splide__arrows">
                    <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $previousLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                    <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $nextLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                </div>
            @endif
        </div>
        <div class="splide__track">
            <ul class="splide__list">
                @foreach ($products as $carouselProduct)
                    <li class="splide__slide">
                        @include('front.desktop.partials.product-card', [
                            'product' => $carouselProduct,
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
