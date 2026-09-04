@extends('front.desktop.layouts.store')

@section('title', __('return_request.page_title'))
@section('body_class', 'commerce-body returns-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    @php
        $captchaSiteKey = trim((string) ($storeSettings['captcha']['recaptcha_v3_site_key'] ?? ''));
        $captchaEnabled = (bool) ($storeSettings['captcha']['recaptcha_v3_enabled'] ?? false) && $captchaSiteKey !== '';
        $fieldClass = 'w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-900 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-100';
        $labelClass = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600';
    @endphp

    <section class="commerce-hero">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-700">{{ __('return_request.eyebrow') }}</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">{{ __('return_request.heading') }}</h1>
        <p class="mt-2 max-w-3xl text-slate-600">{{ __('return_request.subheading') }}</p>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm leading-6 text-emerald-950" role="status">
            <p class="font-semibold">{{ session('status') }}</p>
            @if (session('withdrawal_reference'))
                <p class="mt-1">Referenca: <strong>{{ session('withdrawal_reference') }}</strong></p>
            @endif
        </div>
    @endif
    @if (session('warning'))
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-950" role="alert">{{ session('warning') }}</div>
    @endif
    @error('draft')
        <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-900" role="alert">{{ $message }}</div>
    @enderror

    <div class="mb-6 rounded-xl border border-cyan-200 bg-cyan-50 px-5 py-4 text-sm leading-6 text-slate-800">
        <p class="font-semibold">{{ __('return_request.intro') }}</p>
        <p class="mt-1 text-slate-600">{{ __('return_request.scope_note') }}</p>
    </div>

    <section class="returns-layout">
        <form
            method="POST"
            action="{{ route('returns.review', ['returnRequestSlug' => __('return_request.slug')]) }}"
            class="returns-form-card border border-slate-200 p-6 sm:p-8"
            data-withdrawal-form
            @if($captchaEnabled) data-recaptcha-site-key="{{ $captchaSiteKey }}" data-recaptcha-action="contract_withdrawal_form" @endif
        >
            @csrf
            <input type="hidden" name="recaptcha_token" value="" data-recaptcha-token>

            @if ($errors->any() && ! $errors->has('draft'))
                <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    Provjerite označena polja i pokušajte ponovno.
                </div>
            @endif

            <fieldset>
                <legend class="mb-4 text-base font-bold text-slate-900">{{ __('return_request.form.identity_section') }}</legend>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="withdrawal-full-name" class="{{ $labelClass }}">{{ __('return_request.form.full_name') }}</label>
                        <input id="withdrawal-full-name" type="text" name="full_name" value="{{ old('full_name', $prefill['full_name'] ?? '') }}" autocomplete="name" class="{{ $fieldClass }}" required maxlength="191" @error('full_name') aria-invalid="true" @enderror>
                        @error('full_name') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="withdrawal-email" class="{{ $labelClass }}">{{ __('return_request.form.email') }}</label>
                        <input id="withdrawal-email" type="email" name="email" value="{{ old('email', $prefill['email'] ?? '') }}" autocomplete="email" class="{{ $fieldClass }}" required maxlength="191" @error('email') aria-invalid="true" @enderror>
                        <p class="mt-1 text-xs text-slate-500">{{ __('return_request.form.email_help') }}</p>
                        @error('email') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="withdrawal-phone" class="{{ $labelClass }}">{{ __('return_request.form.phone') }}</label>
                        <input id="withdrawal-phone" type="tel" name="phone" value="{{ old('phone', $prefill['phone'] ?? '') }}" autocomplete="tel" class="{{ $fieldClass }}" maxlength="80" @error('phone') aria-invalid="true" @enderror>
                        @error('phone') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="withdrawal-address" class="{{ $labelClass }}">{{ __('return_request.form.address_line') }}</label>
                        <input id="withdrawal-address" type="text" name="address_line" value="{{ old('address_line', $prefill['address_line'] ?? '') }}" autocomplete="street-address" class="{{ $fieldClass }}" required maxlength="255" @error('address_line') aria-invalid="true" @enderror>
                        @error('address_line') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="withdrawal-postal-code" class="{{ $labelClass }}">{{ __('return_request.form.postal_code') }}</label>
                        <input id="withdrawal-postal-code" type="text" name="postal_code" value="{{ old('postal_code', $prefill['postal_code'] ?? '') }}" autocomplete="postal-code" class="{{ $fieldClass }}" required maxlength="32" @error('postal_code') aria-invalid="true" @enderror>
                        @error('postal_code') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-[minmax(0,1fr)_110px] gap-3">
                        <div>
                            <label for="withdrawal-city" class="{{ $labelClass }}">{{ __('return_request.form.city') }}</label>
                            <input id="withdrawal-city" type="text" name="city" value="{{ old('city', $prefill['city'] ?? '') }}" autocomplete="address-level2" class="{{ $fieldClass }}" required maxlength="120" @error('city') aria-invalid="true" @enderror>
                            @error('city') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="withdrawal-country" class="{{ $labelClass }}">{{ __('return_request.form.country_code') }}</label>
                            <input id="withdrawal-country" type="text" name="country_code" value="{{ old('country_code', $prefill['country_code'] ?? 'HR') }}" autocomplete="country" class="{{ $fieldClass }} uppercase" required minlength="2" maxlength="2" pattern="[A-Za-z]{2}" @error('country_code') aria-invalid="true" @enderror>
                            @error('country_code') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset class="mt-8 border-t border-slate-200 pt-6">
                <legend class="mb-4 text-base font-bold text-slate-900">{{ __('return_request.form.contract_section') }}</legend>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="withdrawal-order-number" class="{{ $labelClass }}">{{ __('return_request.form.order_number') }}</label>
                        <input id="withdrawal-order-number" type="text" name="order_number" value="{{ old('order_number') }}" class="{{ $fieldClass }}" required maxlength="80" @error('order_number') aria-invalid="true" @enderror>
                        @error('order_number') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div></div>
                    <div>
                        <label for="withdrawal-contract-date" class="{{ $labelClass }}">{{ __('return_request.form.contract_date') }}</label>
                        <input id="withdrawal-contract-date" type="date" name="contract_date" value="{{ old('contract_date') }}" max="{{ now()->toDateString() }}" class="{{ $fieldClass }}" @error('contract_date') aria-invalid="true" @enderror>
                        @error('contract_date') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="withdrawal-received-date" class="{{ $labelClass }}">{{ __('return_request.form.received_date') }}</label>
                        <input id="withdrawal-received-date" type="date" name="received_date" value="{{ old('received_date') }}" max="{{ now()->toDateString() }}" class="{{ $fieldClass }}" @error('received_date') aria-invalid="true" @enderror>
                        @error('received_date') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-4">
                    <label for="withdrawal-items" class="{{ $labelClass }}">{{ __('return_request.form.items') }}</label>
                    <textarea id="withdrawal-items" name="items" rows="6" class="{{ $fieldClass }}" placeholder="{{ __('return_request.form.items_placeholder') }}" required maxlength="5000" @error('items') aria-invalid="true" @enderror>{{ old('items') }}</textarea>
                    @error('items') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-4">
                    <label for="withdrawal-note" class="{{ $labelClass }}">{{ __('return_request.form.note') }}</label>
                    <textarea id="withdrawal-note" name="note" rows="4" class="{{ $fieldClass }}" placeholder="{{ __('return_request.form.note_placeholder') }}" maxlength="5000" @error('note') aria-invalid="true" @enderror>{{ old('note') }}</textarea>
                    @error('note') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                </div>
            </fieldset>

            <p class="mt-5 text-xs leading-5 text-slate-500">{{ __('return_request.form.privacy_note') }}</p>
            @error('recaptcha_token') <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
            <button type="submit" class="commerce-primary-action mt-6 px-6 py-3" data-submit-button>
                {{ __('return_request.form.review_submit') }}
            </button>
        </form>

        <aside class="returns-help-card h-fit border border-slate-200 bg-white p-6">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-700">{{ __('return_request.help.title') }}</h2>
            <ul class="mt-3 space-y-3 text-sm leading-6 text-slate-700">
                @foreach (__('return_request.help.items') as $item)
                    <li class="flex gap-2"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-cyan-600"></span><span>{{ $item }}</span></li>
                @endforeach
            </ul>
            @if (($withdrawalSettings['return_address'] ?? '') !== '')
                <div class="mt-5 border-t border-slate-200 pt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('return_request.mail.return_address') }}</p>
                    <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $withdrawalSettings['return_address'] }}</p>
                </div>
            @endif
        </aside>
    </section>

    @if ($captchaEnabled)
        @push('scripts')
            <script src="https://www.google.com/recaptcha/api.js?render={{ $captchaSiteKey }}"></script>
        @endpush
    @endif

    @push('scripts')
        <script>
            document.querySelectorAll('[data-withdrawal-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    const tokenInput = form.querySelector('[data-recaptcha-token]');
                    const siteKey = form.dataset.recaptchaSiteKey;
                    if (!tokenInput || !siteKey || !window.grecaptcha || tokenInput.value) {
                        return;
                    }
                    event.preventDefault();
                    if (!form.reportValidity()) {
                        return;
                    }
                    const button = form.querySelector('[data-submit-button]');
                    if (button) button.disabled = true;
                    grecaptcha.ready(function () {
                        grecaptcha.execute(siteKey, { action: form.dataset.recaptchaAction }).then(function (token) {
                            tokenInput.value = token || '';
                            form.submit();
                        });
                    });
                });
            });
        </script>
    @endpush
@endsection
