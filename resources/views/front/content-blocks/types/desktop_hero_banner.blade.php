@php
    $title = $translation?->title ?: 'Modern essentials, built for everyday carry.';
    $subtitle = trim((string) ($translation?->subtitle ?? ''));
    $primaryCtaLabel = $translation?->cta_label ?: 'Shop featured';
    $primaryCtaUrl = $translation?->cta_url ?: '#featured';
@endphp

<div class="max-w-3xl text-white">
    <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm">
        <span class="h-2 w-2 rounded-full bg-emerald-300"></span>
        New season collection live now
    </p>

    <h1 class="mt-6 text-4xl font-extrabold leading-tight tracking-tight lg:text-6xl">
        {!! nl2br(e($title)) !!}
    </h1>

    @if ($subtitle !== '')
        <p class="mt-6 max-w-xl text-lg text-white/90">{{ $subtitle }}</p>
    @endif

    <div class="mt-10 flex flex-wrap items-center gap-4">
        <a href="{{ $primaryCtaUrl }}" class="rounded-xl bg-white px-6 py-3 font-semibold text-blue-700 hover:bg-slate-100">
            {{ $primaryCtaLabel }}
        </a>
        <a href="#categories" class="rounded-xl border border-white/30 px-6 py-3 text-white hover:bg-white/10">
            Browse categories
        </a>
    </div>
</div>
