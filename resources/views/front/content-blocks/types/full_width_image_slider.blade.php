@php
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $customClasses = trim((string) ($translationPayload['custom_classes'] ?? ''));
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
    $sliderId = 'full-width-slider-'.$block->id;
    $isHomeMainSlider = (string) $block->code === 'home-main-slider';
    $slides = $block->getMedia('block_slides');
    $mobileSlides = $block->getMedia('block_slides_mobile')->values();

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
    $firstMobileSlide = $mobileSlides->first();
    $firstMobileSlideUrl1080 = \App\Support\Media\MediaUrl::conversionOrNull($firstMobileSlide, 'square_1080w', $preferWebp);
    $firstMobileSlideUrl720 = \App\Support\Media\MediaUrl::conversionOrNull($firstMobileSlide, 'square_720w', $preferWebp);
    $firstMobileSlideUrl540 = \App\Support\Media\MediaUrl::conversionOrNull($firstMobileSlide, 'square_540w', $preferWebp);
    $firstMobileSlideSrcset = collect([
        $firstMobileSlideUrl540 ? $firstMobileSlideUrl540.' 540w' : null,
        $firstMobileSlideUrl720 ? $firstMobileSlideUrl720.' 720w' : null,
        $firstMobileSlideUrl1080 ? $firstMobileSlideUrl1080.' 1080w' : null,
    ])->filter()->unique()->implode(', ');
    $firstMobileSlidePreloadUrl = $firstMobileSlide
        ? ($firstMobileSlideUrl1080
            ?? $firstMobileSlideUrl720
            ?? $firstMobileSlideUrl540
            ?? $firstMobileSlide->getUrl())
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
                @if ($firstSlideSrcset !== '') imagesrcset="{{ $firstSlideSrcset }}" imagesizes="(max-width: 1860px) 100vw, 1860px" @endif
                @if ($firstMobileSlidePreloadUrl) media="(min-width: 769px)" @endif
                fetchpriority="high"
            >
            @if ($firstMobileSlidePreloadUrl)
                <link
                    rel="preload"
                    as="image"
                    href="{{ $firstMobileSlidePreloadUrl }}"
                    @if ($firstMobileSlideSrcset !== '') imagesrcset="{{ $firstMobileSlideSrcset }}" imagesizes="100vw" @endif
                    media="(max-width: 768px)"
                    fetchpriority="high"
                >
            @endif
        @endpush
    @endif

    @include('front.partials.splide-assets')

    <section id="{{ $sliderId }}-shell" class="full-width-image-slider-shell {{ $isHomeMainSlider ? 'full-width-image-slider-shell--home-main' : '' }} relative left-1/2 -translate-x-1/2 overflow-hidden {{ $customClasses }}">
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
                            $mobileMedia = $mobileSlides->get($loop->index);
                            $mobileSlideUrl1080 = \App\Support\Media\MediaUrl::conversionOrNull($mobileMedia, 'square_1080w', $preferWebp);
                            $mobileSlideUrl720 = \App\Support\Media\MediaUrl::conversionOrNull($mobileMedia, 'square_720w', $preferWebp);
                            $mobileSlideUrl540 = \App\Support\Media\MediaUrl::conversionOrNull($mobileMedia, 'square_540w', $preferWebp);
                            $mobileSlideUrl = $mobileMedia
                                ? ($mobileSlideUrl1080
                                    ?? $mobileSlideUrl720
                                    ?? $mobileSlideUrl540
                                    ?? $mobileMedia->getUrl())
                                : null;
                            $mobileSlideSrcset = collect([
                                $mobileSlideUrl540 ? $mobileSlideUrl540.' 540w' : null,
                                $mobileSlideUrl720 ? $mobileSlideUrl720.' 720w' : null,
                                $mobileSlideUrl1080 ? $mobileSlideUrl1080.' 1080w' : null,
                            ])->filter()->unique()->implode(', ');
                            $slideLink = trim((string) (
                                data_get($media->custom_properties, 'link_url.'.app()->getLocale())
                                ?: data_get($media->custom_properties, 'link_url.'.config('app.locale'))
                                ?: data_get($media->custom_properties, 'link_url_value', '')
                            ));
                            $slideButtonLabel = trim((string) (
                                data_get($media->custom_properties, 'button_label.'.app()->getLocale())
                                ?: data_get($media->custom_properties, 'button_label.'.config('app.locale'))
                                ?: ''
                            ));
                            $hasSlideLink = $slideLink !== '';
                            $showSlideButton = $slideButtonLabel !== '' && $hasSlideLink;
                            $slideAlt = trim((string) (
                                data_get($media->custom_properties, 'alt.'.app()->getLocale())
                                ?: data_get($media->custom_properties, 'alt.'.config('app.locale'))
                                ?: data_get($mobileMedia?->custom_properties, 'alt.'.app()->getLocale())
                                ?: data_get($mobileMedia?->custom_properties, 'alt.'.config('app.locale'))
                                ?: (($translation?->title ?: $block->name).' '.$loop->iteration)
                            ));
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
                                    <picture class="hero-slide-picture">
                                        @if ($mobileSlideUrl)
                                            <source
                                                media="(max-width: 768px)"
                                                @if ($loop->first)
                                                    srcset="{{ $mobileSlideSrcset !== '' ? $mobileSlideSrcset : $mobileSlideUrl }}"
                                                @else
                                                    data-hero-lazy-srcset="{{ $mobileSlideSrcset !== '' ? $mobileSlideSrcset : $mobileSlideUrl }}"
                                                @endif
                                                sizes="100vw"
                                            >
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
                                            sizes="(max-width: 1860px) 100vw, 1860px"
                                            alt="{{ $slideAlt }}"
                                            class="hero-slide-image bg-slate-100"
                                            width="{{ $slideWidth }}"
                                            height="{{ $slideHeight }}"
                                            decoding="async"
                                        >
                                    </picture>
                                    <div class="absolute inset-0 bg-black/10"></div>
                                    @if (($translation?->title ?? '') !== '' || ($translation?->subtitle ?? '') !== '')
                                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/50 via-black/20 to-transparent px-6 pb-10 pt-16 text-white md:px-12">
                                            @if (($translation?->title ?? '') !== '')
                                                <h2 class="text-3xl font-extrabold tracking-tight md:text-5xl">{{ $translation->title }}</h2>
                                            @endif
                                            @if (($translation?->subtitle ?? '') !== '')
                                                <p class="mt-3 max-w-3xl text-sm text-white/90 md:text-base">{{ $translation->subtitle }}</p>
                                            @endif
                                            @if (! $showSlideButton && ($translation?->cta_label ?? '') !== '' && (($translation?->cta_url ?? '') !== '' || $hasSlideLink))
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

                                @if ($showSlideButton)
                                    <a href="{{ $slideLink }}" class="hero-slide-cta">
                                        {{ $slideButtonLabel }}
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
                                        data-hotspot-x="{{ number_format((float) ($hotspot['x'] ?? 50), 2, '.', '') }}"
                                        data-hotspot-y="{{ number_format((float) ($hotspot['y'] ?? 50), 2, '.', '') }}"
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
                        document.querySelectorAll('[data-slider-hotspot-toggle]').forEach(function (btn) {
                            const x = Math.max(0, Math.min(100, Number.parseFloat(btn.dataset.hotspotX || '50')));
                            const y = Math.max(0, Math.min(100, Number.parseFloat(btn.dataset.hotspotY || '50')));

                            btn.style.left = x + '%';
                            btn.style.top = y + '%';
                        });

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

                                slide.querySelectorAll('[data-hero-lazy-srcset]').forEach(function (node) {
                                    node.setAttribute('srcset', node.dataset.heroLazySrcset);
                                    node.removeAttribute('data-hero-lazy-srcset');
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
