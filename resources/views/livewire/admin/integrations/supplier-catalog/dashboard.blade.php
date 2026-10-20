<div class="space-y-6">
    <section class="admin-panel p-5 sm:p-6">
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Integracije</p>
        <h1 class="mt-2 text-xl font-semibold text-slate-900">Artikli dobavljača</h1>
        <p class="mt-2 text-sm text-slate-600">Pregledajte nove artikle, povežite kategorije i uvezite odabrane kao neaktivne nacrte.</p>
        <nav class="mt-5 flex flex-wrap gap-2" aria-label="Dobavljači">
            @foreach ($suppliers as $key => $label)
                <button type="button" wire:click="chooseSupplier('{{ $key }}')" wire:loading.attr="disabled" class="rounded-xl border px-5 py-2 text-sm font-semibold {{ $supplier === $key ? 'border-cyan-700 bg-cyan-700 text-white' : 'border-slate-300 bg-white text-slate-700' }}">{{ $label }}</button>
            @endforeach
        </nav>
        <div class="mt-5 flex flex-wrap items-center gap-3">
            <button type="button" wire:click="refreshFeed" wire:loading.attr="disabled" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Osvježi katalog {{ $suppliers[$supplier] }}</button>
            <span class="text-xs text-slate-500">Zadnji dohvat: {{ $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->format('d.m.Y. H:i') : 'Još nije dohvaćen' }}</span>
            <span class="text-xs text-slate-500">Novi: {{ $counts['new'] ?? 0 }} · Postojeći / uvezeni: {{ ($counts['existing'] ?? 0) + ($counts['imported'] ?? 0) }} · Sukobi: {{ $counts['conflict'] ?? 0 }}</span>
        </div>
        @if ($supplier === 'braytron')
            <p class="mt-3 text-xs text-amber-800">Braytron dopušta jedan dohvat u tri sata. Katalog dijeli spremljeni dohvat sa zalihama i slikama. Izvorna cijena je NetPrice.</p>
        @elseif ($supplier === 'dpm')
            <p class="mt-3 text-xs text-slate-600">DPM nema kategorije u feedu. Koristite zadanu kategoriju ili mapiranje „Bez kategorije”. Zaliha prati postojeću integraciju (BOX_QTY).</p>
        @endif
    </section>

    @if ($notice !== '')
        <div role="status" class="rounded-xl border p-4 text-sm {{ $failed ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">{{ $notice }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif
    <div wire:loading class="rounded-xl bg-cyan-50 p-3 text-sm text-cyan-800">Obrada je u tijeku…</div>

    <form wire:submit="saveSettings" class="admin-panel p-5">
        <h2 class="admin-section-title">Kategorije i cijene — {{ $suppliers[$supplier] }}</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label class="text-xs font-semibold text-slate-600">Zadana kategorija
                <select wire:model.live="options.default_category_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"><option value="">Odaberite kategoriju</option>@foreach ($categoryLabels as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
            </label>
            <label class="text-xs font-semibold text-slate-600">Uvećanje cijene (%) — 0 bez uvećanja
                <input type="number" min="0" max="10000" step="0.01" wire:model.live.debounce.500ms="options.markup" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="text-xs font-semibold text-slate-600">Porezna stopa artikla
                <select wire:model.live="options.tax_rate_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"><option value="">Odaberite porez</option>@foreach ($taxRates as $tax)<option value="{{ $tax->id }}">{{ $tax->name }} ({{ (float) $tax->rate }}{{ $tax->rate_type === 'percent' ? '%' : '' }})</option>@endforeach</select>
            </label>
            <label class="text-xs font-semibold text-slate-600">Valuta izvora (npr. EUR, USD)
                <input type="text" maxlength="3" wire:model.live.debounce.500ms="options.source_currency" class="mt-1 block w-full rounded-lg border-slate-300 text-sm uppercase">
            </label>
            <label class="text-xs font-semibold text-slate-600">Tečaj: 1 jedinica izvora = toliko {{ $storeCurrency }}
                <input type="number" min="0.000001" step="0.000001" wire:model.live.debounce.500ms="options.exchange_rate" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model.live="options.source_includes_tax" class="rounded border-slate-300">Izvorna cijena uključuje PDV</label>
        </div>
        <p class="mt-3 text-xs text-slate-500">Cijena = izvorna cijena × tečaj × (1 + uvećanje / 100), uz pretvorbu PDV-a prema postavkama trgovine. Prikazana cijena za pohranu {{ $storedIncludesTax ? 'uključuje' : 'ne uključuje' }} PDV. Provjerite valutu i porezni tretman dobavljača prije uvoza.</p>
        <details class="mt-5 rounded-xl border border-slate-200 p-4">
            <summary class="cursor-pointer text-sm font-semibold text-slate-800">Mapiranje kategorija dobavljača ({{ $sourceCategories->count() }})</summary>
            <p class="mt-2 text-xs text-slate-500">Prazno mapiranje koristi zadanu kategoriju. Mapiranje se sprema zasebno za svakog dobavljača.</p>
            <div class="mt-3 max-h-96 space-y-3 overflow-y-auto">
                @forelse ($sourceCategories as $sourceCategory)
                    <label class="grid items-center gap-2 text-sm sm:grid-cols-2" wire:key="map-{{ $supplier }}-{{ hash('sha256', $sourceCategory->category_key) }}"><span>{{ $sourceCategory->category_name }}</span>
                        <select wire:model.live="options.category_map.{{ hash('sha256', $sourceCategory->category_key) }}" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Koristi zadanu kategoriju</option>@foreach ($categoryLabels as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                    </label>
                @empty
                    <p class="text-sm text-slate-500">Osvježite katalog za prikaz izvornih kategorija.</p>
                @endforelse
            </div>
        </details>
        <button type="submit" wire:loading.attr="disabled" class="mt-4 rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Spremi mapiranje i cijene</button>
    </form>

    <section class="admin-panel p-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <input type="search" aria-label="Pretraga artikala" placeholder="Naziv, šifra ili EAN…" wire:model.live.debounce.400ms="search" class="w-full rounded-lg border-slate-300 text-sm">
            <select aria-label="Status artikla" wire:model.live="status" class="w-full rounded-lg border-slate-300 text-sm"><option value="new">Novi — nisu u webshopu</option><option value="all">Svi aktualni</option><option value="existing">Već postoje</option><option value="imported">Uvezeni nacrti</option><option value="conflict">Sukob identiteta</option><option value="stale">Više nisu u feedu</option></select>
            <select aria-label="Kategorija dobavljača" wire:model.live="category" class="w-full rounded-lg border-slate-300 text-sm"><option value="">Sve izvorne kategorije</option>@foreach ($sourceCategories as $sourceCategory)<option value="{{ $sourceCategory->category_key }}">{{ $sourceCategory->category_name }}</option>@endforeach</select>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="availableOnly" class="rounded border-slate-300">Samo dostupni</label>
        </div>
        <div class="my-4 flex flex-wrap items-center gap-3">
            <button type="button" wire:click="selectPage" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">Odaberi nove na stranici</button>
            <button type="button" wire:click="clearSelection" class="text-xs font-semibold text-slate-600">Poništi odabir</button>
            <span class="text-xs text-slate-500">Odabrano: {{ count($selected) }} · Pronađeno: {{ $items->total() }}</span>
            <button type="button" wire:click="importSelected" wire:loading.attr="disabled" @disabled(count($selected) === 0) class="ml-auto rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Uvezi odabrane kao nacrte</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-slate-50 text-xs text-slate-500"><tr><th class="p-3">Odabir</th><th class="p-3">Artikl</th><th class="p-3">Kategorija</th><th class="p-3">Izvorna cijena</th><th class="p-3">Cijena za pohranu</th><th class="p-3">Zaliha</th><th class="p-3">Status</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($items as $item)
                    <tr wire:key="supplier-item-{{ $item->id }}">
                        <td class="p-3"><input type="checkbox" wire:model.live="selected" value="{{ $item->id }}" aria-label="Odaberi {{ $item->name }}" @disabled($item->status !== 'new' || !$item->is_current) class="rounded border-slate-300"></td>
                        <td class="min-w-64 p-3"><div class="flex gap-3">@if ($item->images[0] ?? null)<img src="{{ $item->images[0] }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-14 w-14 rounded-lg border border-slate-100 object-contain">@endif<div><p class="font-semibold text-slate-800">{{ $item->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $item->sku }} @if ($item->barcode) · {{ $item->barcode }} @endif</p>@if ($item->description)<details class="mt-1 text-xs text-slate-500"><summary class="cursor-pointer">Opis</summary><p class="mt-1 max-w-lg">{{ strip_tags($item->description) }}</p></details>@endif</div></div></td>
                        <td class="p-3 text-xs text-slate-600">{{ $item->category_name }}<span class="mt-1 block font-semibold">→ {{ $categoryLabels[app(\App\Services\Integrations\SupplierCatalog\SupplierCatalogService::class)->categoryId($item, $options)] ?? 'Odaberite kategoriju' }}</span></td>
                        <td class="whitespace-nowrap p-3">{{ $item->source_price !== null ? number_format((float) $item->source_price, 4, ',', '.') : 'Nema cijene' }} {{ $item->currency ?: ($options['source_currency'] ?? '') }}</td>
                        <td class="whitespace-nowrap p-3 font-semibold">{{ isset($prices[$item->id]) ? number_format($prices[$item->id], 2, ',', '.').' '.$storeCurrency : 'Provjerite cijenu / valutu' }}</td>
                        <td class="p-3">{{ $item->quantity }}</td>
                        <td class="min-w-40 p-3 text-xs"><span class="rounded-full bg-slate-100 px-2 py-1 font-semibold">{{ ['new' => 'Novi', 'existing' => 'Već postoji', 'imported' => 'Uvezen nacrt', 'conflict' => 'Sukob'][$item->status] ?? $item->status }}</span>@if ($item->message)<p class="mt-2 text-slate-500">{{ $item->message }}</p>@endif @if ($item->product_id && $item->status === 'imported')<button type="button" wire:click="retryImages({{ $item->id }})" wire:loading.attr="disabled" class="mt-2 block font-semibold text-cyan-700">Ponovi dohvat slika</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-10 text-center text-slate-500">Nema artikala za ove filtre. Osvježite katalog ili promijenite filtre.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $items->links() }}</div>
        <p class="mt-3 text-xs text-slate-500">Uvoz koristi trenutačno prikazane postavke cijena i mapiranja. Najviše 100 artikala po uvozu. Postojeći artikli se preskaču. Slike se preuzimaju u pozadini.</p>
    </section>
</div>
