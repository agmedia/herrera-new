@php
    try {
        $authBranding = app(\App\Services\Front\StoreSettingsService::class)->branding();
    } catch (\Throwable) {
        $authBranding = [];
    }

    $authStoreName = trim((string) (($authBranding['store_name'] ?? null) ?: config('app.name', 'AG Shop')));
    $authLogoUrl = trim((string) ($authBranding['logo_url'] ?? ''));
    $authLogoWidth = max(1, (int) ($authBranding['logo_width'] ?? 320));
    $authLogoHeight = max(1, (int) ($authBranding['logo_height'] ?? 145));
    $authFavicons = is_array($authBranding['favicons'] ?? null) ? $authBranding['favicons'] : [];
    $authFaviconUrl = trim((string) ($authBranding['favicon_url'] ?? ''));

    $isLoginPage = request()->routeIs('login');
    $isForgotPasswordPage = request()->routeIs('password.request');
    $isStoreAuthPage = $isLoginPage || $isForgotPasswordPage;

    if ($isLoginPage) {
        $authPageTitle = __('ui.auth.login.page_title');
        $authPageHeading = __('ui.auth.login.heading');
        $authPageSubheading = __('ui.auth.login.subheading');
    } elseif ($isForgotPasswordPage) {
        $authPageTitle = __('ui.auth.forgot.page_title');
        $authPageHeading = __('ui.auth.forgot.heading');
        $authPageSubheading = __('ui.auth.forgot.subheading');
    } elseif (request()->routeIs('register')) {
        $authPageTitle = __('ui.auth.register.page_title');
        $authPageHeading = __('ui.auth.register.heading');
        $authPageSubheading = __('ui.auth.register.subheading');
    } elseif (request()->routeIs('password.reset')) {
        $authPageTitle = __('ui.auth.reset.page_title');
        $authPageHeading = __('ui.auth.reset.heading');
        $authPageSubheading = __('ui.auth.reset.subheading');
    } else {
        $authPageTitle = $authStoreName;
        $authPageHeading = $authStoreName;
        $authPageSubheading = '';
    }
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $authPageTitle }} · {{ $authStoreName }}</title>

    @if (!empty($authFavicons['ico_url']))
        <link rel="icon" href="{{ $authFavicons['ico_url'] }}" sizes="any">
    @elseif ($authFaviconUrl !== '')
        <link rel="icon" href="{{ $authFaviconUrl }}">
    @endif
    @if (!empty($authFavicons['32_url']))
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $authFavicons['32_url'] }}">
    @endif
    @if (!empty($authFavicons['16_url']))
        <link rel="icon" type="image/png" sizes="16x16" href="{{ $authFavicons['16_url'] }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"></noscript>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ route('front.storefront.styles') }}">
    <link rel="stylesheet" href="{{ asset('front-theme/styles/commerce-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/commerce-pages.css')) }}">
    <link rel="stylesheet" href="{{ asset('front-theme/styles/auth-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/auth-pages.css')) }}">
</head>
<body class="commerce-body store-auth-body">
    <header class="store-auth-header">
        <div class="store-auth-header-inner">
            <a href="{{ route('home') }}" class="store-auth-brand" aria-label="{{ $authStoreName }}">
                @if ($authLogoUrl !== '')
                    <img
                        src="{{ $authLogoUrl }}"
                        alt="{{ $authStoreName }}"
                        class="store-auth-brand-logo"
                        width="{{ $authLogoWidth }}"
                        height="{{ $authLogoHeight }}"
                        data-store-brand-logo
                    >
                @else
                    <span>{{ $authStoreName }}</span>
                @endif
            </a>

            <a href="{{ route('home') }}" class="store-auth-back-link">
                <svg viewBox="0 0 20 20" aria-hidden="true">
                    <path d="M11.75 4.5 6.25 10l5.5 5.5M6.75 10H16" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>
                </svg>
                <span>{{ __('ui.auth.back_to_store') }}</span>
            </a>
        </div>
    </header>

    <main class="commerce-main store-auth-main">
        <section class="commerce-hero store-auth-hero">
            <h1>{{ $authPageHeading }}</h1>
            @if ($authPageSubheading !== '')
                <p>{{ $authPageSubheading }}</p>
            @endif
        </section>

        @if ($isStoreAuthPage)
            {{ $slot }}
        @else
            <section class="auth-layout store-auth-legacy-layout">
                <div class="auth-form-card store-auth-card store-auth-legacy-card">
                    {{ $slot }}
                </div>
            </section>
        @endif
    </main>

    <footer class="store-auth-footer">
        <span>© {{ now()->year }} {{ $authStoreName }}</span>
        <a href="{{ route('home') }}">{{ __('ui.auth.back_to_store') }}</a>
    </footer>
</body>
</html>
