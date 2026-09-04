@if ($categories->isEmpty())
    <div class="category-index-empty">{{ __('ui.category_index.empty') }}</div>
@else
    <div class="category-index-grid">
        @foreach ($categories as $category)
            @php
                $translation = $category->translations->firstWhere('locale', $locale)
                    ?? $category->translations->firstWhere('locale', $fallbackLocale)
                    ?? $category->translations->first();
                $categoryName = trim((string) ($translation?->name ?? $category->code));
                $categoryUrl = route('categories.show', ['slug' => $translation?->slug ?? $category->id]);
                $categoryMedia = $category->getFirstMedia('category_banner')
                    ?? $category->getFirstMedia('category_icon');
                $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
                $categoryImageUrl = null;
                $categoryImageSrcset = '';
                $categoryImageWidth = 520;
                $categoryImageHeight = 390;

                if ($categoryMedia) {
                    if ($categoryMedia->collection_name === 'category_banner') {
                        $cardUrl = \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'card_360x240', $preferWebp);
                        $categoryImageUrl = $cardUrl ?? $categoryMedia->getUrl();
                        $categoryImageSrcset = $cardUrl ? $cardUrl.' 360w' : '';
                    } else {
                        $iconUrl96 = \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'icon_96x96', $preferWebp);
                        $iconUrl192 = \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'card_192w', $preferWebp);
                        $iconUrl320 = \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'card_320w', $preferWebp);
                        $iconUrl540 = \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'square_540w', $preferWebp);
                        $categoryImageUrl = $iconUrl540 ?? $iconUrl320 ?? $iconUrl192 ?? $iconUrl96 ?? $categoryMedia->getUrl();
                        $categoryImageSrcset = collect([
                            $iconUrl96 ? $iconUrl96.' 96w' : null,
                            $iconUrl192 ? $iconUrl192.' 192w' : null,
                            $iconUrl320 ? $iconUrl320.' 320w' : null,
                            $iconUrl540 ? $iconUrl540.' 540w' : null,
                        ])->filter()->unique()->implode(', ');
                        $categoryImageWidth = 540;
                        $categoryImageHeight = 540;
                    }
                }
                $categoryImageAlt = $categoryMedia
                    ? trim((string) $categoryMedia->getCustomProperty('alt.'.$locale))
                    : '';

                if ($categoryImageAlt === '' && $categoryMedia) {
                    $categoryImageAlt = trim((string) $categoryMedia->getCustomProperty('alt.'.$fallbackLocale));
                }

                if ($categoryImageAlt === '') {
                    $categoryImageAlt = __('ui.category_index.image_alt', ['name' => $categoryName]);
                }
            @endphp

            <a
                href="{{ $categoryUrl }}"
                class="category-index-card"
                data-category-card="{{ $category->id }}"
                aria-label="{{ $categoryName }}"
            >
                <span class="category-index-card-media">
                    @if ($categoryImageUrl)
                        <img
                            src="{{ $categoryImageUrl }}"
                            @if ($categoryImageSrcset !== '') srcset="{{ $categoryImageSrcset }}" @endif
                            sizes="(max-width: 639px) 100vw, (max-width: 1023px) 50vw, 33vw"
                            alt="{{ $categoryImageAlt }}"
                            width="{{ $categoryImageWidth }}"
                            height="{{ $categoryImageHeight }}"
                            loading="{{ $loop->index < 4 ? 'eager' : 'lazy' }}"
                            decoding="async"
                        >
                    @else
                        <span class="category-index-card-placeholder" aria-hidden="true">
                            <svg viewBox="0 0 64 64" fill="none">
                                <path d="M14 21.5 32 12l18 9.5v21L32 52l-18-9.5v-21Z" stroke="currentColor" stroke-width="2.5"/>
                                <path d="m14 21.5 18 10 18-10M32 31.5V52" stroke="currentColor" stroke-width="2.5"/>
                            </svg>
                        </span>
                    @endif
                </span>

                <span class="category-index-card-content">
                    <span class="category-index-card-copy">
                        <span class="category-index-card-title">{{ $categoryName }}</span>
                        <span class="category-index-card-count">
                            {{ trans_choice('ui.category_index.subcategory_count', (int) $category->subcategories_count, ['count' => (int) $category->subcategories_count]) }}
                        </span>
                    </span>

                    <span class="category-index-card-arrow" aria-hidden="true">
                        <svg viewBox="0 0 20 20" fill="none">
                            <path d="M4 10h11m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                </span>
            </a>
        @endforeach
    </div>
@endif
