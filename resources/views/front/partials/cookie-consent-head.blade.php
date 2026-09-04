@if ((bool) ($storeSettings['cookies']['enabled'] ?? true) && empty($storefrontCssBundleIncludesLegacyAssets))
    <link rel="stylesheet" href="{{ asset('front-theme/styles/cookie-consent-theme.css') }}?v={{ filemtime(public_path('front-theme/styles/cookie-consent-theme.css')) }}">
@endif
