@php
    $currentAdmin = auth()->user();
    $isSuperadmin = $currentAdmin?->isA('superadmin') ?? false;
    $canView = $isSuperadmin || ($currentAdmin?->can('integrations.msan.view') ?? false);
    $canManageSettings = $isSuperadmin || ($currentAdmin?->can('integrations.msan.settings.manage') ?? false);

    $tabs = $canView
        ? [
            ['route' => 'admin.integrations.msan.overview', 'label' => __('Pregled')],
            ['route' => 'admin.integrations.msan.categories', 'label' => __('Mapiranje kategorija')],
            ['route' => 'admin.integrations.msan.specifications', 'label' => __('Specifikacije')],
            ['route' => 'admin.integrations.msan.products', 'label' => __('Odabir artikala')],
            ['route' => 'admin.integrations.msan.runs', 'label' => __('Izvršavanja')],
        ]
        : [];

    if ($canManageSettings) {
        array_splice($tabs, $canView ? 1 : 0, 0, [[
            'route' => 'admin.integrations.msan.settings',
            'label' => __('Postavke'),
        ]]);
    }
@endphp

<nav class="admin-panel overflow-x-auto p-3" aria-label="{{ __('M SAN navigacija') }}">
    <div class="flex min-w-max items-center gap-2">
        @foreach ($tabs as $tab)
            @php($isActive = request()->routeIs($tab['route']))
            <a
                href="{{ route($tab['route']) }}"
                @if($isActive) aria-current="page" @endif
                class="inline-flex min-h-11 items-center rounded-xl border px-4 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 focus-visible:ring-offset-2 {{ $isActive ? 'border-slate-900 bg-slate-900 text-white shadow-sm' : 'border-slate-300 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-100 hover:text-slate-900' }}"
            >
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
</nav>
