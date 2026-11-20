@php
    $locale = (string) ($locale ?? app()->getLocale());
    $fallbackLocale = (string) ($fallbackLocale ?? config('app.locale'));
    if (! isset($categories)) {
        $rootCategories = \App\Models\Catalog\Category\Category::query()
            ->where('scope', \App\Models\Catalog\Category\Category::SCOPE_CATALOG)
            ->currentlyVisible()
            ->whereNull('parent_id')
            ->with(['translations', 'media'])
            ->orderBy('sort_order')
            ->orderBy('id');
        if (request()->routeIs('home')) {
            app(\App\Services\Content\FeaturedCategoriesService::class)->excludeHomeShortcuts($rootCategories);
        }
        $categories = $rootCategories->get();
    }
    $categoryTitle = trim((string) ($title ?? __('herrera.categories')));
    $categorySubtitle = trim((string) ($subtitle ?? ''));
    $categoryCtaLabel = trim((string) ($ctaLabel ?? __('herrera.all_categories')));
    $categoryCtaUrl = trim((string) ($ctaUrl ?? route('categories.index')));
    $showCategoryModuleHeading = (bool) ($showCategoryHeading ?? true);
    $supportFields = $categorySupportFields ?? [];
    $showHerreraCategorySupport = (bool) ($showCategorySupport ?? request()->routeIs('home'))
        && (bool) ($supportFields['support_visible'] ?? true)
        && str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera');
    $preferWebp = (bool) ($storeSettings['images']['use_webp'] ?? false);
    $categoryIcons = [
        'rasvjeta' => 'lightbulb',
        'elektromaterijal' => 'bolt',
        'uticnice-i-prekidaci' => 'outlet',
        'produzni-kabeli-i-motalice' => 'reel',
        'oprema-za-mjerenje-i-ispitivanje' => 'ruler',
        'senzori' => 'sensor',
        'alati' => 'screwdriver-wrench',
        'mali-kucanski-aparati' => 'kitchen-set',
        'ljepota-i-zdravlje' => 'heart-pulse',
        'internet-audio-video-tv' => 'tv',
        'baterije' => 'battery-half',
        'dom-i-dizajn' => 'house',
        'grijanje-hladenje-i-zastita-od-insekata' => 'temperature-half',
        'osobna-zastitna-oprema' => 'helmet-safety',
        'vodovodni-materijal-i-sanitarije' => 'faucet',
        'punionice-za-ev-vozila' => 'car-bolt',
        'extra-popusti' => 'tag',
        'zeljezarija' => 'toolbox',
        'svijece-i-upaljaci' => 'candle-holder',
    ];
@endphp

@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-categories.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-categories.css')) }}">
    @endpush
@endonce

