@php
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $customClasses = trim((string) ($translationPayload['custom_classes'] ?? ''));
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
    $sliderId = 'full-width-slider-'.$block->id;
    $slides = $block->getMedia('block_slides');

    if ($slides->isEmpty()) {
        $fallback = $block->getFirstMedia('block_background');
        if ($fallback) {
            $slides = collect([$fallback]);
        }
    }

    $firstSlide = $slides->first();
    $firstSlideUrl2560 = \App\Support\Media\MediaUrl::conversionOrNull($firstSlide, 'hero_2560w', $preferWebp);
    $firstSlideUrl1920 = \App\Support\Media\MediaUrl::conversionOrNull($firstSlide, 'hero_1920w', $preferWebp);
    $firstSlideUrl1600 = \App\Support\Media\MediaUrl::conversionOrNull($firstSlide, 'hero_1600w', $preferWebp);
    $firstSlideUrl1360 = \App\Support\Media\MediaUrl::conversionOrNull($firstSlide, 'hero_1360w', $preferWebp);
    $firstSlideUrl1200 = \App\Support\Media\MediaUrl::conversionOrNull($firstSlide, 'hero_1200w', $preferWebp);
    $firstSlideUrl960 = \App\Support\Media\MediaUrl::conversionOrNull($firstSlide, 'hero_960w', $preferWebp);
    $firstSlideUrl800 = \App\Support\Media\MediaUrl::conversionOrNull($firstSlide, 'hero_800w', $preferWebp);
    $firstSlideUrl720 = \App\Support\Media\MediaUrl::conversionOrNull($firstSlide, 'hero_720w', $preferWebp);
    $firstSlideUrl540 = \App\Support\Media\MediaUrl::conversionOrNull($firstSlide, 'hero_540w', $preferWebp);
    $firstSlideSrcset = collect([
        $firstSlideUrl540 ? $firstSlideUrl540.' 540w' : null,
        $firstSlideUrl720 ? $firstSlideUrl720.' 720w' : null,
        $firstSlideUrl800 ? $firstSlideUrl800.' 800w' : null,
        $firstSlideUrl960 ? $firstSlideUrl960.' 960w' : null,
        $firstSlideUrl1200 ? $firstSlideUrl1200.' 1200w' : null,
        $firstSlideUrl1360 ? $firstSlideUrl1360.' 1360w' : null,
        $firstSlideUrl1600 ? $firstSlideUrl1600.' 1600w' : null,
        $firstSlideUrl1920 ? $firstSlideUrl1920.' 1920w' : null,
        $firstSlideUrl2560 ? $firstSlideUrl2560.' 2560w' : null,
    ])->filter()->unique()->implode(', ');
    $firstSlidePreloadUrl = $firstSlide
        ? ($firstSlideUrl2560
            ?? $firstSlideUrl1920
            ?? $firstSlideUrl1600
            ?? $firstSlideUrl1360
            ?? $firstSlideUrl1200
            ?? $firstSlideUrl960
            ?? $firstSlideUrl800
            ?? $firstSlideUrl720
            ?? $firstSlideUrl540
            ?? $firstSlide->getUrl())
        : null;

    $autoplayMs = 5000;

    $hotspotProductIds = $slides
        ->flatMap(function ($media) {
            return collect((array) data_get($media->custom_properties, 'product_hotspots', []))
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        })
        ->filter()
        ->unique()
        ->values();

    $hotspotProductsById = collect();
    if ($hotspotProductIds->isNotEmpty()) {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $pricing = app(\App\Services\Pricing\ProductPricePresentationService::class);
        $viewer = auth()->user();

        $hotspotProductsById = \App\Models\Catalog\Product\Product::query()
            ->where('is_active', true)
            ->whereIn('id', $hotspotProductIds->all())
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'media',
            ])
            ->get()
            ->mapWithKeys(function ($product) use ($locale, $fallbackLocale, $preferWebp, $pricing, $viewer) {
                $translation = $product->translations->firstWhere('locale', $locale)
                    ?? $product->translations->firstWhere('locale', $fallbackLocale);
                if (! $translation) {
                    return [];
                }

                $mainMedia = $product->media->firstWhere('collection_name', 'product_main')
                    ?? $product->media->firstWhere('collection_name', 'product_gallery')
                    ?? $product->getFirstMedia('product_main')
                    ?? $product->getFirstMedia('product_gallery');
                $price = $pricing->forProduct($product, $viewer);
                $imageUrl = \App\Support\Media\MediaUrl::conversionOrNull($mainMedia, 'card_320w', $preferWebp)
                    ?? \App\Support\Media\MediaUrl::conversionOrNull($mainMedia, 'card_480w', $preferWebp)
                    ?? ($mainMedia ? (string) $mainMedia->getUrl() : null);

                return [
                    (int) $product->id => [
                        'name' => (string) $translation->name,
                        'url' => route('products.show', ['slug' => $translation->slug]),
                        'price' => number_format((float) ($price['current_gross'] ?? 0), 2).' €',
                        'image_url' => $imageUrl,
                    ],
                ];
            });
    }
