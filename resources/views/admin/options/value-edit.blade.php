<x-admin-layout :title="__('Options / Values / Edit')">
    <livewire:admin.catalog.option.value-manager
        :option-id="$option->id"
        :record-id="$value->id"
        :edit-page="true"
    />
</x-admin-layout>
