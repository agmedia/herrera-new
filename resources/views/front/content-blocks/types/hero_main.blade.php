@php
    $basePayload = is_array($block->payload ?? null) ? $block->payload : [];
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $payload = array_merge($basePayload, $translationPayload);
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

<section class="rounded-3xl border border-slate-200 bg-gradient-to-br from-slate-50 to-white p-8 md:p-10">
    <div class="mx-auto max-w-4xl">
        @if (!empty($translation?->title))
            <h2 class="text-2xl font-semibold tracking-tight text-slate-900 md:text-3xl">{{ $translation->title }}</h2>
        @endif
        @if (!empty($translation?->subtitle))
            <p class="mt-3 text-base text-slate-600">{{ $translation->subtitle }}</p>
        @endif
        @if ($ctaLabel !== '' && $ctaUrl !== '')
            <a href="{{ $ctaUrl }}" class="mt-6 inline-flex rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                {{ $ctaLabel }}
            </a>
        @endif
        @if (!empty($payload['note']))
            <p class="mt-4 text-xs uppercase tracking-[0.14em] text-slate-500">{{ $payload['note'] }}</p>
        @endif
    </div>
</section>