@endphp

@if ($slides->isNotEmpty())
    @if ($firstSlidePreloadUrl)
        @push('head')
            <link
                rel="preload"
                as="image"
                href="{{ $firstSlidePreloadUrl }}"
                @if ($firstSlideSrcset !== '') imagesrcset="{{ $firstSlideSrcset }}" imagesizes="100vw" @endif
                fetchpriority="high"
            >
        @endpush
    @endif

    @include('front.partials.splide-assets')

    <style>
        #{{ $sliderId }}.splide {
            visibility: visible;
        }

        #{{ $sliderId }} .splide__track {
            overflow: hidden;
            height: 100%;
        }

        #{{ $sliderId }} .splide__list {
            display: flex;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        #{{ $sliderId }} .splide__slide {
            flex: 0 0 100%;
            min-width: 0;
        }

        #{{ $sliderId }} .splide__list,
        #{{ $sliderId }} .splide__slide {
            height: 100%;
        }

        #{{ $sliderId }} .hero-slide-frame {
            position: relative;
            aspect-ratio: 1920 / 820;
            background: #f1f5f9;
            height: 100%;
        }

        #{{ $sliderId }} .hero-slide-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }

        #{{ $sliderId }} .ag-hotspot-toggle {
            isolation: isolate;
        }

        #{{ $sliderId }} .ag-hotspot-toggle::before {
            content: '';
            position: absolute;
            inset: -8px;
            z-index: -1;
            border-radius: 9999px;
            border: 1px solid rgba(255, 255, 255, 0.72);
            background: rgba(255, 255, 255, 0.2);
            pointer-events: none;
            animation: ag-hotspot-pulse 1.9s ease-out infinite;
        }

        #{{ $sliderId }} .ag-hotspot-toggle[aria-expanded="true"]::before {
            opacity: 0;
            animation: none;
        }

        #{{ $sliderId }} .ag-hotspot-panel {
            overflow: visible;
        }

        #{{ $sliderId }} .ag-hotspot-panel > a {
            position: relative;
            z-index: 1;
        }

        #{{ $sliderId }} .ag-hotspot-caret {
            width: 14px;
            height: 18px;
            pointer-events: none;
        }

        #{{ $sliderId }} .ag-hotspot-caret::before,
        #{{ $sliderId }} .ag-hotspot-caret::after {
            content: '';
            position: absolute;
            inset: 0;
            clip-path: polygon(0 50%, 100% 0, 100% 100%);
        }

        #{{ $sliderId }} .ag-hotspot-caret::before {
            background: rgb(226 232 240);
        }

        #{{ $sliderId }} .ag-hotspot-caret::after {
            inset: 1px 0 1px 1px;
            background: #fff;
        }

        #{{ $sliderId }} .ag-hotspot-panel.is-side-right .ag-hotspot-caret {
            left: -13px;
            transform: translateY(-50%);
        }

        #{{ $sliderId }} .ag-hotspot-panel.is-side-left .ag-hotspot-caret {
            right: -13px;
            transform: translateY(-50%) scaleX(-1);
        }

        #{{ $sliderId }} .splide__arrow {
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

        #{{ $sliderId }}:hover .splide__arrow,
        #{{ $sliderId }}:focus-within .splide__arrow {
            opacity: 1;
            transform: translateY(-50%) scale(1);
        }

        #{{ $sliderId }} .splide__arrow:hover {
            background: rgba(15, 23, 42, 0.55);
        }

        #{{ $sliderId }} .splide__arrow svg {
            fill: #fff;
        }

        #{{ $sliderId }} .splide__pagination {
            bottom: 1rem;
            gap: 0.45rem;
        }

        #{{ $sliderId }} .splide__pagination__page {
            width: 10px;
            height: 10px;
            margin: 0;
            opacity: 0.95;
            background: #ffffff !important;
            border: 2px solid transparent;
            transition: transform 0.2s linear, background-color 0.2s linear, border-color 0.2s linear;
        }

        #{{ $sliderId }} .splide__pagination__page.is-active {
            transform: none;
            background: #0f172a !important;
            border-color: #ffffff;
        }

        @media (max-width: 768px) {
            #{{ $sliderId }} .hero-slide-frame {
                aspect-ratio: 5 / 4;
            }

            #{{ $sliderId }} .hero-slide-image {
                height: 100%;
            }
        }

        @keyframes ag-hotspot-pulse {
            0% {
                transform: scale(0.9);
                opacity: 0.85;
            }

            70% {
                transform: scale(1.35);
                opacity: 0;
            }

            100% {
                transform: scale(1.35);
                opacity: 0;
            }
        }
    </style>

    <section class="relative left-1/2 w-screen -translate-x-1/2 overflow-hidden {{ $customClasses }}">
        <div id="{{ $sliderId }}" class="splide" data-fullwidth-splide>
            <div class="splide__track">
                <div class="splide__list">
                    @foreach ($slides as $media)
                        @php
                            $slideUrl2560 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_2560w', $preferWebp);
                            $slideUrl1920 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_1920w', $preferWebp);
                            $slideUrl1600 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_1600w', $preferWebp);
                            $slideUrl1360 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_1360w', $preferWebp);
                            $slideUrl1200 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_1200w', $preferWebp);
                            $slideUrl960 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_960w', $preferWebp);
                            $slideUrl800 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_800w', $preferWebp);
                            $slideUrl720 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_720w', $preferWebp);
                            $slideUrl540 = \App\Support\Media\MediaUrl::conversionOrNull($media, 'hero_540w', $preferWebp);
                            $slideUrl = $slideUrl2560
                                ?? $slideUrl1920
                                ?? $slideUrl1600
                                ?? $slideUrl1360
                                ?? $slideUrl1200
                                ?? $slideUrl960
                                ?? $slideUrl800
                                ?? $slideUrl720
                                ?? $slideUrl540
                                ?? $media->getUrl();
                            $slideSrcset = collect([
                                $slideUrl540 ? $slideUrl540.' 540w' : null,
                                $slideUrl720 ? $slideUrl720.' 720w' : null,
                                $slideUrl800 ? $slideUrl800.' 800w' : null,
                                $slideUrl960 ? $slideUrl960.' 960w' : null,
                                $slideUrl1200 ? $slideUrl1200.' 1200w' : null,
                                $slideUrl1360 ? $slideUrl1360.' 1360w' : null,
                                $slideUrl1600 ? $slideUrl1600.' 1600w' : null,
                                $slideUrl1920 ? $slideUrl1920.' 1920w' : null,
                                $slideUrl2560 ? $slideUrl2560.' 2560w' : null,
                            ])->filter()->unique()->implode(', ');
                            $slideLink = trim((string) (
                                data_get($media->custom_properties, 'link_url.'.app()->getLocale())
                                ?: data_get($media->custom_properties, 'link_url.'.config('app.locale'))
                                ?: data_get($media->custom_properties, 'link_url_value', '')
                            ));
                            $hasSlideLink = $slideLink !== '';
                            $slideWidth = max(1, (int) ($media->width ?? 1200));
                            $slideHeight = max(1, (int) ($media->height ?? 700));
                            $slideHotspots = collect((array) data_get($media->custom_properties, 'product_hotspots', []))
                                ->map(function ($row) use ($hotspotProductsById): ?array {
                                    $row = is_array($row) ? $row : [];
                                    $productId = (int) ($row['product_id'] ?? 0);
                                    $product = (array) ($hotspotProductsById->get($productId) ?? []);
                                    if ($productId <= 0 || $product === []) {
                                        return null;
                                    }

                                    return [
                                        'product' => $product,
                                        'x' => max(3, min(97, (float) ($row['x'] ?? 50))),
                                        'y' => max(3, min(97, (float) ($row['y'] ?? 50))),
                                    ];
                                })
                                ->filter()
                                ->values()
                                ->take(3);
                        @endphp
                        <div class="splide__slide">
                            <article class="relative min-w-full hero-slide-frame" data-slider-hotspot-root>
                                @if ($hasSlideLink)
                                    <a href="{{ $slideLink }}" class="block h-full">
                                @endif
                                    <img
                                        @if ($loop->first)
                                            src="{{ $slideUrl }}"
                                            @if ($slideSrcset !== '') srcset="{{ $slideSrcset }}" @endif
                                            loading="eager"
                                            fetchpriority="high"
                                        @else
                                            data-hero-lazy-src="{{ $slideUrl }}"
                                            @if ($slideSrcset !== '') data-hero-lazy-srcset="{{ $slideSrcset }}" @endif
                                        @endif
                                        sizes="100vw"
                                        alt="{{ $translation?->title ?: $block->name }} {{ $loop->iteration }}"
                                        class="hero-slide-image bg-slate-100"
                                        width="{{ $slideWidth }}"
                                        height="{{ $slideHeight }}"
                                        decoding="async"
                                    >
                                    <div class="absolute inset-0 bg-black/10"></div>
                                    @if (($translation?->title ?? '') !== '' || ($translation?->subtitle ?? '') !== '')
                                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/50 via-black/20 to-transparent px-6 pb-10 pt-16 text-white md:px-12">
                                            @if (($translation?->title ?? '') !== '')
                                                <h2 class="text-3xl font-extrabold tracking-tight md:text-5xl">{{ $translation->title }}</h2>
                                            @endif
                                            @if (($translation?->subtitle ?? '') !== '')
                                                <p class="mt-3 max-w-3xl text-sm text-white/90 md:text-base">{{ $translation->subtitle }}</p>
                                            @endif
                                            @if (($translation?->cta_label ?? '') !== '' && (($translation?->cta_url ?? '') !== '' || $hasSlideLink))
                                                @if ($hasSlideLink)
                                                    <span class="mt-6 inline-flex h-11 items-center border border-white bg-white px-6 text-sm font-semibold text-slate-900">
                                                        {{ $translation->cta_label }}
                                                    </span>
                                                @else
                                                    <a href="{{ $translation->cta_url }}" class="mt-6 inline-flex h-11 items-center border border-white bg-white px-6 text-sm font-semibold text-slate-900 hover:bg-slate-100">
                                                        {{ $translation->cta_label }}
                                                    </a>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                @if ($hasSlideLink)
                                    </a>
                                @endif

                                @foreach ($slideHotspots as $hotspotIndex => $hotspot)
                                    @php
                                        $hotspotKey = 'hotspot-'.$media->id.'-'.$hotspotIndex;
                                        $hotspotProduct = (array) ($hotspot['product'] ?? []);
                                    @endphp
                                    <button
                                        type="button"
                                        class="ag-hotspot-toggle absolute z-20 inline-flex h-9 w-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-white/90 bg-white text-[22px] font-light leading-none text-slate-900 shadow-md transition-colors hover:bg-slate-200"
                                        style="left: {{ number_format((float) ($hotspot['x'] ?? 50), 2, '.', '') }}%; top: {{ number_format((float) ($hotspot['y'] ?? 50), 2, '.', '') }}%;"
                                        data-slider-hotspot-toggle
                                        data-panel-key="{{ $hotspotKey }}"
                                        aria-expanded="false"
                                    >
                                        +
                                    </button>
                                    <div
                                        class="ag-hotspot-panel absolute z-30 hidden w-[210px] rounded-xl border border-slate-200 bg-white shadow-xl"
                                        data-panel-key="{{ $hotspotKey }}"
                                        data-slider-hotspot-panel
                                    >
                                        <span class="ag-hotspot-caret absolute" data-slider-hotspot-caret aria-hidden="true"></span>
                                        <a href="{{ $hotspotProduct['url'] ?? '#' }}" class="flex items-center gap-2.5 p-2.5">
                                            @if (!empty($hotspotProduct['image_url']))
                                                <img src="{{ $hotspotProduct['image_url'] }}" alt="{{ $hotspotProduct['name'] ?? '' }}" class="h-14 w-12 rounded-md object-cover">
                                            @endif
                                            <div class="min-w-0">
                                                <p class="line-clamp-2 text-[14px] leading-tight text-slate-800">{{ $hotspotProduct['name'] ?? '' }}</p>
                                                <p class="mt-1.5 text-[14px] font-medium leading-none text-slate-800">{{ $hotspotProduct['price'] ?? '' }}</p>
                                            </div>
                                        </a>
                                    </div>
                                @endforeach
                            </article>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    @once
        @push('scripts')
            <script>
                (function () {
                    const initHotspots = function () {
                        const positionHotspotPanel = function (btn, panel) {
                            const root = btn.closest('[data-slider-hotspot-root]');
                            const caret = panel.querySelector('[data-slider-hotspot-caret]');
                            if (!root) {
                                return;
                            }

                            const rootRect = root.getBoundingClientRect();
                            const btnRect = btn.getBoundingClientRect();
                            const panelWidth = panel.offsetWidth;
                            const panelHeight = panel.offsetHeight;
                            const gap = 28;
                            const minPad = 8;
                            const maxLeft = Math.max(minPad, rootRect.width - panelWidth - minPad);
                            const maxTop = Math.max(minPad, rootRect.height - panelHeight - minPad);
                            const anchorX = (btnRect.left - rootRect.left) + (btnRect.width / 2);
                            const anchorY = (btnRect.top - rootRect.top) + (btnRect.height / 2);
                            const roomRight = rootRect.width - anchorX - gap;
                            const roomLeft = anchorX - gap;
                            const side = roomRight >= panelWidth || roomRight >= roomLeft ? 'right' : 'left';
                            const rawLeft = side === 'right' ? anchorX + gap : anchorX - gap - panelWidth;
                            const left = Math.max(minPad, Math.min(maxLeft, rawLeft));
                            const top = Math.max(minPad, Math.min(maxTop, anchorY - (panelHeight / 2)));

                            panel.style.left = left + 'px';
                            panel.style.top = top + 'px';
                            panel.classList.toggle('is-side-right', side === 'right');
                            panel.classList.toggle('is-side-left', side === 'left');

                            if (!caret) {
                                return;
                            }

                            const caretTop = Math.max(12, Math.min(panelHeight - 12, anchorY - top));
                            caret.style.top = caretTop + 'px';
                            if (side === 'right') {
                                caret.style.left = '-13px';
                                caret.style.right = '';
                            } else {
                                caret.style.right = '-13px';
                                caret.style.left = '';
                            }
                        };

                        const closeAll = function () {
                            document.querySelectorAll('[data-slider-hotspot-panel]').forEach(function (panel) {
                                panel.classList.add('hidden');
                            });
                            document.querySelectorAll('[data-slider-hotspot-toggle]').forEach(function (btn) {
                                btn.setAttribute('aria-expanded', 'false');
                            });
                        };

                        if (window.__sliderHotspotDelegatedBound !== true) {
                            window.__sliderHotspotDelegatedBound = true;
                            document.addEventListener('click', function (event) {
                                const btn = event.target.closest('[data-slider-hotspot-toggle]');
                                if (!btn) {
                                    return;
                                }

                                event.preventDefault();
                                event.stopPropagation();
                                const root = btn.closest('[data-slider-hotspot-root]');
                                if (!root) {
                                    return;
                                }

                                const panelKey = String(btn.getAttribute('data-panel-key') || '');
                                const panel = Array.from(root.querySelectorAll('[data-slider-hotspot-panel]')).find(function (candidate) {
                                    return String(candidate.getAttribute('data-panel-key') || '') === panelKey;
                                });
                                if (!panel) {
                                    return;
                                }

                                const willOpen = panel.classList.contains('hidden');
                                closeAll();
                                if (willOpen) {
                                    panel.classList.remove('hidden');
                                    positionHotspotPanel(btn, panel);
                                    btn.setAttribute('aria-expanded', 'true');
                                }
                            });
                        }

                        document.addEventListener('click', function (event) {
                            if (event.target.closest('[data-slider-hotspot-panel]') || event.target.closest('[data-slider-hotspot-toggle]')) {
                                return;
                            }
                            closeAll();
                        });
                    };

                    const init = function () {
                        if (typeof window.Splide !== 'function') {
                            return false;
                        }

                        const sliders = document.querySelectorAll('[data-fullwidth-splide]');
                        sliders.forEach(function (el) {
                            if (el.dataset.splideReady === '1') {
                                return;
                            }
                            el.dataset.splideReady = '1';

                            const count = el.querySelectorAll('.splide__slide').length;
                            const loadSlideImage = function (index) {
                                const slide = el.querySelectorAll('.splide__slide')[index];
                                if (!slide) {
                                    return;
                                }

                                slide.querySelectorAll('[data-hero-lazy-srcset]').forEach(function (image) {
                                    image.setAttribute('srcset', image.dataset.heroLazySrcset);
                                    image.removeAttribute('data-hero-lazy-srcset');
                                });

                                slide.querySelectorAll('img[data-hero-lazy-src]').forEach(function (image) {
                                    image.setAttribute('src', image.dataset.heroLazySrc);
                                    image.removeAttribute('data-hero-lazy-src');
                                });
                            };
                            const slider = new window.Splide(el, {
                                type: 'slide',
                                rewind: count > 1,
                                perPage: 1,
                                perMove: 1,
                                arrows: count > 1,
                                pagination: count > 1,
                                noDrag: '[data-slider-hotspot-toggle], [data-slider-hotspot-panel]',
                                autoplay: false,
                                interval: {{ $autoplayMs }},
                                pauseOnHover: true,
                                pauseOnFocus: true,
                                speed: 700,
                                easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                            });
                            slider.on('move', loadSlideImage);
                            slider.mount();
                        });

                        initHotspots();
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
