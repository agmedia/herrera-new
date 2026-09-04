@extends('front.desktop.layouts.store')

@section('title', __('contact.page_title'))
@section('body_class', 'commerce-body contact-commerce-body')
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
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('contact.heading') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('contact.subheading') }}</p>
    </section>

    <section class="contact-layout">
        <form
            method="POST"
            action="{{ route('contact.store') }}"
            class="contact-form-card border border-slate-200 p-6 sm:p-8"
            novalidate
            data-contact-form
            data-msg-name-required="{{ __('contact.validation.inline.name_required') }}"
            data-msg-email-required="{{ __('contact.validation.inline.email_required') }}"
            data-msg-email-invalid="{{ __('contact.validation.inline.email_invalid') }}"
            data-msg-message-required="{{ __('contact.validation.inline.message_required') }}"
            data-msg-message-min="{{ __('contact.validation.inline.message_min') }}"
            data-msg-accept-terms="{{ __('contact.validation.inline.accept_terms') }}"
            @if($captchaEnabled) data-recaptcha-form data-recaptcha-site-key="{{ $captchaSiteKey }}" data-recaptcha-action="contact_form" @endif
        >
            @csrf
            <input type="hidden" name="recaptcha_token" value="" data-recaptcha-token>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contact.form.name') }}</label>
                    <input type="text" name="name" value="{{ old('name', auth()->user()?->name) }}" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" required>
                    <p class="mt-2 text-xs font-semibold text-rose-600 {{ $errors->has('name') ? '' : 'hidden' }}" data-field-error="name">@error('name'){{ $message }}@enderror</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contact.form.email') }}</label>
                    <input type="email" name="email" value="{{ old('email', auth()->user()?->email) }}" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" required>
                    <p class="mt-2 text-xs font-semibold text-rose-600 {{ $errors->has('email') ? '' : 'hidden' }}" data-field-error="email">@error('email'){{ $message }}@enderror</p>
                </div>
            </div>

            <div class="mt-4">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contact.form.phone') }}</label>
                <input type="text" name="phone" value="{{ old('phone') }}" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0">
                <p class="mt-2 text-xs font-semibold text-rose-600 {{ $errors->has('phone') ? '' : 'hidden' }}" data-field-error="phone">@error('phone'){{ $message }}@enderror</p>
            </div>

            <div class="mt-4">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contact.form.subject') }}</label>
                <input type="text" name="subject" value="{{ old('subject') }}" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0">
                <p class="mt-2 text-xs font-semibold text-rose-600 {{ $errors->has('subject') ? '' : 'hidden' }}" data-field-error="subject">@error('subject'){{ $message }}@enderror</p>
            </div>

            <div class="mt-4">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('contact.form.message') }}</label>
                <textarea name="message" rows="8" class="w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" required>{{ old('message') }}</textarea>
                <p class="mt-2 text-xs font-semibold text-rose-600 {{ $errors->has('message') ? '' : 'hidden' }}" data-field-error="message">@error('message'){{ $message }}@enderror</p>
            </div>

            <div class="mt-4">
                <label class="inline-flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="accept_terms" value="1" class="mt-0.5 h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" @checked((bool) old('accept_terms'))>
                    <span>{{ __('contact.form.accept_terms') }}</span>
                </label>
                <p class="mt-2 text-xs font-semibold text-rose-600 {{ $errors->has('accept_terms') ? '' : 'hidden' }}" data-field-error="accept_terms">@error('accept_terms'){{ $message }}@enderror</p>
            </div>

            <button type="submit" class="commerce-primary-action mt-6 px-6 py-3">
                {{ __('contact.form.submit') }}
            </button>
            <p class="mt-2 text-xs font-semibold text-rose-600 {{ $errors->has('recaptcha_token') ? '' : 'hidden' }}" data-field-error="recaptcha_token">@error('recaptcha_token'){{ $message }}@enderror</p>
        </form>

        <aside class="space-y-4">
            <div class="border border-slate-200 bg-white p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('contact.direct.title') }}</h2>
                <ul class="mt-3 space-y-2 text-sm text-slate-600">
                    @if (!empty($storeSettings['footer']['email_support'] ?? ''))
                        <li><span class="font-semibold text-slate-900">{{ __('contact.direct.email') }}:</span> {{ $storeSettings['footer']['email_support'] }}</li>
                    @endif
                    @if (!empty($storeSettings['footer']['phone'] ?? ''))
                        <li><span class="font-semibold text-slate-900">{{ __('contact.direct.phone') }}:</span> {{ $storeSettings['footer']['phone'] }}</li>
                    @endif
                    <li><span class="font-semibold text-slate-900">{{ __('contact.direct.response_time') }}:</span> {{ (string) ($storeSettings['footer']['hours'] ?? __('contact.direct.response_fallback')) }}</li>
                </ul>
            </div>

            <div class="border border-slate-200 bg-slate-100 p-6">
                <h3 class="text-sm font-bold uppercase tracking-wide text-slate-700">{{ __('contact.help.title') }}</h3>
                <p class="mt-2 text-sm text-slate-700">{{ __('contact.help.body') }}</p>
            </div>
        </aside>
    </section>

    @if ($captchaEnabled)
        @push('scripts')
            <script src="https://www.google.com/recaptcha/api.js?render={{ $captchaSiteKey }}"></script>
        @endpush
    @endif

    @push('scripts')
        <script>
            (function () {
                const forms = document.querySelectorAll('[data-contact-form]');
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

                forms.forEach(function (form) {
                    const clearError = function (field) {
                        const errorNode = form.querySelector('[data-field-error="' + field + '"]');
                        if (!errorNode) {
                            return;
                        }
                        errorNode.textContent = '';
                        errorNode.classList.add('hidden');
                        errorNode.style.display = 'none';
                    };

                    const setError = function (field, message) {
                        const errorNode = form.querySelector('[data-field-error="' + field + '"]');
                        if (!errorNode) {
                            return;
                        }
                        errorNode.textContent = message;
                        errorNode.classList.remove('hidden');
                        errorNode.style.display = 'block';
                    };

                    form.querySelectorAll('[data-field-error]').forEach(function (node) {
                        if ((node.textContent || '').trim() === '') {
                            node.style.display = 'none';
                        } else {
                            node.style.display = 'block';
                            node.classList.remove('hidden');
                        }
                    });

                    const validate = function () {
                        ['name', 'email', 'message', 'accept_terms', 'recaptcha_token'].forEach(clearError);

                        const name = form.querySelector('[name="name"]');
                        const email = form.querySelector('[name="email"]');
                        const message = form.querySelector('[name="message"]');
                        const acceptTerms = form.querySelector('[name="accept_terms"]');
                        let valid = true;

                        if (!name || name.value.trim() === '') {
                            setError('name', form.dataset.msgNameRequired || '');
                            valid = false;
                        }

                        const emailValue = email ? email.value.trim() : '';
                        if (emailValue === '') {
                            setError('email', form.dataset.msgEmailRequired || '');
                            valid = false;
                        } else if (!emailRegex.test(emailValue)) {
                            setError('email', form.dataset.msgEmailInvalid || '');
                            valid = false;
                        }

                        const messageValue = message ? message.value.trim() : '';
                        if (messageValue === '') {
                            setError('message', form.dataset.msgMessageRequired || '');
                            valid = false;
                        } else if (messageValue.length < 10) {
                            setError('message', form.dataset.msgMessageMin || '');
                            valid = false;
                        }

                        if (!acceptTerms || !acceptTerms.checked) {
                            setError('accept_terms', form.dataset.msgAcceptTerms || '');
                            valid = false;
                        }

                        return valid;
                    };

                    form.addEventListener('submit', function (event) {
                        event.preventDefault();
                        if (!validate()) {
                            return;
                        }

                        const tokenInput = form.querySelector('[data-recaptcha-token]');
                        const siteKey = form.dataset.recaptchaSiteKey;
                        const action = form.dataset.recaptchaAction || 'contact_form';
                        if (!tokenInput || !window.grecaptcha || !siteKey) {
                            form.submit();
                            return;
                        }

                        grecaptcha.ready(function () {
                            grecaptcha.execute(siteKey, { action: action }).then(function (token) {
                                tokenInput.value = token || '';
                                form.submit();
                            });
                        });
                    });
                });
            }());
        </script>
    @endpush
@endsection
