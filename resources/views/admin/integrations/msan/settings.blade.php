<x-admin-layout :title="__('Integracije / M SAN / Postavke')">
    <div class="space-y-6">
        @include('admin.integrations.msan.partials.tabs')

        <section class="admin-panel admin-search-panel p-6">
            <h1 class="text-xl font-semibold tracking-tight">{{ __('M SAN postavke') }}</h1>
            <p class="mt-2 text-sm text-slate-600">
                {{ __('Sigurno spremite klijentski certifikat i PIN, podesite pravila uvoza te provjerite vezu prije prvog dohvaćanja.') }}
            </p>
        </section>

        <livewire:admin.integrations.msan.settings-form />
    </div>
</x-admin-layout>
