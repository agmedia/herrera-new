@php
    $basePayload = is_array($block->payload ?? null) ? $block->payload : [];
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $payload = array_merge($basePayload, $translationPayload);

    $badge = (string) ($payload['badge'] ?? 'Featured drop');
    $status = (string) ($payload['status'] ?? 'In stock');
    $productLabel = (string) ($payload['product_label'] ?? 'Product');
    $priceLabel = (string) ($payload['price_label'] ?? 'Price');
    $specsLabel = (string) ($payload['specs_label'] ?? 'Top specs');

    $productName = (string) ($payload['product_name'] ?? ($translation?->title ?? 'Transit Pro Backpack'));
    $price = (string) ($payload['price'] ?? 'EUR 89');
    $specs = trim((string) ($payload['specs'] ?? ($translation?->subtitle ?? '')));
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

    $primaryCtaLabel = (string) ($translation?->cta_label ?? ($payload['primary_cta_label'] ?? 'Add to cart'));
    $primaryCtaFallbackUrl = (string) ($translation?->cta_url ?? ($payload['primary_cta_url'] ?? '#'));
    $primaryCtaRoute = (string) ($payload['primary_cta_route'] ?? '');
    $primaryCtaRouteParams = $payload['primary_cta_route_params'] ?? [];
    $primaryCtaUrl = $resolveRouteUrl($primaryCtaRoute, $primaryCtaRouteParams, $primaryCtaFallbackUrl);

    $secondaryCtaLabel = (string) ($payload['secondary_cta_label'] ?? 'Details');
    $secondaryCtaFallbackUrl = (string) ($payload['secondary_cta_url'] ?? '#');
    $secondaryCtaRoute = (string) ($payload['secondary_cta_route'] ?? '');
    $secondaryCtaRouteParams = $payload['secondary_cta_route_params'] ?? [];
    $secondaryCtaUrl = $resolveRouteUrl($secondaryCtaRoute, $secondaryCtaRouteParams, $secondaryCtaFallbackUrl);
@endphp

<section class="overflow-hidden rounded-3xl border border-slate-200/70 bg-gradient-to-br from-blue-700 via-indigo-700 to-sky-600 p-8 text-white shadow-xl md:p-10">
    <div class="grid items-start gap-6 md:grid-cols-2">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-white/85">{{ $badge }}</p>
            <h2 class="mt-3 text-3xl font-extrabold tracking-tight">{{ $productName }}</h2>
            @if ($specs !== '')
                <p class="mt-4 max-w-xl text-base text-white/90">{{ $specs }}</p>
            @endif
        </div>

        <div class="rounded-2xl border border-white/20 bg-white/10 p-5">
            <div class="flex items-center justify-between">
                <span class="text-sm text-white/75">{{ $badge }}</span>
                <span class="text-sm font-semibold text-emerald-200">{{ $status }}</span>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-white/10 p-3">
                    <p class="text-xs uppercase tracking-[0.14em] text-white/70">{{ $productLabel }}</p>
                    <p class="mt-1 text-sm font-semibold">{{ $productName }}</p>
                </div>

                <div class="rounded-xl bg-white/10 p-3">
                    <p class="text-xs uppercase tracking-[0.14em] text-white/70">{{ $priceLabel }}</p>
                    <p class="mt-1 text-sm font-semibold">{{ $price }}</p>
                </div>

                @if ($specs !== '')
                    <div class="col-span-2 rounded-xl bg-white/10 p-3">
                        <p class="text-xs uppercase tracking-[0.14em] text-white/70">{{ $specsLabel }}</p>
                        <p class="mt-1 text-sm font-semibold">{{ $specs }}</p>
                    </div>
                @endif
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <a href="{{ $primaryCtaUrl }}" class="inline-flex items-center justify-center rounded-xl bg-white px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-slate-100">
                    {{ $primaryCtaLabel }}
                </a>
                <a href="{{ $secondaryCtaUrl }}" class="inline-flex items-center justify-center rounded-xl border border-white/30 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10">
                    {{ $secondaryCtaLabel }}
                </a>
            </div>
        </div>
    </div>
</section>
