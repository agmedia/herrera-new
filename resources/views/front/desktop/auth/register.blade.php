@extends('front.desktop.layouts.store')

@section('title', __('ui.auth.register.page_title'))
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
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.auth.register.heading') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('ui.auth.register.subheading') }}</p>
    </section>

    <section class="auth-layout">
        <div class="auth-form-card border border-slate-200 p-6" data-address-autofill data-address-source="{{ $placesAssetUrl }}">
            <h2 class="text-xl font-bold text-slate-900">{{ __('ui.auth.register.form_title') }}</h2>

            <form
                method="POST"
                action="{{ route('front.auth.register.store') }}"
                class="mt-5 grid gap-4 md:grid-cols-2"
                novalidate
                data-address-scope="billing"
                @if($captchaEnabled) data-recaptcha-form data-recaptcha-site-key="{{ $captchaSiteKey }}" data-recaptcha-action="register_form" @endif
            >
                @csrf
                <input type="hidden" name="intended" value="{{ old('intended', (string) request('intended', route('account.dashboard'))) }}">
                <input type="hidden" name="recaptcha_token" value="" data-recaptcha-token>

                <div>
                    <label for="auth-register-first-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.first_name') }}</label>
                    <input id="auth-register-first-name" type="text" name="first_name" value="{{ old('first_name') }}" class="w-full px-3 text-sm" autocomplete="given-name" required>
                    @error('first_name')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="auth-register-last-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.last_name') }}</label>
                    <input id="auth-register-last-name" type="text" name="last_name" value="{{ old('last_name') }}" class="w-full px-3 text-sm" autocomplete="family-name" required>
                    @error('last_name')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="auth-register-email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.email') }}</label>
                    <input id="auth-register-email" type="email" name="email" value="{{ old('email') }}" class="w-full px-3 text-sm" autocomplete="email" required>
                    @error('email')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="auth-register-phone" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.phone') }}</label>
                    <input id="auth-register-phone" type="tel" name="phone" value="{{ old('phone') }}" class="w-full px-3 text-sm" autocomplete="tel" required>
                    @error('phone')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="auth-register-address" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.address') }}</label>
                    <input id="auth-register-address" type="text" name="address_line_1" value="{{ old('address_line_1') }}" class="w-full px-3 text-sm" autocomplete="street-address" required>
                    @error('address_line_1')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="auth-register-postal-code" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.postal_code') }}</label>
                    <input id="auth-register-postal-code" type="text" name="postal_code" value="{{ old('postal_code') }}" class="w-full px-3 text-sm" autocomplete="postal-code" inputmode="numeric" data-address-postal required>
                    @error('postal_code')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="auth-register-city" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.city') }}</label>
                    <input id="auth-register-city" type="text" name="city" value="{{ old('city') }}" class="w-full px-3 text-sm" autocomplete="address-level2" data-address-city required>
                    @error('city')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="auth-register-country" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.country') }}</label>
                    <select id="auth-register-country" name="country_code" class="w-full px-3 text-sm" autocomplete="country" data-address-country required>
                        @foreach ($countryOptions as $countryOption)
                            <option value="{{ $countryOption['code'] }}" @selected(old('country_code', 'HR') === $countryOption['code'])>{{ $countryOption['label'] }}</option>
                        @endforeach
                    </select>
                    @error('country_code')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="auth-register-password" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.password') }}</label>
                    <input id="auth-register-password" type="password" name="password" class="w-full px-3 text-sm" autocomplete="new-password" required>
                    @error('password')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="auth-register-password-confirmation" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.fields.password_confirmation') }}</label>
                    <input id="auth-register-password-confirmation" type="password" name="password_confirmation" class="w-full px-3 text-sm" autocomplete="new-password" required>
                </div>

                <div class="md:col-span-2">
                    <label class="flex items-start gap-3 border bg-slate-50 p-4 text-sm text-slate-700 @error('terms_accepted') border-rose-500 @else border-slate-200 @enderror">
                        <input type="checkbox" name="terms_accepted" value="1" class="mt-0.5" @checked(old('terms_accepted')) required @error('terms_accepted') aria-invalid="true" aria-describedby="auth-register-terms-error" @enderror>
                        <span>
                            {{ __('ui.auth.register.terms_prefix') }}
                            <a href="{{ route('pages.show', ['slug' => 'uvjeti-koristenja']) }}" class="font-semibold text-blue-700 underline underline-offset-2" target="_blank" rel="noopener noreferrer">{{ __('ui.auth.register.terms_link') }}</a>.
                        </span>
                    </label>
                    @error('terms_accepted')
                        <p id="auth-register-terms-error" class="mt-2 text-xs font-semibold text-rose-600" aria-live="polite">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <button type="submit" class="commerce-primary-action w-full px-6 py-3">
                        {{ __('ui.auth.register.submit') }}
                    </button>
                    @error('recaptcha_token')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            </form>
        </div>

        <aside class="auth-side-card border border-slate-200 bg-white p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.auth.register.have_account_eyebrow') }}</p>
            <h2 class="mt-2 text-2xl font-bold text-slate-900">{{ __('ui.auth.register.have_account_title') }}</h2>
            <p class="mt-3 text-sm text-slate-600">{{ __('ui.auth.register.have_account_text') }}</p>

            <a href="{{ route('front.auth.login', ['intended' => (string) request('intended', route('account.dashboard'))]) }}" class="commerce-secondary-action mt-5 px-6 py-2.5 text-sm">
                {{ __('ui.auth.register.go_to_login') }}
            </a>

            <div class="mt-6 border-t border-slate-200 pt-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700">{{ __('Kupujete za tvrtku?') }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ __('Zatražite B2B račun za ugovorene cijene i brzu kupnju.') }}</p>
                <a href="{{ route('front.auth.b2b-register') }}" class="commerce-secondary-action mt-4 px-6 py-2.5 text-sm">{{ __('B2B registracija') }}</a>
            </div>
        </aside>
    </section>

    @if ($captchaEnabled)
        @include('front.partials.recaptcha-v3', ['siteKey' => $captchaSiteKey])
    @endif
@endsection

@push('scripts')
    <script defer src="{{ asset('front-theme/scripts/address-autofill.js') }}?v={{ filemtime(public_path('front-theme/scripts/address-autofill.js')) }}"></script>
@endpush
