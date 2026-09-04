@php
    $sectionTitle = $translation?->title ?: 'Shop by category';
    $sectionSubtitle = trim((string) ($translation?->subtitle ?? ''));
    $itemCtaLabel = $translation?->cta_label ?: 'Explore collection';
@endphp

<section id="categories" class="border-y border-slate-200/60 bg-slate-100/70 py-24">
    <div class="mx-auto max-w-7xl px-6">
        <div class="mb-10">
            <h2 class="text-[1.35rem] leading-[1.95rem] sm:text-[1.7rem] sm:leading-[2.5rem] uppercase font-semibold text-slate-900">{{ $sectionTitle }}</h2>
            @if ($sectionSubtitle !== '')
                <p class="mt-4 max-w-2xl text-lg text-slate-600">{{ $sectionSubtitle }}</p>
            @endif
        </div>

        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            @forelse ($categories as $category)
                @php
                    $ct = $category->translations->firstWhere('locale', app()->getLocale())
                        ?? $category->translations->firstWhere('locale', config('app.locale'));
                    $categoryName = $ct?->name ?: $category->code;
                    $categoryDesc = trim((string) ($ct?->clean_description ?? ''));
                @endphp
                <article class="rounded-3xl border border-slate-200/60 bg-white p-7 shadow-sm transition hover:shadow-md">
                    <div class="h-32 rounded-2xl bg-gradient-to-br from-slate-200 to-blue-100"></div>
                    <h3 class="mt-5 text-xl font-semibold text-slate-900">{{ $categoryName }}</h3>
                    @if ($categoryDesc !== '')
                        <p class="mt-3 text-slate-600">{{ $categoryDesc }}</p>
                    @endif
                    <a href="#" class="mt-4 inline-flex text-sm font-semibold text-blue-700 hover:text-blue-800">{{ $itemCtaLabel }}</a>
                </article>
            @empty
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-7 text-sm text-slate-500 sm:col-span-2 lg:col-span-4">
                    No categories selected for this block.
                </div>
            @endforelse
        </div>
    </div>
</section>
