@php
    $productCount = $products->count();
    $headingId = $carouselId.'-heading';
    $previousLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $nextLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $carouselOptions = [
        'type' => $productCount > 1 ? 'loop' : 'slide',
        'rewind' => false,
        'perPage' => 6,
        // Keep the card grid when all items fit; remount the loop at narrower breakpoints.
        'destroy' => $productCount <= 6,
        'perMove' => 1,
        'gap' => '0rem',
        'drag' => $productCount > 1,
        'snap' => true,
        'pagination' => false,
        'arrows' => $productCount > 6,
        'updateOnMove' => true,
        'speed' => 420,
        'i18n' => ['prev' => $previousLabel, 'next' => $nextLabel, 'first' => $nextLabel, 'last' => $previousLabel],
        // Data options override every intermediate breakpoint in the shared initializer.
        'breakpoints' => [
            1280 => ['perPage' => 6, 'destroy' => $productCount <= 6, 'arrows' => $productCount > 6],
            1279 => ['perPage' => 4, 'destroy' => $productCount <= 4, 'arrows' => $productCount > 4],
            1024 => ['perPage' => 4, 'destroy' => $productCount <= 4, 'arrows' => $productCount > 4],
            860 => ['perPage' => 4, 'destroy' => $productCount <= 4, 'arrows' => $productCount > 4],
            767 => ['perPage' => 2, 'destroy' => $productCount <= 2, 'arrows' => $productCount > 2],
            640 => ['perPage' => 2, 'destroy' => $productCount <= 2, 'arrows' => $productCount > 2, 'pagination' => false],
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
            @if ($productCount > 2)
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
