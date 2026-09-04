<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('front.partials.seo-meta')
    @include('front.partials.schema-markup')
    @include('front.partials.analytics')
    @if (!empty($storeSettings['branding']['favicons']['ico_url'] ?? null))
        <link rel="icon" href="{{ $storeSettings['branding']['favicons']['ico_url'] }}" sizes="any">
    @endif
    @if (!empty($storeSettings['branding']['favicons']['32_url'] ?? null))
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $storeSettings['branding']['favicons']['32_url'] }}">
    @endif
    @if (!empty($storeSettings['branding']['favicons']['16_url'] ?? null))
        <link rel="icon" type="image/png" sizes="16x16" href="{{ $storeSettings['branding']['favicons']['16_url'] }}">
    @endif
    @if (!empty($storeSettings['branding']['favicons']['192_url'] ?? null))
        <link rel="icon" type="image/png" sizes="192x192" href="{{ $storeSettings['branding']['favicons']['192_url'] }}">
    @endif
    @if (!empty($storeSettings['branding']['favicons']['512_url'] ?? null))
        <link rel="icon" type="image/png" sizes="512x512" href="{{ $storeSettings['branding']['favicons']['512_url'] }}">
    @endif
    @if (empty($storeSettings['branding']['favicons']['ico_url'] ?? null) && !empty($storeSettings['branding']['favicon_url'] ?? null))
        <link rel="icon" href="{{ $storeSettings['branding']['favicon_url'] }}">
    @endif
    @php
        $storefrontCssBundleIncludesLegacyAssets = true;
        $storefrontAssetVersion = app(\App\Support\AssetVersion::class)->current();
    @endphp
    @include('front.partials.cookie-consent-head')
    @stack('head')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"></noscript>
    @vite(['resources/css/app.css'])
    <link rel="stylesheet" href="{{ asset('front-theme/styles/termol-overrides.css') }}?v={{ filemtime(public_path('front-theme/styles/termol-overrides.css')) }}-{{ $storefrontAssetVersion }}">
    <link rel="stylesheet" href="{{ asset('front-theme/styles/header-cart-popover.css') }}?v={{ filemtime(public_path('front-theme/styles/header-cart-popover.css')) }}-{{ $storefrontAssetVersion }}">
    {{-- Configurable storefront values remain external so product markup contains no inline CSS. --}}
    <link rel="stylesheet" data-storefront-settings href="{{ route('front.storefront.styles', ['v' => $storefrontAssetVersion]) }}">
    @stack('styles')
</head>
@php
    $cartService = app(\App\Services\Front\CartService::class);
    $cartSummary = $cartService->summary();
    $headerCartLines = $cartService->lines();
    $wishlistCount = (int) ($wishlistSummary['item_count'] ?? 0);
    $navigationService = app(\App\Services\Front\NavigationMenuService::class);
    $mainNavigation = $navigationService->forLocale((string) app()->getLocale());
    $topBar = $navigationService->topBar();
    $storeBrandName = trim((string) (($storeSettings['branding']['store_name'] ?? null) ?: config('app.name', 'AG Shop')));
    $storeBrandLogoUrl = trim((string) ($storeSettings['branding']['logo_url'] ?? ''));
    $storeBrandLogoRawWidth = (int) ($storeSettings['branding']['logo_width'] ?? 0);
    $storeBrandLogoRawHeight = (int) ($storeSettings['branding']['logo_height'] ?? 0);
    $storeBrandLogoWidth = $storeBrandLogoRawWidth > 0 ? $storeBrandLogoRawWidth : 176;
    $storeBrandLogoHeight = $storeBrandLogoRawHeight > 0 ? $storeBrandLogoRawHeight : 80;
    $benefitsBar = is_array($storeSettings['benefits_bar'] ?? null)
        ? $storeSettings['benefits_bar']
        : [
            'enabled' => true,
            'items' => [
                'Više od **50 000 proizvoda** u ponudi',
                'Plaćanje karticama do **12 rata bez naknada**',
                '**Dostava** u roku **3-5 radnih dana**',
            ],
        ];
    $searchAutocompleteEnabled = (bool) app(\App\Services\Settings\SystemSettingsService::class)
        ->get('store_search_autocomplete_enabled', false);
@endphp
<body class="storefront min-h-screen overflow-x-hidden bg-white text-slate-900 antialiased @yield('body_class')">
@if ((bool) ($storeSettings['announcement']['enabled'] ?? true))
    @php
        $announcementText = (string) ($storeSettings['announcement']['text'] ?? __('ui.front.desktop.promo_bar'));
        $announcementUrl = trim((string) ($storeSettings['announcement']['url'] ?? ''));
        $announcementNewTab = (bool) ($storeSettings['announcement']['new_tab'] ?? false);
        $announcementScrollEnabled = (bool) ($storeSettings['announcement']['scroll_enabled'] ?? false);
    @endphp
@endif

