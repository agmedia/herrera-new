<x-admin-layout :title="__('Settings / Local / Edit :resource', ['resource' => str_replace('-', ' ', ucwords($resource, '-'))])">
    <livewire:admin.settings.local.resource-manager
        :resource="$resource"
        :record-id="$recordId"
        :edit-page="true"
    />
</x-admin-layout>
