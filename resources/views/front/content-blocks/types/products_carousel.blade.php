@php
    $locale = app()->getLocale();
    $fallbackLocale = config('app.locale');
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $blockPayload = is_array($block->payload ?? null) ? $block->payload : [];
    $mergedPayload = array_merge($blockPayload, $translationPayload);
    $allowedRoutes = config('content_blocks.route_whitelist', []);
    $displayTitle = trim((string) ($translation?->title ?? ''));
    $displaySubtitle = trim((string) ($translation?->subtitle ?? ''));
    $categoryProductsMode = (bool) ($categoryProductsMode ?? false)
        || (string) $block->type === 'category_products_carousel';
    $sourceCategory = $categoryProductsMode ? $categories->first() : null;
    $sourceCategoryTranslation = $sourceCategory?->translations?->firstWhere('locale', $locale)
        ?? $sourceCategory?->translations?->firstWhere('locale', $fallbackLocale)
        ?? $sourceCategory?->translations?->first();
    $sourceCategoryName = trim((string) ($sourceCategoryTranslation?->name ?? $sourceCategory?->code ?? ''));
    $sourceCategoryUrl = $sourceCategory
        ? route('categories.show', ['slug' => $sourceCategoryTranslation?->slug ?? $sourceCategory->id])
        : '';

    if ($displayTitle === '') {
        $allTranslations = $block->translations()->get(['locale', 'title']);

        $displayTitle = trim((string) ($allTranslations->firstWhere('locale', $locale)?->title ?? ''));
        if ($displayTitle === '') {
            $displayTitle = trim((string) ($allTranslations->firstWhere('locale', $fallbackLocale)?->title ?? ''));
        }
        if ($displayTitle === '') {
            $displayTitle = trim((string) ($allTranslations->first(
                static fn ($row): bool => trim((string) ($row->title ?? '')) !== ''
            )?->title ?? ''));
        }
    }

    if ($displayTitle === '') {
        $displayTitle = (string) $block->name;
    }

    $resolveRouteUrl = function (?string $routeName, mixed $routeParams, string $fallbackUrl = '#') use ($allowedRoutes): string {
        $name = trim((string) $routeName);
        if ($name === '') {
            return $fallbackUrl;
        }

        $isAllowed = $allowedRoutes === []
            || collect($allowedRoutes)->contains(fn ($pattern) => \Illuminate\Support\Str::is((string) $pattern, $name));

        if (! $isAllowed || !\Illuminate\Support\Facades\Route::has($name)) {
            return $fallbackUrl;
        }

        $params = is_array($routeParams) ? $routeParams : [];

        try {
            return route($name, $params);
        } catch (\Throwable) {
            return $fallbackUrl;
        }
    };

    $ctaLabel = trim((string) ($translation?->cta_label ?? ''));
    $ctaFallbackUrl = (string) ($translation?->cta_url ?? '#');
    $ctaRoute = (string) ($mergedPayload['cta_route'] ?? '');
    $ctaRouteParams = $mergedPayload['cta_route_params'] ?? [];
    $ctaUrl = $resolveRouteUrl($ctaRoute, $ctaRouteParams, $ctaFallbackUrl);
    $mobileDefaultCols = in_array((int) ($storeSettings['product']['mobile_default_cols'] ?? 2), [1, 2], true)
        ? (int) ($storeSettings['product']['mobile_default_cols'] ?? 2)
        : 2;
    $desktopDefaultCols = in_array((int) ($storeSettings['product']['desktop_default_cols'] ?? 4), [4, 5], true)
        ? (int) ($storeSettings['product']['desktop_default_cols'] ?? 4)
        : 4;
    $requestedGridCols = request()->query('cols', request()->cookie('front_grid_cols', $desktopDefaultCols));
    $preferredGridCols = in_array((int) $requestedGridCols, [1, 2, 3, 4, 5], true)
        ? (int) $requestedGridCols
        : $desktopDefaultCols;
    $carouselDesktopCols = $categoryProductsMode ? 5 : $preferredGridCols;
    $carouselGap = $categoryProductsMode ? '0rem' : '1.25rem';
    $carouselMobileGap = $categoryProductsMode ? '0rem' : '0.8rem';
@endphp

