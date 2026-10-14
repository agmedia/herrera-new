@extends('front.desktop.layouts.store')

@section('title', __('reference_projects.title'))
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-12')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-references.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-references.css')) }}">
@endpush

@section('content')
    <section class="mb-10 px-1">
        <div class="front-soft-hero px-6 py-6 text-center sm:px-8 sm:py-8">
            <nav aria-label="{{ __('Breadcrumb') }}" class="mb-3 text-xs text-slate-500">
                <a href="{{ route('home') }}" class="hover:text-slate-700">{{ __('ui.front.desktop.footer.home') }}</a>
                <span class="mx-2" aria-hidden="true">/</span>
                <span aria-current="page">{{ __('reference_projects.title') }}</span>
            </nav>
            <p class="text-xs font-semibold uppercase tracking-widest text-cyan-700">{{ __('reference_projects.eyebrow') }}</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">{{ __('reference_projects.title') }}</h1>
            <p class="mx-auto mt-3 max-w-2xl text-sm text-slate-600">{{ __('reference_projects.intro') }}</p>
        </div>
    </section>

    @if ($projects->isEmpty())
        <section class="reference-empty">
            <h2 class="text-xl font-semibold">{{ __('reference_projects.empty_title') }}</h2>
            <p class="reference-copy mx-auto mt-3 max-w-xl text-sm">{{ __('reference_projects.empty_text') }}</p>
            <a class="reference-button mt-6" href="{{ route('contact.create') }}">{{ __('reference_projects.contact') }}</a>
        </section>
    @else
        <section class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" aria-label="{{ __('reference_projects.title') }}">
            @foreach ($projects as $project)
                @php
                    $translation = $project->localizedTranslation();
                    $cover = $project->getMedia(\App\Models\Content\Reference\ReferenceProject::GALLERY)->sortBy('order_column')->first();
                @endphp
                <article class="reference-card">
                    <a href="{{ route('references.show', $translation->slug) }}">
                        <div class="reference-card__image">
                            <img src="{{ $project->photoUrl($cover) }}" alt="{{ $cover->getCustomProperty('caption') ?: $translation->title }}" loading="lazy" width="800" height="600">
                        </div>
                        <div class="reference-card__body">
                            @if ($project->location || $project->completed_year)<p class="reference-meta mb-2">{{ collect([$project->location, $project->completed_year])->filter()->implode(' · ') }}</p>@endif
                            <h2>{{ $translation->title }}</h2>
                            @if ($translation->excerpt)<p class="reference-copy mt-3 text-sm">{{ $translation->excerpt }}</p>@endif
                            <span class="reference-link mt-5 inline-flex items-center gap-2 text-sm">{{ __('reference_projects.view_project') }} <span aria-hidden="true">→</span></span>
                        </div>
                    </a>
                </article>
            @endforeach
        </section>
        <div class="mt-10">{{ $projects->links() }}</div>
    @endif
@endsection
