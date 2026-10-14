@extends('front.desktop.layouts.store')

@section('title', $translation->title)
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-12')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-references.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-references.css')) }}">
@endpush
@push('scripts')
    <script defer src="{{ asset('front-theme/scripts/reference-gallery.js') }}?v={{ filemtime(public_path('front-theme/scripts/reference-gallery.js')) }}"></script>
@endpush

@section('content')
    @php
        $descriptionParagraphs = preg_split('/\R\s*\R/u', trim((string) $translation->description), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $galleryItems = $photos->map(fn ($photo) => [
            'src' => $project->photoUrl($photo, 'large'),
            'caption' => $photo->getCustomProperty('caption') ?: $translation->title,
            'alt' => $photo->getCustomProperty('caption') ?: $translation->title,
        ])->values();
    @endphp
    <header class="reference-heading front-soft-hero">
        <nav aria-label="{{ __('Breadcrumb') }}" class="reference-breadcrumbs">
            <a href="{{ route('home') }}">{{ __('ui.front.desktop.footer.home') }}</a><span aria-hidden="true">/</span>
            <a href="{{ route('references.index') }}">{{ __('reference_projects.title') }}</a><span aria-hidden="true">/</span>
            <span aria-current="page">{{ $translation->title }}</span>
        </nav>
        <p class="reference-eyebrow">{{ __('reference_projects.eyebrow') }}</p>
        <h1>{{ $translation->title }}</h1>
        @if ($translation->excerpt)<p class="reference-heading__intro">{{ $translation->excerpt }}</p>@endif
    </header>

    <article class="reference-project" data-reference-gallery-root>
        <script type="application/json" data-reference-gallery-items>@json($galleryItems)</script>
        <section class="reference-gallery reference-gallery--lead" aria-labelledby="reference-gallery-title">
            <div class="reference-section-heading">
                <div><p class="reference-eyebrow">{{ __('reference_projects.gallery_eyebrow') }}</p><h2 id="reference-gallery-title">{{ __('reference_projects.gallery') }}</h2></div>
                <p>{{ __('reference_projects.gallery_hint') }}</p>
            </div>
            <div class="reference-photo-grid {{ $photos->count() >= 3 ? 'reference-photo-grid--mosaic' : ($photos->count() === 1 ? 'reference-photo-grid--single' : '') }}">
                @foreach ($photos->take(3) as $photo)
                    <figure class="reference-gallery__tile">
                        <a class="reference-photo-link" href="{{ $project->photoUrl($photo, 'original') }}" target="_blank" rel="noopener" data-reference-open="{{ $loop->index }}" aria-label="{{ __('reference_projects.show_photo', ['number' => $loop->iteration]) }}">
                            <img src="{{ $project->photoUrl($photo, 'large') }}" alt="{{ $photo->getCustomProperty('caption') ?: $translation->title.' — '.__('reference_projects.photo').' '.$loop->iteration }}" {{ $loop->first ? 'fetchpriority=high' : 'loading=lazy' }} width="800" height="600">
                            <span class="reference-gallery__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <span class="reference-photo-zoom" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5M7 10h6m-3-3v6"/></svg></span>
                        </a>
                    </figure>
                @endforeach
            </div>
            @if ($photos->count() > 3)
                <div class="reference-gallery__more">
                    <a class="reference-button" href="{{ $project->photoUrl($photos->get(3), 'original') }}" target="_blank" rel="noopener" data-reference-open="3">{{ __('reference_projects.view_all_photos', ['count' => $photos->count()]) }}<span aria-hidden="true">↗</span></a>
                </div>
            @endif
        </section>

        <div class="reference-project-overview">
            <section class="reference-project-story" aria-labelledby="reference-about-title">
                <p class="reference-eyebrow">{{ __('reference_projects.project_story') }}</p>
                <h2 id="reference-about-title">{{ __('reference_projects.about') }}</h2>
                @if ($descriptionParagraphs)
                    <div class="reference-project-story__copy">
                        @foreach ($descriptionParagraphs as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                    </div>
                @elseif ($translation->excerpt)
                    <div class="reference-project-story__copy"><p>{{ $translation->excerpt }}</p></div>
                @endif
            </section>
            <aside class="reference-project-card" aria-labelledby="reference-details-title">
                <p class="reference-eyebrow">{{ __('reference_projects.project_details') }}</p>
                <h2 id="reference-details-title">{{ __('reference_projects.project_summary') }}</h2>
                <dl class="reference-detail-facts">
                    @if ($project->location)<div><dt>{{ __('reference_projects.location') }}</dt><dd>{{ $project->location }}</dd></div>@endif
                    @if ($project->completed_year)<div><dt>{{ __('reference_projects.year') }}</dt><dd>{{ $project->completed_year }}</dd></div>@endif
                    @if ($project->project_type)<div><dt>{{ __('reference_projects.type') }}</dt><dd>{{ $project->project_type }}</dd></div>@endif
                    <div><dt>{{ __('reference_projects.gallery') }}</dt><dd>{{ __('reference_projects.photo_count', ['count' => $photos->count()]) }}</dd></div>
                </dl>
                <div class="reference-project-card__contact">
                    <p>{{ __('reference_projects.project_cta') }}</p>
                    <a class="reference-button" href="{{ route('contact.create') }}">{{ __('reference_projects.contact') }}<span aria-hidden="true">→</span></a>
                </div>
            </aside>
        </div>

        <a class="reference-back-link" href="{{ route('references.index') }}"><span aria-hidden="true">←</span>{{ __('reference_projects.back') }}</a>

        <dialog class="reference-lightbox" data-reference-lightbox aria-labelledby="reference-lightbox-title" aria-describedby="reference-lightbox-caption" data-count-template="{{ __('reference_projects.image_count') }}">
            <div class="reference-lightbox__panel">
                <div class="reference-lightbox__header">
                    <div><h2 id="reference-lightbox-title">{{ $translation->title }}</h2><p data-reference-lightbox-count aria-live="polite"></p></div>
                    <button type="button" data-reference-lightbox-close aria-label="{{ __('reference_projects.close_gallery') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
                </div>
                <div class="reference-lightbox__stage" data-reference-lightbox-stage>
                    <button type="button" class="reference-lightbox__arrow reference-lightbox__arrow--previous" data-reference-lightbox-prev aria-label="{{ __('reference_projects.previous_photo') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg></button>
                    <img data-reference-lightbox-image alt="" draggable="false">
                    <button type="button" class="reference-lightbox__arrow reference-lightbox__arrow--next" data-reference-lightbox-next aria-label="{{ __('reference_projects.next_photo') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></button>
                </div>
                <p id="reference-lightbox-caption" class="reference-lightbox__caption" data-reference-lightbox-caption aria-live="polite"></p>
                <div class="reference-lightbox__thumbnails" aria-label="{{ __('reference_projects.gallery') }}">
                    @foreach ($photos as $photo)
                        <button type="button" data-reference-lightbox-thumb="{{ $loop->index }}" aria-label="{{ __('reference_projects.show_photo', ['number' => $loop->iteration]) }}" aria-current="false"><img src="{{ $project->photoUrl($photo) }}" alt="" loading="lazy" width="100" height="70"></button>
                    @endforeach
                </div>
            </div>
        </dialog>
    </article>
@endsection
