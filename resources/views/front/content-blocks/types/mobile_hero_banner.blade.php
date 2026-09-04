@php
    $basePayload = is_array($block->payload ?? null) ? $block->payload : [];
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $payload = array_merge($basePayload, $translationPayload);

    $title = $translation?->title ?: 'Modern essentials';
    $subtitle = trim((string) ($translation?->subtitle ?? ''));
    $ctaLabel = $translation?->cta_label ?: 'Shop';
    $ctaUrl = $translation?->cta_url ?: '#categories';
    $sliderId = 'mobile-hero-slider-'.$block->id;

    $slideClassList = ['bg-19', 'bg-18', 'bg-17', 'bg-20'];
    $customClasses = trim((string) ($payload['custom_classes'] ?? ''));
    if ($customClasses !== '') {
        $slideClassList = preg_split('/\s+/', $customClasses) ?: $slideClassList;
    }
@endphp

@if ($categories->isNotEmpty())
    <div class="splide single-slider slider-no-arrows slider-no-dots" id="{{ $sliderId }}">
        <div class="splide__track">
            <div class="splide__list">
                @foreach ($categories as $index => $category)
                    @php
                        $ct = $category->translations->firstWhere('locale', app()->getLocale())
                            ?? $category->translations->firstWhere('locale', config('app.locale'));
                        $categoryName = $ct?->name ?: $category->code;
                        $slideClass = $slideClassList[$index % max(count($slideClassList), 1)] ?? 'bg-19';
                    @endphp
                    <div class="splide__slide">
                        <div class="card card-style mb-3 {{ $slideClass }}" data-card-height="300">
                            <div class="card-bottom mb-3 ms-3 me-3">
                                <h1 class="color-white font-800 mb-n2">{{ $categoryName }}</h1>
                                @if ($subtitle !== '')
                                    <p class="color-white font-14 mb-2 opacity-60">{{ $subtitle }}</p>
                                @endif
                                <a href="{{ $ctaUrl }}" class="btn btn-xxs rounded-xs bg-white color-black font-700 mt-2">
                                    {{ trim($ctaLabel.' '.$categoryName) }}
                                </a>
                            </div>
                            <div class="card-overlay bg-black opacity-60"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@else
    <div class="card card-style mb-3 bg-19" data-card-height="300">
        <div class="card-bottom mb-3 ms-3 me-3">
            <h1 class="color-white font-800 mb-n2">{{ $title }}</h1>
            @if ($subtitle !== '')
                <p class="color-white font-14 mb-2 opacity-60">{{ $subtitle }}</p>
            @endif
            <a href="{{ $ctaUrl }}" class="btn btn-xxs rounded-xs bg-white color-black font-700 mt-2">{{ $ctaLabel }}</a>
        </div>
        <div class="card-overlay bg-black opacity-60"></div>
    </div>
@endif
