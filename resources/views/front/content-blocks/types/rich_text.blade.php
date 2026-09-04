<section class="rounded-2xl border border-slate-200 bg-white p-6">
    @if (!empty($translation?->title))
        <h2 class="text-xl font-semibold text-slate-900">{{ $translation->title }}</h2>
    @endif
    <div class="prose prose-slate mt-3 max-w-none">
        {!! $translation->body_html ?? '' !!}
    </div>
</section>

