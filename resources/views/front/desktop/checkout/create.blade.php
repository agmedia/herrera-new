@extends('front.desktop.layouts.store')

@section('title', __('ui.checkout.page_title'))
@section('main_class', 'w-full px-0 py-8')

@section('content')
    @php
        $showShippingAddress = old('ship_to_different_address') === '1' || old('use_billing_for_shipping') === '0';
        $selectedShippingCode = (string) old('shipping_method_code', (string) ($checkoutTotals['shipping_method_code'] ?? $shippingMethods->first()?->code ?? ''));
        $selectedPaymentCode = (string) old('payment_method_code', (string) ($checkoutTotals['payment_method_code'] ?? $paymentMethods->first()?->code ?? ''));
        $selectedBoxNowLockerId = (string) old('shipping_boxnow_locker_id', '');
        $selectedBoxNowLockerName = (string) old('shipping_boxnow_locker_name', '');
        $selectedBoxNowAddressLine1 = (string) old('shipping_boxnow_address_line_1', '');
        $selectedBoxNowPostalCode = (string) old('shipping_boxnow_postal_code', '');
        $selectedBoxNowCity = (string) old('shipping_boxnow_city', '');
        $selectedGlsDpmId = (string) old('shipping_gls_dpm_id', '');
        $selectedGlsDpmExternalId = (string) old('shipping_gls_dpm_external_id', '');
        $selectedGlsDpmName = (string) old('shipping_gls_dpm_name', '');
        $selectedGlsDpmType = (string) old('shipping_gls_dpm_type', '');
        $selectedGlsDpmAddressLine1 = (string) old('shipping_gls_dpm_address_line_1', '');
        $selectedGlsDpmPostalCode = (string) old('shipping_gls_dpm_postal_code', '');
        $selectedGlsDpmCity = (string) old('shipping_gls_dpm_city', '');
        $showLoginForm = old('checkout_login') === '1';
        $showRegisterPanel = old('register_account') === '1';
        $showR1Fields = old('want_r1_invoice') === '1'
            || old('billing_company', $prefill['billing']['company']) !== ''
            || old('billing_oib', $prefill['billing']['oib']) !== '';
        $ga4Items = collect($lines)->map(function (array $line) {
            $locale = app()->getLocale();
            $fallbackLocale = (string) config('app.locale');
            $product = $line['product'];
            $translation = $line['translation'];
            $manufacturerTranslation = $product->relationLoaded('manufacturer')
                ? ($product->manufacturer?->translations?->firstWhere('locale', $locale)
                    ?? $product->manufacturer?->translations?->firstWhere('locale', $fallbackLocale))
                : null;
            $categoryTranslation = $product->relationLoaded('categories')
                ? ($product->categories?->first()?->translations?->firstWhere('locale', $locale)
                    ?? $product->categories?->first()?->translations?->firstWhere('locale', $fallbackLocale))
                : null;

            return [
                'item_id' => (string) ($line['sku'] ?: $product->sku ?: $product->id),
                'item_name' => (string) ($translation?->name ?? $product->code),
                'item_brand' => (string) ($manufacturerTranslation?->name ?? ''),
                'item_category' => (string) ($categoryTranslation?->name ?? ''),
                'price' => round((float) ($line['display_unit_price'] ?? $line['unit_price'] ?? 0), 2),
                'quantity' => (int) ($line['quantity'] ?? 1),
            ];
        })->values()->all();
    @endphp

    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/checkout.css') }}?v={{ filemtime(public_path('front-theme/styles/checkout.css')) }}">
    @endpush

    <div class="checkout-shell">
    <section class="checkout-page-header">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.checkout.title') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('ui.checkout.subtitle') }}</p>
    </section>

    @guest
        <section class="checkout-login-card">
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input id="checkout-login-toggle" type="checkbox" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" data-checkout-login-toggle aria-controls="checkout-login-panel" aria-expanded="{{ $showLoginForm ? 'true' : 'false' }}" @checked($showLoginForm)>
                {{ __('ui.checkout.login.toggle') }}
            </label>

            <div id="checkout-login-panel" class="overflow-hidden transition-all duration-300" data-checkout-login-panel aria-hidden="{{ $showLoginForm ? 'false' : 'true' }}" @if (! $showLoginForm) inert @endif style="{{ $showLoginForm ? '' : 'max-height:0;opacity:0;' }}">
                <div class="mt-4 border-t border-slate-200 pt-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ __('ui.checkout.login.title') }}</h2>
                    <form method="POST" action="{{ route('checkout.login') }}" class="mt-3 grid gap-3 md:grid-cols-2" novalidate>
                        @csrf
                        <input type="hidden" name="checkout_login" value="1">
                        <input type="hidden" name="intended" value="{{ route('checkout.create') }}">
                        <div>
                            <label for="checkout-login-email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.checkout.login.email') }}</label>
                            <input id="checkout-login-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" required @error('email') aria-invalid="true" aria-describedby="checkout-login-email-error" @enderror>
                            @error('email')
                                <p id="checkout-login-email-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="checkout-login-password" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.checkout.login.password') }}</label>
                            <input id="checkout-login-password" type="password" name="password" autocomplete="current-password" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" required @error('password') aria-invalid="true" aria-describedby="checkout-login-password-error" @enderror>
                            @error('password')
                                <p id="checkout-login-password-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="md:col-span-2 flex items-center justify-between gap-3">
                            <div class="flex flex-wrap items-center gap-4">
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="remember" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0">
                                    {{ __('ui.checkout.login.remember') }}
                                </label>
                                <a href="{{ route('front.auth.password.request') }}" class="checkout-inline-link text-sm font-semibold">
                                    {{ __('ui.auth.login.forgot_password') }}
                                </a>
                            </div>
                            <button type="submit" class="checkout-primary-button checkout-primary-button--compact px-5 py-2">{{ __('ui.checkout.login.submit') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    @endguest

    <div class="mb-4 hidden border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700" role="alert" aria-live="polite" data-checkout-top-error></div>

    <form method="POST" action="{{ route('checkout.store') }}" class="checkout-layout" novalidate data-address-autofill data-address-source="{{ $placesAssetUrl }}" data-checkout-form data-checkout-options-url="{{ route('checkout.options') }}" data-ga4-checkout-form data-ga4-currency="EUR" data-ga4-value="{{ number_format((float) ($checkoutTotals['grand_total'] ?? $summary['grand_total'] ?? 0), 2, '.', '') }}" data-ga4-items='@json($ga4Items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)' data-success-fallback="{{ route('checkout.success.latest') }}">
        @csrf

        <div class="checkout-stack">
            <section class="checkout-card" data-address-scope="billing">
                <h2 class="text-xl font-bold text-slate-900">{{ __('ui.checkout.sections.customer') }} / {{ __('ui.checkout.sections.billing') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('ui.checkout.sections.basic_information') }}</p>

                <input type="hidden" name="customer_first_name" value="{{ old('customer_first_name', old('billing_first_name', $prefill['billing']['first_name'])) }}" data-customer-first-hidden>
                <input type="hidden" name="customer_last_name" value="{{ old('customer_last_name', old('billing_last_name', $prefill['billing']['last_name'])) }}" data-customer-last-hidden>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="billing-first-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.first_name') }}</label>
                        <input id="billing-first-name" type="text" name="billing_first_name" value="{{ old('billing_first_name', $prefill['billing']['first_name']) }}" autocomplete="billing given-name" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-billing-first required @error('billing_first_name') aria-invalid="true" aria-describedby="billing-first-name-error" @enderror>
                        @error('billing_first_name')
                            <p id="billing-first-name-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="billing-last-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.last_name') }}</label>
                        <input id="billing-last-name" type="text" name="billing_last_name" value="{{ old('billing_last_name', $prefill['billing']['last_name']) }}" autocomplete="billing family-name" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-billing-last required @error('billing_last_name') aria-invalid="true" aria-describedby="billing-last-name-error" @enderror>
                        @error('billing_last_name')
                            <p id="billing-last-name-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="customer-email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.email') }}</label>
                        <input id="customer-email" type="email" name="customer_email" value="{{ old('customer_email', $prefill['email']) }}" autocomplete="email" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" required @error('customer_email') aria-invalid="true" aria-describedby="customer-email-error" @enderror>
                        @error('customer_email')
                            <p id="customer-email-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="customer-phone" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.phone') }}</label>
                        <input id="customer-phone" type="tel" name="customer_phone" value="{{ old('customer_phone', $prefill['phone']) }}" autocomplete="tel" inputmode="tel" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" required @error('customer_phone') aria-invalid="true" aria-describedby="customer-phone-error" @enderror>
                        @error('customer_phone')
                            <p id="customer-phone-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label for="want-r1-invoice" class="inline-flex items-center gap-2 text-sm text-slate-700">
                            <input id="want-r1-invoice" type="checkbox" name="want_r1_invoice" value="1" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" data-r1-toggle aria-controls="r1-fields" aria-expanded="{{ $showR1Fields ? 'true' : 'false' }}" @checked($showR1Fields)>
                            {{ __('ui.checkout.options.r1_invoice') }}
                        </label>
                    </div>
                    <div id="r1-fields" class="md:col-span-2 overflow-hidden transition-all duration-300" data-r1-panel aria-hidden="{{ $showR1Fields ? 'false' : 'true' }}" style="{{ $showR1Fields ? '' : 'max-height:0;opacity:0;' }}">
                        <div class="grid gap-4 border-t border-slate-200 pt-4 md:grid-cols-2">
                            <div><label for="billing-company" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.company') }}</label><input id="billing-company" type="text" name="billing_company" value="{{ old('billing_company', $prefill['billing']['company']) }}" autocomplete="billing organization" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-r1-company></div>
                            <div><label for="billing-oib" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.oib') }}</label><input id="billing-oib" type="text" name="billing_oib" value="{{ old('billing_oib', $prefill['billing']['oib']) }}" inputmode="numeric" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-r1-oib></div>
                        </div>
                    </div>
                    <div>
                        <label for="billing-address" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.address_line_1') }}</label>
                        <input id="billing-address" type="text" name="billing_address_line_1" value="{{ old('billing_address_line_1', $prefill['billing']['address_line_1']) }}" autocomplete="billing street-address" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" required @error('billing_address_line_1') aria-invalid="true" aria-describedby="billing-address-error" @enderror>
                        @error('billing_address_line_1')
                            <p id="billing-address-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="billing-postal-code" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.postal_code') }}</label>
                        <input id="billing-postal-code" type="text" name="billing_postal_code" value="{{ old('billing_postal_code', $prefill['billing']['postal_code']) }}" autocomplete="billing postal-code" inputmode="numeric" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-address-postal required @error('billing_postal_code') aria-invalid="true" aria-describedby="billing-postal-code-error" @enderror>
                        @error('billing_postal_code')
                            <p id="billing-postal-code-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="billing-city" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.city') }}</label>
                        <input id="billing-city" type="text" name="billing_city" value="{{ old('billing_city', $prefill['billing']['city']) }}" autocomplete="billing address-level2" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-address-city required @error('billing_city') aria-invalid="true" aria-describedby="billing-city-error" @enderror>
                        @error('billing_city')
                            <p id="billing-city-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="billing-country" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.country_code') }}</label>
                        <select id="billing-country" name="billing_country_code" autocomplete="billing country" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-address-country required>
                            @foreach ($countryOptions as $countryOption)
                                <option value="{{ $countryOption['code'] }}" @selected(old('billing_country_code', $prefill['billing']['country_code']) === $countryOption['code'])>{{ $countryOption['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @guest
                    <div class="mt-4 border-t border-slate-200 pt-4">
                        <label for="register-account" class="inline-flex items-center gap-2 text-sm text-slate-700">
                            <input id="register-account" type="checkbox" name="register_account" value="1" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" data-register-account-toggle aria-controls="register-account-fields" aria-expanded="{{ $showRegisterPanel ? 'true' : 'false' }}" @checked($showRegisterPanel)>
                            {{ __('ui.checkout.options.register_account') }}
                        </label>

                        <div id="register-account-fields" class="overflow-hidden transition-all duration-300" data-register-account-panel aria-hidden="{{ $showRegisterPanel ? 'false' : 'true' }}" style="{{ $showRegisterPanel ? '' : 'max-height:0;opacity:0;' }}">
                            <div class="mt-4 grid gap-4 border-t border-slate-200 pt-4 md:grid-cols-2">
                                <div>
                                    <label for="register-password" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.checkout.register.password') }}</label>
                                    <input id="register-password" type="password" name="register_password" autocomplete="new-password" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-register-password @disabled(! $showRegisterPanel) @error('register_password') aria-invalid="true" aria-describedby="register-password-error" @enderror>
                                    @error('register_password')
                                        <p id="register-password-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <label for="register-password-confirmation" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.checkout.register.password_repeat') }}</label>
                                    <input id="register-password-confirmation" type="password" name="register_password_confirmation" autocomplete="new-password" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-register-password-confirmation @disabled(! $showRegisterPanel)>
                                </div>
                            </div>
                        </div>
                    </div>
                @endguest
            </section>

            <section class="checkout-card" data-address-scope="shipping">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xl font-bold text-slate-900">{{ __('ui.checkout.sections.shipping') }}</h2>
                    <label for="ship-to-different-address" class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input id="ship-to-different-address" type="checkbox" name="ship_to_different_address" value="1" @checked($showShippingAddress) class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" data-ship-to-different aria-controls="shipping-address-fields" aria-expanded="{{ $showShippingAddress ? 'true' : 'false' }}">
                        {{ __('ui.checkout.options.ship_to_different_address') }}
                    </label>
                </div>

                <input type="hidden" name="use_billing_for_shipping" value="{{ $showShippingAddress ? '0' : '1' }}" data-use-billing-for-shipping>

                <div id="shipping-address-fields" class="overflow-hidden transition-all duration-300" data-shipping-fields aria-hidden="{{ $showShippingAddress ? 'false' : 'true' }}" @if (! $showShippingAddress) inert @endif style="{{ $showShippingAddress ? '' : 'max-height:0;opacity:0;' }}">
                    <div class="mt-4 grid gap-4 border-t border-slate-200 pt-4 md:grid-cols-2">
                        <div><label for="shipping-first-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.first_name') }}</label><input id="shipping-first-name" type="text" name="shipping_first_name" value="{{ old('shipping_first_name', $prefill['shipping']['first_name']) }}" autocomplete="shipping given-name" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0"></div>
                        <div><label for="shipping-last-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.last_name') }}</label><input id="shipping-last-name" type="text" name="shipping_last_name" value="{{ old('shipping_last_name', $prefill['shipping']['last_name']) }}" autocomplete="shipping family-name" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0"></div>
                        <div><label for="shipping-vat-id" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.vat_id') }}</label><input id="shipping-vat-id" type="text" name="shipping_vat_id" value="{{ old('shipping_vat_id', $prefill['shipping']['vat_id']) }}" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0"></div>
                        <div><label for="shipping-address" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.address_line_1') }}</label><input id="shipping-address" type="text" name="shipping_address_line_1" value="{{ old('shipping_address_line_1', $prefill['shipping']['address_line_1']) }}" autocomplete="shipping street-address" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0"></div>
                        <div><label for="shipping-postal-code" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.postal_code') }}</label><input id="shipping-postal-code" type="text" name="shipping_postal_code" value="{{ old('shipping_postal_code', $prefill['shipping']['postal_code']) }}" autocomplete="shipping postal-code" inputmode="numeric" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-address-postal></div>
                        <div><label for="shipping-city" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.city') }}</label><input id="shipping-city" type="text" name="shipping_city" value="{{ old('shipping_city', $prefill['shipping']['city']) }}" autocomplete="shipping address-level2" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-address-city></div>
                        <div>
                            <label for="shipping-country" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.country_code') }}</label>
                            <select id="shipping-country" name="shipping_country_code" autocomplete="shipping country" class="h-11 w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0" data-address-country>
                                @foreach ($countryOptions as $countryOption)
                                    <option value="{{ $countryOption['code'] }}" @selected(old('shipping_country_code', $prefill['shipping']['country_code']) === $countryOption['code'])>{{ $countryOption['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            <section class="checkout-card">
                <h2 class="text-xl font-bold text-slate-900">{{ __('ui.checkout.sections.shipping_payment') }}</h2>

                <div class="mt-4 space-y-5">
                    <fieldset>
                        <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.checkout.labels.shipping_method') }}</legend>
                        <div class="grid gap-2" data-checkout-shipping-options>
                            @foreach ($shippingMethods as $method)
                                <label class="checkout-option-card flex cursor-pointer items-center justify-between gap-3 px-3 py-2.5 text-sm">
                                    <span class="inline-flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="shipping_method_code"
                                            value="{{ $method->code }}"
                                            data-is-boxnow="{{ in_array(strtolower((string) $method->code), ['boxnow', 'box_now'], true) ? '1' : '0' }}"
                                            data-boxnow-partner-id="{{ (string) ((is_array($method->settings ?? null) ? ($method->settings['boxnow_partner_id'] ?? '') : '') ?: '') }}"
                                            data-is-gls-dpm="{{ \App\Support\GlsShipping::isGlsDpmShippingMethod($method) ? '1' : '0' }}"
                                            data-gls-dpm-filter-type="{{ \App\Support\GlsShipping::glsDpmFilterType($method) ?? '' }}"
                                            @checked($selectedShippingCode === (string) $method->code)
                                            class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0"
                                            required
                                        >
                                        <span class="font-semibold text-slate-900">{{ $method->name }}</span>
                                    </span>
                                    <span class="text-slate-600">
                                        {{ (string) $method->pricing_type === 'quote'
                                            ? __('Cijena na upit')
                                            : \App\Support\Currency::format((float) ($method->resolved_price ?? $method->price)) }}
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <div class="checkout-inline-panel mt-3 hidden p-3" data-boxnow-panel>
                            <input type="hidden" name="shipping_boxnow_locker_id" value="{{ $selectedBoxNowLockerId }}" data-boxnow-locker-id>
                            <input type="hidden" name="shipping_boxnow_locker_name" value="{{ $selectedBoxNowLockerName }}" data-boxnow-locker-name>
                            <input type="hidden" name="shipping_boxnow_address_line_1" value="{{ $selectedBoxNowAddressLine1 }}" data-boxnow-address-line-1>
                            <input type="hidden" name="shipping_boxnow_postal_code" value="{{ $selectedBoxNowPostalCode }}" data-boxnow-postal-code>
                            <input type="hidden" name="shipping_boxnow_city" value="{{ $selectedBoxNowCity }}" data-boxnow-city>

                            <div class="flex flex-col items-start gap-3">
                                <button type="button" class="checkout-primary-button checkout-primary-button--compact px-4 py-2 text-xs uppercase tracking-wide" data-boxnow-open>
                                    {{ __('ui.checkout.boxnow.select_locker') }}
                                </button>
                                <div class="w-full border border-cyan-300 bg-cyan-50 px-3 py-3 shadow-sm" data-boxnow-selection-summary aria-live="polite">
                                    <p class="text-sm font-semibold text-slate-900" data-boxnow-selected>
                                        {{ $selectedBoxNowLockerId !== '' ? $selectedBoxNowLockerName.' ('.$selectedBoxNowLockerId.')' : __('ui.checkout.boxnow.no_locker_selected') }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-700" data-boxnow-selected-address>
                                        {{ trim($selectedBoxNowAddressLine1.', '.$selectedBoxNowPostalCode.' '.$selectedBoxNowCity, ', ') }}
                                    </p>
                                </div>
                            </div>
                            @error('shipping_boxnow_locker_id')
                                <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="checkout-inline-panel mt-3 hidden p-3" data-gls-dpm-panel>
                            <input type="hidden" name="shipping_gls_dpm_id" value="{{ $selectedGlsDpmId }}" data-gls-dpm-id>
                            <input type="hidden" name="shipping_gls_dpm_external_id" value="{{ $selectedGlsDpmExternalId }}" data-gls-dpm-external-id>
                            <input type="hidden" name="shipping_gls_dpm_name" value="{{ $selectedGlsDpmName }}" data-gls-dpm-name>
                            <input type="hidden" name="shipping_gls_dpm_type" value="{{ $selectedGlsDpmType }}" data-gls-dpm-type>
                            <input type="hidden" name="shipping_gls_dpm_address_line_1" value="{{ $selectedGlsDpmAddressLine1 }}" data-gls-dpm-address-line-1>
                            <input type="hidden" name="shipping_gls_dpm_postal_code" value="{{ $selectedGlsDpmPostalCode }}" data-gls-dpm-postal-code>
                            <input type="hidden" name="shipping_gls_dpm_city" value="{{ $selectedGlsDpmCity }}" data-gls-dpm-city>

                            <div class="flex flex-col items-start gap-3">
                                <button type="button" class="checkout-primary-button checkout-primary-button--compact px-4 py-2 text-xs uppercase tracking-wide" data-gls-dpm-open>
                                    {{ __('Odaberi GLS paketomat / ParcelShop') }}
                                </button>
                                <div class="w-full border border-cyan-300 bg-cyan-50 px-3 py-3 shadow-sm" data-gls-dpm-selection-summary aria-live="polite">
                                    <p class="text-sm font-semibold text-slate-900" data-gls-dpm-selected>
                                        {{ $selectedGlsDpmId !== '' ? $selectedGlsDpmName.' ('.$selectedGlsDpmId.')' : __('GLS lokacija nije odabrana.') }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-700" data-gls-dpm-selected-address>
                                        {{ trim($selectedGlsDpmAddressLine1.', '.$selectedGlsDpmPostalCode.' '.$selectedGlsDpmCity, ', ') }}
                                    </p>
                                </div>
                            </div>
                            @error('shipping_gls_dpm_id')
                                <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.checkout.labels.payment_method') }}</legend>
                        <div class="grid gap-2" data-checkout-payment-options>
                            @foreach ($paymentMethods as $method)
                                <label class="checkout-option-card flex cursor-pointer items-center justify-between gap-3 px-3 py-2.5 text-sm">
                                    <span class="inline-flex items-center gap-2">
                                        <input type="radio" name="payment_method_code" value="{{ $method->code }}" @checked($selectedPaymentCode === (string) $method->code) class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" required>
                                        @if (in_array(strtolower((string) $method->code), ['keks', 'keks_pay', 'kekspay'], true))
                                            <span class="inline-flex items-center gap-2">
                                                <img src="{{ asset('assets/payments/keks-logo.svg') }}" alt="KEKS Pay" class="h-5 w-auto max-w-[110px]">
                                                <span class="font-semibold text-slate-900">{{ $method->name }}</span>
                                            </span>
                                        @else
                                            <span class="font-semibold text-slate-900">{{ $method->name }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>

                <div class="mt-4">
                    <label for="customer-note" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.checkout.fields.order_note') }}</label>
                    <textarea id="customer-note" name="customer_note" rows="3" class="w-full border-slate-300 text-sm focus:border-slate-500 focus:ring-0">{{ old('customer_note') }}</textarea>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-start gap-x-4 gap-y-2 lg:justify-between">
                    <label for="accept-terms" class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input id="accept-terms" type="checkbox" name="accept_terms" value="1" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" required @checked((bool) old('accept_terms')) @error('accept_terms') aria-invalid="true" aria-describedby="accept-terms-error" @enderror>
                        <span>
                            {{ __('ui.checkout.options.accept_terms_prefix') }}
                            <a href="{{ route('pages.show', ['slug' => 'uvjeti-koristenja']) }}" class="font-semibold text-blue-700 underline underline-offset-2" target="_blank" rel="noopener noreferrer">{{ __('ui.auth.register.terms_link') }}</a>.
                        </span>
                    </label>

                    <label for="newsletter-opt-in" class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input id="newsletter-opt-in" type="checkbox" name="newsletter_opt_in" value="1" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" @checked((bool) old('newsletter_opt_in', false))>
                        {{ __('ui.checkout.options.newsletter_opt_in') }}
                    </label>
                </div>
                @error('accept_terms')
                    <p id="accept-terms-error" class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                @enderror
            </section>
        </div>

        <aside class="checkout-summary h-fit self-start">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('ui.checkout.summary_title') }}</h2>

            <div class="mt-4 space-y-3">
                @foreach ($lines as $line)
                    @php
                        $translation = $line['translation'];
                        $product = $line['product'];
                        $productImage = $product->getFirstMedia('product_main') ?? $product->getFirstMedia('product_gallery');
                        $productImageUrl = $productImage
                            ? ($productImage->hasGeneratedConversion('thumb_100x100') ? $productImage->getUrl('thumb_100x100') : $productImage->getUrl())
                            : null;
                    @endphp
                    <div class="checkout-summary-line flex items-start gap-3">
                        <div class="w-16 shrink-0 border border-slate-200 bg-slate-50 p-1">
                            @if ($productImageUrl)
                                <img
                                    src="{{ $productImageUrl }}"
                                    alt="{{ $translation?->name ?? $line['product']->code }}"
                                    class="h-auto w-full"
                                    loading="lazy"
                                    decoding="async"
                                >
                            @else
                                <span class="flex h-full w-full items-center justify-center text-[10px] font-semibold uppercase text-slate-500">{{ __('ui.product.no_image') }}</span>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-slate-900">{{ $translation?->name ?? $line['product']->code }}</div>
                            @if (!empty($line['sku']))
                                <div class="mt-0.5 text-xs text-slate-500">{{ __('ui.checkout.labels.sku') }}: {{ $line['sku'] }}</div>
                            @endif
                            @if (!empty($line['option_label']))
                                <div class="mt-0.5 text-xs text-slate-500">{{ $line['option_label'] }}</div>
                            @endif
                            @if (!empty($line['is_b2b_price']))
                                <div class="mt-0.5 text-[11px] font-semibold text-cyan-800">{{ __('ui.product.b2b_contract_price') }}</div>
                            @endif
                            <div class="mt-1 flex items-center justify-between text-sm">
                                <span class="text-slate-600">{{ __('ui.checkout.labels.qty') }} {{ $line['quantity'] }}</span>
                                <span class="font-semibold text-slate-900">{{ \App\Support\Currency::format((float) ($line['display_line_total'] ?? $line['line_total'])) }}</span>
                            </div>
                            <x-front.energy-label-arrow :declaration="$line['energy_declaration'] ?? null" class="mt-1" />
                            <x-front.energy-information-sheet-link :declaration="$line['energy_declaration'] ?? null" class="mt-1" />
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 border-t border-slate-200 pt-4 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-slate-600">{{ __('ui.checkout.labels.items') }}</span>
                    <span class="font-semibold text-slate-900">{{ $summary['item_qty'] }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-slate-600">{{ __('ui.checkout.labels.subtotal') }}</span>
                    <span class="font-semibold text-slate-900">{{ \App\Support\Currency::format((float) $summary['subtotal']) }}</span>
                </div>
                @if ((float) ($summary['discount_total'] ?? 0) > 0)
                    <div class="mt-2 flex items-center justify-between">
                        <span class="text-slate-600">{{ __('ui.checkout.labels.discount') }}</span>
                        <span class="font-semibold text-emerald-700">-{{ \App\Support\Currency::format((float) $summary['discount_total']) }}</span>
                    </div>
                @endif
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-slate-600">{{ __('ui.checkout.labels.tax') }}</span>
                    <span class="font-semibold text-slate-900" data-summary-tax>{{ \App\Support\Currency::format((float) ($checkoutTotals['tax_total'] ?? $summary['tax_total'] ?? 0)) }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between" data-summary-shipping-row>
                    <span class="text-slate-600">{{ __('ui.checkout.labels.shipping') }}</span>
                    <span class="font-semibold text-slate-900" data-summary-shipping>{{ \App\Support\Currency::format((float) ($checkoutTotals['shipping_total'] ?? 0)) }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between {{ (float) ($checkoutTotals['payment_fee_total'] ?? 0) <= 0 ? 'hidden' : '' }}" data-summary-payment-fee-row>
                    <span class="text-slate-600">{{ __('ui.checkout.labels.payment_fee') }}</span>
                    <span class="font-semibold text-slate-900" data-summary-payment-fee>{{ \App\Support\Currency::format((float) ($checkoutTotals['payment_fee_total'] ?? 0)) }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between border-t border-slate-200 pt-2">
                    <span class="text-slate-900">{{ __('ui.checkout.labels.total') }}</span>
                    <span class="font-bold text-slate-900" data-summary-total>{{ \App\Support\Currency::format((float) ($checkoutTotals['grand_total'] ?? $summary['grand_total'] ?? 0)) }}</span>
                </div>
            </div>

            <button type="submit" class="checkout-primary-button mt-5 w-full px-4 py-3">{{ __('ui.checkout.actions.place_order') }}</button>
        </aside>
    </form>

    <div id="boxnow-widget-root"></div>
    <gls-dpm-dialog id="gls-dpm-dialog" country="hr" language="hr"></gls-dpm-dialog>
    </div>
@endsection

@push('scripts')
    <script defer src="{{ asset('front-theme/scripts/address-autofill.js') }}?v={{ filemtime(public_path('front-theme/scripts/address-autofill.js')) }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const checkoutForm = document.querySelector('[data-checkout-form]');
            const toggle = document.querySelector('[data-ship-to-different]');
            const shippingFields = document.querySelector('[data-shipping-fields]');
            const useBillingInput = document.querySelector('[data-use-billing-for-shipping]');
            const billingFirst = document.querySelector('[data-billing-first]');
            const billingLast = document.querySelector('[data-billing-last]');
            const customerFirstHidden = document.querySelector('[data-customer-first-hidden]');
            const customerLastHidden = document.querySelector('[data-customer-last-hidden]');
            const loginToggle = document.querySelector('[data-checkout-login-toggle]');
            const loginPanel = document.querySelector('[data-checkout-login-panel]');
            const registerToggle = document.querySelector('[data-register-account-toggle]');
            const registerPanel = document.querySelector('[data-register-account-panel]');
            const registerPassword = document.querySelector('[data-register-password]');
            const registerPasswordConfirmation = document.querySelector('[data-register-password-confirmation]');
            const r1Toggle = document.querySelector('[data-r1-toggle]');
            const r1Panel = document.querySelector('[data-r1-panel]');
            const r1Company = document.querySelector('[data-r1-company]');
            const r1Oib = document.querySelector('[data-r1-oib]');
            const optionsUrl = checkoutForm?.dataset.checkoutOptionsUrl || '';
            const shippingOptionsRoot = checkoutForm?.querySelector('[data-checkout-shipping-options]');
            const paymentOptionsRoot = checkoutForm?.querySelector('[data-checkout-payment-options]');
            const topErrorBox = document.querySelector('[data-checkout-top-error]');
            const boxNowPanel = checkoutForm?.querySelector('[data-boxnow-panel]');
            const boxNowOpenButton = checkoutForm?.querySelector('[data-boxnow-open]');
            const boxNowSelectedLabel = checkoutForm?.querySelector('[data-boxnow-selected]');
            const boxNowSelectedAddress = checkoutForm?.querySelector('[data-boxnow-selected-address]');
            const boxNowLockerId = checkoutForm?.querySelector('[data-boxnow-locker-id]');
            const boxNowLockerName = checkoutForm?.querySelector('[data-boxnow-locker-name]');
            const boxNowAddressLine1 = checkoutForm?.querySelector('[data-boxnow-address-line-1]');
            const boxNowPostalCode = checkoutForm?.querySelector('[data-boxnow-postal-code]');
            const boxNowCity = checkoutForm?.querySelector('[data-boxnow-city]');
            const glsDpmPanel = checkoutForm?.querySelector('[data-gls-dpm-panel]');
            const glsDpmOpenButton = checkoutForm?.querySelector('[data-gls-dpm-open]');
            const glsDpmSelectedLabel = checkoutForm?.querySelector('[data-gls-dpm-selected]');
            const glsDpmSelectedAddress = checkoutForm?.querySelector('[data-gls-dpm-selected-address]');
            const glsDpmId = checkoutForm?.querySelector('[data-gls-dpm-id]');
            const glsDpmExternalId = checkoutForm?.querySelector('[data-gls-dpm-external-id]');
            const glsDpmName = checkoutForm?.querySelector('[data-gls-dpm-name]');
            const glsDpmType = checkoutForm?.querySelector('[data-gls-dpm-type]');
            const glsDpmAddressLine1 = checkoutForm?.querySelector('[data-gls-dpm-address-line-1]');
            const glsDpmPostalCode = checkoutForm?.querySelector('[data-gls-dpm-postal-code]');
            const glsDpmCity = checkoutForm?.querySelector('[data-gls-dpm-city]');
            const glsDpmDialog = document.getElementById('gls-dpm-dialog');
            const summaryTax = checkoutForm?.querySelector('[data-summary-tax]');
            const summaryShipping = checkoutForm?.querySelector('[data-summary-shipping]');
            const summaryPaymentFee = checkoutForm?.querySelector('[data-summary-payment-fee]');
            const summaryTotal = checkoutForm?.querySelector('[data-summary-total]');
            const summaryShippingRow = checkoutForm?.querySelector('[data-summary-shipping-row]');
            const summaryPaymentFeeRow = checkoutForm?.querySelector('[data-summary-payment-fee-row]');

            let optionsAbortController = null;
            let optionsRefreshTimer = null;
            let boxNowScriptLoaded = false;
            let glsWidgetPromise = null;

            const syncCustomerNames = function () {
                if (!billingFirst || !billingLast || !customerFirstHidden || !customerLastHidden) {
                    return;
                }

                customerFirstHidden.value = billingFirst.value;
                customerLastHidden.value = billingLast.value;
            };

            const setShippingState = function () {
                if (!toggle || !shippingFields || !useBillingInput) {
                    return;
                }

                if (toggle.checked) {
                    useBillingInput.value = '0';
                    shippingFields.style.maxHeight = shippingFields.scrollHeight + 'px';
                    shippingFields.style.opacity = '1';
                    toggle.setAttribute('aria-expanded', 'true');
                    shippingFields.setAttribute('aria-hidden', 'false');
                    shippingFields.inert = false;
                } else {
                    useBillingInput.value = '1';
                    shippingFields.style.maxHeight = '0';
                    shippingFields.style.opacity = '0';
                    toggle.setAttribute('aria-expanded', 'false');
                    shippingFields.setAttribute('aria-hidden', 'true');
                    shippingFields.inert = true;
                }

                scheduleOptionsRefresh();
            };

            const escapeHtml = function (value) {
                return String(value || '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            };

            const renderShippingOptions = function (methods, selectedCode) {
                if (!shippingOptionsRoot) {
                    return;
                }

                const currentlySelected = shippingOptionsRoot.querySelector('input[name="shipping_method_code"]:checked')?.value || '';
                if (!Array.isArray(methods) || methods.length === 0) {
                    shippingOptionsRoot.innerHTML = '<div class="text-sm text-rose-600">{{ __('ui.checkout.labels.no_shipping_methods') }}</div>';
                    return;
                }

                shippingOptionsRoot.innerHTML = methods.map(function (method, index) {
                    const checked = (selectedCode && selectedCode === method.code)
                        || (!selectedCode && (currentlySelected !== '' && currentlySelected === method.code))
                        || (!selectedCode && currentlySelected === '' && index === 0);
                    const isBoxNow = method.is_boxnow ? '1' : '0';
                    const partnerId = escapeHtml(method.boxnow_partner_id || '');
                    const isGlsDpm = method.is_gls_dpm ? '1' : '0';
                    const glsDpmFilterType = escapeHtml(method.gls_dpm_filter_type || '');
                    return '<label class="checkout-option-card flex cursor-pointer items-center justify-between gap-3 px-3 py-2.5 text-sm">'
                        + '<span class="inline-flex items-center gap-2">'
                        + '<input type="radio" name="shipping_method_code" value="' + escapeHtml(method.code) + '" data-is-boxnow="' + isBoxNow + '" data-boxnow-partner-id="' + partnerId + '" data-is-gls-dpm="' + isGlsDpm + '" data-gls-dpm-filter-type="' + glsDpmFilterType + '" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" required ' + (checked ? 'checked' : '') + '>'
                        + '<span class="font-semibold text-slate-900">' + escapeHtml(method.name) + '</span>'
                        + '</span>'
                        + '<span class="text-slate-600">' + escapeHtml(method.price_formatted || '') + '</span>'
                        + '</label>';
                }).join('');

                toggleBoxNowPanel();
                toggleGlsDpmPanel();
            };

            const renderPaymentOptions = function (methods, selectedCode) {
                if (!paymentOptionsRoot) {
                    return;
                }

                const currentlySelected = paymentOptionsRoot.querySelector('input[name="payment_method_code"]:checked')?.value || '';
                if (!Array.isArray(methods) || methods.length === 0) {
                    paymentOptionsRoot.innerHTML = '<div class="text-sm text-rose-600">{{ __('ui.checkout.labels.no_payment_methods') }}</div>';
                    return;
                }

                paymentOptionsRoot.innerHTML = methods.map(function (method, index) {
                    const checked = (selectedCode && selectedCode === method.code)
                        || (!selectedCode && (currentlySelected !== '' && currentlySelected === method.code))
                        || (!selectedCode && currentlySelected === '' && index === 0);
                    const code = String(method.code || '').toLowerCase();
                    const isKeks = code === 'keks' || code === 'keks_pay' || code === 'kekspay';
                    const methodLabel = isKeks
                        ? '<span class="inline-flex items-center gap-2"><img src="{{ asset('assets/payments/keks-logo.svg') }}" alt="KEKS Pay" class="h-5 w-auto max-w-[110px]"><span class="font-semibold text-slate-900">' + escapeHtml(method.name) + '</span></span>'
                        : '<span class="font-semibold text-slate-900">' + escapeHtml(method.name) + '</span>';
                    return '<label class="checkout-option-card flex cursor-pointer items-center justify-between gap-3 px-3 py-2.5 text-sm">'
                        + '<span class="inline-flex items-center gap-2">'
                        + '<input type="radio" name="payment_method_code" value="' + escapeHtml(method.code) + '" class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0" required ' + (checked ? 'checked' : '') + '>'
                        + methodLabel
                        + '</span>'
                        + '</label>';
                }).join('');
            };

            const renderCheckoutTotals = function (totals) {
                if (!totals || typeof totals !== 'object') {
                    return;
                }

                if (summaryTax && totals.tax_total_formatted) {
                    summaryTax.textContent = String(totals.tax_total_formatted);
                }
                if (summaryShipping && totals.shipping_total_formatted) {
                    summaryShipping.textContent = String(totals.shipping_total_formatted);
                }
                if (summaryPaymentFee && totals.payment_fee_total_formatted) {
                    summaryPaymentFee.textContent = String(totals.payment_fee_total_formatted);
                }
                if (summaryTotal && totals.grand_total_formatted) {
                    summaryTotal.textContent = String(totals.grand_total_formatted);
                }

                const paymentFeeTotal = Number(totals.payment_fee_total || 0);
                summaryShippingRow?.classList.remove('hidden');
                summaryPaymentFeeRow?.classList.toggle('hidden', paymentFeeTotal <= 0);
            };

            const selectedShippingInput = function () {
                return shippingOptionsRoot?.querySelector('input[name="shipping_method_code"]:checked') || null;
            };

            const toggleBoxNowPanel = function () {
                if (!boxNowPanel) {
                    return;
                }

                const selected = selectedShippingInput();
                const isBoxNow = selected?.dataset?.isBoxnow === '1' || selected?.dataset?.isBoxnow === 'true';
                boxNowPanel.classList.toggle('hidden', !isBoxNow);
            };

            const toggleGlsDpmPanel = function () {
                if (!glsDpmPanel) {
                    return;
                }

                const selected = selectedShippingInput();
                const isGlsDpm = selected?.dataset?.isGlsDpm === '1' || selected?.dataset?.isGlsDpm === 'true';
                const filterType = String(selected?.dataset?.glsDpmFilterType || '').trim();

                glsDpmPanel.classList.toggle('hidden', !isGlsDpm);

                if (!glsDpmDialog) {
                    return;
                }

                if (filterType !== '') {
                    glsDpmDialog.setAttribute('filter-type', filterType);
                } else {
                    glsDpmDialog.removeAttribute('filter-type');
                }
            };

            const initBoxNowWidget = function () {
                if (boxNowScriptLoaded) {
                    return;
                }

                const partnerSource = shippingOptionsRoot?.querySelector('input[name="shipping_method_code"][data-is-boxnow="1"]');
                const partnerId = String(partnerSource?.dataset?.boxnowPartnerId || '').trim();
                if (partnerId === '') {
                    return;
                }

                window._bn_map_widget_config = {
                    partnerId: partnerId,
                    parentElement: '#boxnow-widget-root',
                    buttonSelector: '[data-boxnow-open]',
                    type: 'popup',
                    afterSelect: function (selection) {
                        updateBoxNowSelection(selection || {});
                    },
                };

                const script = document.createElement('script');
                script.src = 'https://widget-cdn.boxnow.hr/map-widget/client/v5.js';
                script.async = true;
                script.defer = true;
                script.onerror = function () {
                    alert(@json(__('ui.checkout.boxnow.widget_unavailable')));
                };
                document.head.appendChild(script);
                boxNowScriptLoaded = true;
            };

            const ensureGlsWidgetLoaded = function () {
                if (window.customElements?.get('gls-dpm-dialog')) {
                    return Promise.resolve();
                }

                if (glsWidgetPromise) {
                    return glsWidgetPromise;
                }

                glsWidgetPromise = new Promise(function (resolve, reject) {
                    const script = document.createElement('script');
                    script.type = 'module';
                    script.src = 'https://map.gls-hungary.com/widget/gls-dpm.js';
                    script.onload = resolve;
                    script.onerror = function () {
                        glsWidgetPromise = null;
                        reject(new Error('GLS widget load failed.'));
                    };
                    document.head.appendChild(script);
                });

                return glsWidgetPromise;
            };

            const updateBoxNowSelection = function (selection) {
                const lockerId = String(selection?.boxnowLockerId || selection?.id || '');
                const lockerAddress = String(selection?.boxnowLockerAddressLine1 || selection?.addressLine1 || '');
                const lockerPostal = String(selection?.boxnowLockerPostalCode || selection?.postalCode || '');
                const lockerCity = String(selection?.boxnowLockerCity || selection?.city || '');
                const lockerName = String(selection?.boxnowLockerDescription || selection?.name || '');

                if (boxNowLockerId) boxNowLockerId.value = lockerId;
                if (boxNowLockerName) boxNowLockerName.value = lockerName;
                if (boxNowAddressLine1) boxNowAddressLine1.value = lockerAddress;
                if (boxNowPostalCode) boxNowPostalCode.value = lockerPostal;
                if (boxNowCity) boxNowCity.value = lockerCity;

                if (boxNowSelectedLabel) {
                    boxNowSelectedLabel.textContent = lockerId !== ''
                        ? [lockerName, lockerId].filter(Boolean).join(' / ')
                        : @json(__('ui.checkout.boxnow.no_locker_selected'));
                }
                if (boxNowSelectedAddress) {
                    boxNowSelectedAddress.textContent = lockerId !== ''
                        ? [lockerAddress, (lockerPostal + ' ' + lockerCity).trim()].filter(Boolean).join(', ')
                        : '';
                }
            };

            const updateGlsDpmSelection = function (selection) {
                const locationId = String(selection?.id || '');
                const externalId = String(selection?.externalId || '');
                const locationName = String(selection?.name || '');
                const locationType = String(selection?.type || '');
                const address = String(selection?.contact?.address || '');
                const postalCode = String(selection?.contact?.postalCode || '');
                const city = String(selection?.contact?.city || '');

                if (glsDpmId) glsDpmId.value = locationId;
                if (glsDpmExternalId) glsDpmExternalId.value = externalId;
                if (glsDpmName) glsDpmName.value = locationName;
                if (glsDpmType) glsDpmType.value = locationType;
                if (glsDpmAddressLine1) glsDpmAddressLine1.value = address;
                if (glsDpmPostalCode) glsDpmPostalCode.value = postalCode;
                if (glsDpmCity) glsDpmCity.value = city;

                if (glsDpmSelectedLabel) {
                    glsDpmSelectedLabel.textContent = locationId !== ''
                        ? [locationName, locationId].filter(Boolean).join(' / ')
                        : @json(__('GLS lokacija nije odabrana.'));
                }

                if (glsDpmSelectedAddress) {
                    glsDpmSelectedAddress.textContent = locationId !== ''
                        ? [address, (postalCode + ' ' + city).trim()].filter(Boolean).join(', ')
                        : '';
                }
            };

            const refreshCheckoutOptions = async function () {
                if (!checkoutForm || !optionsUrl) {
                    return;
                }

                if (optionsAbortController) {
                    optionsAbortController.abort();
                }
                optionsAbortController = new AbortController();

                const shipDifferent = !!toggle?.checked;
                const billingCountry = checkoutForm.querySelector('[name="billing_country_code"]')?.value || '';
                const billingPostal = checkoutForm.querySelector('[name="billing_postal_code"]')?.value || '';
                const billingCity = checkoutForm.querySelector('[name="billing_city"]')?.value || '';
                const shippingCountry = checkoutForm.querySelector('[name="shipping_country_code"]')?.value || '';
                const shippingPostal = checkoutForm.querySelector('[name="shipping_postal_code"]')?.value || '';
                const shippingCity = checkoutForm.querySelector('[name="shipping_city"]')?.value || '';
                const selectedShippingCode = shippingOptionsRoot?.querySelector('input[name="shipping_method_code"]:checked')?.value || '';
                const selectedPaymentCode = paymentOptionsRoot?.querySelector('input[name="payment_method_code"]:checked')?.value || '';

                const params = new URLSearchParams({
                    ship_to_different_address: shipDifferent ? '1' : '0',
                    billing_country_code: billingCountry,
                    billing_postal_code: billingPostal,
                    billing_city: billingCity,
                    shipping_country_code: shippingCountry,
                    shipping_postal_code: shippingPostal,
                    shipping_city: shippingCity,
                    shipping_method_code: selectedShippingCode,
                    payment_method_code: selectedPaymentCode,
                });

                try {
                    const response = await fetch(optionsUrl + '?' + params.toString(), {
                        method: 'GET',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: optionsAbortController.signal,
                    });

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();
                    const totals = payload.totals || {};
                    renderShippingOptions(payload.shipping_methods || [], String(totals.shipping_method_code || ''));
                    renderPaymentOptions(payload.payment_methods || [], String(totals.payment_method_code || ''));
                    renderCheckoutTotals(totals);
                    initBoxNowWidget();
                } catch (error) {
                    // Keep existing options on network/request errors.
                }
            };

            const scheduleOptionsRefresh = function () {
                if (optionsRefreshTimer) {
                    window.clearTimeout(optionsRefreshTimer);
                }

                optionsRefreshTimer = window.setTimeout(function () {
                    refreshCheckoutOptions();
                }, 200);
            };

            const setLoginState = function () {
                if (!loginToggle || !loginPanel) {
                    return;
                }

                if (loginToggle.checked) {
                    loginPanel.style.maxHeight = loginPanel.scrollHeight + 'px';
                    loginPanel.style.opacity = '1';
                    loginToggle.setAttribute('aria-expanded', 'true');
                    loginPanel.setAttribute('aria-hidden', 'false');
                    loginPanel.inert = false;
                } else {
                    loginPanel.style.maxHeight = '0';
                    loginPanel.style.opacity = '0';
                    loginToggle.setAttribute('aria-expanded', 'false');
                    loginPanel.setAttribute('aria-hidden', 'true');
                    loginPanel.inert = true;
                }
            };

            const setRegisterState = function () {
                if (!registerToggle || !registerPanel || !registerPassword || !registerPasswordConfirmation) {
                    return;
                }

                if (registerToggle.checked) {
                    registerPassword.disabled = false;
                    registerPasswordConfirmation.disabled = false;
                    registerPassword.required = true;
                    registerPasswordConfirmation.required = true;
                    registerPanel.style.maxHeight = registerPanel.scrollHeight + 'px';
                    registerPanel.style.opacity = '1';
                    registerToggle.setAttribute('aria-expanded', 'true');
                    registerPanel.setAttribute('aria-hidden', 'false');
                } else {
                    registerPassword.required = false;
                    registerPasswordConfirmation.required = false;
                    registerPassword.disabled = true;
                    registerPasswordConfirmation.disabled = true;
                    registerPassword.value = '';
                    registerPasswordConfirmation.value = '';
                    registerPanel.style.maxHeight = '0';
                    registerPanel.style.opacity = '0';
                    registerToggle.setAttribute('aria-expanded', 'false');
                    registerPanel.setAttribute('aria-hidden', 'true');
                }
            };

            const setR1State = function () {
                if (!r1Toggle || !r1Panel || !r1Company || !r1Oib) {
                    return;
                }

                if (r1Toggle.checked) {
                    r1Company.disabled = false;
                    r1Oib.disabled = false;
                    r1Panel.style.maxHeight = r1Panel.scrollHeight + 'px';
                    r1Panel.style.opacity = '1';
                    r1Toggle.setAttribute('aria-expanded', 'true');
                    r1Panel.setAttribute('aria-hidden', 'false');
                } else {
                    r1Company.disabled = true;
                    r1Oib.disabled = true;
                    r1Panel.style.maxHeight = '0';
                    r1Panel.style.opacity = '0';
                    r1Toggle.setAttribute('aria-expanded', 'false');
                    r1Panel.setAttribute('aria-hidden', 'true');
                }
            };

            const clearInlineErrors = function () {
                document.querySelectorAll('[data-checkout-dynamic-invalid]').forEach(function (node) {
                    node.removeAttribute('aria-invalid');
                    node.removeAttribute('aria-describedby');
                    node.removeAttribute('data-checkout-dynamic-invalid');
                });
                document.querySelectorAll('[data-checkout-error]').forEach(function (node) {
                    node.remove();
                });
            };

            const hideTopError = function () {
                if (!topErrorBox) {
                    return;
                }
                topErrorBox.classList.add('hidden');
                topErrorBox.textContent = '';
            };

            const showTopError = function (message) {
                if (!topErrorBox) {
                    return;
                }
                topErrorBox.textContent = String(message || '');
                topErrorBox.classList.remove('hidden');
                topErrorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            };

            const renderInlineError = function (field, message) {
                if (!checkoutForm) {
                    return;
                }

                const input = checkoutForm.querySelector('[name="' + field + '"]');
                if (!input || input.type === 'hidden') {
                    return;
                }

                const errorNode = document.createElement('p');
                const errorId = 'checkout-error-' + String(field).replace(/[^a-z0-9_-]+/gi, '-');
                errorNode.className = 'mt-2 text-xs font-semibold text-rose-600';
                errorNode.dataset.checkoutError = '1';
                errorNode.id = errorId;
                errorNode.textContent = message;
                input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', errorId);
                input.setAttribute('data-checkout-dynamic-invalid', '1');

                if (input.type === 'checkbox' || input.type === 'radio') {
                    const label = input.closest('label');
                    if (label && label.parentElement) {
                        label.parentElement.appendChild(errorNode);
                        return;
                    }
                }

                input.insertAdjacentElement('afterend', errorNode);
            };

            syncCustomerNames();
            setShippingState();
            setLoginState();
            setRegisterState();
            setR1State();
            scheduleOptionsRefresh();
            toggleBoxNowPanel();
            toggleGlsDpmPanel();
            initBoxNowWidget();

            toggle?.addEventListener('change', setShippingState);
            loginToggle?.addEventListener('change', setLoginState);
            registerToggle?.addEventListener('change', setRegisterState);
            r1Toggle?.addEventListener('change', setR1State);
            shippingOptionsRoot?.addEventListener('change', function (event) {
                if (event.target && event.target.name === 'shipping_method_code') {
                    toggleBoxNowPanel();
                    toggleGlsDpmPanel();
                    scheduleOptionsRefresh();
                }
            });
            paymentOptionsRoot?.addEventListener('change', function (event) {
                if (event.target && event.target.name === 'payment_method_code') {
                    scheduleOptionsRefresh();
                }
            });
            boxNowOpenButton?.addEventListener('click', function () {
                const selected = selectedShippingInput();
                const partnerId = String(selected?.dataset?.boxnowPartnerId || '').trim();
                if (partnerId === '') {
                    alert(@json(__('ui.checkout.boxnow.partner_missing')));
                }
            });
            glsDpmOpenButton?.addEventListener('click', async function () {
                const selected = selectedShippingInput();
                const isGlsDpm = selected?.dataset?.isGlsDpm === '1' || selected?.dataset?.isGlsDpm === 'true';

                if (!isGlsDpm) {
                    return;
                }

                try {
                    await ensureGlsWidgetLoaded();
                    toggleGlsDpmPanel();
                    glsDpmDialog?.showModal?.();
                } catch (error) {
                    alert(@json(__('GLS widget trenutno nije dostupan.')));
                }
            });
            glsDpmDialog?.addEventListener('change', function (event) {
                updateGlsDpmSelection(event.detail || {});
            });
            billingFirst?.addEventListener('input', syncCustomerNames);
            billingLast?.addEventListener('input', syncCustomerNames);
            checkoutForm?.addEventListener('submit', syncCustomerNames);
            checkoutForm?.querySelectorAll('[data-address-country], [name="billing_postal_code"], [name="shipping_postal_code"], [name="billing_city"], [name="shipping_city"]').forEach(function (node) {
                node.addEventListener('change', function () {
                    scheduleOptionsRefresh();
                });
                node.addEventListener('input', function () {
                    scheduleOptionsRefresh();
                });
            });

            window.addEventListener('resize', function () {
                if (toggle?.checked && shippingFields) {
                    shippingFields.style.maxHeight = shippingFields.scrollHeight + 'px';
                }
                if (loginToggle?.checked && loginPanel) {
                    loginPanel.style.maxHeight = loginPanel.scrollHeight + 'px';
                }
                if (registerToggle?.checked && registerPanel) {
                    registerPanel.style.maxHeight = registerPanel.scrollHeight + 'px';
                }
                if (r1Toggle?.checked && r1Panel) {
                    r1Panel.style.maxHeight = r1Panel.scrollHeight + 'px';
                }
            });

            checkoutForm?.addEventListener('submit', async function (event) {
                event.preventDefault();
                clearInlineErrors();
                hideTopError();

                const submitBtn = checkoutForm.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }
                checkoutForm.setAttribute('aria-busy', 'true');

                try {
                    const formData = new FormData(checkoutForm);
                    formData.append('_ajax', '1');

                    const response = await fetch(checkoutForm.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });

                    if (response.status === 422) {
                        const payload = await response.json();
                        const errors = payload.errors || {};
                        let firstErrorField = null;
                        Object.keys(errors).forEach(function (field) {
                            const firstMessage = Array.isArray(errors[field]) ? errors[field][0] : errors[field];
                            if (firstMessage) {
                                renderInlineError(field, firstMessage);
                                if (firstErrorField === null) {
                                    firstErrorField = field;
                                }
                                if (field === 'accept_terms') {
                                    showTopError(firstMessage);
                                }
                            }
                        });

                        if (firstErrorField) {
                            const firstInput = checkoutForm.querySelector('[name="' + firstErrorField + '"]');
                            firstInput?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        return;
                    }

                    if (!response.ok) {
                        return;
                    }

                    const headerRedirect = response.headers.get('X-Checkout-Redirect');
                    if (headerRedirect) {
                        window.location.href = headerRedirect;
                        return;
                    }

                    const rawBody = await response.text();
                    let payload = null;
                    try {
                        payload = rawBody ? JSON.parse(rawBody) : null;
                    } catch (error) {
                        payload = null;
                    }

                    if (payload && payload.redirect) {
                        window.location.href = payload.redirect;
                        return;
                    }

                    if (response.url && response.url !== window.location.href) {
                        window.location.href = response.url;
                        return;
                    }

                    const successFallbackUrl = checkoutForm.dataset.successFallback;
                    if (successFallbackUrl) {
                        window.location.href = successFallbackUrl;
                    }
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                    checkoutForm.setAttribute('aria-busy', 'false');
                }
            });
        });
    </script>
@endpush
