@extends('front.desktop.layouts.store')

@section('title', __('ui.checkout.wspay.redirect_page_title'))
@section('main_class', 'w-full px-0 py-8')

@section('content')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/checkout.css') }}?v={{ filemtime(public_path('front-theme/styles/checkout.css')) }}">
    @endpush

    <section class="checkout-status-card">
        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ __('ui.checkout.wspay.redirect_title') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('ui.checkout.wspay.redirect_subtitle') }}</p>

        <form id="wspay-redirect-form" method="POST" action="{{ $formData['action_url'] }}" class="mt-6">
            <input type="hidden" name="ShopID" value="{{ $formData['shop_id'] }}">
            <input type="hidden" name="ShoppingCartID" value="{{ $formData['shopping_cart_id'] }}">
            <input type="hidden" name="Version" value="{{ $formData['version'] }}">
            <input type="hidden" name="TotalAmount" value="{{ $formData['total_amount'] }}">
            <input type="hidden" name="ReturnURL" value="{{ $formData['return_url'] }}">
            <input type="hidden" name="ReturnErrorURL" value="{{ $formData['return_error_url'] }}">
            <input type="hidden" name="CancelURL" value="{{ $formData['cancel_url'] }}">
            <input type="hidden" name="ReturnMethod" value="{{ $formData['return_method'] }}">
            <input type="hidden" name="Signature" value="{{ $formData['signature'] }}">
            <input type="hidden" name="CustomerFirstName" value="{{ (string) ($formData['customer']['first_name'] ?? '') }}">
            <input type="hidden" name="CustomerLastName" value="{{ (string) ($formData['customer']['last_name'] ?? '') }}">
            <input type="hidden" name="CustomerAddress" value="{{ (string) ($formData['customer']['address'] ?? '') }}">
            <input type="hidden" name="CustomerCity" value="{{ (string) ($formData['customer']['city'] ?? '') }}">
            <input type="hidden" name="CustomerZIP" value="{{ (string) ($formData['customer']['zip'] ?? '') }}">
            <input type="hidden" name="CustomerCountry" value="{{ (string) ($formData['customer']['country'] ?? '') }}">
            <input type="hidden" name="CustomerPhone" value="{{ (string) ($formData['customer']['phone'] ?? '') }}">
            <input type="hidden" name="CustomerEmail" value="{{ (string) ($formData['customer']['email'] ?? '') }}">

            <button type="submit" class="checkout-primary-button px-5 py-2.5">
                {{ __('ui.checkout.wspay.redirect_button') }}
            </button>
        </form>
    </section>

    @push('scripts')
        <script>
            window.setTimeout(function () {
                var form = document.getElementById('wspay-redirect-form');
                if (form) {
                    form.submit();
                }
            }, 120);
        </script>
    @endpush
@endsection
