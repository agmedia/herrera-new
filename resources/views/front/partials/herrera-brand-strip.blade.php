@php
    $brandStripLocale = (string) app()->getLocale();
    $brandStripFallbackLocale = (string) config('app.locale');
    $brandStripLogoMap = (array) config('manufacturer-logo-assets', []);
    $brandStripPriority = array_flip(array_keys((array) config('herrera-brand-logos', [])));
    $brandStripManufacturers = \App\Models\Catalog\Manufacturer\Manufacturer::query()
        ->where('is_active', true)
        ->whereHas('products', fn ($query) => $query->where('is_active', true))
        ->with(['translations', 'media'])
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();
    $brandStripItems = $brandStripManufacturers->map(function ($manufacturer) use ($brandStripLogoMap, $brandStripPriority, $brandStripLocale, $brandStripFallbackLocale) {
        $translation = collect([$brandStripLocale, $brandStripFallbackLocale])->unique()
            ->map(fn ($locale) => $manufacturer->translations->firstWhere('locale', $locale))
            ->first(fn ($item) => $item && trim((string) $item->slug) !== '');

        // The manufacturer controller resolves only the current/fallback locale's slug.
        if (! $translation || trim((string) $translation->slug) === '') {
            return null;
        }

        $brandKeys = $manufacturer->translations->flatMap(fn ($item) => [
            (string) $item->slug,
            \Illuminate\Support\Str::slug((string) $item->name),
        ])->push(\Illuminate\Support\Str::slug((string) $manufacturer->code))->unique();
        $localBrandKey = $brandKeys->first(function ($key) use ($brandStripLogoMap) {
            $path = trim((string) ($brandStripLogoMap[$key]['path'] ?? ''));

            return $path !== '' && is_file(public_path($path));
        });
        $hasUploadedLogo = \App\Support\Media\MediaUrl::hasUsableOriginal($manufacturer->getFirstMedia('manufacturer_logo'));

        // Only real uploaded logos or verified local artwork belong in the carousel.
        // Imported manufacturer image fields may contain product photos.
        if (! $hasUploadedLogo && ! $localBrandKey) {
            return null;
        }

        $resolvedLogo = \App\Support\Media\ManufacturerLogo::resolve($manufacturer);
        $preferredBrandKey = $brandKeys->first(fn ($key) => isset($brandStripPriority[$key]));
        $brandKey = $preferredBrandKey ?? $localBrandKey ?? \Illuminate\Support\Str::slug((string) $translation->name);

        return [
            'key' => $brandKey,
            'name' => trim((string) ($translation->name ?: $manufacturer->code)),
            'slug' => (string) $translation->slug,
            'logo' => $resolvedLogo['url'],
            'variant' => $resolvedLogo['variant'],
            'priority' => $brandStripPriority[$brandKey] ?? count($brandStripPriority),
        ];
    })->filter()->sortBy('priority')->values();
    $brandStripCount = $brandStripItems->count();
    $brandStripPrevious = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $brandStripNext = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $brandStripOptions = [
        'type' => $brandStripCount > 1 ? 'loop' : 'slide', 'rewind' => false, 'perPage' => min(7, max(1, $brandStripCount)),
        'perMove' => 1, 'gap' => '12px', 'drag' => $brandStripCount > 1, 'snap' => true,
        'pagination' => false, 'arrows' => $brandStripCount > 1, 'updateOnMove' => true, 'speed' => 420,
        'i18n' => ['prev' => $brandStripPrevious, 'next' => $brandStripNext, 'first' => $brandStripNext, 'last' => $brandStripPrevious],
        'breakpoints' => [
            1439 => ['perPage' => min(6, max(1, $brandStripCount))],
            1023 => ['perPage' => min(4, max(1, $brandStripCount))],
            767 => ['perPage' => min(2, max(1, $brandStripCount))],
        ],
    ];
@endphp

@if ($brandStripItems->isNotEmpty())
    @once
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-brand-strip.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-brand-strip.css')) }}">
        @endpush
    @endonce
    @include('front.partials.splide-assets')

    <section class="herrera-home-section herrera-brand-strip" aria-labelledby="herrera-brand-strip-title">
        <div class="splide herrera-brand-carousel" data-herrera-brand-carousel data-herrera-home-carousel data-splide='@json($brandStripOptions)' aria-labelledby="herrera-brand-strip-title" style="--herrera-brand-columns: {{ $brandStripOptions['perPage'] }}; --herrera-brand-laptop-columns: {{ $brandStripOptions['breakpoints'][1439]['perPage'] }}; --herrera-brand-tablet-columns: {{ $brandStripOptions['breakpoints'][1023]['perPage'] }}; --herrera-brand-mobile-columns: {{ $brandStripOptions['breakpoints'][767]['perPage'] }};">
            <div class="herrera-home-heading herrera-brand-strip__heading">
                <div>
                    <p class="herrera-home-section-eyebrow">{{ __('herrera.brands_eyebrow') }}</p>
                    <h2 id="herrera-brand-strip-title">{{ __('herrera.brands_title') }}</h2>
                </div>
                <div class="herrera-brand-strip__actions">
                    <a class="herrera-brand-strip__all" href="{{ route('manufacturers.index') }}">{{ __('herrera.brands_all') }} <x-fa-icon name="arrow-right" /></a>
                    @if ($brandStripCount > 1)
                        <div class="splide__arrows">
                            <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $brandStripPrevious }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                            <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $brandStripNext }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                        </div>
                    @endif
                </div>
            </div>

            <div class="splide__track">
                <div class="splide__list herrera-brand-strip__logos">
                    @foreach ($brandStripItems as $brandStripItem)
                        <div class="splide__slide">
                            <a
                                class="herrera-brand-strip__brand"
                                href="{{ route('manufacturers.show', ['slug' => $brandStripItem['slug']]) }}"
                                aria-label="{{ __('herrera.brand_browse', ['name' => $brandStripItem['name']]) }}"
                                data-herrera-brand="{{ $brandStripItem['key'] }}"
                            >
                                <span class="herrera-brand-strip__image {{ $brandStripItem['variant'] !== '' ? 'herrera-brand-strip__image--'.$brandStripItem['variant'] : '' }}">
                                    <img src="{{ $brandStripItem['logo'] }}" alt="{{ $brandStripItem['name'] }}" width="160" height="64" loading="lazy" decoding="async">
                                </span>
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
