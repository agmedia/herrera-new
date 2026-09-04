@extends('front.desktop.layouts.store')

@php
    use Illuminate\Support\Str;

    $translation = $post->translations->firstWhere('locale', $locale)
        ?? $post->translations->firstWhere('locale', $fallbackLocale);
    $mediaItems = $post->relationLoaded('media')
        ? $post->media
            ->sortBy(static fn ($mediaItem) => (int) ($mediaItem->order_column ?? 0))
            ->values()
        : collect();
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
    $coverImage = $mediaItems->firstWhere('collection_name', 'blog_cover') ?? $post->getFirstMedia('blog_cover');
    $coverImageUrl = $coverImage?->getUrl();
    $galleryItems = $mediaItems->where('collection_name', 'blog_gallery')->values();
    if ($galleryItems->isEmpty()) {
        $galleryItems = $post->getMedia('blog_gallery')
            ->sortBy(static fn ($mediaItem) => (int) ($mediaItem->order_column ?? 0))
            ->values();
    }
    $galleryCount = $galleryItems->count();
    $galleryColumnsClass = match (true) {
        $galleryCount <= 1 => 'grid-cols-1',
        $galleryCount === 2 => 'grid-cols-1 md:grid-cols-2',
        $galleryCount === 4 => 'grid-cols-1 md:grid-cols-2',
        default => 'grid-cols-1 md:grid-cols-3',
    };
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
    $hotspotProductsById = collect($hotspotProducts ?? [])->mapWithKeys(
        fn ($row, $id) => [(int) $id => (array) $row]
    );
@endphp

@section('title', $translation?->title ?? __('ui.blog.page_title'))
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-8')

