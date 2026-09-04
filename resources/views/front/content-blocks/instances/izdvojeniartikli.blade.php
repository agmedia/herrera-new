@php
    $locale = app()->getLocale();
    $fallbackLocale = config('app.locale');
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $blockPayload = is_array($block->payload ?? null) ? $block->payload : [];
    $mergedPayload = array_merge($blockPayload, $translationPayload);
    $allowedRoutes = config('content_blocks.route_whitelist', []);
    $displayTitle = trim((string) ($translation?->title ?? ''));
    $displaySubtitle = trim((string) ($translation?->subtitle ?? ''));

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
@endphp

<section class="relative left-1/2 w-screen max-w-[100vw] -translate-x-1/2 overflow-x-hidden bg-white max-[540px]:py-5 py-8">
    <div class="w-full px-3 sm:px-4 lg:px-6">
        <div class="max-[540px]:mb-5 mb-8 text-center">
            <div class="mx-auto flex max-w-3xl items-center gap-4 md:gap-6">
                @include('front.partials.section-heading-line', ['side' => 'left'])
                <h2 class="text-[1.35rem] leading-[1.95rem] sm:text-[1.7rem] sm:leading-[2.5rem] uppercase font-semibold text-slate-900">{{ $displayTitle }}</h2>
                @include('front.partials.section-heading-line', ['side' => 'right'])
            </div>
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
                }

                @media (hover: none) {
                    #products-carousel-{{ $block->id }} .splide__arrow {
                        opacity: 1;
                        transform: translateY(-50%) scale(1);
                    }
                }

                @media (max-width: 1024px) {
                }

                @media (max-width: 640px) {
                }
            </style>

            @include('front.partials.splide-assets')

            <div class="mt-4">
                <div id="products-carousel-{{ $block->id }}" class="splide" data-products-carousel-splide>
                    <div class="splide__track">
                        <ul class="splide__list">
                            @foreach ($products as $product)
                                <li class="splide__slide">
                                    @include('front.desktop.partials.product-card', [
                                        'product' => $product,
                                        'locale' => $locale,
                                        'fallbackLocale' => $fallbackLocale,
                                        'flat' => true,
                                    ])
                                </li>
                            @endforeach
                        </ul>
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
                                    const preferredDesktopPerPage = {{ $preferredGridCols }};
                                    const desktopGap = preferredDesktopPerPage >= 5 ? '1rem' : '1.25rem';
                                    new window.Splide(el, {
                                        type: count > 1 ? 'loop' : 'slide',
                                        perPage: Math.min(Math.max(1, preferredDesktopPerPage), Math.max(1, count)),
                                        perMove: 1,
                                        gap: desktopGap,
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
                                            860: { perPage: Math.min(mobilePerPage, Math.max(1, count)), gap: '1rem' },
                                            640: { perPage: Math.min(mobilePerPage, Math.max(1, count)), gap: '0.8rem' },
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
