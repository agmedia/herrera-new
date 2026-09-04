@php
    use App\Models\Content\Blog\BlogPost;

    $locale = app()->getLocale();
    $fallbackLocale = config('app.locale');
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $blockPayload = is_array($block->payload ?? null) ? $block->payload : [];
    $mergedPayload = array_merge($blockPayload, $translationPayload);
    $allowedRoutes = config('content_blocks.route_whitelist', []);
    $displayTitle = trim((string) ($translation?->title ?? ''));
    $displaySubtitle = trim((string) ($translation?->subtitle ?? ''));
    $sourceCandidate = (string) ($mergedPayload['blog_source'] ?? 'latest');
    $source = in_array($sourceCandidate, ['latest', 'featured'], true)
        ? $sourceCandidate
        : 'latest';
    $limit = max(1, min(12, (int) ($mergedPayload['items_limit'] ?? 6)));

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

    $ctaLabel = trim((string) ($translation?->cta_label ?: __('ui.blog.view_all')));
    $ctaFallbackUrl = (string) ($translation?->cta_url ?: route('blog.index'));
    $ctaRoute = (string) ($mergedPayload['cta_route'] ?? '');
    $ctaRouteParams = $mergedPayload['cta_route_params'] ?? [];
    $ctaUrl = $resolveRouteUrl($ctaRoute, $ctaRouteParams, $ctaFallbackUrl);

    $postsQuery = BlogPost::query()
        ->where('is_active', true)
        ->where(function ($q): void {
            $q->whereNull('published_at')->orWhere('published_at', '<=', now());
        })
        ->with([
            'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
            'media',
        ])
        ->orderByDesc('published_at')
        ->orderByDesc('id');

    if ($source === 'featured') {
        $postsQuery->where('is_featured', true);
    }

    $posts = $postsQuery->limit($limit)->get();
@endphp

