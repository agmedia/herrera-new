<x-admin-layout :title="__('Integracije / EPREL')">
    <div class="space-y-6">
        <section class="admin-panel admin-search-panel p-6">
            <h1 class="text-xl font-semibold tracking-tight">{{ __('EPREL postavke') }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ __('Povežite artikle s europskim registrom energetski označenih proizvoda. EPREL ima vlastite postavke i radi neovisno o dobavljačima.') }}</p>
        </section>
        <livewire:admin.integrations.eprel.settings-form />
    </div>
</x-admin-layout>
