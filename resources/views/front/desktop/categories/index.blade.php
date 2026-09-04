@extends('front.desktop.layouts.store')

@section('title', __('ui.category_index.page_title'))
@section('main_class', 'w-full px-0 pt-3 pb-4 sm:pt-3 sm:pb-6')
@section('body_class', 'category-index-page')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/category-index.css') }}?v={{ filemtime(public_path('front-theme/styles/category-index.css')) }}">
@endpush

@section('content')
    <section class="storefront-container px-3 sm:px-4 lg:px-6">
        <div class="front-soft-hero px-4 py-4 text-center sm:px-6 sm:py-5">
            <nav aria-label="Breadcrumb" class="mb-2">
                <ol class="flex flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                    <li>
                        <a href="{{ route('home') }}" class="inline-flex items-center justify-center text-slate-500 hover:text-slate-700">{{ __('ui.front.desktop.footer.home') }}</a>
                    </li>
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">{{ __('ui.category_index.page_title') }}</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ __('ui.category_index.page_title') }}</h1>
            <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ $categories->count() }} {{ __('ui.cart.summary.total') }}</p>
        </div>
    </section>

    <section class="storefront-container category-index-section px-3 sm:px-4 lg:px-6" aria-label="{{ __('ui.category_index.page_title') }}">
        @include('front.categories.index-grid')
    </section>
@endsection
