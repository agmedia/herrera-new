@php
    $blogLocale = (string) app()->getLocale();
    $blogFallbackLocale = (string) config('app.locale');
    $blogPayload = array_replace((array) ($block->payload ?? []), (array) ($translation?->payload ?? []));
    $blogTitle = trim((string) ($translation?->title ?: $block->name));
    $blogCtaLabel = trim((string) ($translation?->cta_label ?: __('ui.blog.view_all')));
    $blogCtaUrl = trim((string) ($translation?->cta_url ?: route('blog.index')));
    $blogPosts = \App\Models\Content\Blog\BlogPost::query()
        ->where('is_active', true)
        ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
        ->when(($blogPayload['blog_source'] ?? 'latest') === 'featured', fn ($query) => $query->where('is_featured', true))
        ->with(['translations' => fn ($query) => $query->whereIn('locale', [$blogLocale, $blogFallbackLocale]), 'media'])
        ->orderByDesc('published_at')->orderByDesc('id')
        ->limit(max(1, min(12, (int) ($blogPayload['items_limit'] ?? 6))))->get();
    $blogCount = $blogPosts->count();
    $blogDesktopColumns = min(3, max(1, $blogCount));
    $blogTabletColumns = min(2, max(1, $blogCount));
    $blogPrevious = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $blogNext = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $blogHeadingId = 'herrera-home-blog-title-'.$block->id;
    $blogOptions = [
        'type' => $blogCount > 1 ? 'loop' : 'slide', 'perPage' => $blogDesktopColumns,
        'destroy' => $blogCount <= $blogDesktopColumns, 'perMove' => 1, 'gap' => '20px',
        'focus' => 0, 'padding' => 0, 'autoWidth' => false, 'rewind' => false,
        'drag' => $blogCount > 1, 'snap' => true, 'pagination' => false,
        'arrows' => $blogCount > $blogDesktopColumns, 'updateOnMove' => true, 'speed' => 420,
        'i18n' => ['prev' => $blogPrevious, 'next' => $blogNext, 'first' => $blogNext, 'last' => $blogPrevious],
        'breakpoints' => [
            1023 => ['perPage' => $blogTabletColumns, 'destroy' => $blogCount <= $blogTabletColumns, 'arrows' => $blogCount > $blogTabletColumns, 'padding' => 0],
            767 => ['perPage' => 1, 'destroy' => $blogCount <= 1, 'arrows' => $blogCount > 1, 'padding' => 0],
        ],
    ];
@endphp

@once('herrera-home-b2b-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-b2b.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-b2b.css')) }}">
    @endpush
@endonce
@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-blog.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-blog.css')) }}">
    @endpush
@endonce
@include('front.partials.splide-assets')

<section class="herrera-home-section herrera-b2b-products herrera-home-blog-widget" data-latest-news aria-labelledby="{{ $blogHeadingId }}">
    <div class="splide herrera-home-blog-carousel" @if ($blogPosts->isNotEmpty()) data-herrera-home-carousel @endif data-herrera-blog-carousel data-post-count="{{ $blogCount }}" data-splide='@json($blogOptions)' aria-labelledby="{{ $blogHeadingId }}" style="--blog-columns: {{ $blogDesktopColumns }}; --blog-tablet-columns: {{ $blogTabletColumns }};">
        <div class="herrera-home-heading">
            <div>
                <p class="herrera-home-section-eyebrow">{{ __('herrera.blog.eyebrow') }}</p>
                <h2 id="{{ $blogHeadingId }}">{{ $blogTitle }}</h2>
            </div>
            <div class="herrera-home-product-actions">
                @if ($blogCtaLabel !== '' && $blogCtaUrl !== '')
                    <a class="herrera-home-products-link" href="{{ $blogCtaUrl }}">{{ $blogCtaLabel }} <x-fa-icon name="arrow-right" /></a>
                @endif
                @if ($blogCount > 1)
                    <div class="splide__arrows">
                        <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $blogPrevious }}" disabled><x-fa-icon name="chevron-right" /></button>
                        <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $blogNext }}" disabled><x-fa-icon name="chevron-right" /></button>
                    </div>
                @endif
            </div>
        </div>
        @if ($blogPosts->isNotEmpty())
            <div class="splide__track">
                <ul class="splide__list">
                    @foreach ($blogPosts as $blogItem)
                        <li class="splide__slide">
                            @include('front.partials.herrera-blog-card', ['blogCardPost' => $blogItem, 'blogCardLocale' => $blogLocale, 'blogCardFallbackLocale' => $blogFallbackLocale])
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <p>{{ __('ui.blog.empty') }}</p>
        @endif
    </div>
</section>
@if ($blogPosts->isNotEmpty())
    @include('front.partials.herrera-home-carousel-script')
@endif
