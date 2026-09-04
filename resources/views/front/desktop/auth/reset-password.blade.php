@extends('front.desktop.layouts.store')

@section('title', __('ui.auth.reset.page_title'))
@section('body_class', 'commerce-body auth-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    <section class="commerce-hero">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.auth.reset.heading') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('ui.auth.reset.subheading') }}</p>
    </section>

    <section class="auth-layout">
        <div class="auth-form-card border border-slate-200 p-6">
            <h2 class="text-xl font-bold text-slate-900">{{ __('ui.auth.reset.form_title') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('ui.auth.reset.intro') }}</p>

            <form method="POST" action="{{ route('front.auth.password.update') }}" class="mt-5 space-y-4" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div>
                    <label for="reset-password-email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.email') }}</label>
                    <input
                        id="reset-password-email"
                        type="email"
                        name="email"
                        value="{{ old('email', $email) }}"
                        class="w-full px-3 text-sm"
                        autocomplete="email"
                        required
                        @error('email') aria-invalid="true" aria-describedby="reset-password-email-error" @enderror
                    >
                    @error('email')
                        <p id="reset-password-email-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="reset-password-password" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.password') }}</label>
                    <input id="reset-password-password" type="password" name="password" class="w-full px-3 text-sm" autocomplete="new-password" autofocus required @error('password') aria-invalid="true" aria-describedby="reset-password-password-error" @enderror>
                    @error('password')
                        <p id="reset-password-password-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="reset-password-confirmation" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.password_confirmation') }}</label>
                    <input id="reset-password-confirmation" type="password" name="password_confirmation" class="w-full px-3 text-sm" autocomplete="new-password" required>
                </div>

                <button type="submit" class="commerce-primary-action w-full px-6 py-3">
                    {{ __('ui.auth.reset.submit') }}
                </button>
            </form>
        </div>

        <aside class="auth-side-card border border-slate-200 p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.reset.side_eyebrow') }}</p>
            <h2 class="mt-2 text-2xl font-bold text-slate-900">{{ __('ui.auth.reset.side_title') }}</h2>
            <p class="mt-3 text-sm text-slate-600">{{ __('ui.auth.reset.side_text') }}</p>
        </aside>
    </section>
@endsection
