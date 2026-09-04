@php
    use App\Models\Catalog\Category\Category;
    use App\Models\Content\Blog\BlogPost;

    $payload = $block->payload ?? [];
    $source = ($payload['source'] ?? 'manual') === 'query' ? 'query' : 'manual';
    $limit = max(1, min(12, (int) ($payload['limit'] ?? 3)));
    $sort = (string) ($payload['sort'] ?? 'newest');
    $locale = app()->getLocale();
    $fallbackLocale = config('app.locale');
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $mergedPayload = is_array($payload) ? array_merge($payload, $translationPayload) : $translationPayload;
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

    $manualIds = collect($payload['manual_blog_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values()->all();
    $categoryIds = collect($payload['category_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values()->all();

    $query = BlogPost::query()
        ->where('is_active', true)
        ->where(function ($q): void {
            $q->whereNull('published_at')->orWhere('published_at', '<=', now());
        })
        ->with(['translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale])]);

    if ($source === 'manual' && $manualIds !== []) {
        $query->whereIn('id', $manualIds);
    } else {
        if ($categoryIds !== []) {
            $query->whereHas('categories', function ($q) use ($categoryIds): void {
                $q->where('categories.scope', Category::SCOPE_BLOG)
                    ->whereIn('categories.id', $categoryIds);
            });
        }
    }

    if ($sort === 'featured') {
        $query->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id');
    } elseif ($sort === 'title') {
        $query->join('content_blog_post_translations as bt_sort', function ($join) use ($locale) {
            $join->on('bt_sort.post_id', '=', 'content_blog_posts.id')->where('bt_sort.locale', '=', $locale);
        })->orderBy('bt_sort.title')->select('content_blog_posts.*');
    } else {
        $query->orderByDesc('published_at')->orderByDesc('id');
    }

    $posts = $query->limit($limit)->get();

    if ($source === 'manual' && $manualIds !== []) {
        $rank = array_flip($manualIds);
        $posts = $posts->sortBy(fn ($item) => $rank[$item->id] ?? PHP_INT_MAX)->values();
    }

    $ctaLabel = (string) ($translation?->cta_label ?? '');
    $ctaFallbackUrl = (string) ($translation?->cta_url ?? '#');
    $ctaRoute = (string) ($mergedPayload['cta_route'] ?? '');
    $ctaRouteParams = $mergedPayload['cta_route_params'] ?? [];
    $ctaUrl = $resolveRouteUrl($ctaRoute, $ctaRouteParams, $ctaFallbackUrl);
@endphp

<section class="rounded-2xl border border-slate-200 bg-white p-6">
    <div class="mb-4 flex items-center justify-between gap-3">
        <div>
            <h2 class="text-[1.35rem] leading-[1.95rem] sm:text-[1.7rem] sm:leading-[2.5rem] uppercase font-semibold text-slate-900">{{ $translation->title ?? $block->name }}</h2>
            @if (!empty($translation?->subtitle))
                <p class="mt-1 text-sm text-slate-600">{{ $translation->subtitle }}</p>
            @endif
        </div>
        @if ($ctaLabel !== '' && $ctaUrl !== '')
            <a href="{{ $ctaUrl }}" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ $ctaLabel }}</a>
        @endif
    </div>

    <div class="grid gap-3 md:grid-cols-3">
        @forelse ($posts as $post)
            @php
                $pt = $post->translations->firstWhere('locale', $locale)
                    ?? $post->translations->firstWhere('locale', $fallbackLocale);
                $excerpt = $pt?->meta_description ?: $pt?->excerpt;
            @endphp
            <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div class="h-32 rounded-lg bg-gradient-to-br from-slate-200 to-slate-100"></div>
                <h3 class="mt-3 text-sm font-semibold text-slate-900">{{ $pt?->title ?? $post->code }}</h3>
                @if (!empty($excerpt))
                    <p class="mt-1 text-xs text-slate-600">{{ \Illuminate\Support\Str::limit((string) $excerpt, 80, '...') }} <span class="font-semibold">more</span></p>
                @endif
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-xs text-slate-500 md:col-span-3">
                No blog posts matched this grid source.
            </div>
        @endforelse
    </div>
</section>
