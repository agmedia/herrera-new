@php
    $locale = app()->getLocale();
    $fallbackLocale = config('app.locale');
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $customClasses = trim((string) ($translationPayload['custom_classes'] ?? ''));
    $slides = $block->getMedia('block_slides')->values();

    $title = trim((string) ($translation?->title ?? '')) !== ''
        ? trim((string) $translation->title)
        : 'Prati nas na Instagramu';
    $subtitle = trim((string) ($translation?->subtitle ?? ''));
    $ctaUrl = trim((string) ($translation?->cta_url ?? ''));
@endphp

@if ($slides->isNotEmpty())
    <section class="relative left-1/2 w-screen max-w-[100vw] -translate-x-1/2 overflow-x-hidden py-10 sm:py-12 {{ $customClasses }}">
        <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-4 pb-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">Instagram</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">{{ $title }}</h2>
                </div>

                @if ($subtitle !== '' && $ctaUrl !== '')
                    <a
                        href="{{ $ctaUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-slate-50 px-4 text-sm font-medium text-slate-700 transition hover:border-slate-500 hover:text-slate-900"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <rect x="3.5" y="3.5" width="17" height="17" rx="5"></rect>
                            <circle cx="12" cy="12" r="4.2"></circle>
                            <circle cx="17.4" cy="6.6" r="1"></circle>
                        </svg>
                        <span>{{ $subtitle }}</span>
                    </a>
                @endif
            </div>
        </div>

        <style>
            #instagram-carousel-{{ $block->id }} {
                position: relative;
            }

            #instagram-carousel-{{ $block->id }} .splide__arrow {
                opacity: 0;
                width: 46px;
                height: 46px;
                border: 1px solid rgba(255, 255, 255, 0.75);
                background: rgba(15, 23, 42, 0.35);
                backdrop-filter: blur(6px);
                transform: translateY(-50%) scale(0.92);
                transition: opacity .25s ease, transform .25s ease, background-color .25s ease;
            }

            #instagram-carousel-{{ $block->id }}:hover .splide__arrow,
            #instagram-carousel-{{ $block->id }}:focus-within .splide__arrow {
                opacity: 1;
                transform: translateY(-50%) scale(1);
            }

            #instagram-carousel-{{ $block->id }} .splide__arrow:hover {
                background: rgba(15, 23, 42, 0.55);
            }

            #instagram-carousel-{{ $block->id }} .splide__arrow svg {
                fill: #fff;
            }

            #instagram-carousel-{{ $block->id }} .splide__track {
                overflow: hidden;
            }

            #instagram-carousel-{{ $block->id }} .splide__pagination {
                bottom: -1.1rem;
                z-index: 10;
            }

            #instagram-carousel-{{ $block->id }} .splide__pagination__page {
                width: 8px;
                height: 8px;
                margin: 0 0.2rem;
                opacity: 1;
                background: #cbd5e1;
                transition: background-color .2s ease, transform .2s ease;
            }

            #instagram-carousel-{{ $block->id }} .splide__pagination__page.is-active {
                transform: none;
                background: #0f172a;
            }

            @media (max-width: 640px) {
                #instagram-carousel-{{ $block->id }} {
                    padding-bottom: 1.5rem;
                }

                #instagram-carousel-{{ $block->id }} .splide__pagination {
                    bottom: 0;
                    pointer-events: auto;
                    z-index: 20;
                }
            }

            @media (hover: none) {
                #instagram-carousel-{{ $block->id }} .splide__arrow {
                    opacity: 1;
                    transform: translateY(-50%) scale(1);
                }
            }
        </style>

        @include('front.partials.splide-assets')

        <div class="mt-6 w-full px-3 sm:px-4 md:px-0">
                <div id="instagram-carousel-{{ $block->id }}" class="splide" data-instagram-grid-splide>
                    <div class="splide__track">
                        <ul class="splide__list">
                            @foreach ($slides as $media)
                                @php
                                    $props = (array) ($media->custom_properties ?? []);
                                    $postUrl = trim((string) (
                                        data_get($props, "link_url.$locale")
                                        ?: data_get($props, "link_url.$fallbackLocale")
                                        ?: data_get($props, 'link_url_value', '')
                                    ));
                                    $label = trim((string) (
                                        data_get($props, "block_title.$locale")
                                        ?: data_get($props, "block_title.$fallbackLocale")
                                    ));
                                    $caption = trim((string) (
                                        data_get($props, "caption.$locale")
                                        ?: data_get($props, "caption.$fallbackLocale")
                                    ));
                                    $alt = trim((string) (
                                        data_get($props, "alt.$locale")
                                        ?: data_get($props, "alt.$fallbackLocale")
                                        ?: $label
                                        ?: $title.' '.$loop->iteration
                                    ));
                                    $overlayText = $label !== '' ? $label : \Illuminate\Support\Str::limit($caption, 78, '...');
                                    $cacheBuster = trim((string) (($media->updated_at?->timestamp ?? time()).'-'.($media->size ?? 0)));
                                    $imageBaseUrl = $media->getUrl();
                                    $imageUrl = $imageBaseUrl.(str_contains($imageBaseUrl, '?') ? '&' : '?').'v='.$cacheBuster;
                                @endphp

                                <li class="splide__slide">
                                    <a
                                        href="{{ $postUrl !== '' ? $postUrl : '#' }}"
                                        @if ($postUrl !== '')
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        @endif
                                        class="group relative block overflow-hidden bg-white"
                                    >
                                        <img
                                            src="{{ $imageUrl }}"
                                            alt="{{ $alt }}"
                                            class="aspect-square w-full object-cover transition duration-500 group-hover:scale-[1.02]"
                                            loading="lazy"
                                        />
                                        <div class="absolute inset-0 bg-slate-950/0 transition duration-300 sm:group-hover:bg-slate-950/34"></div>
                                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/92 via-slate-950/48 to-transparent opacity-100 transition duration-300 sm:opacity-0 sm:group-hover:opacity-100"></div>

                                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950/96 via-slate-950/62 to-transparent px-4 py-3 text-white opacity-100 transition duration-300 sm:translate-y-2 sm:opacity-0 sm:group-hover:translate-y-0 sm:group-hover:opacity-100">
                                            <div class="flex items-end justify-between gap-3">
                                                <p class="text-xs font-medium leading-5">
                                                    {{ $overlayText !== '' ? $overlayText : 'Instagram objava' }}
                                                </p>
                                                <span class="shrink-0 text-[11px] font-semibold uppercase tracking-[0.18em] text-white/80">IG</span>
                                            </div>
                                        </div>
                                    </a>
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

                            const sliders = document.querySelectorAll('[data-instagram-grid-splide]');
                            sliders.forEach(function (el) {
                                if (el.dataset.splideReady === '1') {
                                    return;
                                }

                                const count = el.querySelectorAll('.splide__slide').length;
                                if (!count) {
                                    return;
                                }

                                const desktopPerPage = Math.min(5, Math.max(1, count));
                                const laptopPerPage = Math.min(4, Math.max(1, count));
                                const tabletPerPage = Math.min(3, Math.max(1, count));
                                const compactTabletPerPage = Math.min(2, Math.max(1, count));
                                const mobilePaddingRight = count > 1 ? '18%' : '0';

                                el.dataset.splideReady = '1';

                                new window.Splide(el, {
                                    type: count > 1 ? 'loop' : 'slide',
                                    perPage: desktopPerPage,
                                    perMove: 1,
                                    gap: '1rem',
                                    drag: count > 1,
                                    snap: true,
                                    pagination: false,
                                    arrows: count > desktopPerPage,
                                    updateOnMove: true,
                                    speed: 520,
                                    breakpoints: {
                                        1536: { perPage: desktopPerPage, arrows: count > desktopPerPage },
                                        1280: { perPage: laptopPerPage, arrows: count > laptopPerPage },
                                        1024: { perPage: tabletPerPage, arrows: count > tabletPerPage },
                                        860: { perPage: compactTabletPerPage, gap: '0.9rem', arrows: false },
                                        640: {
                                            perPage: 1,
                                            gap: '0.8rem',
                                            arrows: false,
                                            pagination: count > 1,
                                            padding: { left: '0', right: mobilePaddingRight },
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
    </section>
@endif
