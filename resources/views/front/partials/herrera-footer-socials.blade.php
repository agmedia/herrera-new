@php
    $herreraFooterSocialSettings = collect($storeSettings['branding']['social'] ?? [])
        ->filter(fn ($social) => is_array($social));
    $herreraConfiguredFooterSocials = $herreraFooterSocialSettings
        ->filter(fn ($social) => (bool) ($social['enabled'] ?? true) && trim((string) ($social['url'] ?? '')) !== '')
        ->map(fn ($social, $network) => ['network' => $network, 'url' => trim((string) $social['url'])]);
    $herreraTopBarSocialFallbacks = collect($topBar['socials'] ?? [])
        ->filter(fn ($social) => is_array($social) && !empty($social['is_active']) && trim((string) ($social['url'] ?? '')) !== '')
        ->filter(fn ($social) => (bool) ($herreraFooterSocialSettings->get($social['network'])['enabled'] ?? true));
    // Configured footer URLs take precedence, and disabled footer networks stay disabled.
    $herreraFooterSocials = $herreraConfiguredFooterSocials->values()
        ->concat($herreraTopBarSocialFallbacks)
        ->unique('network')
        ->values();
@endphp
@if ($herreraFooterSocials->isNotEmpty())
    <div class="herrera-footer-socials" aria-label="{{ __('Društvene mreže') }}">
        @foreach ($herreraFooterSocials as $social)
            @php
                $socialName = match ($social['network']) { 'linkedin' => 'LinkedIn', 'twitter' => 'X / Twitter', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', default => ucfirst($social['network']) };
                $socialIcon = match ($social['network']) { 'linkedin' => 'linkedin-in', 'twitter' => 'x-twitter', 'facebook' => 'facebook-f', default => $social['network'] };
            @endphp
            <a href="{{ $social['url'] }}" aria-label="{{ $socialName }}" target="_blank" rel="noopener noreferrer">
                <x-fa-icon :name="$socialIcon" style="brands" />
                <span>{{ $socialName }}</span>
            </a>
        @endforeach
    </div>
@endif
