@extends('front.desktop.layouts.store')

@section('title', __('ui.blog.page_title'))
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-0')

@section('content')
    <section class="mb-8 px-1">
        <div class="front-soft-hero px-6 py-4 text-center sm:px-8 sm:py-5">
            <nav aria-label="Breadcrumb" class="mb-2">
                <ol class="flex flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-slate-700">{{ __('ui.front.desktop.footer.home') }}</a></li>
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">{{ __('ui.blog.title') }}</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-extrabold uppercase tracking-tight text-slate-900">{{ __('ui.blog.title') }}</h1>
            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('ui.blog.subtitle') }}</p>
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
                @php
                    $translation = $post->translations->firstWhere('locale', $locale)
                        ?? $post->translations->firstWhere('locale', $fallbackLocale);
                    $postImage = $post->getFirstMedia('blog_cover');
                    $postImageUrl = $postImage?->getUrl();
                @endphp

                <article>
                    <a href="{{ route('blog.show', ['slug' => $translation?->slug ?? $post->id]) }}" class="group block">
                        <div class="aspect-[3/2] overflow-hidden bg-slate-200">
                            @if ($postImageUrl)
                                <img
                                    src="{{ $postImageUrl }}"
                                    alt="{{ $translation?->title ?? $post->code }}"
                                    class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                                    loading="lazy"
                                    decoding="async"
                                >
                            @else
                                <div class="flex h-full w-full items-center justify-center text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.product.no_image') }}</div>
                            @endif
                        </div>

                        <h2 class="mt-4 text-center text-lg font-semibold leading-snug text-slate-800 lg:text-xl">
                            {{ $translation?->title ?? $post->code }}
                        </h2>

                        <p class="mx-auto mt-4 max-w-[30ch] text-center text-sm leading-relaxed text-slate-700">
                            {{ $translation?->excerpt ?: __('ui.blog.excerpt_fallback') }}
                        </p>
                    </a>
                </article>
            @endforeach
        </section>

        <div class="mt-10">{{ $posts->links() }}</div>
    @endif

    @if ($bottomBlocks->isNotEmpty())
        <section class="mt-10">@include('components.content-placement', ['items' => $bottomBlocks])</section>
    @endif
@endsection
