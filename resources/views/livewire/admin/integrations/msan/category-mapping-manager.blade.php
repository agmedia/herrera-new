@php
    $statusOptions = [
        'unmapped' => [__('Nije mapirano'), 'border-amber-200 bg-amber-50 text-amber-900'],
        'mapped' => [__('Mapirano'), 'border-emerald-200 bg-emerald-50 text-emerald-900'],
        'ignored' => [__('Ignorirano'), 'border-slate-200 bg-slate-50 text-slate-700'],
        'all' => [__('Sve kategorije'), 'border-cyan-200 bg-cyan-50 text-cyan-900'],
    ];
    $categoryState = static function ($category) use ($energyRequirementOptions): array {
        $mapping = $category->mapping;
        $depth = blank($category->parent_external_id)
            ? 0
            : max(1, substr_count(trim((string) $category->path), ' > '));
        $mappingStatus = match (true) {
            $mapping?->status === 'ignored' => 'ignored',
            $mapping?->status === 'mapped' && $mapping?->local_category_id => 'mapped',
            default => 'unmapped',
        };
        $localCategory = $mapping?->localCategory;
        $localTranslation = $localCategory?->translations?->firstWhere('locale', 'hr')
            ?? $localCategory?->translations?->first();

        return [
            'depth' => $depth,
            'isRoot' => $depth === 0,
            'indentClass' => match (min($depth, 4)) {
                1 => 'pl-5',
                2 => 'pl-10',
                3 => 'pl-14',
                4 => 'pl-20',
                default => '',
            },
            'mobileIndentClass' => match (min($depth, 4)) {
                1 => 'pl-2',
                2 => 'pl-4',
                3 => 'pl-6',
                4 => 'pl-8',
                default => '',
            },
            'hierarchyLabel' => $depth === 0
                ? __('Glavna kategorija')
                : __('Podkategorija · razina :depth', ['depth' => $depth]),
            'mapping' => $mapping,
            'status' => $mappingStatus,
            'statusLabel' => match ($mappingStatus) {
                'mapped' => __('Mapirano'),
                'ignored' => __('Ignorirano'),
                default => __('Nije mapirano'),
            },
            'statusClass' => match ($mappingStatus) {
                'mapped' => 'bg-emerald-100 text-emerald-800',
                'ignored' => 'bg-slate-200 text-slate-700',
                default => 'bg-amber-100 text-amber-800',
            },
            'localCategory' => $localCategory,
            'localName' => $localTranslation?->name ?? $localCategory?->code,
            'energyLabel' => __($energyRequirementOptions[$mapping?->energy_requirement ?? 'inherit'] ?? __('Automatski prema dostupnim podacima')),
        ];
    };
@endphp

