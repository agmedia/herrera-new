@props(['branding' => null])

@php
    if (! is_array($branding)) {
        try {
            $branding = app(\App\Services\Front\StoreSettingsService::class)->branding();
        } catch (\Throwable) {
            $branding = [];
        }
    }

    $siteIconStoreName = trim((string) (($branding['store_name'] ?? null) ?: config('app.name', 'AG Shop')));
    $usesHerreraIcons = str_contains(strtolower($siteIconStoreName), 'herrera');
    $siteIcons = is_array($branding['favicons'] ?? null) ? $branding['favicons'] : [];
    $legacySiteIcon = trim((string) ($branding['favicon_url'] ?? ''));
    $siteManifestUrl = null;

    if ($usesHerreraIcons) {
        // Bundled theme icons replace legacy Herrera favicons without changing stored branding or logos.
        $versionedSiteIcon = static function (string $filename): string {
            $path = 'assets/herrera/icons/'.$filename;

            return asset($path).'?v='.substr(hash_file('sha256', public_path($path)), 0, 12);
        };
        $siteIcons = [
            'ico_url' => $versionedSiteIcon('favicon.ico'),
            '16_url' => $versionedSiteIcon('favicon-16x16.png'),
            '32_url' => $versionedSiteIcon('favicon-32x32.png'),
            '180_url' => $versionedSiteIcon('apple-touch-icon.png'),
            '192_url' => $versionedSiteIcon('android-chrome-192x192.png'),
            '512_url' => $versionedSiteIcon('android-chrome-512x512.png'),
        ];
        $siteManifestUrl = $versionedSiteIcon('site.webmanifest');
    }
@endphp

@if (! empty($siteIcons['ico_url']))
    <link rel="icon" type="image/x-icon" href="{{ $siteIcons['ico_url'] }}" sizes="{{ $usesHerreraIcons ? '16x16 32x32 48x48' : 'any' }}">
@elseif ($legacySiteIcon !== '')
    <link rel="icon" href="{{ $legacySiteIcon }}">
@endif
@foreach ([32, 16, 192, 512] as $siteIconSize)
    @if (! empty($siteIcons[$siteIconSize.'_url']))
        <link rel="icon" type="image/png" sizes="{{ $siteIconSize }}x{{ $siteIconSize }}" href="{{ $siteIcons[$siteIconSize.'_url'] }}">
    @endif
@endforeach
@if (! empty($siteIcons['180_url']))
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $siteIcons['180_url'] }}">
@endif
@if ($siteManifestUrl)
    <link rel="manifest" type="application/manifest+json" href="{{ $siteManifestUrl }}">
    <meta name="theme-color" content="#007b8b">
    <meta name="apple-mobile-web-app-title" content="{{ $siteIconStoreName }}">
@endif
