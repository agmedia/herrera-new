@php
    $warranty = $storeSettings['legal_warranty'] ?? app(\App\Services\Front\StoreSettingsService::class)->legalWarranty();
    $warrantyVariant = $warrantyVariant ?? 'product';
@endphp
@if (($warranty['enabled'] ?? false) && ($warranty[$warrantyVariant.'_enabled'] ?? true))
<aside class="legal-warranty-notice legal-warranty-notice--{{ $warrantyVariant }}" data-legal-warranty-notice="{{ $warrantyVariant }}" aria-label="{{ __('herrera.warranty.title') }}">
    <a href="{{ $warranty['asset_url'] }}" target="_blank" rel="noopener noreferrer" data-legal-warranty-open aria-controls="legal-warranty-modal" aria-haspopup="dialog">
        <x-fa-icon name="shield-halved" class="legal-warranty-icon" />
        <span>{{ __('herrera.warranty.title') }}</span>
    </a>
</aside>
@endif
