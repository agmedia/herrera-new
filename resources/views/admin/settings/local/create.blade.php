<x-admin-layout :title="__('Settings / Local / Create :resource', ['resource' => str_replace('-', ' ', ucwords($resource, '-'))])">
    <livewire:admin.settings.local.resource-manager
        :resource="$resource"
        :create-page="true"
    />
</x-admin-layout>
