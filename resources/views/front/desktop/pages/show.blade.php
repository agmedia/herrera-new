@extends('front.desktop.layouts.store')

@php
    $translation = $selectedTranslation
        ?? $page->translations->firstWhere('locale', $locale)
        ?? $page->translations->firstWhere('locale', $fallbackLocale);
@endphp

@section('title', $translation?->title ?? 'Page')
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-8')

@section('content')
    @if ($topBlocks->isNotEmpty())
        <section class="mb-8">@include('components.content-placement', ['items' => $topBlocks])</section>
    @endif

    <section class="mb-8 px-1">
        <div class="front-soft-hero px-6 py-4 text-center sm:px-8 sm:py-5">
            <nav aria-label="Breadcrumb" class="mb-2">
                <ol class="flex flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-slate-700">{{ __('ui.front.desktop.footer.home') }}</a></li>
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">{{ $translation?->title ?? $page->code }}</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-extrabold uppercase tracking-tight text-slate-900">{{ $translation?->title ?? $page->code }}</h1>
            @if (!empty($translation?->excerpt))
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ $translation->excerpt }}</p>
            @endif
        </div>
    </section>

    <article class="bg-white px-2 py-2">
        <div class="content-richtext">
            {!! $translation?->body_html ?: '<p>This page has no body content.</p>' !!}
        </div>
    </article>

    @if ($bottomBlocks->isNotEmpty())
        <section class="mt-10">@include('components.content-placement', ['items' => $bottomBlocks])</section>
    @endif
@endsection
