@php
    $subcategoryCarouselId = 'category-subcategories-'.$category->id;
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
@endphp

@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-subcategory-carousel.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-subcategory-carousel.css')) }}">
    @endpush
    @push('scripts')
        <script defer src="{{ asset('front-theme/scripts/herrera-subcategory-carousel.js') }}?v={{ filemtime(public_path('front-theme/scripts/herrera-subcategory-carousel.js')) }}"></script>
    @endpush
@endonce

<nav class="herrera-subcategories storefront-container px-3 sm:px-4 lg:px-6" aria-labelledby="{{ $subcategoryCarouselId }}-title" data-herrera-subcategory-carousel>
    <div class="herrera-subcategories-heading">
        <h2 id="{{ $subcategoryCarouselId }}-title">{{ __('herrera.subcategories.title') }}</h2>
        <div class="herrera-subcategories-controls" data-subcategory-controls hidden>
            <button type="button" data-subcategory-previous aria-label="{{ __('herrera.subcategories.previous') }}" aria-controls="{{ $subcategoryCarouselId }}" disabled>
                <x-fa-icon name="chevron-left" style="solid" />
            </button>
            <button type="button" data-subcategory-next aria-label="{{ __('herrera.subcategories.next') }}" aria-controls="{{ $subcategoryCarouselId }}" disabled>
                <x-fa-icon name="chevron-right" style="solid" />
            </button>
        </div>
    </div>
    <ul id="{{ $subcategoryCarouselId }}" class="herrera-subcategories-track" data-subcategory-track tabindex="0" aria-label="{{ __('herrera.subcategories.browse') }}">
        @foreach ($carouselCategories as $carouselCategory)
            @php
                $carouselTranslation = $carouselCategory->translations->firstWhere('locale', $locale)
                    ?? $carouselCategory->translations->firstWhere('locale', $fallbackLocale)
                    ?? $carouselCategory->translations->first();
                $carouselCategoryName = trim((string) ($carouselTranslation?->name ?? $carouselCategory->code));
                $carouselCategoryMedia = collect([
                    $carouselCategory->getFirstMedia('category_banner'),
                    $carouselCategory->getFirstMedia('category_icon'),
                ])->first(fn ($media) => \App\Support\Media\MediaUrl::hasUsableSource($media, ['card_360x240', 'icon_96x96', 'card_192w', 'card_320w', 'square_540w']));
                $carouselCategoryImage = $carouselCategoryMedia
                    ? ($carouselCategoryMedia->collection_name === 'category_banner'
                        ? (\App\Support\Media\MediaUrl::conversionOrNull($carouselCategoryMedia, 'card_360x240', $preferWebp) ?? $carouselCategoryMedia->getUrl())
                        : (\App\Support\Media\MediaUrl::conversionOrNull($carouselCategoryMedia, 'square_540w', $preferWebp)
                            ?? \App\Support\Media\MediaUrl::conversionOrNull($carouselCategoryMedia, 'card_320w', $preferWebp)
                            ?? \App\Support\Media\MediaUrl::conversionOrNull($carouselCategoryMedia, 'card_192w', $preferWebp)
                            ?? \App\Support\Media\MediaUrl::conversionOrNull($carouselCategoryMedia, 'icon_96x96', $preferWebp)
                            ?? $carouselCategoryMedia->getUrl()))
                    : null;
                $carouselCategoryImage ??= \App\Support\Media\HerreraCategoryImage::url($carouselCategory, $locale, $fallbackLocale,
                    (string) ($storeSettings['branding']['store_name'] ?? config('app.name')));
                $carouselCategoryImage ??= \App\Support\Media\LegacyCatalogImage::first($carouselCategory);
            @endphp
            <li class="herrera-subcategories-slide">
                <a href="{{ $catalogCategoryUrl($carouselCategory) }}" class="herrera-subcategory-card" data-subcategory-card="{{ $carouselCategory->id }}">
                    <span class="herrera-subcategory-media">
                        @if ($carouselCategoryImage)
                            <img src="{{ $carouselCategoryImage }}" alt="" width="360" height="360" loading="{{ $loop->index < 4 ? 'eager' : 'lazy' }}" decoding="async">
                        @else
                            <x-fa-icon name="boxes-stacked" style="light" aria-hidden="true" />
                        @endif
                    </span>
                    <span class="herrera-subcategory-caption">{{ $carouselCategoryName }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
