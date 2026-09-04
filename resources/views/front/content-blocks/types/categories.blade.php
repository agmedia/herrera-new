@php
    $basePayload = is_array($block->payload ?? null) ? $block->payload : [];
    $translationPayload = is_array($translation?->payload ?? null) ? $translation->payload : [];
    $payload = array_merge($basePayload, $translationPayload);

    $sectionClass = (string) ($payload['section_class'] ?? 'rounded-3xl border border-slate-200 bg-white p-6');
    $gridClass = (string) ($payload['grid_class'] ?? 'mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4');
    $cardClass = (string) ($payload['card_class'] ?? 'rounded-2xl border border-slate-200 bg-slate-50 p-4');
@endphp

<section class="{{ $sectionClass }}">
    <h2 class="text-[1.35rem] leading-[1.95rem] sm:text-[1.7rem] sm:leading-[2.5rem] uppercase font-semibold text-slate-900">{{ $translation?->title ?: $block->name }}</h2>
    @if (!empty($translation?->subtitle))
        <p class="mt-2 text-sm text-slate-600">{{ $translation->subtitle }}</p>
    @endif

    <div class="{{ $gridClass }}">
        @forelse ($categories as $category)
            @php
                $ct = $category->translations->firstWhere('locale', app()->getLocale())
                    ?? $category->translations->firstWhere('locale', config('app.locale'));
            @endphp
            <article class="{{ $cardClass }}">
                <div class="h-28 rounded-xl bg-gradient-to-br from-slate-200 to-slate-100"></div>
                <h3 class="mt-3 text-sm font-semibold text-slate-900">{{ $ct?->name ?? $category->code }}</h3>
                <p class="mt-1 text-xs uppercase tracking-[0.12em] text-slate-500">{{ $category->scope }}</p>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500 sm:col-span-2 xl:col-span-4">
                No categories selected for this block.
            </div>
        @endforelse
    </div>
</section>

