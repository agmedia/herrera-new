@extends('front.desktop.layouts.store')

@section('title', __('ui.checkout.corvus.redirect_page_title'))
@section('main_class', 'w-full px-0 py-8')

@section('content')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/checkout.css') }}?v={{ filemtime(public_path('front-theme/styles/checkout.css')) }}">
    @endpush

    <section class="checkout-status-card">
        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ __('ui.checkout.corvus.redirect_title') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('ui.checkout.corvus.redirect_subtitle') }}</p>

        <form id="corvus-redirect-form" method="POST" action="{{ $formData['action_url'] }}" class="mt-6">
            @foreach (($formData['payload'] ?? []) as $field => $value)
                <input type="hidden" name="{{ $field }}" value="{{ (string) $value }}">
            @endforeach

            <button type="submit" class="checkout-primary-button px-5 py-2.5">
                {{ __('ui.checkout.corvus.redirect_button') }}
            </button>
        </form>
    </section>

    @push('scripts')
        <script>
            window.setTimeout(function () {
                var form = document.getElementById('corvus-redirect-form');
                if (form) {
                    form.submit();
                }
            }, 120);
        </script>
    @endpush
@endsection
