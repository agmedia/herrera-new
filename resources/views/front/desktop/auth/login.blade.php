@extends('front.desktop.layouts.store')

@section('title', __('ui.auth.login.page_title'))
@section('body_class', 'commerce-body auth-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    @php
        $captchaSiteKey = trim((string) ($storeSettings['captcha']['recaptcha_v3_site_key'] ?? ''));
        $captchaEnabled = (bool) ($storeSettings['captcha']['recaptcha_v3_enabled'] ?? false) && $captchaSiteKey !== '';
    @endphp

    <section class="commerce-hero">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.auth.login.heading') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('ui.auth.login.subheading') }}</p>
    </section>

    <section class="auth-layout">
        <div class="auth-form-card border border-slate-200 p-6">
            <h2 class="text-xl font-bold text-slate-900">{{ __('ui.auth.login.form_title') }}</h2>

            <form
                method="POST"
                action="{{ route('front.auth.login.store') }}"
                class="mt-5 space-y-4"
                novalidate
                @if($captchaEnabled) data-recaptcha-form data-recaptcha-site-key="{{ $captchaSiteKey }}" data-recaptcha-action="login_form" @endif
            >
                @csrf
                <input type="hidden" name="intended" value="{{ old('intended', (string) request('intended', route('account.dashboard'))) }}">
                <input type="hidden" name="recaptcha_token" value="" data-recaptcha-token>

                <div>
                    <label for="auth-login-email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.email') }}</label>
                    <input id="auth-login-email" type="email" name="email" value="{{ old('email') }}" class="w-full px-3 text-sm" autocomplete="email" required>
                    @error('email')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="auth-login-password" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.password') }}</label>
                    <input id="auth-login-password" type="password" name="password" class="w-full px-3 text-sm" autocomplete="current-password" required>
                    @error('password')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="remember" value="1" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" @checked(old('remember'))>
                        {{ __('ui.auth.login.remember') }}
                    </label>
                    <a href="{{ route('front.auth.password.request') }}" class="auth-inline-link text-sm font-semibold">
                        {{ __('ui.auth.login.forgot_password') }}
                    </a>
                </div>

                <button type="submit" class="commerce-primary-action w-full px-6 py-3">
                    {{ __('ui.auth.login.submit') }}
                </button>
                @error('recaptcha_token')
                    <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                @enderror
            </form>
        </div>

        <aside class="auth-side-card border border-slate-200 bg-white p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.login.new_customer_eyebrow') }}</p>
            <h2 class="mt-2 text-2xl font-bold text-slate-900">{{ __('ui.auth.login.new_customer_title') }}</h2>
            <p class="mt-3 text-sm text-slate-600">{{ __('ui.auth.login.new_customer_text') }}</p>

            <a href="{{ route('front.auth.register', ['intended' => (string) request('intended', route('account.dashboard'))]) }}" class="commerce-secondary-action mt-5 px-6 py-2.5 text-sm">
                {{ __('ui.auth.login.go_to_register') }}
            </a>
        </aside>
    </section>

    @if ($captchaEnabled)
        @include('front.partials.recaptcha-v3', ['siteKey' => $captchaSiteKey])
    @endif
@endsection