@if ($categories->isNotEmpty())
    <section class="herrera-category-module" data-herrera-category-module data-featured-categories>
        @if ($showCategoryModuleHeading)
        <header class="herrera-category-module-heading">
            <div>
                @if ($categoryTitle !== '')
                    <h2>{{ $categoryTitle }}</h2>
                @endif
                @if ($categorySubtitle !== '')
                    <p>{{ $categorySubtitle }}</p>
                @endif
            </div>
            @if ($categoryCtaLabel !== '' && $categoryCtaUrl !== '')
                <a href="{{ $categoryCtaUrl }}">{{ $categoryCtaLabel }} <x-fa-icon name="arrow-right" /></a>
            @endif
        </header>
        @endif
            @if (request()->routeIs('home') && str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera'))
                @php
                    $clearanceId = app(\App\Services\Catalog\Clearance\ClearanceService::class)->settings()['category_id'];
                    $saleCategory = \App\Models\Catalog\Category\Category::where('scope', 'catalog')->currentlyVisible()
                        ->when($clearanceId, fn ($q) => $q->whereKey($clearanceId), fn ($q) => $q->where(fn ($match) => $match->whereIn('code', ['rasprodaja', 'clearance'])->orWhereHas('translations', fn ($t) => $t->where('slug', 'rasprodaja'))))
                        ->with('translations')->first();
                    $saleSlug = $saleCategory?->translations->firstWhere('locale', $locale)?->slug ?? $saleCategory?->translations->first()?->slug;
                @endphp
                <nav aria-label="Posebne ponude" class="mb-3 grid grid-cols-2 gap-3">
                @if ($saleSlug)
                    <a class="herrera-category-tile is-icon-only" href="{{ route('categories.show', ['slug' => $saleSlug]) }}"><span class="herrera-category-tile-media"><x-fa-icon name="tag" class="herrera-category-tile-fallback" /></span><span class="herrera-category-tile-caption">Rasprodaja</span></a>
                @endif
                <a class="herrera-category-tile is-icon-only" href="{{ route('shop.index', ['new_arrivals' => 1]) }}"><span class="herrera-category-tile-media"><x-fa-icon name="star" class="herrera-category-tile-fallback" /></span><span class="herrera-category-tile-caption">NOVO u ponudi</span></a>
                </nav>
            @endif
        <div class="herrera-category-module-grid category-index-grid">
            @foreach ($categories as $category)
                @php
                    $categoryTranslation = $category->translations->firstWhere('locale', $locale)
                        ?? $category->translations->firstWhere('locale', $fallbackLocale)
                        ?? $category->translations->first();
                    $categoryName = trim((string) ($categoryTranslation?->name ?? $category->code));
                    $categorySlug = (string) ($categoryTranslation?->slug ?? $category->id);
                    $iconSlug = (string) ($category->translations->firstWhere('locale', 'hr')?->slug ?? $categorySlug);
                    $categoryIcon = $categoryIcons[$iconSlug] ?? 'boxes-stacked';
                    $categoryMedia = collect([
                        $category->getFirstMedia('category_banner'),
                        $category->getFirstMedia('category_icon'),
                    ])->first(fn ($media) => \App\Support\Media\MediaUrl::hasUsableSource($media, ['card_360x240', 'icon_96x96', 'card_192w', 'card_320w', 'square_540w']));
                    $categoryImageUrl = $categoryMedia
                        ? ($categoryMedia->collection_name === 'category_banner'
                            ? (\App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'card_360x240', $preferWebp) ?? $categoryMedia->getUrl())
                            : (\App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'square_540w', $preferWebp)
                                ?? \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'card_320w', $preferWebp)
                                ?? \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'card_192w', $preferWebp)
                                ?? \App\Support\Media\MediaUrl::conversionOrNull($categoryMedia, 'icon_96x96', $preferWebp)
                                ?? $categoryMedia->getUrl()))
                        : null;
                    $categoryPhotoPath = ltrim((string) config('herrera-category-photography.'.$iconSlug, ''), '/');
                    if (! $categoryImageUrl && $categoryPhotoPath !== '' && is_file(public_path($categoryPhotoPath))) {
                        $categoryImageUrl = asset($categoryPhotoPath);
                    }
                    $categoryImageUrl ??= \App\Support\Media\LegacyCatalogImage::first($category);
                @endphp
                <a href="{{ route('categories.show', ['slug' => $categorySlug]) }}" class="herrera-category-tile {{ $categoryImageUrl ? 'has-image' : 'is-icon-only' }}" data-category-card="{{ $category->id }}" data-featured-category="{{ $category->id }}">
                    <span class="herrera-category-tile-media">
                        @if ($categoryImageUrl)
                            <img src="{{ $categoryImageUrl }}" alt="" width="144" height="144" loading="lazy" decoding="async">
                            <span class="herrera-category-tile-icon"><x-fa-icon :name="$categoryIcon" style="light" /></span>
                        @else
                            <x-fa-icon :name="$categoryIcon" style="light" class="herrera-category-tile-fallback" />
                        @endif
                    </span>
                    <span class="herrera-category-tile-caption">
                        <span class="herrera-category-tile-name">{{ $categoryName }}</span>
                        <x-fa-icon name="arrow-up-right" class="herrera-category-tile-arrow" />
                    </span>
                </a>
            @endforeach
            @if ($showHerreraCategorySupport)
                @php
                    $supportEyebrow = (string) ($supportFields['support_eyebrow'] ?? __('herrera.category_support.eyebrow'));
                    $supportTitle = (string) ($supportFields['support_title'] ?? __('herrera.category_support.title'));
                    $supportDescription = (string) ($supportFields['support_description'] ?? __('herrera.category_support.description'));
                    $supportContactLabel = (string) ($supportFields['support_contact_label'] ?? __('herrera.category_support.contact'));
                    $supportRegisterLabel = (string) ($supportFields['support_register_label'] ?? __('herrera.category_support.register'));
                    $supportContactUrl = \App\Support\HerreraHomeWidgets::safeUrl($supportFields['support_contact_url'] ?? null, route('contact.create'));
                    $supportRegisterUrl = \App\Support\HerreraHomeWidgets::safeUrl($supportFields['support_register_url'] ?? null, route('front.auth.b2b-register'));
                @endphp
                <article class="herrera-category-tile herrera-category-support" aria-label="{{ $supportTitle ?: __('herrera.category_support.title') }}" data-herrera-category-support>
                    <div class="herrera-category-support-copy">
                        @if ($supportEyebrow !== '')<p class="herrera-category-support-eyebrow">{{ $supportEyebrow }}</p>@endif
                        @if ($supportTitle !== '')<h3>{{ $supportTitle }}</h3>@endif
                        @if ($supportDescription !== '')<p class="herrera-category-support-description">{{ $supportDescription }}</p>@endif
                    </div>
                    @if ($supportContactLabel !== '' || $supportRegisterLabel !== '')
                        <div class="herrera-category-support-actions">
                            @if ($supportContactLabel !== '')<a class="herrera-category-support-contact" href="{{ $supportContactUrl }}">{{ $supportContactLabel }}</a>@endif
                            @if ($supportRegisterLabel !== '')<a class="herrera-category-support-register" href="{{ $supportRegisterUrl }}">{{ $supportRegisterLabel }}</a>@endif
                        </div>
                    @endif
                </article>
            @endif
        </div>
    </section>
@endif
