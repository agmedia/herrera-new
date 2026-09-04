<x-admin-layout :title="__('Integracije / M SAN / Specifikacije')">
    <div class="space-y-4">
        @include('admin.integrations.msan.partials.tabs')

        <livewire:admin.integrations.msan.specification-mapping-manager />
    </div>
</x-admin-layout>
