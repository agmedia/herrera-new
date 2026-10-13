@extends('front.desktop.layouts.store')

@section('title', ($blogCategoryTranslation?->meta_title ?? null) ?: ($blogCategoryTranslation?->name ?? null) ?: __('ui.blog.page_title'))
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-0')

@section('content')
    @once
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-blog.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-blog.css')) }}">
        @endpush
    @endonce
    <section class="mb-8 px-1">
        <div class="front-soft-hero px-6 py-4 text-center sm:px-8 sm:py-5">
            <nav aria-label="Breadcrumb" class="mb-2">
                <ol class="flex flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-slate-700">{{ __('ui.front.desktop.footer.home') }}</a></li>
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">{{ __('ui.blog.title') }}</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-extrabold uppercase tracking-tight text-slate-900">{{ ($blogCategoryTranslation?->name ?? null) ?: __('ui.blog.title') }}</h1>
            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? '')), 'herrera') ? __('herrera.blog.subtitle') : __('ui.blog.subtitle') }}</p>
        </div>
    </section>

    @if ($topBlocks->isNotEmpty())
        <section class="mb-8">@include('components.content-placement', ['items' => $topBlocks])</section>
    @endif

    @if ($posts->isEmpty())
        <div class="border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">{{ __('ui.blog.empty') }}</div>
    @else
        <section class="grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($posts as $post)
                @include('front.partials.herrera-blog-card', ['blogCardPost' => $post, 'blogCardLocale' => $locale, 'blogCardFallbackLocale' => $fallbackLocale, 'blogCardInList' => true])
            @endforeach
        </section>

        <div class="mt-10">{{ $posts->links() }}</div>
    @endif

    @if ($bottomBlocks->isNotEmpty())
        <section class="mt-10">@include('components.content-placement', ['items' => $bottomBlocks])</section>
    @endif
@endsection
