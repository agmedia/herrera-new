@php
    $cards = $translation->payload['cards'] ?? $block->payload['cards'] ?? [];
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
@endphp

<section class="grid gap-4 md:grid-cols-2">
    @forelse ($cards as $card)
        @php
            $cardUrl = $resolveRouteUrl(
                (string) ($card['route'] ?? ''),
                $card['route_params'] ?? [],
                (string) ($card['url'] ?? '#')
            );
        @endphp
        <article class="rounded-2xl border border-slate-200 bg-white p-5">
            @if (!empty($card['title']))
                <h3 class="text-base font-semibold text-slate-900">{{ $card['title'] }}</h3>
            @endif
            @if (!empty($card['excerpt']))
                <p class="mt-2 text-sm text-slate-600">{{ $card['excerpt'] }}</p>
            @endif
            @if (!empty($card['label']) && $cardUrl !== '')
                <a href="{{ $cardUrl }}" class="mt-4 inline-flex text-sm font-semibold text-slate-900 hover:text-slate-600">{{ $card['label'] }}</a>
            @endif
        </article>
    @empty
        <article class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-500 md:col-span-2">
            No cards configured for this block.
        </article>
    @endforelse
</section>
