@extends('front.desktop.layouts.store')

@section('title', __('B2B brza kupnja'))
@section('body_class', 'commerce-body account-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
    <link rel="stylesheet" href="{{ asset('front-theme/styles/b2b-quick-order.css') }}?v={{ filemtime(public_path('front-theme/styles/b2b-quick-order.css')) }}">
@endpush

@section('content')
    <section class="front-soft-hero b2b-quick-order-hero mb-8 px-4 py-6 text-center sm:px-6">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">{{ $b2bAccount->company_name }}</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">{{ __('B2B brza kupnja') }}</h1>
        <p class="b2b-quick-order-hero-description mt-2 text-slate-600">{{ __('Pronađite artikle po nazivu, šifri, SKU-u ili barkodu i dodajte ih po ugovorenim cijenama.') }}</p>
    </section>

    <div class="account-layout">
        @include('front.desktop.account.partials.nav', ['current' => 'b2b_quick_order'])

        <div class="min-w-0">
            <section class="border border-slate-200 bg-white">
                @include('front.shared.account.b2b-quick-order-form')
            </section>
        </div>
    </div>
@endsection

@push('scripts')
    <script defer src="{{ asset('front-theme/scripts/b2b-quick-order.js') }}?v={{ filemtime(public_path('front-theme/scripts/b2b-quick-order.js')) }}"></script>
@endpush