<section class="home-news storefront-widget-wide pb-16" data-latest-news>
    <header class="home-news-heading storefront-widget-heading--split">
        <h2 class="storefront-widget-heading-title">{{ $displayTitle }}</h2>
        @if ($displaySubtitle !== '' || ($ctaLabel !== '' && $ctaUrl !== ''))
            <div class="storefront-widget-heading-meta">
                @if ($displaySubtitle !== '')
                    <span>{{ $displaySubtitle }}</span>
                @endif
                @if ($ctaLabel !== '' && $ctaUrl !== '')
                    <a href="{{ $ctaUrl }}" class="storefront-widget-heading-link">{{ $ctaLabel }}</a>
                @endif
            </div>
        @endif
    </header>

    @if ($posts->isNotEmpty())
        @include('front.partials.splide-assets')

        <div>
            <div id="blogs-carousel-{{ $block->id }}" class="splide home-news-carousel" data-blogs-carousel-splide>
                    <div class="splide__track">
                        <div class="splide__list">
                            @foreach ($posts as $post)
                                @php
                                    $postTranslation = $post->translations->firstWhere('locale', $locale)
                                        ?? $post->translations->firstWhere('locale', $fallbackLocale);
                                    $postTitle = (string) ($postTranslation?->title ?? $post->code);
                                    $postExcerpt = (string) ($postTranslation?->excerpt ?? '');
                                    $postSlug = (string) ($postTranslation?->slug ?? $post->id);
                                    $postUrl = route('blog.show', ['slug' => $postSlug]);
                                    $postCover = $post->getFirstMedia('blog_cover');
                                    $postCoverUrl1600 = $postCover
                                        ? \App\Support\Media\MediaUrl::conversionOrNull($postCover, 'cover_1600x2133', $preferWebp)
                                        : null;
                                    $postCoverUrl1200 = $postCover
                                        ? \App\Support\Media\MediaUrl::conversionOrNull($postCover, 'cover_1200x1600', $preferWebp)
                                        : null;
                                    $postCoverUrl900 = $postCover
                                        ? \App\Support\Media\MediaUrl::conversionOrNull($postCover, 'cover_900x1200', $preferWebp)
                                        : null;
                                    $postCoverUrl680 = $postCover
                                        ? \App\Support\Media\MediaUrl::conversionOrNull($postCover, 'cover_680x900', $preferWebp)
                                        : null;
                                    $postCoverUrl520 = $postCover
                                        ? \App\Support\Media\MediaUrl::conversionOrNull($postCover, 'cover_520x700', $preferWebp)
                                        : null;
                                    $postCoverUrl = $postCoverUrl1600 ?? $postCoverUrl1200 ?? $postCoverUrl900 ?? $postCoverUrl680 ?? $postCoverUrl520 ?? ($postCover?->getUrl());
                                    $postCoverSrcset = collect([
                                        $postCoverUrl520 ? $postCoverUrl520.' 520w' : null,
                                        $postCoverUrl680 ? $postCoverUrl680.' 680w' : null,
                                        $postCoverUrl900 ? $postCoverUrl900.' 900w' : null,
                                        $postCoverUrl1200 ? $postCoverUrl1200.' 1200w' : null,
                                        $postCoverUrl1600 ? $postCoverUrl1600.' 1600w' : null,
                                    ])->filter()->unique()->implode(', ');
                                    $postCoverWidth = max(1, (int) ($postCover?->width ?? 900));
                                    $postCoverHeight = max(1, (int) ($postCover?->height ?? 1200));
                                @endphp
                                <div class="splide__slide">
                                    <article class="group h-full bg-white">
                                        <a href="{{ $postUrl }}" class="block">
                                            <div class="aspect-[4/3] overflow-hidden bg-slate-100">
                                                @if ($postCoverUrl)
                                                    <img
                                                        src="{{ $postCoverUrl }}"
                                                        @if ($postCoverSrcset !== '') srcset="{{ $postCoverSrcset }}" @endif
                                                        sizes="(max-width: 639px) 100vw, (max-width: 1023px) 50vw, (max-width: 1279px) 50vw, 33vw"
                                                        alt="{{ $postTitle }}"
                                                        class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                                                        width="{{ $postCoverWidth }}"
                                                        height="{{ $postCoverHeight }}"
                                                        loading="lazy"
                                                        decoding="async"
                                                    >
                                                @else
                                                    <div class="flex h-full w-full items-center justify-center text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.product.no_image') }}</div>
                                                @endif
                                            </div>
                                            <div class="p-4">
                                                @if ($post->published_at)
                                                    <time datetime="{{ $post->published_at->toDateString() }}" class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                        {{ $post->published_at->format('d.m.Y.') }}
                                                    </time>
                                                @endif
                                                <h3 class="mt-2 text-[1.02rem] leading-[1.4rem] font-semibold text-slate-900 min-[541px]:text-lg min-[541px]:leading-snug lg:text-xl">{{ $postTitle }}</h3>
                                                @if ($postExcerpt !== '')
                                                    <p class="mt-2 text-sm text-slate-600">{{ \Illuminate\Support\Str::limit($postExcerpt, 120, '...') }}</p>
                                                @endif
                                            </div>
                                        </a>
                                    </article>
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

                                const sliders = document.querySelectorAll('[data-blogs-carousel-splide]');
                                sliders.forEach(function (el) {
                                    if (el.dataset.splideReady === '1') {
                                        return;
                                    }
                                    el.dataset.splideReady = '1';

                                    const count = el.querySelectorAll('.splide__slide').length;
                                    const desktopPerPage = Math.min(3, Math.max(1, count));
                                    const tabletPerPage = Math.min(2, Math.max(1, count));
                                    const mobilePerPage = 1;
                                    const canSlideDesktop = count > desktopPerPage;
                                    const canSlideTablet = count > tabletPerPage;
                                    const canSlideMobile = count > mobilePerPage;
                                    const mobilePaddingRight = count > 1 ? '18%' : '0';

                                    new window.Splide(el, {
                                        type: 'slide',
                                        rewind: canSlideDesktop,
                                        perPage: desktopPerPage,
                                        perMove: 1,
                                        gap: '1.25rem',
                                        drag: canSlideDesktop,
                                        snap: true,
                                        pagination: canSlideDesktop,
                                        arrows: canSlideDesktop,
                                        updateOnMove: true,
                                        speed: 520,
                                        breakpoints: {
                                            1024: {
                                                rewind: canSlideTablet,
                                                perPage: tabletPerPage,
                                                gap: '1rem',
                                                drag: canSlideTablet,
                                                pagination: canSlideTablet,
                                                arrows: canSlideTablet,
                                            },
                                            640: {
                                                rewind: canSlideMobile,
                                                perPage: mobilePerPage,
                                                gap: '0.8rem',
                                                drag: canSlideMobile,
                                                pagination: canSlideMobile,
                                                arrows: canSlideMobile,
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
    @else
        <div class="bg-slate-50 p-4 text-xs text-slate-500">
            {{ __('ui.blog.empty') }}
        </div>
    @endif
</section>
