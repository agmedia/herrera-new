@extends('front.desktop.layouts.store')

@section('title', str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera') ? __('herrera.page_title') : config('app.name', 'AG Shop').' Store')
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-0'.(str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera') ? ' herrera-home-main' : ''))

@section('content')
    @php
        $resolver = app(\App\Services\Content\ContentBlockResolver::class);
        $locale = app()->getLocale();
        $isHerreraHome = str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera');
        $isManagedHerreraHome = $isHerreraHome
            && app(\App\Services\Settings\SystemSettingsService::class)->get('herrera_home_widgets_2026_11_applied', false);
        $homeVariant = $isManagedHerreraHome && ! empty($isMobileDevice) ? 'mobile' : 'desktop';

        $resolveHomePlacement = function (string $placement) use ($resolver, $locale, $homeVariant, $isManagedHerreraHome) {
            $items = $resolver->forPlacement($placement, $locale, frontendVariant: $homeVariant);
            if (! $isManagedHerreraHome || ! $items->contains(fn ($item) => $item['slot']->frontend_variant === $homeVariant)) {
                return $items;
            }

            // A device-specific slot overrides the shared slot of the same
            // block, while the other built-in widgets keep their own slots.
            $sharedWidgets = $resolver->forPlacement($placement, $locale)->filter(fn ($item) => in_array($item['block']->type, [
                'herrera_hero', 'herrera_curated_products', 'herrera_popular_products', 'herrera_registration',
            ], true) || in_array($item['block']->code, \App\Support\HerreraHomeWidgets::FIXED_CODES, true));

            return $items->concat($sharedWidgets)->unique('block.id')->sortBy([
                ['slot.sort_order', 'asc'], ['slot.id', 'asc'],
            ])->values();
        };

        $homeHeroBlocks = $resolveHomePlacement('home.hero');
        $homeHeroBenefitsBlocks = $resolveHomePlacement('home.hero_benefits');
        $homeBeforeProductsBlocks = $resolveHomePlacement('home.before_products');
        $homeCategoriesBlocks = $resolveHomePlacement('home.categories');
        $homeAfterProductsBlocks = $resolveHomePlacement('home.after_products');
        $homeBottomBlocks = $resolveHomePlacement('home.bottom');
        $homeBottomIsInstagramOnly = $homeBottomBlocks->isNotEmpty()
            && $homeBottomBlocks->every(fn ($item) => (string) data_get($item, 'block.type') === 'instagram_curated_grid');
        $hasManagedHerreraHomeCategories = $isHerreraHome && ! $isManagedHerreraHome
            && \Illuminate\Support\Facades\Schema::hasTable('content_block_slots')
            && \Illuminate\Support\Facades\Schema::hasTable('content_blocks')
            && \App\Models\Content\ContentBlockSlot::query()
                ->where('placement', 'home.categories')
                ->whereHas('block', fn ($query) => $query->where('type', 'featured_categories'))
                ->exists();

        $viewer = auth()->user();
        $canPreviewBlock = $viewer && ($viewer->isA('superadmin') || $viewer->can('content.blocks'));
        $previewBlockId = $canPreviewBlock ? (int) request()->query('preview_block', 0) : 0;
        $requestedPreviewPlacement = $canPreviewBlock ? (string) request()->query('preview_placement', '') : '';

        if ($previewBlockId > 0) {
            $previewBlock = \App\Models\Content\ContentBlock::query()
                ->with([
                    'translations' => fn ($q) => $q->whereIn('locale', [$locale, config('app.locale')]),
                    'slots' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                ])
                ->find($previewBlockId);

            if ($previewBlock) {
                $previewPlacement = $requestedPreviewPlacement !== ''
                    ? $requestedPreviewPlacement
                    : (string) ($previewBlock->slots->first()?->placement ?? 'home.hero');

                $previewTranslation = $previewBlock->translations->firstWhere('locale', $locale)
                    ?? $previewBlock->translations->firstWhere('locale', config('app.locale'));

                $previewSlot = $previewBlock->slots->firstWhere('placement', $previewPlacement)
                    ?? new \App\Models\Content\ContentBlockSlot(['placement' => $previewPlacement]);

                $previewItem = collect([[
                    'slot' => $previewSlot,
                    'block' => $previewBlock,
                    'translation' => $previewTranslation,
                ]]);

                if ($previewPlacement === 'home.hero') {
                    $homeHeroBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.hero_benefits') {
                    $homeHeroBenefitsBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.before_products') {
                    $homeBeforeProductsBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.categories') {
                    $homeCategoriesBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.after_products') {
                    $homeAfterProductsBlocks = $previewItem;
                } elseif ($previewPlacement === 'home.bottom') {
                    $homeBottomBlocks = $previewItem;
                    $homeBottomIsInstagramOnly = (string) $previewBlock->type === 'instagram_curated_grid';
                }
            }
        }

        $useHerreraHomeFallback = $isHerreraHome && ! $isManagedHerreraHome && $homeHeroBlocks->isEmpty();
        $hasHomeBrandsBlock = collect([$homeHeroBlocks, $homeHeroBenefitsBlocks, $homeCategoriesBlocks, $homeBeforeProductsBlocks, $homeAfterProductsBlocks, $homeBottomBlocks])
            ->flatten(1)
            ->contains(fn ($item) => (string) data_get($item, 'block.type') === 'popular_brands');
    @endphp

    @if ($homeHeroBlocks->isNotEmpty())
        <section class="-mt-px" data-herrera-home-hero-placement>
            @include('front.partials.herrera-home-placement', ['items' => $homeHeroBlocks])
        </section>
    @elseif ($useHerreraHomeFallback)
        @include('front.partials.herrera-home', ['herreraHomeSection' => 'hero'])
    @endif

    @if ($homeHeroBenefitsBlocks->isNotEmpty())
        <section class="mt-8">
            @include('front.partials.herrera-home-placement', ['items' => $homeHeroBenefitsBlocks])
        </section>
    @endif

    @if ($isHerreraHome && $homeCategoriesBlocks->isNotEmpty() && $homeCategoriesBlocks->every(fn ($item) => (string) data_get($item, 'block.type') === 'featured_categories'))
        @include('front.partials.herrera-home-placement', ['items' => $homeCategoriesBlocks])
    @elseif ($homeCategoriesBlocks->isNotEmpty())
        <section class="mt-8">
            @include('front.partials.herrera-home-placement', ['items' => $homeCategoriesBlocks])
        </section>
    @elseif ($useHerreraHomeFallback && ! $hasManagedHerreraHomeCategories)
        @include('front.partials.herrera-home', ['herreraHomeSection' => 'categories'])
    @endif

    @if ($homeBeforeProductsBlocks->isNotEmpty())
        <section class="mt-8">
            @include('front.partials.herrera-home-placement', ['items' => $homeBeforeProductsBlocks])
        </section>
    @endif

    @if ($useHerreraHomeFallback)
        @include('front.partials.herrera-home', ['herreraHomeSection' => 'products'])
    @endif

    @if ($homeAfterProductsBlocks->isNotEmpty())
        <section class="mt-8">
            @include('front.partials.herrera-home-placement', ['items' => $homeAfterProductsBlocks])
        </section>
    @endif

    @if ($useHerreraHomeFallback && ! $hasHomeBrandsBlock)
        @include('front.partials.herrera-home', ['herreraHomeSection' => 'brands'])
    @endif

    @if ($isHerreraHome && ! $isManagedHerreraHome && ! $hasHomeBrandsBlock)
        @include('front.partials.herrera-popular-products')
    @endif

    @if ($homeBottomBlocks->isNotEmpty())
        <section class="{{ $homeBottomIsInstagramOnly ? 'mt-0' : 'mt-8' }}">
            @include('front.partials.herrera-home-placement', ['items' => $homeBottomBlocks])
        </section>
    @endif

    @if (
        $homeHeroBlocks->isEmpty()
        && $homeHeroBenefitsBlocks->isEmpty()
        && $homeBeforeProductsBlocks->isEmpty()
        && $homeCategoriesBlocks->isEmpty()
        && $homeAfterProductsBlocks->isEmpty()
        && $homeBottomBlocks->isEmpty()
        && ! str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? config('app.name'))), 'herrera')
    )
        <section class="border border-slate-200 bg-white p-10">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Home</h1>
            <p class="mt-3 text-slate-600">Nema aktivnih content blokova za desktop home.</p>
        </section>
    @endif
@endsection
