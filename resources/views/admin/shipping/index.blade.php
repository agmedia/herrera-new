<x-admin-layout :title="__('Postavke / Lokalno / Dostava')">
    @php
        $adminUser = auth()->user();
        $canManageGls = $adminUser
            && ($adminUser->isA('superadmin') || $adminUser->can('settings.api.manage'));
        $tab = request()->query('tab', 'methods');
        if (! in_array($tab, ['methods', 'gls'], true) || ($tab === 'gls' && ! $canManageGls)) {
            $tab = 'methods';
        }
    @endphp

    <div class="space-y-6">
        <nav class="admin-panel flex flex-wrap gap-2 p-3" aria-label="{{ __('Modul dostave') }}">
            <a
                href="{{ route('admin.shipping.index', ['tab' => 'methods']) }}"
                class="rounded-xl px-4 py-2 text-sm font-semibold {{ $tab === 'methods' ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}"
            >
                {{ __('Metode i cjenici') }}
            </a>
            @if ($canManageGls)
                <a
                    href="{{ route('admin.shipping.index', ['tab' => 'gls']) }}"
                    class="rounded-xl px-4 py-2 text-sm font-semibold {{ $tab === 'gls' ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}"
                >
                    {{ __('GLS integracija') }}
                </a>
            @endif
        </nav>

        @if ($tab === 'gls')
            <livewire:admin.settings.api.gls-manager />
        @else
            <livewire:admin.shipping.shipping-manager />
        @endif
    </div>
</x-admin-layout>
