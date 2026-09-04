<x-admin-layout :title="__('Postavke / Lokalno / Dostava / Uredi')">
    <livewire:admin.shipping.shipping-manager :edit-page="true" :record-id="$shippingMethod->id" />
</x-admin-layout>