<header class="site-main-header sticky top-0 z-40 bg-white">
    @if ($topBar['is_enabled'])
        <div class="site-top-bar hidden lg:block">
            <div class="site-top-bar-shell storefront-container">
                <div class="site-top-bar-inner storefront-header-container">
                    <nav class="site-top-bar-links" aria-label="{{ __('admin.content.navigation.top_bar_title') }}">
                        @foreach ($topBar['links'] as $link)
                            @if ($link['is_active'])
                                <a
                                    href="{{ $link['url'] }}"
                                    class="site-top-bar-link"
                                    @if($link['open_in_new_tab']) target="_blank" rel="noopener noreferrer" @endif
                                >
                                    {{ $link['label'] }}
                                </a>
                            @endif
                        @endforeach
                    </nav>

                    <div class="site-top-bar-socials">
                        @foreach ($topBar['socials'] as $social)
                            @continue(! $social['is_active'])
                            <a
                                href="{{ $social['url'] }}"
                                class="site-top-bar-social-link"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="{{ ucfirst($social['network']) }}"
                            >
                                @switch($social['network'])
                                    @case('youtube')
                                        <x-fa-icon name="youtube" style="brands" />
                                        @break
                                    @case('instagram')
                                        <x-fa-icon name="instagram" style="brands" />
                                        @break
                                    @default
                                        <x-fa-icon name="facebook-f" style="brands" />
                                @endswitch
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="site-main-header-shell bg-white">
        <div class="site-main-header-row storefront-header-container relative mx-auto flex h-[60px] w-full items-stretch justify-between px-2 sm:px-4 lg:px-0">
            <a href="{{ route('home') }}" class="responsive-header-brand inline-flex h-full shrink-0 items-center pr-4 text-2xl font-black tracking-tight text-slate-900 sm:text-4xl lg:w-[230px] lg:px-6">
                @if ($storeBrandLogoUrl !== '')
                    <img src="{{ $storeBrandLogoUrl }}" alt="{{ $storeBrandName }}" class="site-main-logo h-10 w-auto object-contain" width="{{ $storeBrandLogoWidth }}" height="{{ $storeBrandLogoHeight }}" data-store-brand-logo>
                @else
                    {{ $storeBrandName }}
                @endif
            </a>

            <div class="header-search-panel-shell" data-header-search-panel data-header-search-persistent>
                <form
                    method="GET"
                    action="{{ route('shop.index') }}"
                    class="header-search-form"
                    role="search"
                    autocomplete="off"
                    data-header-search-form
                    data-autocomplete-enabled="{{ $searchAutocompleteEnabled ? '1' : '0' }}"
                    data-autocomplete-endpoint="{{ $searchAutocompleteEnabled ? route('search.autocomplete') : '' }}"
                    data-autocomplete-results-label="{{ __('ui.shop.search_autocomplete.results', ['count' => '__COUNT__']) }}"
                    data-autocomplete-empty-label="{{ __('ui.shop.search_autocomplete.no_results', ['query' => '__QUERY__']) }}"
                    data-autocomplete-loading-label="{{ __('ui.shop.search_autocomplete.searching') }}"
                    data-autocomplete-view-all-label="{{ __('ui.shop.search_autocomplete.view_all') }}"
                    data-autocomplete-products-label="{{ __('ui.shop.search_autocomplete.groups.products') }}"
                    data-autocomplete-categories-label="{{ __('ui.shop.search_autocomplete.groups.categories') }}"
                    data-autocomplete-manufacturers-label="{{ __('ui.shop.search_autocomplete.groups.manufacturers') }}"
                    data-autocomplete-blog-label="{{ __('ui.shop.search_autocomplete.groups.blog') }}"
                    data-autocomplete-b2b-label="{{ __('ui.product.b2b_contract_price') }}"
                >
                    @foreach (['category', 'manufacturer', 'size', 'sort', 'cols'] as $queryKey)
                        @if (request()->routeIs('shop.index') && request()->filled($queryKey))
                            <input type="hidden" name="{{ $queryKey }}" value="{{ (string) request()->query($queryKey) }}">
                        @endif
                    @endforeach
                    <input
                        type="search"
                        name="q"
                        value="{{ (string) request()->query('q', '') }}"
                        placeholder="{{ __('ui.shop.filters.header_search_placeholder') }}"
                        class="header-search-input"
                        aria-label="{{ __('ui.front.desktop.search') }}"
                        autocomplete="off"
                        autocapitalize="none"
                        autocorrect="off"
                        spellcheck="false"
                        data-header-search-input
                    >
                    <button type="submit" class="header-search-submit" aria-label="{{ __('ui.shop.filters.search') }}">
                        <x-fa-icon name="magnifying-glass" />
                    </button>
                    <div class="header-search-suggestions" data-header-search-suggestions hidden>
                        <div class="header-search-suggestions-meta" data-header-search-suggestions-meta></div>
                        <button type="button" class="header-search-suggestions-close" aria-label="{{ __('Zatvori rezultate pretrage') }}" data-header-search-suggestions-close>
                            <x-fa-icon name="xmark" />
                        </button>
                        <div class="header-search-suggestions-loading" data-header-search-loading hidden></div>
                        <div class="header-search-suggestions-empty" data-header-search-empty hidden></div>
                        <div class="header-search-suggestions-list" data-header-search-suggestions-list></div>
                        <div class="header-search-suggestions-footer" data-header-search-footer hidden>
                            <a href="{{ route('shop.index') }}" class="header-search-suggestions-link" data-header-search-view-all></a>
                        </div>
                    </div>
                </form>
            </div>

            <div class="desktop-header-actions hidden h-full shrink-0 items-stretch lg:flex">
                @php
                    $activeLocale = (string) ($frontLocale ?? app()->getLocale());
                    $switchLanguage = collect($frontLanguages ?? [])->first(
                        static fn (array $language): bool => (string) ($language['code'] ?? '') !== $activeLocale
                    );
                @endphp
                @if ($switchLanguage)
                    <div class="inline-flex w-[76px] items-center justify-center border-r border-slate-200 text-xs font-semibold uppercase tracking-wide text-slate-700">
                        <a
                            href="{{ route('front.locale.switch', ['code' => $switchLanguage['code']]) }}"
                            class="text-slate-500 hover:text-black"
                            hreflang="{{ $switchLanguage['code'] }}"
                        >
                            {{ strtoupper((string) $switchLanguage['code']) }}
                        </a>
                    </div>
                @endif

                @auth
                    <a href="{{ route('account.dashboard') }}" class="inline-flex min-w-[136px] items-center justify-center gap-2 border-r border-slate-200 px-4 text-sm text-slate-700 transition hover:bg-slate-50 hover:text-black">
                        <x-fa-icon name="user" style="regular" class="h-5 w-5" />
                        {{ __('ui.front.desktop.account') }}
                    </a>
                @else
                    <a href="{{ route('front.auth.login') }}" class="inline-flex min-w-[136px] items-center justify-center gap-2 border-r border-slate-200 px-4 text-sm text-slate-700 transition hover:bg-slate-50 hover:text-black">
                        <x-fa-icon name="user" style="regular" class="h-5 w-5" />
                        {{ __('ui.front.desktop.account') }}
                    </a>
                @endauth

                <a href="{{ route('wishlist.index') }}" class="relative inline-flex h-full w-[76px] items-center justify-center border-r border-slate-200 text-slate-700 transition hover:bg-slate-50 hover:text-black {{ $wishlistCount > 0 ? '' : 'hidden' }}" aria-label="{{ __('ui.front.desktop.favorites') }}: {{ $wishlistCount }}" data-wishlist-link>
                    <x-fa-icon name="heart" style="regular" class="block h-5 w-5 text-current" />
                    <span class="header-count-badge absolute right-3 top-4 z-10 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-xs font-bold" data-wishlist-count>
                        {{ $wishlistCount }}
                    </span>
                </a>

                <div class="header-cart-hover" data-header-cart>
                    <a
                        href="{{ route('cart.index') }}"
                        class="header-cart-trigger"
                        aria-label="{{ __('ui.front.desktop.cart') }}: {{ (int) $cartSummary['item_qty'] }}"
                        aria-controls="header-cart-popover"
                        aria-expanded="false"
                        data-header-cart-trigger
                    >
                        <x-fa-icon name="bag-shopping" class="header-cart-trigger-icon" />
                        <span class="header-count-badge header-cart-count" data-cart-count>
                            {{ (int) $cartSummary['item_qty'] }}
                        </span>
                    </a>

                    @include('front.desktop.partials.header-cart-popover', [
                        'cartLines' => $headerCartLines,
                        'cartSummary' => $cartSummary,
                    ])
                </div>
            </div>

            <div class="responsive-header-actions flex h-full items-stretch border-l border-slate-200 lg:hidden">
                @auth
                    <a href="{{ route('account.dashboard') }}" class="responsive-header-action inline-flex w-12 items-center justify-center border-r border-slate-200 text-slate-700 transition hover:bg-slate-50 hover:text-black sm:w-14 lg:w-16" aria-label="{{ __('ui.front.desktop.account') }}">
                        <x-fa-icon name="user" style="regular" class="h-5 w-5" />
                    </a>
                @else
                    <a href="{{ route('front.auth.login') }}" class="responsive-header-action inline-flex w-12 items-center justify-center border-r border-slate-200 text-slate-700 transition hover:bg-slate-50 hover:text-black sm:w-14 lg:w-16" aria-label="{{ __('ui.front.desktop.sign_in') }}">
                        <x-fa-icon name="user" style="regular" class="h-5 w-5" />
                    </a>
                @endauth

                <a href="{{ route('wishlist.index') }}" class="responsive-header-action relative inline-flex w-12 items-center justify-center border-r border-slate-200 text-slate-700 transition hover:bg-slate-50 hover:text-black sm:w-14 lg:w-16" aria-label="{{ __('ui.front.desktop.favorites') }}: {{ $wishlistCount }}" data-wishlist-link data-wishlist-always-visible>
                    <x-fa-icon name="heart" style="regular" class="h-5 w-5" />
                    <span class="header-count-badge absolute right-0.5 top-2.5 h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold {{ $wishlistCount > 0 ? 'inline-flex' : 'hidden' }}" data-wishlist-count>
                        {{ $wishlistCount }}
                    </span>
                </a>

                <a href="{{ route('cart.index') }}" class="responsive-header-action relative inline-flex h-full w-12 items-center justify-center border-r border-slate-200 text-slate-900 transition hover:bg-slate-50 hover:text-black sm:w-14 lg:w-16" aria-label="{{ __('ui.front.desktop.cart') }}: {{ (int) $cartSummary['item_qty'] }}">
                    <x-fa-icon name="bag-shopping" class="h-6 w-6" />
                    <span class="header-count-badge absolute right-0.5 top-2.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold" data-cart-count>
                        {{ (int) $cartSummary['item_qty'] }}
                    </span>
                </a>

                <button type="button" class="responsive-header-action flex h-full w-12 items-center justify-center border-r border-slate-200 bg-white text-slate-900 transition hover:bg-slate-50 sm:w-14 lg:w-16" aria-label="{{ __('ui.front.desktop.open_navigation') }}" data-mobile-menu-open>
                    <x-fa-icon name="bars" class="h-5 w-5" />
                </button>
            </div>
        </div>
    </div>

    <div class="site-main-nav-shell hidden bg-white lg:block">
        <nav
            class="site-main-nav-row storefront-container relative mx-auto grid w-full grid-flow-col auto-cols-fr items-stretch"
        >
            @include('front.desktop.partials.main-nav')
        </nav>
    </div>

    @if ((bool) ($benefitsBar['enabled'] ?? true) && !empty($benefitsBar['items']))
        <div class="store-benefits-shell storefront-container">
            <div class="store-benefits-bar" aria-label="{{ __('Prednosti kupnje') }}" aria-live="off" data-store-benefits-rotator>
                @foreach ($benefitsBar['items'] as $benefitIndex => $benefitItem)
                    @php
                        $benefitSegments = preg_split(
                            '/(\*\*.+?\*\*)/u',
                            (string) $benefitItem,
                            -1,
                            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
                        ) ?: [(string) $benefitItem];
                    @endphp
                    <p class="store-benefits-item {{ $benefitIndex === 0 ? 'is-active' : '' }}" data-store-benefit-item>
                        <span class="store-benefits-copy">
                            @foreach ($benefitSegments as $benefitSegment)
                                @if (str_starts_with($benefitSegment, '**') && str_ends_with($benefitSegment, '**') && mb_strlen($benefitSegment) > 4)
                                    <strong>{{ mb_substr($benefitSegment, 2, mb_strlen($benefitSegment) - 4) }}</strong>
                                @else
                                    {{ $benefitSegment }}
                                @endif
                            @endforeach
                        </span>
                    </p>
                @endforeach
            </div>
        </div>
    @endif

    @if ((bool) ($storeSettings['announcement']['enabled'] ?? true))
        <div class="store-announcement-shell storefront-container">
            <div class="store-announcement-bar {{ $announcementScrollEnabled ? 'is-scrolling' : '' }} py-2 text-center text-xs font-semibold uppercase tracking-wide">
                @if ($announcementUrl !== '')
                    <a href="{{ $announcementUrl }}" class="store-announcement-content hover:underline" @if($announcementNewTab) target="_blank" rel="noopener noreferrer" @endif>
                        {{ $announcementText }}
                    </a>
                @else
                    <span class="store-announcement-content">{{ $announcementText }}</span>
                @endif
            </div>
        </div>
    @endif
