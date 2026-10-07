@php
    $brandStripLocale = (string) app()->getLocale();
    $brandStripFallbackLocale = (string) config('app.locale');
    $brandStripLogoMap = (array) config('herrera-brand-logos', []);
    $brandStripManufacturers = \App\Models\Catalog\Manufacturer\Manufacturer::query()
        ->where('is_active', true)
        ->whereHas('products', fn ($query) => $query->where('is_active', true))
        ->with(['translations', 'media'])
        ->get();
    $brandStripItems = collect($brandStripLogoMap)->map(function ($logoConfig, $brandKey) use ($brandStripManufacturers, $brandStripLocale, $brandStripFallbackLocale) {
        $manufacturer = $brandStripManufacturers->first(function ($item) use ($brandKey) {
            return $item->translations->contains(function ($translation) use ($brandKey) {
                return \Illuminate\Support\Str::slug((string) $translation->name) === $brandKey
                    || (string) $translation->slug === $brandKey;
            }) || \Illuminate\Support\Str::slug((string) $item->code) === $brandKey;
        });

        if (! $manufacturer) {
            return null;
        }

        $translation = $manufacturer->translations->firstWhere('locale', $brandStripLocale)
            ?? $manufacturer->translations->firstWhere('locale', $brandStripFallbackLocale);

        // The manufacturer controller resolves only the current/fallback locale's slug.
        if (! $translation || trim((string) $translation->slug) === '') {
            return null;
        }

        $uploadedLogo = $manufacturer->getFirstMedia('manufacturer_logo');
        $hasUploadedLogo = \App\Support\Media\MediaUrl::hasUsableOriginal($uploadedLogo)
            && is_file($uploadedLogo->getPath());
        $localLogoPath = trim((string) ($logoConfig['path'] ?? ''));

        if (! $hasUploadedLogo && ($localLogoPath === '' || ! is_file(public_path($localLogoPath)))) {
            return null;
        }

        $resolvedLogo = \App\Support\Media\ManufacturerLogo::resolve($manufacturer);

        return [
            'key' => $brandKey,
            'name' => trim((string) ($translation->name ?: $manufacturer->code)),
            'slug' => (string) $translation->slug,
            'logo' => $resolvedLogo['url'],
            'variant' => $resolvedLogo['variant'],
        ];
    })->filter()->values();
@endphp

@if ($brandStripItems->isNotEmpty())
    @once
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-brand-strip.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-brand-strip.css')) }}">
        @endpush
    @endonce

    <section class="herrera-home-section herrera-brand-strip" aria-labelledby="herrera-brand-strip-title">
        <div class="herrera-home-heading herrera-brand-strip__heading">
            <div>
                <p class="herrera-eyebrow">{{ __('herrera.brands_eyebrow') }}</p>
                <h2 id="herrera-brand-strip-title">{{ __('herrera.brands_title') }}</h2>
            </div>
            <a href="{{ route('manufacturers.index') }}">{{ __('herrera.brands_all') }} <span aria-hidden="true">&rarr;</span></a>
        </div>

        <div class="herrera-brand-strip__logos">
            @foreach ($brandStripItems as $brandStripItem)
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
            @endforeach
        </div>
    </section>
@endif
