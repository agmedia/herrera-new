@php
    $locale = app()->getLocale();
    $fallbackLocale = config('app.locale');
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $blockPayload = is_array($block->payload ?? null) ? $block->payload : [];
    $mergedPayload = array_merge($blockPayload, $translationPayload);
    $allowedRoutes = config('content_blocks.route_whitelist', []);
    $displayTitle = trim((string) ($translation?->title ?? ''));
    $displaySubtitle = trim((string) ($translation?->subtitle ?? ''));
    $itemsLimit = max(1, (int) ($mergedPayload['items_limit'] ?? 6));

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
    $reviewRows = ($comments ?? collect())->take($itemsLimit);
@endphp

<section class="relative left-1/2 w-screen -translate-x-1/2 overflow-hidden bg-[linear-gradient(180deg,_#eef2f3_0%,_#e8edf0_46%,_#f6f7f7_100%)] max-[540px]:pt-5 max-[540px]:pb-[2.75rem] pt-8 pb-16">
    <div class="pointer-events-none absolute inset-0">
        <div class="absolute inset-x-0 top-0 h-px bg-black/8"></div>
        <div class="absolute inset-x-0 bottom-0 h-px bg-black/6"></div>
        <div
            class="absolute inset-0 opacity-[0.58]"
            style="background-image: linear-gradient(rgba(255,255,255,0.34) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.34) 1px, transparent 1px); background-size: 34px 34px;"
        ></div>
        <div
            class="absolute inset-0"
            style="background: radial-gradient(58% 92% at 12% 20%, rgba(255,255,255,0.58) 0%, rgba(255,255,255,0) 48%), radial-gradient(42% 74% at 88% 22%, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 44%), linear-gradient(180deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0) 34%, rgba(15,23,42,0.03) 100%);"
        ></div>
    </div>
    <div class="relative w-full px-3 sm:px-4 lg:px-6">
        <div class="max-[540px]:mb-5 mb-8 text-center">
            <div class="mx-auto flex max-w-3xl items-center gap-4 md:gap-6">
                @include('front.partials.section-heading-line', ['side' => 'left'])
                <h2 class="max-[540px]:text-[1.18rem] max-[540px]:leading-[1.65rem] text-[1.35rem] leading-[1.95rem] sm:text-[1.7rem] sm:leading-[2.5rem] uppercase font-semibold text-slate-900">{{ $displayTitle }}</h2>
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

        @if ($reviewRows->isNotEmpty())
            <style>
                #reviews-carousel-{{ $block->id }} {
                    padding-bottom: 2rem;
                }

                #reviews-carousel-{{ $block->id }} .splide__pagination {
                    bottom: -1.1rem;
                    gap: 0.4rem;
                }

                #reviews-carousel-{{ $block->id }} .splide__pagination__page {
                    width: 10px;
                    height: 10px;
                    margin: 0;
                    opacity: 0.95;
                    background: #ffffff !important;
                    border: 2px solid transparent;
                    transition: transform 0.2s linear, background-color 0.2s linear, border-color 0.2s linear;
                }

                #reviews-carousel-{{ $block->id }} .splide__pagination__page.is-active {
                    transform: none;
                    background: #0f172a !important;
                    border-color: #ffffff;
                }

                #reviews-carousel-{{ $block->id }} .review-card {
                    border: 1px solid #dbe3ef;
                    background: #ffffff;
                }

                #reviews-carousel-{{ $block->id }} .review-quote {
                    color: #c9d3e5;
                    font-size: 1.45rem;
                    line-height: 1;
                    font-weight: 600;
                }

            </style>

            @include('front.partials.splide-assets')

            <div id="reviews-carousel-{{ $block->id }}" class="splide" data-five-star-reviews-splide>
                <div class="splide__track">
                    <ul class="splide__list">
                        @foreach ($reviewRows as $row)
                            <li class="splide__slide">
                                @php
                                    $author = $row->author_name ?: __('ui.product.comments_anonymous');
                                    $authorInitial = mb_strtoupper(mb_substr(trim($author), 0, 1));
                                @endphp
                                <article class="review-card h-full p-6">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="inline-flex items-center gap-1 text-slate-900/80" aria-label="5/5">
                                            @for ($i = 0; $i < 5; $i++)
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.34 4.1a1 1 0 0 0 .95.69h4.3c.97 0 1.37 1.24.59 1.81l-3.48 2.52a1 1 0 0 0-.37 1.12l1.33 4.1c.3.92-.75 1.68-1.54 1.12l-3.48-2.53a1 1 0 0 0-1.18 0l-3.48 2.53c-.78.56-1.83-.2-1.54-1.12l1.33-4.1a1 1 0 0 0-.36-1.12L1.9 9.53c-.79-.57-.38-1.81.59-1.81h4.31a1 1 0 0 0 .95-.69l1.3-4.1Z"/>
                                                </svg>
                                            @endfor
                                        </div>
                                        <span class="review-quote" aria-hidden="true">“</span>
                                    </div>
                                    <p class="mt-3 line-clamp-4 text-sm leading-relaxed text-slate-700">{{ $row->body }}</p>
                                    <div class="mt-5 flex items-center gap-3 border-t border-slate-200 pt-3">
                                        <span class="inline-flex h-8 w-8 items-center justify-center border border-slate-300 bg-white text-xs font-bold uppercase text-slate-700">{{ $authorInitial }}</span>
                                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ $author }}</p>
                                    </div>
                                </article>
                            </li>
                        @endforeach
                    </ul>
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

                                const sliders = document.querySelectorAll('[data-five-star-reviews-splide]');
                                sliders.forEach(function (el) {
                                    if (el.dataset.splideReady === '1') {
                                        return;
                                    }
                                    el.dataset.splideReady = '1';

                                    const count = el.querySelectorAll('.splide__slide').length;
                                    new window.Splide(el, {
                                        type: count > 1 ? 'loop' : 'slide',
                                        perPage: Math.min(3, Math.max(1, count)),
                                        perMove: 1,
                                        gap: '1.25rem',
                                        drag: count > 1,
                                        snap: true,
                                        pagination: count > 1,
                                        arrows: false,
                                        autoplay: count > 1,
                                        interval: 4200,
                                        pauseOnHover: true,
                                        pauseOnFocus: true,
                                        resetProgress: false,
                                        breakpoints: {
                                            1024: { perPage: Math.min(2, Math.max(1, count)) },
                                            640: { perPage: 1 },
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
        @endif
    </div>
</section>
