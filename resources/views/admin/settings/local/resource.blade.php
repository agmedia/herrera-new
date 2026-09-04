<x-admin-layout :title="__('Settings / Local / :resource', ['resource' => str_replace('-', ' ', ucwords($resource, '-'))])">
    <livewire:admin.settings.local.resource-manager :resource="$resource" />
</x-admin-layout>
