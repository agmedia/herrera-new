@php
    $locale = app()->getLocale();
    $fallbackLocale = (string) config('app.locale');
    $title = trim((string) ($translation?->title ?: __('ui.popular_brands.title')));
    $subtitle = trim((string) ($translation?->subtitle ?? ''));
    $ctaLabel = trim((string) ($translation?->cta_label ?: __('ui.popular_brands.view_all')));
    $ctaUrl = trim((string) ($translation?->cta_url ?: route('manufacturers.index')));
@endphp

<section class="popular-brands storefront-widget-wide" data-popular-brands>
    <header class="popular-brands-heading storefront-widget-heading--split">
        <h2 class="storefront-widget-heading-title">{{ $title }}</h2>
        @if ($subtitle !== '' || ($ctaLabel !== '' && $ctaUrl !== ''))
            <div class="storefront-widget-heading-meta">
                @if ($subtitle !== '')
                    <span>{{ $subtitle }}</span>
                @endif
                @if ($ctaLabel !== '' && $ctaUrl !== '')
                    <a href="{{ $ctaUrl }}" class="storefront-widget-heading-link">{{ $ctaLabel }}</a>
                @endif
            </div>
        @endif
    </header>

    @if ($manufacturers->isNotEmpty())
        <div class="popular-brands-grid">
            @foreach ($manufacturers as $manufacturer)
                @php
                    $manufacturerTranslation = $manufacturer->translations->firstWhere('locale', $locale)
                        ?? $manufacturer->translations->firstWhere('locale', $fallbackLocale)
                        ?? $manufacturer->translations->first();
                    $manufacturerName = trim((string) ($manufacturerTranslation?->name ?? $manufacturer->code));
                    $manufacturerSlug = $manufacturerTranslation?->slug ?? $manufacturer->id;
                    $logo = $manufacturer->getFirstMedia('manufacturer_logo');
                    $uploadedLogoUrl = \App\Support\Media\MediaUrl::hasUsableOriginal($logo)
                        ? (string) $logo->getUrl()
                        : '';
                    $knownLogoUrl = trim((string) config('manufacturer_logos.'.$manufacturer->code, ''));
                    $logoUrl = $uploadedLogoUrl !== '' ? $uploadedLogoUrl : $knownLogoUrl;
                    $nameParts = preg_split('/\s+/u', $manufacturerName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $initials = collect($nameParts)
                        ->take(2)
                        ->map(static fn (string $part): string => mb_substr($part, 0, 1))
                        ->implode('');
                    $initials = \Illuminate\Support\Str::upper(
                        $initials !== '' ? $initials : mb_substr($manufacturerName, 0, 2)
                    );
                    $fallbackLabel = mb_strlen($manufacturerName) <= 18
                        ? $manufacturerName
                        : $initials;
                @endphp

                <a
                    href="{{ route('manufacturers.show', ['slug' => $manufacturerSlug]) }}"
                    class="popular-brand-card"
                    aria-label="{{ __('ui.popular_brands.browse', ['name' => $manufacturerName]) }}"
                    data-popular-brand="{{ $manufacturer->id }}"
                >
                    <span class="popular-brand-logo">
                        @if ($logoUrl !== '')
                            <img
                                src="{{ $logoUrl }}"
                                alt="{{ __('ui.popular_brands.image_alt', ['name' => $manufacturerName]) }}"
                                width="220"
                                height="90"
                                loading="{{ $loop->index < 6 ? 'eager' : 'lazy' }}"
                                decoding="async"
                                referrerpolicy="no-referrer"
                                data-popular-brand-logo
                            >
                            <span class="popular-brand-fallback" aria-hidden="true" data-popular-brand-fallback hidden>
                                {{ $fallbackLabel }}
                            </span>
                        @else
                            <span class="popular-brand-fallback" aria-hidden="true">{{ $fallbackLabel }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>

    @else
        <div class="popular-brands-empty">{{ __('ui.popular_brands.empty') }}</div>
    @endif
</section>

@once
    @push('scripts')
        <script>
            (function () {
                function initializePopularBrandLogos() {
                    document.querySelectorAll('[data-popular-brand-logo]:not([data-logo-ready])').forEach(function (logo) {
                        logo.dataset.logoReady = '1';
                        const fallback = logo.parentElement.querySelector('[data-popular-brand-fallback]');
                        const showFallback = function () {
                            logo.hidden = true;
                            if (fallback) {
                                fallback.hidden = false;
                            }
                        };

                        logo.addEventListener('error', showFallback, { once: true });
                        if (logo.complete && logo.naturalWidth === 0) {
                            showFallback();
                        }
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initializePopularBrandLogos, { once: true });
                } else {
                    initializePopularBrandLogos();
                }
            })();
        </script>
    @endpush
@endonce
