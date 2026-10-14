@extends('front.desktop.layouts.store')

@section('title', __('supplier_catalogs.title'))
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-12')

@section('content')
    <section class="front-soft-hero mb-8 px-6 py-8 text-center sm:px-10 sm:py-10">
        <nav aria-label="Breadcrumb" class="mb-3 text-xs text-slate-500">
            <a href="{{ route('home') }}" class="hover:text-cyan-700">{{ __('ui.front.desktop.footer.home') }}</a>
            <span class="mx-2" aria-hidden="true">/</span>
            <span>{{ __('supplier_catalogs.title') }}</span>
        </nav>
        <p class="mb-2 text-xs font-bold uppercase tracking-widest text-cyan-700">{{ __('supplier_catalogs.eyebrow') }}</p>
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('supplier_catalogs.title') }}</h1>
        <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-slate-600">{{ __('supplier_catalogs.subtitle') }}</p>
    </section>

    @if ($brands->count() > 1)
        <nav class="mb-8 flex flex-wrap justify-center gap-2" aria-label="{{ __('supplier_catalogs.brand') }}">
            <a href="{{ route('supplier-catalogs.index') }}" @if ($selectedBrand === '') aria-current="page" @endif class="border px-4 py-2 text-sm font-semibold {{ $selectedBrand === '' ? 'border-cyan-700 bg-cyan-700 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-cyan-700 hover:text-cyan-700' }}">{{ __('supplier_catalogs.all_brands') }}</a>
            @foreach ($brands as $brand)
                <a href="{{ route('supplier-catalogs.index', ['brand' => $brand]) }}" @if ($selectedBrand === $brand) aria-current="page" @endif class="border px-4 py-2 text-sm font-semibold {{ $selectedBrand === $brand ? 'border-cyan-700 bg-cyan-700 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-cyan-700 hover:text-cyan-700' }}">{{ $brand }}</a>
            @endforeach
        </nav>
    @endif

    <section class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" aria-label="{{ __('supplier_catalogs.title') }}">
        @forelse ($catalogs as $catalog)
            <article class="group flex flex-col overflow-hidden border border-slate-200 bg-white transition-shadow hover:shadow-lg">
                <a href="{{ $catalog->publicUrl() }}" target="_blank" rel="noopener noreferrer" class="relative flex aspect-[3/4] items-center justify-center overflow-hidden bg-slate-100 p-5" aria-label="{{ $catalog->title }}">
                    @if ($catalog->coverUrl())
                        <img src="{{ $catalog->coverUrl() }}" alt="{{ $catalog->title }}" loading="lazy" class="h-full w-full object-cover shadow-sm transition-transform duration-300 group-hover:scale-[1.02]">
                    @else
                        <div class="relative flex h-full w-full flex-col justify-between overflow-hidden bg-cyan-900 p-6 text-white shadow-md">
                            <div class="absolute -bottom-12 -right-20 h-72 w-40 rotate-45 bg-cyan-600/40" aria-hidden="true"></div>
                            <div class="relative border-b border-white/30 pb-4 text-2xl font-extrabold tracking-tight">{{ $catalog->brand }}</div>
                            <div class="relative">
                                <span class="text-xs font-semibold uppercase tracking-widest text-cyan-100">{{ $catalog->format === 'pdf' ? 'PDF' : __('supplier_catalogs.web') }}</span>
                                <div class="mt-3 text-xl font-bold leading-tight">{{ $catalog->title }}</div>
                                @if ($catalog->year)<div class="mt-4 text-4xl font-light">{{ $catalog->year }}</div>@endif
                            </div>
                        </div>
                    @endif
                </a>
                <div class="flex flex-1 flex-col p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-semibold uppercase tracking-wide text-cyan-700">
                        <span>{{ $catalog->brand }}</span>
                        @if ($catalog->year)<span>{{ $catalog->year }}</span>@endif
                    </div>
                    <h2 class="mt-2 text-lg font-bold leading-snug text-slate-900">{{ $catalog->title }}</h2>
                    @if ($catalog->language)<p class="mt-2 text-xs text-slate-500">{{ $catalog->language }}</p>@endif
                    @if ($catalog->description)<p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $catalog->description }}</p>@endif
                    <a href="{{ $catalog->publicUrl() }}" target="_blank" rel="noopener noreferrer" class="mt-5 inline-flex items-center justify-between gap-3 border-t border-slate-200 pt-4 text-sm font-bold text-cyan-700 hover:text-cyan-900">
                        {{ $catalog->format === 'pdf' ? __('supplier_catalogs.open_pdf') : __('supplier_catalogs.open_catalog') }}
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" /></svg>
                    </a>
                </div>
            </article>
        @empty
            <p class="col-span-full border border-dashed border-slate-300 p-12 text-center text-slate-500">{{ __($selectedBrand === '' ? 'supplier_catalogs.empty' : 'supplier_catalogs.empty_brand') }}</p>
        @endforelse
    </section>
@endsection
