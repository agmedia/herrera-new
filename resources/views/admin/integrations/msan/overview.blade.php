<x-admin-layout :title="__('Integracije / M SAN / Pregled')">
    <div class="space-y-6">
        @include('admin.integrations.msan.partials.tabs')

        <section class="admin-panel admin-search-panel p-6">
            <h1 class="text-xl font-semibold tracking-tight">{{ __('M SAN integracija') }}</h1>
            <p class="mt-2 text-sm text-slate-600">
                {{ __('Pregled veze, posljednjih dohvaćanja i spremnosti kataloga za kontrolirani uvoz.') }}
            </p>
        </section>

        <livewire:admin.integrations.msan.dashboard />
    </div>
</x-admin-layout>