<section class="{{ $categoryProductsMode ? 'w-full' : 'relative left-1/2 w-screen -translate-x-1/2' }} bg-white max-[540px]:py-5 py-8">
    <div class="mx-auto w-full {{ $categoryProductsMode ? '' : 'px-3 sm:px-4 lg:px-6' }}">
        <div class="max-[540px]:mb-5 mb-8 text-center">
            @if ($categoryProductsMode)
                <div class="storefront-widget-heading--split">
                    <h2 class="storefront-widget-heading-title">{{ $displayTitle }}</h2>
                    @if ($sourceCategoryName !== '' && $sourceCategoryUrl !== '')
                        <p class="storefront-widget-heading-meta">
                            {{ __('from category') }}
                            <a href="{{ $sourceCategoryUrl }}" class="storefront-widget-heading-link">
                                {{ $sourceCategoryName }}
                            </a>
                        </p>
                    @endif
                </div>
            @else
                <div class="mx-auto flex max-w-3xl items-center gap-4 md:gap-6">
                    @include('front.partials.section-heading-line', ['side' => 'left'])
                    <h2 class="text-[1.35rem] leading-[1.95rem] sm:text-[1.7rem] sm:leading-[2.5rem] uppercase font-semibold text-slate-900">{{ $displayTitle }}</h2>
                    @include('front.partials.section-heading-line', ['side' => 'right'])
                </div>
            @endif
            @if ($displaySubtitle !== '')
                <p class="mx-auto mt-2 max-w-2xl text-sm text-slate-600 md:text-base">{{ $displaySubtitle }}</p>
            @endif

            @if ($ctaLabel !== '' && $ctaUrl !== '')
                <a href="{{ $ctaUrl }}" class="mt-4 inline-flex h-10 items-center bg-slate-100 px-5 text-xs font-semibold uppercase tracking-[0.14em] text-slate-700 hover:bg-slate-200">
                    {{ $ctaLabel }}
                </a>
            @endif
        </div>

        @if ($products->isNotEmpty())
            <style>
                #products-carousel-{{ $block->id }} .splide__arrow {
                    opacity: 0;
                    width: 46px;
                    height: 46px;
                    border-radius: 9999px;
                    border: 1px solid rgba(255, 255, 255, 0.75);
                    background: rgba(15, 23, 42, 0.35);
                    backdrop-filter: blur(6px);
                    transform: translateY(-50%) scale(0.92);
                    transition: opacity .25s ease, transform .25s ease, background-color .25s ease;
                }

                #products-carousel-{{ $block->id }}:hover .splide__arrow,
                #products-carousel-{{ $block->id }}:focus-within .splide__arrow {
                    opacity: 1;
                    transform: translateY(-50%) scale(1);
                }

                #products-carousel-{{ $block->id }} .splide__arrow:hover {
                    background: rgba(15, 23, 42, 0.55);
                }

                #products-carousel-{{ $block->id }} .splide__arrow svg {
                    fill: #fff;
                }

                #products-carousel-{{ $block->id }} .splide__track {
                    overflow: hidden;
                    @if ($categoryProductsMode)
                        border-top: 1px solid #e2e8f0;
                        border-bottom: 1px solid #e2e8f0;
                        border-left: 1px solid #e2e8f0;
                    @endif
                }

                #products-carousel-{{ $block->id }} .splide__list {
                    display: flex;
                    margin: 0 !important;
                    padding: 0 !important;
                    list-style: none;
                    gap: {{ $carouselGap }};
                }

                #products-carousel-{{ $block->id }} .splide__slide {
                    flex: 0 0 {{ $categoryProductsMode
                        ? 'calc(100% / '.max(1, $carouselDesktopCols).')'
                        : 'calc((100% - ('.max(1, $carouselDesktopCols).' - 1) * 1.25rem) / '.max(1, $carouselDesktopCols).')' }};
                    min-width: 0;
                    @if ($categoryProductsMode)
                        border-right: 1px solid #e2e8f0;
                    @endif
                }

                #products-carousel-{{ $block->id }} .splide__pagination {
                    display: none;
                }

                #products-carousel-swipe-hint-{{ $block->id }} {
                    display: none;
                }

                @media (hover: none) {
                    #products-carousel-{{ $block->id }} .splide__arrow {
                        opacity: 1;
                        transform: translateY(-50%) scale(1);
                    }
                }

                @media (max-width: 1024px) {
                    #products-carousel-{{ $block->id }} .splide__slide {
                        flex-basis: {{ $categoryProductsMode ? '50%' : 'calc((100% - 1.25rem) / 2)' }};
                    }

                }

                @media (max-width: 640px) {
                    #products-carousel-{{ $block->id }} .splide__arrow {
                        display: none;
                    }

                    #products-carousel-{{ $block->id }} .splide__list {
                        gap: {{ $carouselMobileGap }};
                    }

                    #products-carousel-{{ $block->id }} .splide__slide {
                        flex-basis: {{ $mobileDefaultCols === 2
                            ? ($categoryProductsMode ? '50%' : 'calc((100% - 0.8rem) / 2)')
                            : '100%' }};
                    }

                    #products-carousel-{{ $block->id }} .splide__pagination {
                        position: static;
                        display: flex;
                        width: 100%;
                        transform: none;
                        gap: 0.5rem;
                        padding: 1rem 0 0.1rem;
                    }

                    #products-carousel-{{ $block->id }} .splide__pagination__page {
                        width: 0.5rem;
                        height: 0.5rem;
                        margin: 0;
                        border: 0;
                        background: #cbd5e1 !important;
                        opacity: 1;
                    }

                    #products-carousel-{{ $block->id }} .splide__pagination__page.is-active {
                        background: #0f172a !important;
                        transform: scale(1.25);
                    }

                    #products-carousel-swipe-hint-{{ $block->id }} {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: #64748b;
                    }
                }

            </style>

            @include('front.partials.splide-assets')

            @if ($products->count() > $mobileDefaultCols)
                <div
                    id="products-carousel-swipe-hint-{{ $block->id }}"
                    class="product-carousel-swipe-hint"
                    data-products-carousel-swipe-hint
                    aria-hidden="true"
                >
                    <svg width="40" height="28" viewBox="0 0 40 28" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8.5 8.5 4 13l4.5 4.5M31.5 8.5 36 13l-4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M20 4.5v9m0 0V9.75a2 2 0 0 1 4 0V14m-4-.5v-6a2 2 0 0 0-4 0v8.25l-1.2-1.35a2 2 0 0 0-2.98 2.67l4.35 4.86A4 4 0 0 0 19.15 23.8H23a5 5 0 0 0 5-5V14a2 2 0 0 0-4 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            @endif

            <div
                class="mt-4 {{ $categoryProductsMode ? 'storefront-widget-wide' : '' }}"
            >
                <div id="products-carousel-{{ $block->id }}" class="splide" data-products-carousel-splide>
                    <div class="splide__track">
                        <div class="splide__list">
                            @foreach ($products as $product)
                                <div class="splide__slide">
                                    @include('front.desktop.partials.product-card', [
                                        'product' => $product,
                                        'locale' => $locale,
                                        'fallbackLocale' => $fallbackLocale,
                                        'flat' => true,
                                        'lined' => $categoryProductsMode,
                                    ])
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            @once
                @push('scripts')
                    <script>
                        (function () {
                            const init = function () {
                                if (typeof window.Splide !== 'function') {
                                    return false;
                                }

                                const sliders = document.querySelectorAll('[data-products-carousel-splide]');
                                sliders.forEach(function (el) {
                                    if (el.dataset.splideReady === '1') {
                                        return;
                                    }
                                    el.dataset.splideReady = '1';

                                    const count = el.querySelectorAll('.splide__slide').length;
                                    const mobilePerPage = {{ $mobileDefaultCols }};
                                    const preferredDesktopPerPage = {{ $carouselDesktopCols }};
                                    new window.Splide(el, {
                                        type: count > 1 ? 'loop' : 'slide',
                                        perPage: Math.min(Math.max(1, preferredDesktopPerPage), Math.max(1, count)),
                                        perMove: 1,
                                        gap: '{{ $carouselGap }}',
                                        drag: count > 1,
                                        snap: true,
                                        pagination: false,
                                        arrows: count > 1,
                                        updateOnMove: true,
                                        speed: 520,
                                        breakpoints: {
                                            1536: { perPage: Math.min(Math.min(Math.max(1, preferredDesktopPerPage), 5), Math.max(1, count)) },
                                            1280: { perPage: Math.min(Math.min(Math.max(1, preferredDesktopPerPage), 4), Math.max(1, count)) },
                                            1024: { perPage: Math.min(Math.min(Math.max(1, preferredDesktopPerPage), 3), Math.max(1, count)) },
                                            860: { perPage: Math.min(mobilePerPage, Math.max(1, count)), gap: '{{ $categoryProductsMode ? '0rem' : '1rem' }}' },
                                            640: {
                                                perPage: Math.min(mobilePerPage, Math.max(1, count)),
                                                gap: '{{ $carouselMobileGap }}',
                                                arrows: false,
                                                pagination: count > mobilePerPage,
                                            },
                                        },
                                    }).mount();
                                });

                                return true;
                            };

                            if (init()) {
                                return;
                            }

                            let attempts = 0;
                            const timer = window.setInterval(function () {
                                attempts += 1;
                                if (init() || attempts > 40) {
                                    window.clearInterval(timer);
                                }
                            }, 120);
                        })();
                    </script>
                @endpush
            @endonce
        @else
            <div class="bg-slate-50 p-4 text-xs text-slate-500">
                No selected products for this carousel.
            </div>
        @endif
    </div>
</section>
