<x-admin-layout :title="__('Integracije / M SAN / Izvršavanja')">
    <div class="space-y-4">
        @include('admin.integrations.msan.partials.tabs')

        <livewire:admin.integrations.msan.run-history-manager />
    </div>
</x-admin-layout>
