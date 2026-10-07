<x-admin-layout :title="__('B2B cjenici')">
    <div class="mb-5 rounded-xl border border-slate-200 bg-white p-4">
        <a href="{{ route('admin.b2b-prices.catalogs') }}" class="font-semibold text-cyan-700">{{ __('B2B cjenici: popusti po grupama, cijene po artiklu i provjera cijene kupca') }} →</a>
        <p class="mt-1 text-sm text-slate-500">{{ __('Objavljena verzija čuva izvorne Herrera cijene. Postojeća pravila vrijede samo kada verzija nije aktivna.') }}</p>
    </div>
    <livewire:admin.catalog.pricing.b2-b-price-rule-manager />
</x-admin-layout>
