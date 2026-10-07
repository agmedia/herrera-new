@if ((bool) ($storeSettings['cookies']['enabled'] ?? true))
    <link rel="stylesheet" data-cookie-consent-css="1" href="{{ asset('front-theme/vendors/cookieconsent/cookieconsent.css') }}">
    <script defer src="{{ asset('front-theme/vendors/cookieconsent/cookieconsent.umd.js') }}"></script>
    @if (empty($storefrontCssBundleIncludesLegacyAssets))
        <link rel="stylesheet" href="{{ asset('front-theme/styles/cookie-consent-theme.css') }}?v={{ filemtime(public_path('front-theme/styles/cookie-consent-theme.css')) }}">
    @endif
@endif