</header>

<div class="site-main-header-spacer" aria-hidden="true" data-site-main-header-spacer></div>

<div class="pointer-events-none fixed inset-0 lg:hidden" data-mobile-menu-root>
    <button type="button" class="absolute inset-0 bg-black/45 opacity-0 transition-opacity duration-300" aria-label="{{ __('ui.front.desktop.close_navigation') }}" data-mobile-menu-close></button>
    <aside class="absolute inset-y-0 left-0 flex w-full max-w-none -translate-x-full flex-col bg-white shadow-2xl transition-transform duration-300 ease-out" data-mobile-menu-panel>
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-4">
            @if ($storeBrandLogoUrl !== '')
                <img src="{{ $storeBrandLogoUrl }}" alt="{{ $storeBrandName }}" class="block h-10 w-auto max-w-[12rem] object-contain" width="{{ $storeBrandLogoWidth }}" height="{{ $storeBrandLogoHeight }}" data-store-brand-logo>
            @else
                <span class="text-xl font-black tracking-tight text-slate-900">{{ $storeBrandName }}</span>
            @endif
            <button type="button" class="inline-flex h-10 w-10 items-center justify-center border border-slate-200 text-slate-700 transition hover:bg-slate-50 hover:text-black" aria-label="{{ __('ui.front.desktop.close_navigation') }}" data-mobile-menu-close>
                <x-fa-icon name="xmark" class="h-5 w-5" />
            </button>
        </div>
        @include('front.desktop.partials.main-nav-mobile')
    </aside>