<div class="space-y-4">
    <section class="admin-panel admin-search-panel p-5 sm:p-6">
        <div class="flex flex-col gap-5">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-800">{{ __('M SAN katalog') }}</p>
                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">{{ __('Mapiranja se trajno spremaju') }}</span>
                    </div>
                    <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">{{ __('Mapiranje kategorija') }}</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('Povežite M SAN kategorije s kategorijama webshopa. Aktualne kategorije složene su po stablu: svaka glavna kategorija i njezine podkategorije prikazuju se abecednim redom.') }}</p>
                </div>
                @if ($canManageMapping)
                    <button type="button" wire:click="autoMatchExactNames" wire:confirm="{{ __('Automatski mapirati samo M SAN i lokalne kategorije s potpuno jednakim, jedinstvenim nazivom? Postojeća mapiranja neće se mijenjati.') }}" wire:loading.attr="disabled" wire:target="autoMatchExactNames" class="min-h-11 shrink-0 rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60"><span wire:loading.remove wire:target="autoMatchExactNames">{{ __('Sigurno spoji jednake nazive') }}</span><span wire:loading wire:target="autoMatchExactNames">{{ __('Mapiram...') }}</span></button>
                @endif
            </div>

            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Filtriranje kategorija prema statusu') }}">
                @foreach ($statusOptions as $statusValue => [$statusLabel, $statusClass])
                    <button type="button" wire:click="$set('status', '{{ $statusValue }}')" aria-pressed="{{ $status === $statusValue ? 'true' : 'false' }}" class="min-h-14 rounded-xl border px-4 py-3 text-left transition {{ $status === $statusValue ? 'border-slate-900 bg-slate-900 text-white shadow-sm' : $statusClass.' hover:border-slate-400' }}">
                        <span class="block text-xs font-semibold uppercase tracking-wide opacity-75">{{ $statusLabel }}</span>
                        <span class="mt-1 block text-xl font-bold tabular-nums">{{ number_format($statusCounts[$statusValue] ?? 0, 0, ',', '.') }}</span>
                    </button>
                @endforeach
            </div>

            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <form wire:submit="applySearch" class="w-full max-w-2xl">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-category-search">{{ __('Pretraga kategorija') }}</label>
                    <div class="flex gap-2">
                        <input id="msan-category-search" type="search" wire:model="searchInput" minlength="2" maxlength="120" placeholder="{{ __('Naziv, putanja ili M SAN šifra...') }}" class="admin-search-input min-w-0 flex-1 rounded-xl border px-3 py-2 text-sm">
                        <button type="submit" wire:loading.attr="disabled" wire:target="applySearch" class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-wait disabled:opacity-60"><span wire:loading.remove wire:target="applySearch">{{ __('Traži') }}</span><span wire:loading wire:target="applySearch">{{ __('Tražim...') }}</span></button>
                    </div>
                    @error('searchInput') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </form>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <p class="mr-1 text-xs text-slate-500">{{ __('Primijenjeni filtri pamte se u ovoj administratorskoj sesiji.') }}</p>
                    <label for="msan-categories-with-products-only" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        <input id="msan-categories-with-products-only" type="checkbox" wire:model.live="withProductsOnly" class="h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">
                        <span>{{ __('Samo s artiklima') }}</span>
                    </label>
                    @if ($search !== '')<button type="button" wire:click="clearSearch" class="min-h-11 rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">{{ __('Ukloni pretragu') }} <span aria-hidden="true">×</span></button>@endif
                    <button type="button" wire:click="clearFilters" @disabled($activeFilterCount === 0) class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50">{{ __('Prikaži sve') }}</button>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-panel admin-panel-soft p-4 sm:p-5" aria-labelledby="msan-categories-heading">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 id="msan-categories-heading" tabindex="-1" class="admin-section-title">{{ __('M SAN kategorije') }}</h2><p class="mt-1 text-xs text-slate-500">{{ __('Mapirajte kategoriju ili je svjesno označite za preskakanje.') }}</p></div>
            <p class="text-xs text-slate-500">{{ __('Prikazano :from–:to od :total', ['from' => $categories->firstItem() ?? 0, 'to' => $categories->lastItem() ?? 0, 'total' => $categories->total()]) }} · {{ __(':count po stranici', ['count' => $perPage]) }}</p>
        </div>

        <div class="mt-4 hidden overflow-x-auto xl:block" tabindex="0" aria-label="{{ __('Tablica M SAN kategorija') }}">
            <table class="admin-items-table min-w-[68rem] text-sm">
                <caption class="sr-only">{{ __('M SAN kategorije i trajno spremljena mapiranja prema kategorijama webshopa') }}</caption>
                <thead class="text-slate-600"><tr><th scope="col" class="px-3 py-3 text-left font-semibold">{{ __('M SAN kategorija') }}</th><th scope="col" class="px-3 py-3 text-right font-semibold">{{ __('Artikli') }}</th><th scope="col" class="px-3 py-3 text-left font-semibold">{{ __('Status / kategorija webshopa') }}</th><th scope="col" class="px-3 py-3 text-left font-semibold">{{ __('Energetski podaci') }}</th><th scope="col" class="px-3 py-3 text-left font-semibold">{{ __('Zadnje viđeno') }}</th>@if($canManageMapping)<th scope="col" class="px-3 py-3 text-right font-semibold">{{ __('Radnje') }}</th>@endif</tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($categories as $category)
                        @php($state = $categoryState($category))
                        <tr
                            wire:key="msan-category-row-{{ $category->id }}"
                            data-msan-category-depth="{{ $state['depth'] }}"
                            @class([
                                'bg-cyan-50/60' => $editingCategoryId === $category->id,
                                'border-t-2 border-slate-200 bg-slate-50/90 first:border-t-0' => $state['isRoot'] && $editingCategoryId !== $category->id,
                            ])
                        >
                            <td class="px-3 py-3">
                                <div class="{{ $state['indentClass'] }}">
                                    <div class="flex max-w-[32rem] items-start gap-2.5">
                                        @if (! $state['isRoot'])
                                            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-slate-200/80 text-xs font-bold text-slate-500" aria-hidden="true">↳</span>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <div class="text-slate-900 {{ $state['isRoot'] ? 'font-bold' : 'font-semibold' }}">{{ $category->name }}</div>
                                                <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $state['isRoot'] ? 'bg-cyan-100 text-cyan-800' : 'bg-slate-100 text-slate-500' }}">{{ $state['hierarchyLabel'] }}</span>
                                            </div>
                                            @if($category->path && $category->path !== $category->name)<div class="mt-0.5 max-w-[32rem] whitespace-normal text-xs leading-5 text-slate-500">{{ $category->path }}</div>@endif
                                            <div class="mt-1 font-mono text-[11px] text-slate-400">{{ $category->external_id }}</div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right text-base font-semibold tabular-nums text-slate-800">{{ number_format((int) $category->product_count, 0, ',', '.') }}</td>
                            <td class="px-3 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $state['statusClass'] }}">{{ $state['statusLabel'] }}</span>@if($state['localCategory'])<div class="mt-2 font-medium text-slate-800">{{ $state['localName'] }}</div><div class="mt-0.5 font-mono text-[11px] text-slate-400">{{ $state['localCategory']->code }}</div>@elseif($state['status'] === 'ignored')<p class="mt-2 text-xs text-slate-500">{{ __('Preskače se pri uvozu') }}</p>@endif</td>
                            <td class="px-3 py-3 text-xs text-slate-600"><div class="max-w-64 whitespace-normal font-medium leading-5 text-slate-700">{{ $state['energyLabel'] }}</div><div class="mt-1 font-mono text-[11px] text-slate-400">{{ $state['mapping']?->eprel_product_group ?: __('EPREL grupa nije zadana') }}</div></td>
                            <td class="px-3 py-3 text-xs text-slate-600"><div>{{ $category->last_seen_at?->format('d.m.Y. H:i') ?? '—' }}</div>@if($category->is_stale)<span class="mt-1 inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">{{ __('Zastarjelo') }}</span>@endif</td>
                            @if($canManageMapping)<td class="px-3 py-3 text-right"><div class="flex flex-wrap justify-end gap-2"><button type="button" data-msan-category-editor-trigger wire:click="openEditor({{ $category->id }})" wire:loading.attr="disabled" wire:target="openEditor({{ $category->id }})" class="min-h-10 rounded-lg border border-cyan-300 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">{{ $state['status'] === 'mapped' ? __('Promijeni') : __('Mapiraj') }}</button>@if($state['status'] !== 'ignored')<button type="button" wire:click="ignoreCategory({{ $category->id }})" wire:confirm="{{ __('Označiti kategoriju :name za preskakanje pri uvozu?', ['name' => $category->name]) }}" wire:loading.attr="disabled" wire:target="ignoreCategory({{ $category->id }})" class="min-h-10 rounded-lg border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-50">{{ __('Ignoriraj') }}</button>@endif @if($state['mapping'])<button type="button" wire:click="clearMapping({{ $category->id }})" wire:confirm="{{ __('Ukloniti postojeće mapiranje kategorije :name?', ['name' => $category->name]) }}" wire:loading.attr="disabled" wire:target="clearMapping({{ $category->id }})" class="min-h-10 rounded-lg border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Ukloni') }}</button>@endif</div></td>@endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canManageMapping ? 6 : 5 }}" class="px-4 py-12 text-center"><p class="font-semibold text-slate-700">{{ __('Nema kategorija prema odabranim filtrima.') }}</p><p class="mt-1 text-sm text-slate-500">{{ __('Odaberite drugi status, uklonite pretragu ili dohvatite M SAN katalog.') }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:hidden">
            @forelse($categories as $category)
                @php($state = $categoryState($category))
                <article
                    wire:key="msan-category-card-{{ $category->id }}"
                    data-msan-category-depth="{{ $state['depth'] }}"
                    class="rounded-xl border p-4 {{ $state['isRoot'] ? 'md:col-span-2' : '' }} {{ $editingCategoryId === $category->id ? 'border-cyan-300 bg-cyan-50/60' : ($state['isRoot'] ? 'border-slate-300 bg-slate-50' : 'border-slate-200 border-l-4 border-l-slate-300 bg-white') }}"
                >
                    <div class="{{ $state['isRoot'] ? '' : $state['mobileIndentClass'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-2.5">
                                @if (! $state['isRoot'])<span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-slate-200/80 text-xs font-bold text-slate-500" aria-hidden="true">↳</span>@endif
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2"><h3 class="leading-5 text-slate-900 {{ $state['isRoot'] ? 'font-bold' : 'font-semibold' }}">{{ $category->name }}</h3><span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $state['isRoot'] ? 'bg-cyan-100 text-cyan-800' : 'bg-slate-100 text-slate-500' }}">{{ $state['hierarchyLabel'] }}</span></div>
                                    <p class="mt-1 break-all font-mono text-[11px] text-slate-500">{{ $category->external_id }}</p>
                                </div>
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $state['statusClass'] }}">{{ $state['statusLabel'] }}</span>
                        </div>
                    </div>
                    @if($category->path && $category->path !== $category->name)<p class="mt-2 text-xs leading-5 text-slate-500">{{ $category->path }}</p>@endif
                    <dl class="mt-3 grid grid-cols-2 gap-3 border-t border-slate-200 pt-3 text-xs">
                        <div><dt class="text-slate-500">{{ __('Artikli') }}</dt><dd class="mt-1 text-base font-bold tabular-nums text-slate-800">{{ number_format((int) $category->product_count, 0, ',', '.') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('Kategorija webshopa') }}</dt><dd class="mt-1 font-semibold text-slate-800">{{ $state['localName'] ?? ($state['status'] === 'ignored' ? __('Preskače se') : '—') }}</dd></div>
                        <div class="col-span-2"><dt class="text-slate-500">{{ __('Energetski podaci') }}</dt><dd class="mt-1 font-medium text-slate-700">{{ $state['energyLabel'] }}</dd></div>
                        <div class="col-span-2">
                            <dt class="text-slate-500">{{ __('Zadnje viđeno') }}</dt>
                            <dd class="mt-1 flex flex-wrap items-center gap-2 font-medium text-slate-700">
                                <span>{{ $category->last_seen_at?->format('d.m.Y. H:i') ?? '—' }}</span>
                                @if($category->is_stale)<span class="inline-flex rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-700">{{ __('Zastarjelo') }}</span>@endif
                            </dd>
                        </div>
                    </dl>
                    @if($canManageMapping)
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" data-msan-category-editor-trigger wire:click="openEditor({{ $category->id }})" wire:loading.attr="disabled" wire:target="openEditor({{ $category->id }})" class="min-h-11 flex-1 rounded-xl border border-cyan-300 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100 disabled:cursor-wait disabled:opacity-60">{{ $state['status'] === 'mapped' ? __('Promijeni mapiranje') : __('Mapiraj kategoriju') }}</button>
                            @if($state['status'] !== 'ignored')<button type="button" wire:click="ignoreCategory({{ $category->id }})" wire:confirm="{{ __('Označiti kategoriju :name za preskakanje pri uvozu?', ['name' => $category->name]) }}" wire:loading.attr="disabled" wire:target="ignoreCategory({{ $category->id }})" class="min-h-11 rounded-xl border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-50 disabled:cursor-wait disabled:opacity-60">{{ __('Ignoriraj') }}</button>@endif
                            @if($state['mapping'])<button type="button" wire:click="clearMapping({{ $category->id }})" wire:confirm="{{ __('Ukloniti postojeće mapiranje kategorije :name?', ['name' => $category->name]) }}" wire:loading.attr="disabled" wire:target="clearMapping({{ $category->id }})" class="min-h-11 rounded-xl border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:cursor-wait disabled:opacity-60">{{ __('Ukloni') }}</button>@endif
                        </div>
                    @endif
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-12 text-center text-sm text-slate-500">{{ __('Nema kategorija prema odabranim filtrima.') }}</div>
            @endforelse
        </div>

        <div class="mt-4">{{ $categories->links() }}</div>
    </section>

    @if ($editingCategoryId && $canManageMapping)
        <div
            data-msan-category-drawer
            class="fixed inset-0 z-[80]"
            role="dialog"
            aria-modal="true"
            aria-labelledby="msan-category-editor-title"
            wire:key="msan-category-editor-{{ $editingCategoryId }}"
            x-data="{
                focusables() {
                    const selector = `a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex='-1'])`;
                    return [...this.$refs.panel.querySelectorAll(selector)].filter((element) => element.getAttribute('aria-hidden') !== 'true' && (element.offsetWidth > 0 || element.offsetHeight > 0));
                },
                focusInitial(attempt = 0) {
                    const select = this.$refs.initialFocus;
                    const target = select?.tomselect?.focus_node;
                    if (!target && attempt < 12) {
                        setTimeout(() => this.focusInitial(attempt + 1), 25);
                        return;
                    }
                    (target || select)?.focus({ preventScroll: true });
                },
                trapTab(event) {
                    const focusables = this.focusables();
                    if (focusables.length === 0) {
                        event.preventDefault();
                        return;
                    }
                    const first = focusables[0];
                    const last = focusables[focusables.length - 1];
                    if (event.shiftKey && (document.activeElement === first || !this.$refs.panel.contains(document.activeElement))) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                },
                init() {
                    const active = document.activeElement;
                    if ((!window.__msanCategoryDrawerReturnFocus || !window.__msanCategoryDrawerReturnFocus.isConnected) && active instanceof HTMLElement && !active.closest('[data-msan-category-drawer]')) {
                        window.__msanCategoryDrawerReturnFocus = active;
                    }
                    document.body.classList.add('overflow-y-hidden');
                    this.$nextTick(() => this.focusInitial());
                },
                destroy() {
                    setTimeout(() => {
                        if (document.querySelector('[data-msan-category-drawer]')) return;
                        document.body.classList.remove('overflow-y-hidden');
                        const stored = window.__msanCategoryDrawerReturnFocus;
                        delete window.__msanCategoryDrawerReturnFocus;
                        const target = stored?.isConnected
                            ? stored
                            : (document.querySelector('[data-msan-category-editor-trigger]') || document.getElementById('msan-categories-heading'));
                        target?.focus({ preventScroll: true });
                    }, 0);
                }
            }"
            x-on:keydown.tab="trapTab($event)"
            x-on:keydown.escape.window.prevent="$wire.cancelEdit()"
        >
            <button type="button" tabindex="-1" wire:click="cancelEdit" class="absolute inset-0 h-full w-full cursor-default bg-slate-950/40 backdrop-blur-[1px]" aria-label="{{ __('Zatvori editor mapiranja') }}"></button>
            <section x-ref="panel" class="absolute inset-y-0 right-0 flex w-full max-w-2xl flex-col overflow-hidden bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                    <div class="min-w-0"><p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-800">{{ __('Uredi mapiranje') }}</p><h2 id="msan-category-editor-title" class="mt-1 text-lg font-semibold text-slate-900">{{ $editingCategory?->name ?? __('M SAN kategorija #:id', ['id' => $editingCategoryId]) }}</h2>@if($editingCategory?->path)<p class="mt-1 text-sm leading-5 text-slate-600">{{ $editingCategory->path }}</p>@endif<div class="mt-2 flex flex-wrap gap-2 text-xs"><span class="admin-chip">{{ __('M SAN') }}: {{ $editingCategory?->external_id ?? $editingCategoryId }}</span><span class="admin-chip">{{ __('Artikli') }}: {{ number_format((int) ($editingCategory?->product_count ?? 0), 0, ',', '.') }}</span></div></div>
                    <button type="button" wire:click="cancelEdit" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-300 text-xl text-slate-600 hover:bg-slate-100" aria-label="{{ __('Zatvori') }}">×</button>
                </div>
                <form wire:submit="saveMapping" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-6">
                        <div><label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-local-category">{{ __('Kategorija webshopa') }}</label><select x-ref="initialFocus" id="msan-local-category" wire:model="localCategoryId" data-tom-select data-tom-preserve-order="1" data-tom-placeholder="{{ __('Pretražite kategorije webshopa...') }}" class="admin-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm"><option value="">{{ __('Odaberite kategoriju...') }}</option>@foreach($localCategoryOptions as $option)<option value="{{ $option['id'] }}">{{ $option['label'] }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-500">{{ __('Možete upisati dio naziva; hijerarhija kategorija ostaje u izvornom redoslijedu.') }}</p>@error('localCategoryId')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                        @if ($branchImportSuggestion)
                            <section class="rounded-xl border border-cyan-200 bg-cyan-50 p-4" aria-labelledby="msan-category-branch-import-title">
                                <p id="msan-category-branch-import-title" class="font-semibold text-cyan-950">{{ __('Nema jednake kategorije u webshopu') }}</p>
                                <p class="mt-1 text-sm leading-5 text-cyan-900">{{ __('Možete uvesti „:name” i pripadajuće M SAN podkategorije. Hijerarhija će se sačuvati, a sve uvezene kategorije odmah mapirati.', ['name' => $editingCategory?->name]) }}</p>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold text-cyan-900">
                                    <span class="rounded-full bg-white/80 px-2.5 py-1">{{ __('Kategorije: :count', ['count' => (int) $branchImportSuggestion['category_count']]) }}</span>
                                    <span class="rounded-full bg-white/80 px-2.5 py-1">{{ __('Podkategorije: :count', ['count' => (int) $branchImportSuggestion['descendant_count']]) }}</span>
                                    <span class="rounded-full bg-white/80 px-2.5 py-1">{{ __('Jedinstveni artikli: :count', ['count' => (int) $branchImportSuggestion['product_count']]) }}</span>
                                </div>
                                @if (! $branchImportSuggestion['source_is_root'])
                                    <div class="mt-4">
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-cyan-900" for="msan-category-branch-parent">{{ __('Smjesti ispod kategorije') }}</label>
                                        <select id="msan-category-branch-parent" wire:model="branchImportParentId" data-tom-select data-tom-preserve-order="1" data-tom-placeholder="{{ __('Kao glavna kategorija webshopa') }}" class="admin-select w-full rounded-xl border border-cyan-300 bg-white px-3 py-2 text-sm">
                                            <option value="">{{ __('Kao glavna kategorija webshopa') }}</option>
                                            @foreach($localCategoryOptions as $option)<option value="{{ $option['id'] }}">{{ $option['label'] }}</option>@endforeach
                                        </select>
                                    </div>
                                @endif
                                <p class="mt-3 text-xs leading-5 text-cyan-800">{{ __('Nove kategorije bit će neaktivne i skrivene iz izbornika dok ih ne pregledate. Artikli se i dalje uvoze zasebno.') }}</p>
                                <button
                                    type="button"
                                    wire:click="importCategoryBranch"
                                    wire:confirm="{{ __('Uvesti M SAN stablo „:name” i spremiti mapiranja? Postojeća valjana mapiranja i ignorirane grane neće se mijenjati.', ['name' => $editingCategory?->name]) }}"
                                    wire:loading.attr="disabled"
                                    wire:target="importCategoryBranch"
                                    class="mt-4 min-h-11 w-full rounded-xl bg-cyan-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-wait disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="importCategoryBranch">{{ (int) $branchImportSuggestion['descendant_count'] > 0 ? __('Uvezi kategoriju i podkategorije') : __('Uvezi i mapiraj kategoriju') }}</span>
                                    <span wire:loading wire:target="importCategoryBranch" role="status">{{ __('Uvozim i mapiram...') }}</span>
                                </button>
                                @error('categoryBranchImport')<p class="mt-2 text-xs font-medium text-rose-700" role="alert">{{ $message }}</p>@enderror
                            </section>
                        @endif
                        <div><label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-eprel-group">{{ __('EPREL grupa proizvoda') }}</label><select id="msan-eprel-group" wire:model="eprelProductGroup" data-tom-select data-tom-placeholder="{{ __('Bez EPREL grupe') }}" class="admin-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm"><option value="">{{ __('Bez EPREL grupe') }}</option>@foreach($eprelProductGroupOptions as $groupSlug => $groupCode)<option value="{{ $groupSlug }}">{{ str_replace('_', ' ', $groupCode) }} · {{ $groupSlug }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-500">{{ __('Opcionalno; odaberite samo grupu koju podržava EPREL servis.') }}</p>@error('eprelProductGroup')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                        <div><label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-energy-requirement">{{ __('Pravilo energetske oznake') }}</label><select id="msan-energy-requirement" wire:model="energyRequirement" data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">@foreach($energyRequirementOptions as $requirementValue => $requirementLabel)<option value="{{ $requirementValue }}">{{ __($requirementLabel) }}</option>@endforeach</select>@error('energyRequirement')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                        <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-4 text-xs leading-5 text-cyan-900"><strong>{{ __('Važno:') }}</strong> {{ __('Artikli ove M SAN kategorije postaju spremni za odabir čim spremite valjano mapiranje. Odabiri artikala koji su već bili uključeni ostaju zapamćeni.') }}</div>
                    </div>
                    <div class="border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6"><div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" wire:click="cancelEdit" class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Odustani') }}</button><button type="button" wire:click="saveMappingAndContinue" wire:loading.attr="disabled" wire:target="saveMappingAndContinue" class="min-h-11 rounded-xl border border-cyan-300 bg-cyan-50 px-4 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-100 disabled:opacity-60"><span wire:loading.remove wire:target="saveMappingAndContinue">{{ __('Spremi i otvori sljedeću') }}</span><span wire:loading wire:target="saveMappingAndContinue">{{ __('Spremam...') }}</span></button><button type="submit" wire:loading.attr="disabled" wire:target="saveMapping" class="min-h-11 rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:opacity-60"><span wire:loading.remove wire:target="saveMapping">{{ __('Spremi mapiranje') }}</span><span wire:loading wire:target="saveMapping">{{ __('Spremam...') }}</span></button></div></div>
                </form>
            </section>
        </div>
    @endif
</div>