@section('content')
    <style>
        [data-blog-gallery-hotspot-root] .ag-hotspot-toggle {
            isolation: isolate;
        }

        [data-blog-gallery-hotspot-root] .ag-hotspot-toggle::before {
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

        [data-blog-gallery-hotspot-root] .ag-hotspot-toggle[aria-expanded="true"]::before {
            opacity: 0;
            animation: none;
        }

        [data-blog-gallery-hotspot-root] .ag-hotspot-panel {
            overflow: visible;
        }

        [data-blog-gallery-hotspot-root] .ag-hotspot-panel > a {
            position: relative;
            z-index: 1;
        }

        [data-blog-gallery-hotspot-root] .ag-hotspot-caret {
            width: 14px;
            height: 18px;
            pointer-events: none;
        }

        [data-blog-gallery-hotspot-root] .ag-hotspot-caret::before,
        [data-blog-gallery-hotspot-root] .ag-hotspot-caret::after {
            content: '';
            position: absolute;
            inset: 0;
            clip-path: polygon(0 50%, 100% 0, 100% 100%);
        }

        [data-blog-gallery-hotspot-root] .ag-hotspot-caret::before {
            background: rgb(226 232 240);
        }

        [data-blog-gallery-hotspot-root] .ag-hotspot-caret::after {
            inset: 1px 0 1px 1px;
            background: #fff;
        }

        [data-blog-gallery-hotspot-root] .ag-hotspot-panel.is-side-right .ag-hotspot-caret {
            left: -13px;
            transform: translateY(-50%);
        }

        [data-blog-gallery-hotspot-root] .ag-hotspot-panel.is-side-left .ag-hotspot-caret {
            right: -13px;
            transform: translateY(-50%) scaleX(-1);
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

    <section class="mb-8 px-1">
        <div class="front-soft-hero px-6 py-4 text-center sm:px-8 sm:py-5">
            <nav aria-label="Breadcrumb" class="mb-2">
                <ol class="flex max-w-full flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-slate-700">{{ __('ui.front.desktop.footer.home') }}</a></li>
                    <li class="text-slate-400">/</li>
                    <li><a href="{{ route('blog.index') }}" class="hover:text-slate-700">{{ __('ui.blog.title') }}</a></li>
                    <li class="text-slate-400">/</li>
                    <li class="max-w-[42ch] truncate text-slate-700">{{ Str::limit((string) ($translation?->title ?? $post->code), 78, '...') }}</li>
                </ol>
            </nav>
            <h1 class="mx-auto mt-1 max-w-4xl text-[1.7rem] font-semibold leading-[1.12] tracking-[-0.01em] text-slate-900 md:text-[2.2rem]">{{ $translation?->title ?? $post->code }}</h1>
            @if (!empty($translation?->excerpt))
                <p class="mx-auto mt-2 max-w-2xl text-sm leading-relaxed text-slate-600">{{ $translation->excerpt }}</p>
            @endif
        </div>
    </section>

    <article class="bg-white px-2 py-2">
        <div class="mx-auto w-full max-w-4xl">
            @if ($post->published_at)
                <p class="mb-4 inline-flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-500">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="17" rx="2"></rect>
                        <line x1="8" y1="2.5" x2="8" y2="6"></line>
                        <line x1="16" y1="2.5" x2="16" y2="6"></line>
                        <line x1="3" y1="9" x2="21" y2="9"></line>
                    </svg>
                    <span>{{ $post->published_at->format('d.m.Y.') }}</span>
                </p>
            @endif

            @if ($coverImageUrl)
                <figure class="mb-8">
                    <img
                        src="{{ $coverImageUrl }}"
                        alt="{{ $translation?->title ?? $post->code }}"
                        class="mx-auto block h-auto max-w-full"
                        loading="eager"
                        decoding="async"
                    >
                </figure>
            @endif

            <div class="content-richtext">
                {!! $translation?->body_html ?: '<p>No body content available.</p>' !!}
            </div>
        </div>
    </article>

    @if ($galleryItems->isNotEmpty())
        <section class="mt-12 border-t border-slate-200 pt-8">
            <div class="grid gap-5 {{ $galleryColumnsClass }}" data-blog-gallery>
                @foreach ($galleryItems as $mediaItem)
                    @php
                        $galleryImageUrl = \App\Support\Media\MediaUrl::conversion($mediaItem, 'detail_960x960', $preferWebp) ?? $mediaItem->getUrl();
                        $galleryHotspots = collect((array) data_get($mediaItem->custom_properties, 'product_hotspots', []))
                            ->map(function ($row) use ($hotspotProductsById): ?array {
                                $row = is_array($row) ? $row : [];
                                $productId = (int) ($row['product_id'] ?? 0);
                                $product = (array) ($hotspotProductsById->get($productId) ?? []);
                                if ($productId <= 0 || $product === []) {
                                    return null;
                                }

                                return [
                                    'product' => $product,
                                    'x' => max(0, min(100, (float) ($row['x'] ?? 50))),
                                    'y' => max(0, min(100, (float) ($row['y'] ?? 50))),
                                ];
                            })
                            ->filter()
                            ->values()
                            ->take(3);
                    @endphp
                    <div class="relative block aspect-[3/4] overflow-hidden bg-slate-100" data-blog-gallery-hotspot-root>
                        <a
                            href="{{ $galleryImageUrl }}"
                            class="block h-full w-full"
                            data-blog-gallery-item
                            data-sub-html="{{ $translation?->title ?? $post->code }}"
                        >
                            <img
                                src="{{ $galleryImageUrl }}"
                                alt="{{ $translation?->title ?? $post->code }}"
                                class="h-full w-full object-cover"
                                loading="lazy"
                                decoding="async"
                            >
                        </a>

                        @foreach ($galleryHotspots as $hotspotIndex => $hotspot)
                            @php
                                $hotspotDomId = 'blog-hotspot-'.$post->id.'-'.$mediaItem->id.'-'.$hotspotIndex;
                                $hotspotProduct = (array) ($hotspot['product'] ?? []);
                            @endphp
                            <button
                                type="button"
                                class="ag-hotspot-toggle absolute z-20 inline-flex h-9 w-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-white/90 bg-white text-[22px] font-light leading-none text-slate-900 shadow-md transition-colors hover:bg-slate-200"
                                style="left: {{ number_format(max(3, min(97, (float) ($hotspot['x'] ?? 50))), 2, '.', '') }}%; top: {{ number_format(max(3, min(97, (float) ($hotspot['y'] ?? 50))), 2, '.', '') }}%;"
                                data-blog-hotspot-toggle
                                data-target="{{ $hotspotDomId }}"
                                aria-expanded="false"
                                aria-controls="{{ $hotspotDomId }}"
                            >
                                +
                            </button>

                            <div
                                id="{{ $hotspotDomId }}"
                                class="ag-hotspot-panel absolute z-30 hidden w-[210px] rounded-xl border border-slate-200 bg-white shadow-xl"
                                data-anchor-x="{{ number_format(max(3, min(97, (float) ($hotspot['x'] ?? 50))), 2, '.', '') }}"
                                data-anchor-y="{{ number_format(max(3, min(97, (float) ($hotspot['y'] ?? 50))), 2, '.', '') }}"
                                data-blog-hotspot-panel
                            >
                                <span
                                    class="ag-hotspot-caret absolute"
                                    data-blog-hotspot-caret
                                    aria-hidden="true"
                                ></span>
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
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if (($relatedProducts ?? collect())->isNotEmpty())
        <section class="mt-16" data-blog-related-products>
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <x-storefront-section-heading class="mb-7">
                    {{ __('ui.product.related') }}
                </x-storefront-section-heading>

                <style>
                    #blog-related-products-carousel-{{ $post->id }} {
                        visibility: visible;
                    }

                    #blog-related-products-carousel-{{ $post->id }} .splide__track {
                        overflow: hidden;
                        border-left: 1px solid #e2e8f0;
                    }

                    #blog-related-products-carousel-{{ $post->id }} .splide__list {
                        gap: 0 !important;
                    }

                    #blog-related-products-carousel-{{ $post->id }} .splide__slide {
                        min-width: 0;
                        border-top: 1px solid #e2e8f0;
                        border-right: 1px solid #e2e8f0;
                        border-bottom: 1px solid #e2e8f0;
                    }

                    #blog-related-products-carousel-{{ $post->id }} .splide__arrow {
                        opacity: 0;
                        width: 42px;
                        height: 42px;
                        border-radius: 9999px;
                        border: 1px solid #d7dde5;
                        background: rgba(255, 255, 255, 0.94);
                        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.12);
                        transform: translateY(-50%) scale(0.92);
                        transition: opacity .2s ease, transform .2s ease, border-color .2s ease, background-color .2s ease;
                    }

                    #blog-related-products-carousel-{{ $post->id }}:hover .splide__arrow,
                    #blog-related-products-carousel-{{ $post->id }}:focus-within .splide__arrow {
                        opacity: 1;
                        transform: translateY(-50%) scale(1);
                    }

                    #blog-related-products-carousel-{{ $post->id }} .splide__arrow:hover {
                        border-color: var(--navigation-background-color, #e65100);
                        background: var(--navigation-background-color, #e65100);
                    }

                    #blog-related-products-carousel-{{ $post->id }} .splide__arrow:hover svg {
                        fill: #fff;
                    }

                    @media (hover: none) {
                        #blog-related-products-carousel-{{ $post->id }} .splide__arrow {
                            opacity: 1;
                            transform: translateY(-50%) scale(1);
                        }
                    }
                </style>

                <div class="mt-4">
                    <div id="blog-related-products-carousel-{{ $post->id }}" class="splide" data-blog-related-products-splide>
                        <div class="splide__track">
                            <ul class="splide__list">
                                @foreach ($relatedProducts as $relatedProduct)
                                    <li class="splide__slide">
                                        @include('front.desktop.partials.product-card', [
                                            'product' => $relatedProduct,
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
                </div>
            </div>
        </section>
    @endif

@endsection

@push('scripts')
    @include('front.partials.splide-assets')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lightgallery@2.7.2/css/lightgallery-bundle.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/lightgallery@2.7.2/lightgallery.min.js"></script>
    <script defer>
        document.addEventListener('DOMContentLoaded', function () {
            const galleryRoot = document.querySelector('[data-blog-gallery]');
            if (galleryRoot && typeof window.lightGallery === 'function') {
                window.lightGallery(galleryRoot, {
                    selector: '[data-blog-gallery-item]',
                    download: false,
                    counter: true,
                });
            }

            const hotspotToggles = document.querySelectorAll('[data-blog-hotspot-toggle]');
            const positionHotspotPanel = function (btn, panel) {
                const root = btn.closest('[data-blog-gallery-hotspot-root]');
                const caret = panel.querySelector('[data-blog-hotspot-caret]');
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

                const rawLeft = side === 'right'
                    ? anchorX + gap
                    : anchorX - gap - panelWidth;
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

            const closeAllHotspots = function () {
                document.querySelectorAll('[data-blog-hotspot-panel]').forEach(function (panel) {
                    panel.classList.add('hidden');
                });
                document.querySelectorAll('[data-blog-hotspot-toggle]').forEach(function (btn) {
                    btn.setAttribute('aria-expanded', 'false');
                });
            };

            hotspotToggles.forEach(function (btn) {
                btn.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const targetId = btn.getAttribute('data-target') || '';
                    if (!targetId) {
                        return;
                    }

                    const panel = document.getElementById(targetId);
                    if (!panel) {
                        return;
                    }

                    const willOpen = panel.classList.contains('hidden');
                    closeAllHotspots();
                    if (willOpen) {
                        panel.classList.remove('hidden');
                        positionHotspotPanel(btn, panel);
                        btn.setAttribute('aria-expanded', 'true');
                    }
                });
            });

            document.addEventListener('click', function (event) {
                if (event.target.closest('[data-blog-hotspot-panel]') || event.target.closest('[data-blog-hotspot-toggle]')) {
                    return;
                }
                closeAllHotspots();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeAllHotspots();
                }
            });

            const initRelatedProductSliders = function () {
                if (typeof window.Splide !== 'function') {
                    return false;
                }

                const sliders = document.querySelectorAll('[data-blog-related-products-splide]');
                sliders.forEach(function (el) {
                    if (el.dataset.splideReady === '1') {
                        return;
                    }

                    const count = el.querySelectorAll('.splide__slide').length;
                    if (count === 0) {
                        return;
                    }

                    el.dataset.splideReady = '1';
                    const mobilePerPage = {{ $mobileDefaultCols }};
                    const preferredDesktopPerPage = {{ $preferredGridCols }};
                    new window.Splide(el, {
                        type: count > preferredDesktopPerPage ? 'loop' : 'slide',
                        perPage: Math.min(Math.max(1, preferredDesktopPerPage), count),
                        perMove: 1,
                        gap: '0rem',
                        drag: count > 1,
                        snap: true,
                        pagination: false,
                        arrows: count > preferredDesktopPerPage,
                        updateOnMove: true,
                        speed: 520,
                        breakpoints: {
                            1280: {
                                perPage: Math.min(4, count),
                                arrows: count > 4,
                            },
                            1024: {
                                perPage: Math.min(3, count),
                                arrows: count > 3,
                            },
                            860: {
                                perPage: Math.min(mobilePerPage, count),
                                arrows: count > mobilePerPage,
                            },
                            640: {
                                perPage: Math.min(mobilePerPage, count),
                                arrows: false,
                                pagination: count > mobilePerPage,
                            },
                        },
                    }).mount();
                });

                return true;
            };

            if (!initRelatedProductSliders()) {
                let attempts = 0;
                const timer = window.setInterval(function () {
                    attempts += 1;
                    if (initRelatedProductSliders() || attempts > 40) {
                        window.clearInterval(timer);
                    }
                }, 120);
            }
        });
    </script>
@endpush
