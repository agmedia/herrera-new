@extends('front.desktop.layouts.store')

@section('title', __('ui.auth.forgot.page_title'))
@section('body_class', 'commerce-body auth-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    <section class="commerce-hero">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.auth.forgot.heading') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('ui.auth.forgot.subheading') }}</p>
    </section>

    <section class="auth-layout">
        <div class="auth-form-card border border-slate-200 p-6">
            <h2 class="text-xl font-bold text-slate-900">{{ __('ui.auth.forgot.form_title') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('ui.auth.forgot.intro') }}</p>

            <form method="POST" action="{{ route('front.auth.password.email') }}" class="mt-5 space-y-4" novalidate>
                @csrf

                <div>
                    <label for="forgot-password-email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.email') }}</label>
                    <input
                        id="forgot-password-email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        class="w-full px-3 text-sm"
                        autocomplete="email"
                        autofocus
                        required
                        @error('email') aria-invalid="true" aria-describedby="forgot-password-email-error" @enderror
                    >
                    @error('email')
                        <p id="forgot-password-email-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="commerce-primary-action w-full px-6 py-3">
                    {{ __('ui.auth.forgot.submit') }}
                </button>
            </form>
        </div>

        <aside class="auth-side-card border border-slate-200 p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.forgot.back_eyebrow') }}</p>
            <h2 class="mt-2 text-2xl font-bold text-slate-900">{{ __('ui.auth.forgot.back_title') }}</h2>
            <p class="mt-3 text-sm text-slate-600">{{ __('ui.auth.forgot.back_text') }}</p>
            <a href="{{ route('front.auth.login') }}" class="commerce-secondary-action mt-5 px-6 py-2.5 text-sm">
                {{ __('ui.auth.forgot.back_action') }}
            </a>
        </aside>
    </section>
@endsection
