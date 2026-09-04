@extends('front.desktop.layouts.store')

@section('title', config('app.name', 'AG Shop').' Store')
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-0')

@section('content')
    @php
        $resolver = app(\App\Services\Content\ContentBlockResolver::class);
        $locale = app()->getLocale();

        $homeHeroBlocks = $resolver->forPlacement('home.hero', $locale, null, null, 'desktop');
        $homeHeroBenefitsBlocks = $resolver->forPlacement('home.hero_benefits', $locale, null, null, 'desktop');
        $homeBeforeProductsBlocks = $resolver->forPlacement('home.before_products', $locale, null, null, 'desktop');
        $homeCategoriesBlocks = $resolver->forPlacement('home.categories', $locale, null, null, 'desktop');
        $homeAfterProductsBlocks = $resolver->forPlacement('home.after_products', $locale, null, null, 'desktop');
        $homeBottomBlocks = $resolver->forPlacement('home.bottom', $locale, null, null, 'desktop');
        $homeBottomIsInstagramOnly = $homeBottomBlocks->isNotEmpty()
            && $homeBottomBlocks->every(fn ($item) => (string) data_get($item, 'block.type') === 'instagram_curated_grid');

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
    @endphp

    @if ($homeHeroBlocks->isNotEmpty())
        <section class="-mt-px">
            @include('components.content-placement', ['items' => $homeHeroBlocks])
        </section>
    @endif

    @if ($homeHeroBenefitsBlocks->isNotEmpty())
        <section class="mt-8">
            @include('components.content-placement', ['items' => $homeHeroBenefitsBlocks])
        </section>
    @endif

    @if ($homeCategoriesBlocks->isNotEmpty())
        <section class="mt-8">
            @include('components.content-placement', ['items' => $homeCategoriesBlocks])
        </section>
    @endif

    @if ($homeBeforeProductsBlocks->isNotEmpty())
        <section class="mt-8">
            @include('components.content-placement', ['items' => $homeBeforeProductsBlocks])
        </section>
    @endif

    @if ($homeAfterProductsBlocks->isNotEmpty())
        <section class="mt-8">
            @include('components.content-placement', ['items' => $homeAfterProductsBlocks])
        </section>
    @endif

    @if ($homeBottomBlocks->isNotEmpty())
        <section class="{{ $homeBottomIsInstagramOnly ? 'mt-0' : 'mt-8' }}">
            @include('components.content-placement', ['items' => $homeBottomBlocks])
        </section>
    @endif

    @if (
        $homeHeroBlocks->isEmpty()
        && $homeHeroBenefitsBlocks->isEmpty()
        && $homeBeforeProductsBlocks->isEmpty()
        && $homeCategoriesBlocks->isEmpty()
        && $homeAfterProductsBlocks->isEmpty()
        && $homeBottomBlocks->isEmpty()
    )
        <section class="border border-slate-200 bg-white p-10">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">Home</h1>
            <p class="mt-3 text-slate-600">Nema aktivnih content blokova za desktop home.</p>
        </section>
    @endif
@endsection
