@php
    $activeCategoryLabel = collect($categories)->firstWhere('id', (int) $categoryId)['label'] ?? null;
    $activeImportStatusLabel = collect($importStatuses)->firstWhere('value', $importStatus)['label'] ?? null;
    $availabilityFilterLabels = [
        'available' => __('Raspoloživo'),
        'unavailable' => __('Nije raspoloživo'),
        'unknown' => __('Nepoznato'),
    ];
    $selectionFilterLabels = [
        'selected' => __('Uključeni za uvoz'),
        'unselected' => __('Nisu uključeni'),
    ];
    $productState = static function ($product) use ($availabilityLevelLabels, $stockLevelQuantities): array {
        $readyForImport = in_array((string) $product->import_status, \App\Models\Integrations\Msan\MsanProduct::IMPORT_READY_STATUSES, true);
        $eligible = $readyForImport
            && (int) $product->mapped_categories_count > 0
            && ! $product->is_stale
            && ! in_array((string) $product->match_status, ['conflict', 'ignored'], true);
        $availabilityLevel = $product->availability_level === null ? null : (int) $product->availability_level;

        return [
            'eligible' => $eligible,
            'readyForImport' => $readyForImport,
            'selectionStatusLabel' => match ((string) $product->import_status) {
                'imported' => __('Već uvezen'),
                'queued' => __('U redu čekanja'),
                'importing' => __('Uvoz u tijeku'),
                default => __('Nije za uvoz'),
            },
            'currency' => strtoupper((string) ($product->currency_code ?: 'EUR')),
            'importClass' => match ((string) $product->import_status) {
                'imported' => 'bg-emerald-100 text-emerald-800',
                'queued', 'importing' => 'bg-cyan-100 text-cyan-800',
                'failed' => 'bg-rose-100 text-rose-800',
                'skipped' => 'bg-amber-100 text-amber-800',
                default => 'bg-slate-200 text-slate-700',
            },
            'importLabel' => match ((string) $product->import_status) {
                'pending' => __('Čeka uvoz'),
                'queued' => __('U redu čekanja'),
                'importing' => __('Uvoz u tijeku'),
                'imported' => __('Uvezeno'),
                'failed' => __('Neuspješno'),
                'skipped' => __('Preskočeno'),
                default => (string) $product->import_status,
            },
            'matchLabel' => match ((string) $product->match_status) {
                'matched' => __('Povezano'),
                'conflict' => __('Konflikt'),
                'ignored' => __('Ignorirano'),
                default => __('Nije povezano'),
            },
            'availabilityLevel' => $availabilityLevel,
            'availabilityLabel' => $availabilityLevel === null
                ? __('Nepoznata dostupnost')
                : __($availabilityLevelLabels[$availabilityLevel] ?? 'Nepoznata dostupnost'),
            'localSellableLimit' => $availabilityLevel === null
                ? 0
                : (int) ($stockLevelQuantities[$availabilityLevel] ?? 0),
            'availabilityClass' => match (true) {
                $availabilityLevel === null => 'bg-slate-200 text-slate-700',
                $availabilityLevel === 0 => 'bg-rose-100 text-rose-800',
                default => 'bg-emerald-100 text-emerald-800',
            },
            'blockedReason' => match (true) {
                (string) $product->import_status === 'imported' => __('Artikl je već uvezen'),
                in_array((string) $product->import_status, ['queued', 'importing'], true) => __('Artikl je već u obradi'),
                $product->is_stale => __('Zastarjeli artikl'),
                in_array((string) $product->match_status, ['conflict', 'ignored'], true) => __('Konflikt ili ignoriran artikl'),
                default => __('Najprije mapirajte barem jednu kategoriju'),
            },
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
                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">{{ __('Odabir se sprema automatski') }}</span>
                    </div>
                    <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">{{ __('Artikli za uvoz') }}</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                        {{ __('Uključeni artikli ostaju u zajedničkoj radnoj listi administratora i nakon promjene stranice ili nove sinkronizacije kataloga.') }}
                    </p>
                </div>

                <dl class="flex shrink-0 flex-wrap gap-2 text-center xl:justify-end">
                    <div class="min-w-28 rounded-xl border border-slate-200 bg-white px-3 py-2.5"><dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ __('Rezultati') }}</dt><dd class="mt-1 text-lg font-bold tabular-nums text-slate-900">{{ number_format($filteredCount, 0, ',', '.') }}</dd></div>
                    <div class="min-w-28 rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2.5"><dt class="text-[11px] font-semibold uppercase tracking-wide text-cyan-700">{{ __('Uključeno') }}</dt><dd class="mt-1 text-lg font-bold tabular-nums text-cyan-900">{{ number_format($selectedTotalCount, 0, ',', '.') }}</dd></div>
                    <div class="min-w-28 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5"><dt class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">{{ __('Spremno') }}</dt><dd class="mt-1 text-lg font-bold tabular-nums text-emerald-900">{{ number_format($selectedEligibleCount, 0, ',', '.') }}</dd></div>
                </dl>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-8">
                <form wire:submit="applySearch" class="md:col-span-2 xl:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-product-search">{{ __('Pretraga') }}</label>
                    <div class="flex gap-2">
                        <input id="msan-product-search" type="search" wire:model="searchInput" minlength="2" maxlength="120" placeholder="{{ __('Naziv, M SAN šifra, model ili kataloški broj...') }}" class="admin-search-input min-w-0 flex-1 rounded-xl border px-3 py-2 text-sm">
                        <button type="submit" wire:loading.attr="disabled" wire:target="applySearch" class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="applySearch">{{ __('Traži') }}</span><span wire:loading wire:target="applySearch">{{ __('Tražim...') }}</span>
                        </button>
                    </div>
                    @error('searchInput') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </form>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-product-category">{{ __('Kategorija') }}</label>
                    <select id="msan-product-category" wire:model.live="categoryId" data-tom-placeholder="{{ __('Pretraži M SAN kategorije...') }}" class="msan-product-category-select admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="">{{ __('Sve kategorije') }}</option>
                        @foreach ($categories as $category)<option value="{{ $category['id'] }}">{{ $category['label'] }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-product-brand">{{ __('Proizvođač') }}</label>
                    <select id="msan-product-brand" wire:model.live="brand" data-tom-placeholder="{{ __('Svi proizvođači') }}" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="">{{ __('Svi proizvođači') }}</option>
                        @foreach ($brands as $brandOption)<option value="{{ $brandOption }}">{{ $brandOption }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-product-availability">{{ __('Raspoloživost') }}</label>
                    <select id="msan-product-availability" wire:model.live="availability" data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="all">{{ __('Sve') }}</option><option value="available">{{ __('Raspoloživo') }}</option><option value="unavailable">{{ __('Nije raspoloživo') }}</option><option value="unknown">{{ __('Nepoznato') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-product-selection">{{ __('Radna lista') }}</label>
                    <select id="msan-product-selection" wire:model.live="selection" data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="all">{{ __('Svi artikli') }}</option><option value="selected">{{ __('Uključeni za uvoz') }}</option><option value="unselected">{{ __('Nisu uključeni') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-product-import-status">{{ __('Status uvoza') }}</label>
                    <select id="msan-product-import-status" wire:model.live="importStatus" data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="all">{{ __('Svi statusi') }}</option>
                        @foreach ($importStatuses as $statusOption)<option value="{{ $statusOption['value'] }}">{{ $statusOption['label'] }}</option>@endforeach
                    </select>
                </div>
                <div class="flex items-end md:col-span-1 xl:col-span-1">
                    <button type="button" wire:click="clearFilters" @disabled($activeFilterCount === 0) class="min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 disabled:cursor-not-allowed disabled:opacity-50">{{ __('Očisti filtre') }}@if($activeFilterCount > 0) ({{ $activeFilterCount }})@endif</button>
                </div>
            </div>

            @if ($activeFilterCount > 0)
                <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-4 text-xs">
                    <span class="font-semibold text-slate-500">{{ __('Aktivni filteri:') }}</span>
                    @if ($search !== '')<button type="button" wire:click="clearFilter('search')" class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1.5 font-semibold text-cyan-800 hover:bg-cyan-100">{{ __('Pretraga') }}: {{ $search }} <span aria-hidden="true">×</span></button>@endif
                    @if ($activeCategoryLabel)<button type="button" wire:click="clearFilter('category')" class="max-w-full truncate rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1.5 font-semibold text-cyan-800 hover:bg-cyan-100">{{ $activeCategoryLabel }} <span aria-hidden="true">×</span></button>@endif
                    @if ($brand !== '')<button type="button" wire:click="clearFilter('brand')" class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1.5 font-semibold text-cyan-800 hover:bg-cyan-100">{{ $brand }} <span aria-hidden="true">×</span></button>@endif
                    @if ($availability !== 'all')<button type="button" wire:click="clearFilter('availability')" class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1.5 font-semibold text-cyan-800 hover:bg-cyan-100">{{ $availabilityFilterLabels[$availability] ?? $availability }} <span aria-hidden="true">×</span></button>@endif
                    @if ($selection !== 'all')<button type="button" wire:click="clearFilter('selection')" class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1.5 font-semibold text-cyan-800 hover:bg-cyan-100">{{ $selectionFilterLabels[$selection] ?? $selection }} <span aria-hidden="true">×</span></button>@endif
                    @if ($importStatus !== 'all')<button type="button" wire:click="clearFilter('importStatus')" class="rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1.5 font-semibold text-cyan-800 hover:bg-cyan-100">{{ $activeImportStatusLabel ?? $importStatus }} <span aria-hidden="true">×</span></button>@endif
                </div>
            @endif

            @if ($canManageImport)
                <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-800">{{ __('Masovne akcije nad svim rezultatima filtra') }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ __('Spremno :eligible od :total rezultata; trenutno uključeno :selected.', ['eligible' => number_format($filteredEligibleCount, 0, ',', '.'), 'total' => number_format($filteredCount, 0, ',', '.'), 'selected' => number_format($filteredSelectedCount, 0, ',', '.')]) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="selectFiltered" wire:confirm="{{ __('Uključiti svih :count spremnih artikala koji odgovaraju filtru, na svim stranicama rezultata?', ['count' => $filteredEligibleCount]) }}" wire:loading.attr="disabled" wire:target="selectFiltered" @disabled($filteredEligibleCount === 0) class="min-h-11 rounded-xl border border-cyan-300 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100 disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="selectFiltered">{{ __('Uključi svih :count spremnih', ['count' => number_format($filteredEligibleCount, 0, ',', '.')]) }}</span><span wire:loading wire:target="selectFiltered">{{ __('Spremam...') }}</span></button>
                        <button type="button" wire:click="deselectFiltered" wire:confirm="{{ __('Isključiti svih :count trenutno uključenih artikala koji odgovaraju filtru, na svim stranicama rezultata?', ['count' => $filteredSelectedCount]) }}" wire:loading.attr="disabled" wire:target="deselectFiltered" @disabled($filteredSelectedCount === 0) class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="deselectFiltered">{{ __('Isključi filtrirane (:count)', ['count' => number_format($filteredSelectedCount, 0, ',', '.')]) }}</span><span wire:loading wire:target="deselectFiltered">{{ __('Spremam...') }}</span></button>
                    </div>
                </div>
            @endif
        </div>
    </section>

    @if ($selectedTotalCount > 0)
        <section class="admin-panel sticky top-20 z-10 border-cyan-200 bg-white/95 p-3 shadow-lg shadow-slate-900/5 backdrop-blur" aria-label="{{ __('Sažetak radne liste za uvoz') }}">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-cyan-100 font-bold text-cyan-800">{{ number_format($selectedEligibleCount, 0, ',', '.') }}</span>
                    <div>
                        <p class="text-sm font-semibold text-slate-900">{{ __('Spremno za uvoz: :count', ['count' => number_format($selectedEligibleCount, 0, ',', '.')]) }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ __('Ukupno uključeno: :total.', ['total' => number_format($selectedTotalCount, 0, ',', '.')]) }} @if ($selectedIneligibleCount > 0)<span class="font-semibold text-amber-700">{{ __('Nije za novi uvoz: :count — već je uvezeno, u obradi ili blokirano.', ['count' => number_format($selectedIneligibleCount, 0, ',', '.')]) }}</span>@endif</p>
                    </div>
                </div>
                @if ($canManageImport)
                    <button type="button" wire:click="queueSelectedImport" wire:confirm="{{ __('Pokrenuti uvoz svih :count spremnih artikala iz zajedničke radne liste? Trenutni filteri ne ograničavaju uvoz.', ['count' => $selectedEligibleCount]) }}" wire:loading.attr="disabled" wire:target="queueSelectedImport" @disabled($selectedEligibleCount === 0) class="min-h-11 shrink-0 rounded-xl bg-cyan-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="queueSelectedImport">{{ __('Uvezi odabrane (:count)', ['count' => number_format($selectedEligibleCount, 0, ',', '.')]) }}</span><span wire:loading wire:target="queueSelectedImport">{{ __('Pokrećem uvoz...') }}</span></button>
                @endif
            </div>
        </section>
    @endif

    <section class="admin-panel admin-panel-soft p-4 sm:p-5" aria-labelledby="msan-products-heading">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 id="msan-products-heading" class="admin-section-title">{{ __('M SAN artikli') }}</h2><p class="mt-1 text-xs text-slate-500">{{ __('Klik na uključivanje ne mijenja položaj retka; stanje se odmah sprema.') }}</p></div>
            <p class="text-xs text-slate-500">{{ __('Prikazano :from–:to od :total', ['from' => $products->firstItem() ?? 0, 'to' => $products->lastItem() ?? 0, 'total' => $products->total()]) }} · {{ __(':count po stranici', ['count' => $perPage]) }}</p>
        </div>

        <div class="mt-4 hidden overflow-x-auto xl:block" tabindex="0" aria-label="{{ __('Tablica M SAN artikala; pomičite vodoravno za sve podatke') }}">
            <table class="admin-items-table min-w-[76rem] text-sm">
                <caption class="sr-only">{{ __('M SAN artikli i njihov trajno spremljen status uvoza') }}</caption>
                <thead class="text-slate-600"><tr><th scope="col" class="px-3 py-3 text-left font-semibold">{{ __('Uvoz') }}</th><th scope="col" class="px-3 py-3 text-left font-semibold">{{ __('Artikl') }}</th><th scope="col" class="px-3 py-3 text-right font-semibold">{{ __('Cijene') }}</th><th scope="col" class="px-3 py-3 text-center font-semibold">{{ __('Dostupnost / limit') }}</th><th scope="col" class="px-3 py-3 text-left font-semibold">{{ __('Lokalni artikl') }}</th><th scope="col" class="px-3 py-3 text-left font-semibold">{{ __('Status') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($products as $product)
                        @php($state = $productState($product))
                        <tr wire:key="msan-product-row-{{ $product->id }}" @class(['bg-cyan-50/60' => $product->selected])>
                            <td class="px-3 py-3 align-top">
                                @if ($canManageImport)
                                    @if (! $product->selected && ! $state['readyForImport'])
                                        <span class="inline-flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600">{{ $state['selectionStatusLabel'] }}</span>
                                    @else
                                        <label class="inline-flex min-h-11 items-center gap-2 rounded-xl border px-3 py-2 text-xs font-semibold transition {{ $product->selected ? 'border-cyan-300 bg-cyan-100 text-cyan-900' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }} {{ ! $state['eligible'] && ! $product->selected ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}">
                                            <input type="checkbox" @checked($product->selected) wire:click="toggleSelection({{ $product->id }})" wire:loading.attr="disabled" wire:target="toggleSelection({{ $product->id }})" @disabled(!($product->selected || $state['eligible'])) class="h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600" aria-label="{{ $product->selected ? __('Isključi :name iz M SAN uvoza', ['name' => $product->name]) : __('Uključi :name za M SAN uvoz', ['name' => $product->name]) }}">
                                            <span>{{ $product->selected ? __('Uključen') : __('Uključi') }}</span>
                                        </label>
                                    @endif
                                @else
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $product->selected ? 'bg-cyan-100 text-cyan-800' : 'bg-slate-200 text-slate-600' }}">{{ $product->selected ? __('Uključen') : __('Nije uključen') }}</span>
                                @endif
                                @if (! $state['eligible'])<p class="mt-1 max-w-36 text-xs leading-4 text-amber-700">{{ $state['blockedReason'] }}</p>@endif
                            </td>
                            <td class="px-3 py-3 align-top">
                                <div class="flex max-w-[38rem] items-start gap-3">
                                    @include('livewire.admin.integrations.msan.partials.product-image', ['product' => $product])
                                    <div class="min-w-0">
                                        <div class="font-semibold leading-5 text-slate-900">{{ $product->name }}</div>
                                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 font-mono text-[11px] text-slate-500"><span>{{ __('M SAN') }}: {{ $product->external_code }}</span>@if ($product->part_number)<span>{{ __('Kat. broj') }}: {{ $product->part_number }}</span>@endif @if ($product->brand)<span class="font-sans font-medium">{{ $product->brand }}</span>@endif</div>
                                        <div class="mt-2 flex flex-wrap gap-1">@foreach ($product->categories->take(3) as $category)<span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{{ $category->name }}</span>@endforeach @if ($product->categories->count() > 3)<span class="text-xs text-slate-400">+{{ $product->categories->count() - 3 }}</span>@endif</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right align-top tabular-nums text-slate-700"><div><span class="text-xs text-slate-500">{{ __('Nabavna') }}</span> <strong>{{ $product->partner_price !== null ? number_format((float) $product->partner_price, 2, ',', '.').' '.$state['currency'] : '—' }}</strong></div><div class="mt-1"><span class="text-xs text-slate-500">{{ __('MPC') }}</span> {{ $product->recommended_retail_price !== null ? number_format((float) $product->recommended_retail_price, 2, ',', '.').' '.$state['currency'] : '—' }}</div></td>
                            <td class="px-3 py-3 text-center align-top"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $state['availabilityClass'] }}">{{ $state['availabilityLabel'] }}@if($state['availabilityLevel'] !== null)<span class="ml-1 font-normal opacity-75">({{ __('razina :level', ['level' => $state['availabilityLevel']]) }})</span>@endif</span><div class="mt-1 text-xs font-medium text-slate-600">{{ __('Lokalni prodajni limit: :count kom.', ['count' => number_format($state['localSellableLimit'], 0, ',', '.')]) }}</div><div class="mt-0.5 text-xs text-slate-500">{{ __('Nije stvarna M SAN zaliha') }}</div></td>
                            <td class="px-3 py-3 align-top text-slate-700">@if ($product->localProduct)<div class="font-medium">{{ $product->localProduct->code }}</div><div class="mt-0.5 font-mono text-[11px] text-slate-500">{{ $product->localProduct->sku ?: __('bez SKU-a') }}</div>@else<span class="text-xs text-slate-500">{{ __('Nije povezan') }}</span>@endif<div class="mt-1 text-xs text-slate-500">{{ $state['matchLabel'] }}</div></td>
                            <td class="px-3 py-3 align-top"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $state['importClass'] }}">{{ $state['importLabel'] }}</span><div class="mt-2 text-xs text-slate-500">{{ __('Viđeno') }}: {{ $product->last_seen_at?->format('d.m.Y. H:i') ?? '—' }}</div>@if ($product->last_imported_at)<div class="mt-1 text-xs text-slate-500">{{ __('Uvezeno') }}: {{ $product->last_imported_at->format('d.m.Y. H:i') }}</div>@endif @if ($product->last_error)<p class="mt-1 max-w-[18rem] whitespace-normal text-xs text-rose-700">{{ \Illuminate\Support\Str::limit($product->last_error, 120) }}</p>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center"><p class="font-semibold text-slate-700">{{ __('Nema artikala prema odabranim filtrima.') }}</p><p class="mt-1 text-sm text-slate-500">{{ __('Očistite filtre ili najprije dohvatite M SAN katalog.') }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:hidden">
            @forelse ($products as $product)
                @php($state = $productState($product))
                <article wire:key="msan-product-card-{{ $product->id }}" class="rounded-xl border p-4 {{ $product->selected ? 'border-cyan-200 bg-cyan-50/60' : 'border-slate-200 bg-white' }}">
                    <div class="flex items-start gap-3">
                        @include('livewire.admin.integrations.msan.partials.product-image', ['product' => $product])
                        <div class="min-w-0 flex-1"><h3 class="font-semibold leading-5 text-slate-900">{{ $product->name }}</h3><p class="mt-1 break-all font-mono text-[11px] text-slate-500">{{ $product->external_code }}@if($product->part_number) · {{ $product->part_number }}@endif</p></div>
                    </div>
                    @if ($canManageImport)
                        @if (! $product->selected && ! $state['readyForImport'])
                            <span class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 sm:w-auto">{{ $state['selectionStatusLabel'] }}</span>
                        @else
                            <label class="mt-3 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border px-3 py-2 text-xs font-semibold sm:w-auto {{ $product->selected ? 'border-cyan-300 bg-cyan-100 text-cyan-900' : 'border-slate-300 bg-white text-slate-700' }} {{ ! $state['eligible'] && ! $product->selected ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}"><input type="checkbox" @checked($product->selected) wire:click="toggleSelection({{ $product->id }})" wire:loading.attr="disabled" wire:target="toggleSelection({{ $product->id }})" @disabled(!($product->selected || $state['eligible'])) class="h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600" aria-label="{{ $product->selected ? __('Isključi :name iz M SAN uvoza', ['name' => $product->name]) : __('Uključi :name za M SAN uvoz', ['name' => $product->name]) }}"><span>{{ $product->selected ? __('Uključen') : __('Uključi') }}</span></label>
                        @endif
                    @else
                        <span class="mt-3 inline-flex min-h-11 items-center rounded-xl border px-3 py-2 text-xs font-semibold {{ $product->selected ? 'border-cyan-200 bg-cyan-100 text-cyan-800' : 'border-slate-200 bg-slate-100 text-slate-600' }}">{{ $product->selected ? __('Uključen za uvoz') : __('Nije uključen za uvoz') }}</span>
                    @endif
                    @if (! $state['eligible'])<p class="mt-2 rounded-lg bg-amber-50 px-2.5 py-2 text-xs font-medium text-amber-800">{{ $state['blockedReason'] }}</p>@endif
                    <div class="mt-3 flex flex-wrap gap-1.5">@if($product->brand)<span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ $product->brand }}</span>@endif @foreach($product->categories->take(2) as $category)<span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">{{ $category->name }}</span>@endforeach</div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-slate-200 pt-3 text-xs">
                        <div><dt class="text-slate-500">{{ __('Dostupnost') }}</dt><dd class="mt-1 font-semibold text-slate-800">{{ $state['availabilityLabel'] }}@if($state['availabilityLevel'] !== null) · {{ __('razina :level', ['level' => $state['availabilityLevel']]) }}@endif</dd><dd class="mt-0.5 text-slate-500">{{ __('Lokalni prodajni limit: :count kom.', ['count' => number_format($state['localSellableLimit'], 0, ',', '.')]) }}</dd><dd class="mt-0.5 text-slate-500">{{ __('Nije stvarna M SAN zaliha') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('Cijene') }}</dt><dd class="mt-1 font-semibold tabular-nums text-slate-800">{{ $product->partner_price !== null ? number_format((float) $product->partner_price, 2, ',', '.').' '.$state['currency'] : '—' }}</dd><dd class="mt-0.5 tabular-nums text-slate-500">{{ __('MPC') }}: {{ $product->recommended_retail_price !== null ? number_format((float) $product->recommended_retail_price, 2, ',', '.').' '.$state['currency'] : '—' }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('Lokalni artikl') }}</dt><dd class="mt-1 font-semibold text-slate-800">{{ $product->localProduct?->code ?? __('Nije povezan') }}</dd><dd class="mt-0.5 text-slate-500">{{ $state['matchLabel'] }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('Status uvoza') }}</dt><dd class="mt-1"><span class="rounded-full px-2 py-1 font-semibold {{ $state['importClass'] }}">{{ $state['importLabel'] }}</span></dd><dd class="mt-1 text-slate-500">{{ $product->last_seen_at?->format('d.m.Y. H:i') ?? '—' }}</dd></div>
                    </dl>
                    @if($product->last_error)<p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ \Illuminate\Support\Str::limit($product->last_error, 160) }}</p>@endif
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-12 text-center"><p class="font-semibold text-slate-700">{{ __('Nema artikala prema odabranim filtrima.') }}</p><p class="mt-1 text-sm text-slate-500">{{ __('Očistite filtre ili najprije dohvatite M SAN katalog.') }}</p></div>
            @endforelse
        </div>

        <div class="mt-4">{{ $products->links() }}</div>
    </section>
</div>