</div>

<main class="@yield('main_class', 'mx-auto w-full max-w-7xl px-6 py-8')">
    @include('front.desktop.partials.flash')
    @yield('content')
</main>

@include('front.partials.analytics-ecommerce')

<footer class="site-footer {{ request()->routeIs('home') ? 'mt-0' : 'mt-5' }} bg-white">
    <div class="site-footer-shell storefront-header-container">
        <div class="site-footer-accent" aria-hidden="true"></div>
        <div class="site-footer-content">
        @php
            $newsletterErrors = $errors->getBag('newsletter');
            $newsletterCaptchaSiteKey = trim((string) ($storeSettings['captcha']['recaptcha_v3_site_key'] ?? ''));
            $newsletterCaptchaEnabled = (bool) ($storeSettings['captcha']['recaptcha_v3_enabled'] ?? false) && $newsletterCaptchaSiteKey !== '';
            $newsletterSettings = is_array($storeSettings['newsletter'] ?? null) ? $storeSettings['newsletter'] : [];
            $newsletterTitle = trim((string) ($newsletterSettings['title'] ?? '')) ?: __('ui.front.desktop.newsletter.title');
            $newsletterSubtitle = trim((string) ($newsletterSettings['subtitle'] ?? '')) ?: __('ui.front.desktop.newsletter.subtitle');
            $newsletterButtonLabel = trim((string) ($newsletterSettings['button_label'] ?? '')) ?: __('ui.front.desktop.newsletter.button');
            $newsletterConsentLabel = trim((string) ($newsletterSettings['consent_label'] ?? '')) ?: __('ui.front.desktop.newsletter.consent');
            $footerContactTitle = trim((string) ($storeSettings['footer']['contact_title'] ?? '')) ?: __('ui.front.desktop.footer.support');
            $footerContactIntro = trim((string) ($storeSettings['footer']['contact_intro'] ?? '')) ?: __('ui.front.desktop.footer.webshop_queries');
            $footerContactAddress = trim((string) ($storeSettings['footer']['address'] ?? ''));
            $footerBenefits = collect($benefitsBar['items'] ?? [])
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->take(3)
                ->values();
        @endphp

        <section class="site-footer-newsletter">
            <div class="grid gap-4 lg:grid-cols-[1.2fr_1fr] lg:items-center">
                <div>
                    <h3 class="text-xl font-bold leading-tight text-slate-900">{{ $newsletterTitle }}</h3>
                    <p class="mt-1 text-sm text-slate-600">{{ $newsletterSubtitle }}</p>
                </div>
                <form
                    action="{{ route('newsletter.subscribe') }}"
                    method="post"
                    novalidate
                    class="grid items-start gap-2.5 sm:grid-cols-[1fr_auto]"
                    data-newsletter-form
                    data-email-required-message="{{ __('ui.front.desktop.newsletter.validation.email_required') }}"
                    data-email-invalid-message="{{ __('ui.front.desktop.newsletter.validation.email_invalid') }}"
                    data-accept-terms-message="{{ __('ui.front.desktop.newsletter.validation.accept_terms') }}"
                    @if($newsletterCaptchaEnabled) data-recaptcha-site-key="{{ $newsletterCaptchaSiteKey }}" data-recaptcha-action="newsletter_footer" @endif
                >
                    @csrf
                    @if($newsletterCaptchaEnabled)
                        <input type="hidden" name="recaptcha_token" value="" data-recaptcha-token>
                    @endif
                    <div class="space-y-1.5">
                        <input
                            type="email"
                            name="newsletter_email"
                            value="{{ (string) old('newsletter_email', '') }}"
                            placeholder="{{ __('ui.front.desktop.newsletter.placeholder') }}"
                            class="h-11 w-full border border-slate-300 bg-white px-4 text-sm text-slate-900 outline-none ring-0 transition placeholder:text-slate-400 focus:border-[#aeb9c8] focus:shadow-[0_0_0_3px_rgba(24,33,45,0.06)] focus:outline-none focus:ring-0"
                            data-newsletter-email
                            aria-describedby="footer-newsletter-error"
                            aria-invalid="{{ $newsletterErrors->has('newsletter_email') ? 'true' : 'false' }}"
                            autocomplete="email"
                        >
                        <p
                            id="footer-newsletter-error"
                            class="mt-2 text-xs font-semibold text-rose-600 {{ $newsletterErrors->has('newsletter_email') ? '' : 'hidden' }}"
                            data-newsletter-error
                            aria-live="polite"
                        >{{ $newsletterErrors->first('newsletter_email') }}</p>
                        <p class="mt-2 hidden text-xs font-semibold" data-newsletter-status aria-live="polite"></p>
                    </div>
                    <button type="submit" class="inline-flex h-11 items-center justify-center border border-slate-300 bg-white px-5 text-xs font-semibold uppercase tracking-wide transition">{{ $newsletterButtonLabel }}</button>
                    <div class="sm:col-span-2">
                        <label class="flex items-start gap-2 text-[11px] text-slate-500">
                            <input
                                type="checkbox"
                                name="newsletter_accept_terms"
                                value="1"
                                class="mt-0.5 h-4 w-4 border-slate-400 text-slate-700 focus:ring-0 focus:ring-offset-0"
                                @checked((bool) old('newsletter_accept_terms'))
                                data-newsletter-accept-terms
                            >
                            {{ $newsletterConsentLabel }}
                        </label>
                        <p class="mt-2 text-xs font-semibold text-rose-600 {{ $newsletterErrors->has('newsletter_accept_terms') ? '' : 'hidden' }}" data-newsletter-accept-error aria-live="polite">{{ $newsletterErrors->first('newsletter_accept_terms') }}</p>
                        <p class="mt-2 text-xs font-semibold text-rose-600 {{ $newsletterErrors->has('recaptcha_token') ? '' : 'hidden' }}" data-newsletter-recaptcha-error aria-live="polite">{{ $newsletterErrors->first('recaptcha_token') }}</p>
                    </div>
                </form>
            </div>
        </section>

        @if ((bool) ($benefitsBar['enabled'] ?? true) && $footerBenefits->isNotEmpty())
            <div class="site-footer-benefits grid md:grid-cols-3">
                @foreach ($footerBenefits as $benefitIndex => $benefitItem)
                    @php
                        $benefitSegments = preg_split(
                            '/(\*\*.+?\*\*)/u',
                            $benefitItem,
                            -1,
                            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
                        ) ?: [$benefitItem];
                        $benefitIcon = ['truck-fast', 'credit-card', 'lock'][$benefitIndex] ?? 'circle-check';
                    @endphp
                    <div class="site-footer-benefit flex items-center gap-4">
                        <span class="site-footer-benefit-icon">
                            <x-fa-icon :name="$benefitIcon" class="site-footer-benefit-svg" />
                        </span>
                        <p class="text-sm">
                            @foreach ($benefitSegments as $benefitSegment)
                                @if (str_starts_with($benefitSegment, '**') && str_ends_with($benefitSegment, '**') && mb_strlen($benefitSegment) > 4)
                                    <strong>{{ mb_substr($benefitSegment, 2, mb_strlen($benefitSegment) - 4) }}</strong>
                                @else
                                    {{ $benefitSegment }}
                                @endif
                            @endforeach
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

        @php
            $footerColumnsRaw = collect($storeSettings['footer']['link_columns'] ?? [])->take(3)->values();
            $footerColumns = collect([1, 2, 3])->map(function (int $index) use ($footerColumnsRaw) {
                $defaultTitle = match ($index) {
                    1 => (string) __('ui.front.desktop.footer.shop'),
                    2 => (string) __('ui.front.desktop.footer.help'),
                    default => (string) __('ui.front.desktop.footer.info'),
                };
                $row = $footerColumnsRaw->get($index - 1);
                $title = trim((string) (is_array($row) ? ($row['title'] ?? '') : '')) ?: $defaultTitle;
                $links = collect(is_array($row) ? ($row['links'] ?? []) : [])
                    ->filter(fn ($link) => is_array($link) && trim((string) ($link['url'] ?? '')) !== '')
                    ->map(fn ($link) => [
                        'label' => (string) ($link['label'] ?? ''),
                        'url' => (string) ($link['url'] ?? '#'),
                    ])
                    ->filter(fn (array $link) => trim($link['label']) !== '')
                    ->values()
                    ->all();

                return ['title' => $title, 'links' => $links];
            })->values();
        @endphp

        <div class="site-footer-mobile-links lg:hidden">
            <details class="site-footer-mobile-section group">
                <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-4 text-base font-semibold text-slate-900">
                    {{ $footerContactTitle }}
                    <span class="inline-flex h-6 w-6 items-center justify-center text-[21px] font-light leading-none text-slate-500 group-open:hidden">+</span>
                    <span class="hidden h-6 w-6 items-center justify-center text-[21px] font-light leading-none text-slate-500 group-open:inline-flex">−</span>
                </summary>
                <div class="space-y-2 px-4 pb-4 text-sm text-slate-600">
                    @if ($footerContactIntro !== '')
                        <p class="text-slate-500">{{ $footerContactIntro }}</p>
                    @endif
                    @if (!empty($storeSettings['footer']['phone'] ?? ''))
                        <p><a href="tel:{{ preg_replace('/\\s+/', '', (string) $storeSettings['footer']['phone']) }}" class="text-base font-semibold text-slate-900 transition hover:text-slate-700">{{ $storeSettings['footer']['phone'] }}</a></p>
                    @endif
                    @if (!empty($storeSettings['footer']['email_sales'] ?? ''))
                        <p><a href="mailto:{{ $storeSettings['footer']['email_sales'] }}" class="transition hover:text-slate-900">{{ $storeSettings['footer']['email_sales'] }}</a></p>
                    @endif
                    @if (!empty($storeSettings['footer']['email_support'] ?? ''))
                        <p><a href="mailto:{{ $storeSettings['footer']['email_support'] }}" class="transition hover:text-slate-900">{{ $storeSettings['footer']['email_support'] }}</a></p>
                    @endif
                    @if (!empty($storeSettings['footer']['hours'] ?? ''))
                        <p>{{ $storeSettings['footer']['hours'] }}</p>
                    @endif
                    @if ($footerContactAddress !== '')
                        <p>{{ $footerContactAddress }}</p>
                    @endif
                </div>
            </details>

            @foreach ($footerColumns as $column)
                <details class="site-footer-mobile-section group">
                    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-4 text-base font-semibold text-slate-900">
                        {{ $column['title'] }}
                        <span class="inline-flex h-6 w-6 items-center justify-center text-[21px] font-light leading-none text-slate-500 group-open:hidden">+</span>
                        <span class="hidden h-6 w-6 items-center justify-center text-[21px] font-light leading-none text-slate-500 group-open:inline-flex">−</span>
                    </summary>
                    <ul class="space-y-2.5 px-4 pb-4 text-sm text-slate-600">
                        @foreach ($column['links'] as $link)
                            <li><a href="{{ $link['url'] }}" class="transition hover:text-slate-900">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </details>
            @endforeach
        </div>

        <div class="site-footer-links hidden lg:grid">
            <div class="site-footer-link-column site-footer-contact">
                <h3 class="site-footer-contact-title">{{ $footerContactTitle }}</h3>
                <div class="site-footer-contact-details">
                    @if ($footerContactIntro !== '')
                        <p class="site-footer-contact-intro">{{ $footerContactIntro }}</p>
                    @endif
                    @if (!empty($storeSettings['footer']['phone'] ?? ''))
                        <p><a href="tel:{{ preg_replace('/\\s+/', '', (string) $storeSettings['footer']['phone']) }}" class="site-footer-contact-phone transition">{{ $storeSettings['footer']['phone'] }}</a></p>
                    @endif
                    @if (!empty($storeSettings['footer']['email_sales'] ?? ''))
                        <p><a href="mailto:{{ $storeSettings['footer']['email_sales'] }}" class="transition">{{ $storeSettings['footer']['email_sales'] }}</a></p>
                    @endif
                    @if (!empty($storeSettings['footer']['email_support'] ?? ''))
                        <p><a href="mailto:{{ $storeSettings['footer']['email_support'] }}" class="transition">{{ $storeSettings['footer']['email_support'] }}</a></p>
                    @endif
                    @if (!empty($storeSettings['footer']['hours'] ?? ''))
                        <p>{{ $storeSettings['footer']['hours'] }}</p>
                    @endif
                    @if ($footerContactAddress !== '')
                        <p>{{ $footerContactAddress }}</p>
                    @endif
                </div>
                <div class="site-footer-socials flex items-center gap-2">
                    @if (!empty($storeSettings['branding']['social']['facebook']['url'] ?? '') && (bool) ($storeSettings['branding']['social']['facebook']['enabled'] ?? true))
                        <a href="{{ (string) $storeSettings['branding']['social']['facebook']['url'] }}" aria-label="{{ __('ui.front.desktop.social.facebook') }}" target="_blank" rel="noopener noreferrer">
                            <x-fa-icon name="facebook-f" style="brands" class="h-4 w-4" />
                        </a>
                    @endif
                    @if (!empty($storeSettings['branding']['social']['instagram']['url'] ?? '') && (bool) ($storeSettings['branding']['social']['instagram']['enabled'] ?? true))
                        <a href="{{ (string) $storeSettings['branding']['social']['instagram']['url'] }}" aria-label="{{ __('ui.front.desktop.social.instagram') }}" target="_blank" rel="noopener noreferrer">
                            <x-fa-icon name="instagram" style="brands" class="h-4 w-4" />
                        </a>
                    @endif
                    @if (!empty($storeSettings['branding']['social']['tiktok']['url'] ?? '') && (bool) ($storeSettings['branding']['social']['tiktok']['enabled'] ?? true))
                        <a href="{{ (string) $storeSettings['branding']['social']['tiktok']['url'] }}" aria-label="{{ __('ui.front.desktop.social.tiktok') }}" target="_blank" rel="noopener noreferrer">
                            <x-fa-icon name="tiktok" style="brands" class="h-4 w-4" />
                        </a>
                    @endif
                    @if (!empty($storeSettings['branding']['social']['youtube']['url'] ?? '') && (bool) ($storeSettings['branding']['social']['youtube']['enabled'] ?? true))
                        <a href="{{ (string) $storeSettings['branding']['social']['youtube']['url'] }}" aria-label="{{ __('ui.front.desktop.social.youtube') }}" target="_blank" rel="noopener noreferrer">
                            <x-fa-icon name="youtube" style="brands" class="h-4 w-4" />
                        </a>
                    @endif
                </div>
            </div>

            @foreach ($footerColumns as $column)
                <div class="site-footer-link-column site-footer-nav-column">
                    <h3 class="site-footer-column-title">{{ $column['title'] }}</h3>
                    <ul class="site-footer-link-list">
                        @foreach ($column['links'] as $link)
                            <li><a href="{{ $link['url'] }}" class="transition">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        <div class="site-footer-payments">
            <div class="site-footer-payment-logos flex flex-wrap items-center justify-center gap-2.5">
                <span class="site-footer-payment-logo inline-flex h-12 w-28 items-center justify-center px-1">
                    <img src="{{ asset('assets/payments/corvus-logo.svg') }}" alt="Corvus Pay" class="block h-6 w-auto object-contain" loading="lazy" width="116" height="24">
                </span>
                <span class="site-footer-payment-logo inline-flex h-12 w-28 items-center justify-center px-1">
                    <img src="{{ asset('assets/payments/visa-brand.svg') }}" alt="Visa" class="block h-6 w-auto object-contain" loading="lazy" width="74" height="24">
                </span>
                <span class="site-footer-payment-logo inline-flex h-12 w-28 items-center justify-center px-1">
                    <img src="{{ asset('assets/payments/mastercard-brand.svg') }}" alt="Mastercard" class="block h-6 w-auto object-contain" loading="lazy" width="31" height="24">
                </span>
                <span class="site-footer-payment-logo inline-flex h-12 w-28 items-center justify-center px-1">
                    <img src="{{ asset('assets/payments/diners-brand.svg') }}" alt="Diners Club" class="block h-6 w-auto object-contain" loading="lazy" width="93" height="24">
                </span>
                <span class="site-footer-payment-logo inline-flex h-12 w-28 items-center justify-center px-1">
                    <img src="{{ asset('assets/payments/maestro-brand.svg') }}" alt="Maestro" class="block h-6 w-auto object-contain" loading="lazy" width="31" height="24">
                </span>
                <span class="site-footer-payment-logo inline-flex h-12 w-28 items-center justify-center px-1">
                    <img src="{{ asset('assets/payments/applepay.svg') }}" alt="Apple Pay" class="block h-6 w-auto object-contain" loading="lazy" width="38" height="24">
                </span>
                <span class="site-footer-payment-logo inline-flex h-12 w-28 items-center justify-center px-1">
                    <img src="{{ asset('assets/payments/googlepay.svg') }}" alt="Google Pay" class="block h-6 w-auto object-contain" loading="lazy" width="45" height="24">
                </span>
            </div>
        </div>

        @php
            $copyrightText = trim((string) ($storeSettings['footer']['bottom_copyright_text'] ?? ''));
            if ($copyrightText === '') {
                $copyrightText = (string) __('ui.front.desktop.footer.copyright');
            }
            $storeName = (string) ($storeSettings['branding']['store_name'] ?? config('app.name', 'AG Shop'));
            $bottomLinks = collect($storeSettings['footer']['bottom_links'] ?? [])
                ->filter(fn ($link) => is_array($link) && trim((string) ($link['url'] ?? '')) !== '' && trim((string) ($link['label'] ?? '')) !== '')
                ->map(fn ($link) => ['label' => (string) $link['label'], 'url' => (string) $link['url']])
                ->values()
                ->all();
        @endphp
        <div class="store-footer-bottom grid items-center gap-3 text-xs lg:grid-cols-[1fr_auto_1fr]">
            <div class="store-footer-copyright">
                © {{ now()->year }} {{ $storeName }}. {{ $copyrightText }}
            </div>
            <div class="store-footer-bottom-links flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
                @foreach ($bottomLinks as $link)
                    <a href="{{ $link['url'] }}" class="store-footer-bottom-link transition">{{ $link['label'] }}</a>
                @endforeach
            </div>
            <div class="store-footer-creator flex items-center justify-center gap-2 lg:justify-end">
                <span>{{ __('ui.front.desktop.footer.web_by') }}</span>
                <a href="https://www.agmedia.hr" class="store-footer-creator-link inline-flex" target="_blank" rel="noopener noreferrer" aria-label="AG media">
                    <img src="{{ asset('assets/payments/ag-footer-logo.svg') }}" alt="AG media" width="91" height="30" loading="lazy">
                </a>
            </div>
        </div>
        </div>
    </div>
