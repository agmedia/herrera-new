<x-admin-layout :title="__('Settings / API / Wholesale')">
    <div class="space-y-6">
        @include('admin.settings.api.partials.nav')
        <livewire:admin.settings.api.manager />
    </div>
</x-admin-layout>
