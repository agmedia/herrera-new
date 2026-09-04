<x-admin-layout :title="__('Integracije / M SAN / Artikli')">
    <div class="space-y-4">
        @include('admin.integrations.msan.partials.tabs')

        <livewire:admin.integrations.msan.product-selection-manager />
    </div>
</x-admin-layout>
