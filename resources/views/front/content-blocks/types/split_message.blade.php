@php
    $payload = $block->payload ?? [];
@endphp

<section class="grid gap-4 md:grid-cols-2">
    <div class="rounded-2xl border border-slate-200 bg-white p-6">
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ $payload['left_label'] ?? 'Left' }}</p>
        <h3 class="mt-2 text-xl font-semibold text-slate-900">{{ $translation->title ?? $block->name }}</h3>
        @if (!empty($translation?->subtitle))
            <p class="mt-2 text-sm text-slate-600">{{ $translation->subtitle }}</p>
        @endif
    </div>
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ $payload['right_label'] ?? 'Right' }}</p>
        <div class="mt-2 text-sm text-slate-700">{!! $translation->body_html ?? '' !!}</div>
    </div>
</section>

