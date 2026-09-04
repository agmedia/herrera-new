@extends('front.desktop.layouts.store')

@section('title', __('ui.account.profile.page_title'))
@section('body_class', 'commerce-body account-commerce-body account-profile-commerce-body')
@section('main_class', 'commerce-main')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
@endpush

@section('content')
    <section class="front-soft-hero mb-8 px-4 py-6 text-center sm:px-6">
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.account.profile.title') }}</h1>
        <p class="mt-2 text-slate-600">{{ __('ui.account.profile.subtitle') }}</p>
    </section>

    <div class="account-layout" data-address-autofill data-address-source="{{ $placesAssetUrl }}">
        @include('front.desktop.account.partials.nav', ['current' => 'profile'])

        <div class="min-w-0 space-y-8">
            <form method="POST" action="{{ route('account.profile.update') }}" class="border border-slate-200 bg-white p-6">
                @csrf
                @method('PUT')

                <h2 class="text-xl font-bold text-slate-900">{{ __('ui.account.profile.personal_info') }}</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div><label for="account-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.display_name') }}</label><input id="account-name" type="text" name="name" value="{{ old('name', $user->name) }}" autocomplete="name" class="w-full px-3 text-sm" required></div>
                    <div><label for="account-email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.email') }}</label><input id="account-email" type="email" name="email" value="{{ old('email', $user->email) }}" autocomplete="email" class="w-full px-3 text-sm" required></div>
                    <div><label for="account-first-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.first_name') }}</label><input id="account-first-name" type="text" name="first_name" value="{{ old('first_name', $user->profile?->first_name) }}" autocomplete="given-name" class="w-full px-3 text-sm"></div>
                    <div><label for="account-last-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.last_name') }}</label><input id="account-last-name" type="text" name="last_name" value="{{ old('last_name', $user->profile?->last_name) }}" autocomplete="family-name" class="w-full px-3 text-sm"></div>
                    <div><label for="account-phone" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.phone') }}</label><input id="account-phone" type="tel" name="phone" value="{{ old('phone', $user->profile?->phone) }}" autocomplete="tel" class="w-full px-3 text-sm"></div>
                    <div><label for="account-company" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.company') }}</label><input id="account-company" type="text" name="company" value="{{ old('company', $user->profile?->company) }}" autocomplete="organization" class="w-full px-3 text-sm"></div>
                    <div><label for="account-oib" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.oib') }}</label><input id="account-oib" type="text" name="oib" value="{{ old('oib', $user->profile?->oib) }}" inputmode="numeric" class="w-full px-3 text-sm"></div>
                    <div><label for="account-birthday" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.birthday') }}</label><input id="account-birthday" type="date" name="birthday" value="{{ old('birthday', optional($user->profile?->birthday)->format('Y-m-d')) }}" autocomplete="bday" class="w-full px-3 text-sm"></div>
                </div>

                <fieldset class="mt-4 border border-slate-200 p-3">
                    <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.gender') }}</legend>
                    @php
                        $genderValue = old('gender', $user->profile?->gender);
                    @endphp
                    <div class="flex flex-wrap items-center gap-6 text-sm text-slate-800">
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="gender" value="female" @checked($genderValue === 'female') class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0">
                            <span>{{ __('ui.account.gender.female') }}</span>
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="gender" value="male" @checked($genderValue === 'male') class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0">
                            <span>{{ __('ui.account.gender.male') }}</span>
                        </label>
                    </div>
                </fieldset>

                <button type="submit" class="commerce-primary-action mt-5 px-5 py-2.5">{{ __('ui.account.actions.save_profile') }}</button>
            </form>

            <form method="POST" action="{{ route('account.preferences.update') }}" class="border border-slate-200 bg-white p-6">
                @csrf
                @method('PUT')

                <h2 class="text-xl font-bold text-slate-900">{{ __('ui.account.profile.preferences') }}</h2>
                <div class="mt-4 space-y-2 text-sm text-slate-700">
                    <label class="flex items-center gap-2"><input type="checkbox" name="newsletter_opt_in" value="1" @checked((bool) old('newsletter_opt_in', $user->profile?->newsletter_opt_in)) class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0"> {{ __('ui.account.profile.newsletter_opt_in') }}</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="gdpr_marketing_opt_in" value="1" @checked((bool) old('gdpr_marketing_opt_in', $preferencePayload['gdpr_marketing_opt_in'] ?? false)) class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0"> {{ __('ui.account.profile.gdpr_marketing') }}</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="gdpr_personalization_opt_in" value="1" @checked((bool) old('gdpr_personalization_opt_in', $preferencePayload['gdpr_personalization_opt_in'] ?? false)) class="h-4 w-4 border-slate-300 text-slate-900 focus:ring-0"> {{ __('ui.account.profile.gdpr_personalization') }}</label>
                </div>

                <button type="submit" class="commerce-primary-action mt-5 px-5 py-2.5">{{ __('ui.account.actions.save_preferences') }}</button>
            </form>

            <div class="grid gap-6 lg:grid-cols-2">
                @php
                    $addressForms = [
                        'billing' => $billing,
                        'shipping' => $shipping,
                    ];
                @endphp

                @foreach ($addressForms as $type => $address)
                    <form method="POST" action="{{ route('account.addresses.update', ['type' => $type]) }}" class="border border-slate-200 bg-white p-6" data-address-scope="{{ $type }}">
                        @csrf
                        @method('PUT')

                        <h2 class="text-xl font-bold text-slate-900">{{ __('ui.account.address.title', ['type' => __('ui.account.address.types.'.$type)]) }}</h2>
                        <div class="mt-4 grid gap-3">
                            <div><label for="{{ $type }}-first-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.first_name') }}</label><input id="{{ $type }}-first-name" type="text" name="first_name" value="{{ old('first_name', $address?->first_name) }}" autocomplete="{{ $type }} given-name" class="w-full px-3 text-sm"></div>
                            <div><label for="{{ $type }}-last-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.last_name') }}</label><input id="{{ $type }}-last-name" type="text" name="last_name" value="{{ old('last_name', $address?->last_name) }}" autocomplete="{{ $type }} family-name" class="w-full px-3 text-sm"></div>
                            @if ($type === 'billing')
                                <div><label for="{{ $type }}-company" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.company') }}</label><input id="{{ $type }}-company" type="text" name="company" value="{{ old('company', $address?->company) }}" autocomplete="{{ $type }} organization" class="w-full px-3 text-sm"></div>
                            @endif
                            <div><label for="{{ $type }}-oib" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.oib') }}</label><input id="{{ $type }}-oib" type="text" name="oib" value="{{ old('oib', $address?->oib) }}" inputmode="numeric" class="w-full px-3 text-sm"></div>
                            <div><label for="{{ $type }}-vat-id" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.vat_id') }}</label><input id="{{ $type }}-vat-id" type="text" name="vat_id" value="{{ old('vat_id', $address?->vat_id) }}" class="w-full px-3 text-sm"></div>
                            <div><label for="{{ $type }}-phone" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.phone') }}</label><input id="{{ $type }}-phone" type="tel" name="phone" value="{{ old('phone', $address?->phone) }}" autocomplete="{{ $type }} tel" class="w-full px-3 text-sm"></div>
                            <div><label for="{{ $type }}-address-line-1" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.address_line_1') }}</label><input id="{{ $type }}-address-line-1" type="text" name="address_line_1" value="{{ old('address_line_1', $address?->address_line_1) }}" autocomplete="{{ $type }} address-line1" class="w-full px-3 text-sm" required></div>
                            <div><label for="{{ $type }}-postal-code" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.postal_code') }}</label><input id="{{ $type }}-postal-code" type="text" name="postal_code" value="{{ old('postal_code', $address?->postal_code) }}" autocomplete="{{ $type }} postal-code" class="w-full px-3 text-sm" data-address-postal required></div>
                            <div><label for="{{ $type }}-city" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.city') }}</label><input id="{{ $type }}-city" type="text" name="city" value="{{ old('city', $address?->city) }}" autocomplete="{{ $type }} address-level2" class="w-full px-3 text-sm" data-address-city required></div>
                            <label for="{{ $type }}-country" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ui.account.fields.country_code') }}</label>
                            <select id="{{ $type }}-country" name="country_code" autocomplete="{{ $type }} country" class="w-full px-3 text-sm" data-address-country required>
                                @foreach ($countryOptions as $countryOption)
                                    <option value="{{ $countryOption['code'] }}" @selected(old('country_code', $address?->country_code ?? 'HR') === $countryOption['code'])>{{ $countryOption['label'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <button type="submit" class="commerce-primary-action mt-4 px-5 py-2.5">{{ __('ui.account.actions.save_address', ['type' => __('ui.account.address.types.'.$type)]) }}</button>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script defer src="{{ asset('front-theme/scripts/address-autofill.js') }}?v={{ filemtime(public_path('front-theme/scripts/address-autofill.js')) }}"></script>
@endpush
