<x-admin-layout :title="__('Integracije / M SAN / Kategorije')">
    <div class="space-y-4">
        @include('admin.integrations.msan.partials.tabs')

        <livewire:admin.integrations.msan.category-mapping-manager />
    </div>
</x-admin-layout>
