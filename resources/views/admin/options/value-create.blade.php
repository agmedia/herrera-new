<x-admin-layout :title="__('Options / Values / Create')">
    <livewire:admin.catalog.option.value-manager
        :option-id="$option->id"
        :create-page="true"
    />
</x-admin-layout>
