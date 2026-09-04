@php
    $basePayload = is_array($block->payload ?? null) ? $block->payload : [];
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $payload = array_merge($basePayload, $translationPayload);

    $sectionClass = (string) ($payload['section_class'] ?? 'relative overflow-hidden rounded-3xl border border-slate-200/70 p-8 md:p-12');
    $contentClass = (string) ($payload['content_class'] ?? 'relative z-10 max-w-3xl');
    $textClass = (string) ($payload['text_class'] ?? 'text-slate-900');
    $titleClass = (string) ($payload['title_class'] ?? 'text-4xl font-extrabold tracking-tight md:text-5xl');
    $subtitleClass = (string) ($payload['subtitle_class'] ?? 'mt-4 text-lg text-slate-700');
    $ctaClass = (string) ($payload['cta_class'] ?? 'mt-8 inline-flex rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800');
    $overlayClass = (string) ($payload['overlay_class'] ?? 'absolute inset-0 bg-gradient-to-br from-white/80 via-white/60 to-white/40');
    $bgCss = trim((string) ($payload['bg_css'] ?? ''));

    $backgroundUrl = $block->getFirstMediaUrl('block_background', 'hero_1200w');
    if ($backgroundUrl === '') {
        $backgroundUrl = $block->getFirstMediaUrl('block_background');
    }

    $bgStyle = $bgCss;
    if ($backgroundUrl !== '') {
        $bgImageCss = "background-image:url('".e($backgroundUrl)."');background-size:contain;background-repeat:no-repeat;background-position:center;";
        $bgStyle = trim($bgImageCss.' '.$bgCss);
    }

    $allowedRoutes = config('content_blocks.route_whitelist', []);
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

    $ctaLabel = (string) ($translation?->cta_label ?? '');
    $ctaFallbackUrl = (string) ($translation?->cta_url ?? '#');
    $ctaRoute = (string) ($payload['cta_route'] ?? '');
    $ctaRouteParams = $payload['cta_route_params'] ?? [];
    $ctaUrl = $resolveRouteUrl($ctaRoute, $ctaRouteParams, $ctaFallbackUrl);
@endphp

<section class="{{ $sectionClass }}" @if ($bgStyle !== '') style="{{ $bgStyle }}" @endif>
    <div class="{{ $overlayClass }}"></div>
    <div class="{{ $contentClass }} {{ $textClass }}">
        @if (!empty($translation?->title))
            <h2 class="{{ $titleClass }}">{!! nl2br(e($translation->title)) !!}</h2>
        @endif
        @if (!empty($translation?->subtitle))
            <p class="{{ $subtitleClass }}">{{ $translation->subtitle }}</p>
        @endif
        @if ($ctaLabel !== '' && $ctaUrl !== '')
            <a href="{{ $ctaUrl }}" class="{{ $ctaClass }}">{{ $ctaLabel }}</a>
        @endif
    </div>
</section>