</footer>

<script>
    (function () {
        @if (!empty($newsletterCaptchaEnabled))
            let newsletterCaptchaReady = Promise.resolve();
            const newsletterCaptchaScript = document.createElement('script');
            newsletterCaptchaScript.src = 'https://www.google.com/recaptcha/api.js?render={{ $newsletterCaptchaSiteKey }}';
            newsletterCaptchaScript.async = true;
            newsletterCaptchaScript.defer = true;
            newsletterCaptchaReady = new Promise(function (resolve) {
                newsletterCaptchaScript.onload = resolve;
                newsletterCaptchaScript.onerror = resolve;
            });
            document.head.appendChild(newsletterCaptchaScript);
        @else
            const newsletterCaptchaReady = Promise.resolve();
        @endif

        const newsletterForm = document.querySelector('[data-newsletter-form]');
        if (newsletterForm) {
            const emailInput = newsletterForm.querySelector('[data-newsletter-email]');
            const errorMessage = newsletterForm.querySelector('[data-newsletter-error]');
            const acceptTermsInput = newsletterForm.querySelector('[data-newsletter-accept-terms]');
            const acceptTermsError = newsletterForm.querySelector('[data-newsletter-accept-error]');
            const recaptchaError = newsletterForm.querySelector('[data-newsletter-recaptcha-error]');
            const statusMessage = newsletterForm.querySelector('[data-newsletter-status]');
            const requiredMessage = newsletterForm.dataset.emailRequiredMessage || 'Upišite email adresu.';
            const invalidMessage = newsletterForm.dataset.emailInvalidMessage || 'Upišite ispravnu email adresu.';
            const acceptTermsMessage = newsletterForm.dataset.acceptTermsMessage || 'Morate prihvatiti GDPR privolu.';
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            const clearEmailError = function () {
                if (!emailInput || !errorMessage) {
                    return;
                }

                errorMessage.textContent = '';
                errorMessage.classList.add('hidden');
                errorMessage.style.display = 'none';
                emailInput.setAttribute('aria-invalid', 'false');
            };

            const showEmailError = function (message) {
                if (!emailInput || !errorMessage) {
                    return;
                }

                errorMessage.classList.add('hidden');
                errorMessage.style.display = 'block';
                errorMessage.textContent = message;
                errorMessage.classList.remove('hidden');
                emailInput.setAttribute('aria-invalid', 'true');
            };

            const clearStatusMessage = function () {
                if (!statusMessage) {
                    return;
                }

                statusMessage.textContent = '';
                statusMessage.className = 'mt-2 hidden text-xs font-semibold';
            };

            const showStatusMessage = function (message, tone) {
                if (!statusMessage) {
                    return;
                }

                const toneClass = tone === 'success'
                    ? 'text-emerald-700'
                    : (tone === 'warning' ? 'text-amber-700' : 'text-rose-600');

                statusMessage.textContent = message;
                statusMessage.className = 'mt-2 text-xs font-semibold ' + toneClass;
            };

            const clearAcceptTermsError = function () {
                if (!acceptTermsError) {
                    return;
                }

                acceptTermsError.textContent = '';
                acceptTermsError.classList.add('hidden');
                acceptTermsError.style.display = 'none';
            };

            const showAcceptTermsError = function (message) {
                if (!acceptTermsError) {
                    return;
                }

                acceptTermsError.classList.add('hidden');
                acceptTermsError.style.display = 'block';
                acceptTermsError.textContent = message;
                acceptTermsError.classList.remove('hidden');
            };

            const clearRecaptchaError = function () {
                if (!recaptchaError) {
                    return;
                }

                recaptchaError.textContent = '';
                recaptchaError.classList.add('hidden');
                recaptchaError.style.display = 'none';
            };

            const showRecaptchaError = function (message) {
                if (!recaptchaError) {
                    return;
                }

                recaptchaError.classList.add('hidden');
                recaptchaError.style.display = 'block';
                recaptchaError.textContent = message;
                recaptchaError.classList.remove('hidden');
            };

            const validateNewsletterForm = function () {
                clearEmailError();
                clearAcceptTermsError();
                clearRecaptchaError();
                clearStatusMessage();

                let isValid = true;
                const value = emailInput ? emailInput.value.trim() : '';

                if (emailInput) {
                    emailInput.value = value;
                }

                if (!emailInput || value === '') {
                    showEmailError(requiredMessage);
                    isValid = false;
                } else if (!emailRegex.test(value)) {
                    showEmailError(invalidMessage);
                    isValid = false;
                }

                if (!acceptTermsInput || !acceptTermsInput.checked) {
                    showAcceptTermsError(acceptTermsMessage);
                    isValid = false;
                }

                return isValid;
            };

            const refreshNewsletterRecaptcha = function () {
                const tokenInput = newsletterForm.querySelector('[data-recaptcha-token]');
                const siteKey = newsletterForm.dataset.recaptchaSiteKey || '';
                const action = newsletterForm.dataset.recaptchaAction || 'newsletter_footer';

                if (!tokenInput || !siteKey) {
                    return Promise.resolve();
                }

                return newsletterCaptchaReady.then(function () {
                    if (!window.grecaptcha || typeof window.grecaptcha.ready !== 'function') {
                        tokenInput.value = '';
                        return;
                    }

                    return new Promise(function (resolve) {
                        window.grecaptcha.ready(function () {
                            window.grecaptcha.execute(siteKey, { action: action })
                                .then(function (token) {
                                    tokenInput.value = token || '';
                                    resolve();
                                })
                                .catch(function () {
                                    tokenInput.value = '';
                                    resolve();
                                });
                        });
                    });
                });
            };

            newsletterForm.addEventListener('submit', async function (event) {
                if (!validateNewsletterForm()) {
                    event.preventDefault();
                    return;
                }

                event.preventDefault();

                const submitButton = newsletterForm.querySelector('button[type="submit"]');
                const originalButtonText = submitButton ? submitButton.textContent : '';

                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                }

                try {
                    await refreshNewsletterRecaptcha();

                    const formData = new FormData(newsletterForm);

                    const response = await fetch(newsletterForm.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });

                    const payload = await response.json().catch(function () {
                        return {};
                    });

                    if (!response.ok) {
                        const errors = payload && typeof payload === 'object' ? (payload.errors || {}) : {};
                        const emailErrors = Array.isArray(errors.newsletter_email) ? errors.newsletter_email : [];
                        const consentErrors = Array.isArray(errors.newsletter_accept_terms) ? errors.newsletter_accept_terms : [];
                        const recaptchaErrors = Array.isArray(errors.recaptcha_token) ? errors.recaptcha_token : [];

                        if (emailErrors.length > 0) {
                            showEmailError(emailErrors[0]);
                        } else if (payload.message) {
                            showEmailError(payload.message);
                        }

                        if (consentErrors.length > 0) {
                            showAcceptTermsError(consentErrors[0]);
                        }

                        if (recaptchaErrors.length > 0) {
                            showRecaptchaError(recaptchaErrors[0]);
                        }

                        if (payload.message && emailErrors.length === 0 && consentErrors.length === 0 && recaptchaErrors.length === 0) {
                            showStatusMessage(payload.message, 'error');
                        }

                        return;
                    }

                    clearEmailError();
                    clearAcceptTermsError();
                    showStatusMessage(payload.message || 'Uspješno spremljeno.', payload.type || 'success');
                    newsletterForm.reset();
                    emailInput?.focus();
                } catch (error) {
                    showStatusMessage('Newsletter prijava trenutno nije moguća. Pokušajte ponovno uskoro.', 'error');
                } finally {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
                        submitButton.textContent = originalButtonText;
                    }
                }
            });

            emailInput?.addEventListener('input', function () {
                if (emailInput.value.trim() === '') {
                    clearEmailError();
                    return;
                }

                if (emailRegex.test(emailInput.value.trim())) {
                    clearEmailError();
                }
            });

            acceptTermsInput?.addEventListener('change', function () {
                if (acceptTermsInput.checked) {
                    clearAcceptTermsError();
                }
            });
        }

        const onLoadScripts = [];

        onLoadScripts.push(@json(asset('front-theme/scripts/desktop-header-menu.js').'?v='.filemtime(public_path('front-theme/scripts/desktop-header-menu.js'))));
        onLoadScripts.push(@json(asset('front-theme/scripts/header-search-panel.js').'?v='.filemtime(public_path('front-theme/scripts/header-search-panel.js'))));
        onLoadScripts.push(@json(asset('front-theme/scripts/store-benefits-rotator.js').'?v='.filemtime(public_path('front-theme/scripts/store-benefits-rotator.js'))));
        onLoadScripts.push(@json(asset('front-theme/scripts/wishlist-toggle.js').'?v='.filemtime(public_path('front-theme/scripts/wishlist-toggle.js'))));

        function loadScript(src, module) {
            const s = document.createElement('script');
            s.src = src;
            if (module) {
                s.type = 'module';
            } else {
                s.defer = true;
            }
            document.body.appendChild(s);
        }

        let scriptsLoaded = false;
        function loadOnReady() {
            if (scriptsLoaded) {
                return;
            }
            scriptsLoaded = true;
            onLoadScripts.forEach(function (src) {
                loadScript(src, false);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', loadOnReady, { once: true });
        } else {
            loadOnReady();
        }
        window.addEventListener('pageshow', loadOnReady);
    })();
</script>
@if (empty($isMobileDevice))
    @include('front.partials.scroll-to-top')
@endif
@include('front.partials.cookie-consent', ['showCookieFloatingButton' => empty($isMobileDevice)])
<script defer src="{{ asset('front-theme/scripts/header-cart-popover.js') }}?v={{ filemtime(public_path('front-theme/scripts/header-cart-popover.js')) }}"></script>
<script defer src="{{ asset('front-theme/scripts/storefront-ui.js') }}?v={{ filemtime(public_path('front-theme/scripts/storefront-ui.js')) }}"></script>
@if (empty($isMobileDevice))
    <script defer src="{{ asset('front-theme/scripts/scroll-to-top.js') }}?v={{ filemtime(public_path('front-theme/scripts/scroll-to-top.js')) }}"></script>
@endif
@stack('scripts')
</body>
</html>
