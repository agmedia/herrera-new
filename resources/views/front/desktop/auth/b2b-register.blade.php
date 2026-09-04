@extends('front.desktop.layouts.store')

@section('title', __('B2B registracija'))
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
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">{{ __('Poslovni korisnici') }}</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">{{ __('B2B registracija') }}</h1>
        <p class="mt-2 max-w-3xl text-slate-600">{{ __('Pošaljite podatke tvrtke. Nakon provjere i odobrenja dobit ćete pristup ugovorenim cijenama, brzoj kupnji i ponovnom naručivanju.') }}</p>
    </section>

    <section class="auth-layout">
        <div class="auth-form-card border border-slate-200 p-6" data-address-autofill data-address-source="{{ $placesAssetUrl }}">
            <h2 class="text-xl font-bold text-slate-900">{{ __('Podaci korisnika i tvrtke') }}</h2>

            <form method="POST" action="{{ route('front.auth.b2b-register.store') }}" class="mt-5 grid gap-4 md:grid-cols-2" novalidate
                data-address-scope="billing"
                @if($captchaEnabled) data-recaptcha-form data-recaptcha-site-key="{{ $captchaSiteKey }}" data-recaptcha-action="register_form" @endif>
                @csrf
                <input type="hidden" name="recaptcha_token" value="" data-recaptcha-token>

                @php
                    $fields = [
                        ['first_name', __('Ime'), 'given-name', true],
                        ['last_name', __('Prezime'), 'family-name', true],
                        ['email', __('E-mail'), 'email', true, 'email'],
                        ['phone', __('Telefon'), 'tel', true],
                        ['company_name', __('Naziv tvrtke'), 'organization', true],
                        ['oib', __('ui.account.fields.vat_id').' ('.__('ui.account.fields.oib').')', 'off', true],
                        ['address_line_1', __('Adresa'), 'street-address', true],
                        ['postal_code', __('Poštanski broj'), 'postal-code', true],
                        ['city', __('Grad'), 'address-level2', true],
                    ];
                @endphp

                @foreach ($fields as $field)
                    <div class="{{ $field[0] === 'company_name' ? 'md:col-span-2' : '' }}">
                        <label for="b2b-{{ $field[0] }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $field[1] }}</label>
                        <input
                            id="b2b-{{ $field[0] }}"
                            type="{{ $field[4] ?? 'text' }}"
                            name="{{ $field[0] }}"
                            value="{{ old($field[0]) }}"
                            autocomplete="{{ $field[2] }}"
                            class="w-full px-3 text-sm @error($field[0]) border-rose-500 @enderror"
                            @if($field[0] === 'postal_code') data-address-postal inputmode="numeric" @endif
                            @if($field[0] === 'city') data-address-city @endif
                            @required($field[3])
                            @error($field[0]) aria-invalid="true" aria-describedby="b2b-{{ $field[0] }}-error" @enderror
                        >
                        @error($field[0])
                            <p id="b2b-{{ $field[0] }}-error" class="mt-2 text-xs font-semibold text-rose-600" aria-live="polite">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <div>
                    <label for="b2b-country" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Država') }}</label>
                    <select
                        id="b2b-country"
                        name="country_code"
                        class="w-full px-3 text-sm @error('country_code') border-rose-500 @enderror"
                        required
                        data-address-country
                        @error('country_code') aria-invalid="true" aria-describedby="b2b-country-error" @enderror
                    >
                        @foreach ($countryOptions as $countryOption)
                            <option value="{{ $countryOption['code'] }}" @selected(old('country_code', 'HR') === $countryOption['code'])>{{ $countryOption['label'] }}</option>
                        @endforeach
                    </select>
                    @error('country_code')
                        <p id="b2b-country-error" class="mt-2 text-xs font-semibold text-rose-600" aria-live="polite">{{ $message }}</p>
                    @enderror
                </div>

                <div></div>

                <div>
                    <label for="b2b-password" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Lozinka') }}</label>
                    <input id="b2b-password" type="password" name="password" class="w-full px-3 text-sm @error('password') border-rose-500 @enderror" autocomplete="new-password" required @error('password') aria-invalid="true" aria-describedby="b2b-password-error" @enderror>
                    @error('password')
                        <p id="b2b-password-error" class="mt-2 text-xs font-semibold text-rose-600" aria-live="polite">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="b2b-password-confirmation" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Potvrda lozinke') }}</label>
                    <input id="b2b-password-confirmation" type="password" name="password_confirmation" class="w-full px-3 text-sm @error('password_confirmation') border-rose-500 @enderror" autocomplete="new-password" required @error('password_confirmation') aria-invalid="true" aria-describedby="b2b-password-confirmation-error" @enderror>
                    @error('password_confirmation')
                        <p id="b2b-password-confirmation-error" class="mt-2 text-xs font-semibold text-rose-600" aria-live="polite">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="flex items-start gap-3 border bg-slate-50 p-4 text-sm text-slate-700 @error('terms_accepted') border-rose-500 @else border-slate-200 @enderror">
                        <input type="checkbox" name="terms_accepted" value="1" class="mt-0.5" @checked(old('terms_accepted')) required @error('terms_accepted') aria-invalid="true" aria-describedby="b2b-terms-error" @enderror>
                        <span>
                            {{ __('ui.auth.register.b2b_accuracy') }}
                            {{ __('ui.auth.register.terms_prefix') }}
                            <a href="{{ route('pages.show', ['slug' => 'uvjeti-koristenja']) }}" class="font-semibold text-blue-700 underline underline-offset-2" target="_blank" rel="noopener noreferrer">{{ __('ui.auth.register.terms_link') }}</a>.
                        </span>
                    </label>
                    @error('terms_accepted')
                        <p id="b2b-terms-error" class="mt-2 text-xs font-semibold text-rose-600" aria-live="polite">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <button type="submit" class="commerce-primary-action px-5 py-3 text-sm font-semibold">{{ __('Pošalji B2B zahtjev') }}</button>
                    @error('recaptcha_token')
                        <p class="mt-2 text-xs font-semibold text-rose-600" aria-live="polite">{{ $message }}</p>
                    @enderror
                </div>
            </form>
        </div>

        <aside class="border border-slate-200 bg-slate-50 p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Već imate račun?') }}</p>
            <h2 class="mt-2 text-xl font-bold text-slate-900">{{ __('Prijavite se') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Status B2B zahtjeva i sve aktivirane pogodnosti vidljivi su u korisničkom računu.') }}</p>
            <a href="{{ route('front.auth.login') }}" class="commerce-secondary-action mt-5 inline-flex px-4 py-2 text-sm font-semibold">{{ __('Prijava') }}</a>
        </aside>
    </section>

    @if ($captchaEnabled)
        @include('front.partials.recaptcha-v3', ['siteKey' => $captchaSiteKey])
    @endif
@endsection

@push('scripts')
    <script defer src="{{ asset('front-theme/scripts/address-autofill.js') }}?v={{ filemtime(public_path('front-theme/scripts/address-autofill.js')) }}"></script>
@endpush
