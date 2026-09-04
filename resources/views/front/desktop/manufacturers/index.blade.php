@extends('front.desktop.layouts.store')

@section('title', 'Brendovi')
@section('main_class', 'w-full px-0 pt-3 pb-4 sm:pt-3 sm:pb-6')
@section('body_class', 'brand-directory-page')

@section('content')
    @php
        $brandCount = $manufacturerGroups->flatten(1)->count();
    @endphp

    <section class="storefront-container px-3 sm:px-4 lg:px-6">
        <div class="front-soft-hero px-4 py-4 text-center sm:px-6 sm:py-5">
            <nav aria-label="Breadcrumb" class="mb-2">
                <ol class="flex flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                    <li>
                        <a href="{{ route('home') }}" class="inline-flex items-center justify-center text-slate-500 hover:text-slate-700">{{ __('ui.front.desktop.footer.home') }}</a>
                    </li>
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">Brendovi</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">Brendovi</h1>
            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ $brandCount }} brendova</p>
        </div>
    </section>

    <section class="storefront-container brand-directory-section px-3 sm:px-4 lg:px-6">
        @include('front.partials.manufacturer-directory')
    </section>
@endsection

@push('head')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/manufacturer-directory.css') }}?v={{ filemtime(public_path('front-theme/styles/manufacturer-directory.css')) }}">
@endpush

@push('scripts')
    <script defer src="{{ asset('front-theme/scripts/manufacturer-directory.js') }}?v={{ filemtime(public_path('front-theme/scripts/manufacturer-directory.js')) }}"></script>
@endpush
