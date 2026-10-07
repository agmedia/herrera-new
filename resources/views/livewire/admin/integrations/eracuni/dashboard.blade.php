<div class="space-y-6" wire:poll.visible.30s x-data>
    @php
        $formatTime = static fn ($time) => $time?->copy()->timezone(config('app.timezone'))->format('d.m.Y. H:i:s') ?? '—';
        $statusTone = static fn ($status) => match ($status) {
            'completed', 'new', 'created', 'update', 'updated' => 'bg-emerald-100 text-emerald-800',
            'failed', 'invalid' => 'bg-rose-100 text-rose-800',
            'running' => 'bg-cyan-100 text-cyan-800',
            'ambiguous', 'skipped' => 'bg-amber-100 text-amber-800',
            default => 'bg-slate-100 text-slate-700',
        };
    @endphp

    <section class="admin-panel admin-search-panel p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Integracije') }}</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">{{ __('e-Računi katalog') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ __('Pregledajte nove ERP artikle, odaberite promjene cijena i naziva te osvježite svojstva postojećih artikala.') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('Uvoz kreira neaktivne nacrte. Prije objave provjerite cijenu, porez, kategoriju i podatke artikla.') }}</p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $configured ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $configured ? __('Veza je podešena') : __('Veza nije podešena') }}</span>
        </div>
        @if (! $configured)<p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('Koriste se pristupne postavke e-Računi integracije za zalihe. Prvo treba podesiti vezu s ERP-om.') }}</p>@endif
        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <div><label for="eracuni-range-preset" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Raspon ERP šifri') }}</label><select id="eracuni-range-preset" wire:model.live="rangePreset" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"><option value="all">{{ __('Bez granica') }}</option><option value="first">0 – 10000</option><option value="second">10001 – 20000</option><option value="third">20001 – 30000</option><option value="custom">{{ __('Prilagođeni raspon') }}</option></select></div>
            <div><label for="eracuni-code-from" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Šifra od') }}</label><input id="eracuni-code-from" type="text" maxlength="120" wire:model.live="codeFrom" class="admin-input w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('Bez donje granice') }}">@error('codeFrom')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror</div>
            <div><label for="eracuni-code-to" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Šifra do') }}</label><input id="eracuni-code-to" type="text" maxlength="120" wire:model.live="codeTo" class="admin-input w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('Bez gornje granice') }}">@error('codeTo')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror</div>
        </div>
        <p class="mt-2 text-xs text-slate-500">{{ __('ERP vraća najviše 10.000 artikala po pozivu. Za veći katalog suzite raspon šifri i obradite svaki raspon zasebno.') }}</p>
        <div class="mt-5 flex flex-wrap gap-3">
            <button type="button" wire:click="previewCatalog" wire:loading.attr="disabled" @disabled(! $configured) class="min-h-11 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Pregledaj nove artikle') }}</button>
            <button type="button" wire:click="previewUpdates('prices')" wire:loading.attr="disabled" @disabled(! $configured) class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Pregledaj promjene cijena') }}</button>
            <button type="button" wire:click="previewUpdates('names')" wire:loading.attr="disabled" @disabled(! $configured) class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Pregledaj promjene naziva') }}</button>
            <button type="button" wire:click="syncAttributes" wire:loading.attr="disabled" @disabled(! $configured) class="min-h-11 rounded-xl border border-cyan-300 bg-cyan-50 px-4 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-100 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Osvježi svojstva postojećih') }}</button>
            <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-40">{{ __('Osvježi pregled') }}</button>
        </div>
        <p role="status" wire:loading wire:target="previewCatalog,previewUpdates,importSelected,applySelectedUpdates,syncAttributes" class="mt-4 rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm text-cyan-800">{{ __('ERP obrada je u tijeku. Izvještaj će se prikazati nakon završetka.') }}</p>
    </section>

    <section class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="admin-section-title">{{ __('Cron: svojstva postojećih artikala') }}</h2><p class="mt-2 text-xs text-slate-500">{{ __('Automatski poziv koristi odabrani raspon ERP šifri. Raspored poziva podešava se u EasyCronu.') }}</p></div><span class="rounded-full px-3 py-1 text-xs font-semibold {{ $attributesCronEnabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">{{ $attributesCronEnabled ? __('Cron uključen') : __('Cron isključen') }}</span></div>
        <dl class="mt-4 flex flex-wrap gap-x-8 gap-y-3 text-xs"><div><dt class="text-slate-500">{{ __('Zadnji cron pokušaj') }}</dt><dd class="mt-1 font-semibold text-slate-800">{{ $formatTime($lastAttributesCronAttempt?->started_at) }} @if ($lastAttributesCronAttempt) · {{ $statusLabels[$lastAttributesCronAttempt->status] ?? $lastAttributesCronAttempt->status }} @endif</dd></div><div><dt class="text-slate-500">{{ __('Zadnji uspješan cron') }}</dt><dd class="mt-1 font-semibold text-slate-800">{{ $formatTime($lastAttributesCronSuccess?->completed_at) }}</dd></div></dl>
        <div class="mt-4 flex flex-wrap gap-2"><button type="button" wire:click="toggleAttributesCron({{ $attributesCronEnabled ? 'false' : 'true' }})" wire:loading.attr="disabled" @disabled(! $configured && ! $attributesCronEnabled) class="min-h-10 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-40">{{ $attributesCronEnabled ? __('Isključi cron svojstava') : __('Uključi cron svojstava') }}</button>@if ($lastAttributesCronAttempt)<button type="button" wire:click="selectRun({{ $lastAttributesCronAttempt->id }})" class="min-h-10 rounded-xl border border-cyan-200 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-50">{{ __('Zadnji cron izvještaj') }}</button>@endif</div>
        @if ($attributesCronUrl !== '')<details class="mt-4 border-t border-slate-200 pt-3"><summary class="cursor-pointer text-xs font-semibold text-slate-600">{{ __('URL crona svojstava') }}</summary><p class="mt-3 text-xs text-slate-500">{{ __('Kopirajte ovaj URL u EasyCron. Promjena raspona mijenja URL; ažurirajte i postojeći posao u EasyCronu.') }}</p><input type="text" readonly value="{{ $attributesCronUrl }}" x-on:click="$event.target.select()" aria-label="{{ __('URL crona svojstava') }}" class="mt-2 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700"></details>@else<p class="mt-3 text-xs text-amber-800">{{ __('Cron URL će biti dostupan nakon podešavanja pristupnog ključa.') }}</p>@endif
    </section>

    <form wire:submit="previewSupplierCsv" class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('IDEUS CSV: novi artikli') }}</h2>
        <p class="mt-2 text-xs text-slate-500">{{ __('Podržan je dosadašnji IDEUS CSV format od 46 stupaca, do 10 MB i 10.000 redova. Prije uvoza pregledajte podatke i odaberite artikle. Kreiraju se neaktivni nacrti za doradu cijene, poreza i objave.') }}</p>
        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end"><div class="min-w-0 flex-1"><label for="ideus-csv-upload" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('IDEUS CSV datoteka') }}</label><input id="ideus-csv-upload" type="file" accept=".csv" wire:model="supplierUpload" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">@error('supplierUpload')<p class="mt-2 text-xs text-rose-700">{{ $message }}</p>@enderror<p wire:loading wire:target="supplierUpload" class="mt-2 text-xs text-cyan-800">{{ __('Datoteka se učitava…') }}</p></div><button type="submit" wire:loading.attr="disabled" class="min-h-11 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-40">{{ __('Pregledaj IDEUS CSV') }}</button></div>
        <p role="status" wire:loading wire:target="previewSupplierCsv" class="mt-3 text-xs text-cyan-800">{{ __('Pripremam CSV pregled…') }}</p>
    </form>

    @if ($previewRun)
        @php
            $isCsvPreview = $previewRun->kind === 'preview_csv';
            $isDraftPreview = in_array($previewRun->kind, ['preview', 'preview_csv'], true);
            $isPricePreview = $previewRun->kind === 'preview_prices';
            $previewLimited = (bool) data_get($previewRun->summary, 'limit_reached', false);
        @endphp
        <section wire:key="eracuni-preview-{{ $previewRun->id }}" class="admin-panel admin-panel-soft p-5" aria-labelledby="eracuni-preview-title">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 id="eracuni-preview-title" class="admin-section-title">{{ $isDraftPreview ? __('Pregled prije uvoza') : $kindLabels[$previewRun->kind] }} #{{ $previewRun->id }}</h2>
                    <p class="mt-2 text-xs text-slate-500">{{ $formatTime($previewRun->completed_at) }} · {{ $isDraftPreview ? __('Novih artikala spremnih za uvoz') : __('Promjena spremnih za primjenu') }}: <strong>{{ number_format($previewRun->eligible_count, 0, ',', '.') }}</strong></p>
                </div>
                <div>
                    <label for="eracuni-candidate-filter" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Kandidati') }}</label>
                    <select id="eracuni-candidate-filter" wire:model.live="candidateFilter" class="admin-select rounded-xl border border-slate-300 px-3 py-2 text-xs">
                        <option value="all">{{ __('Svi kandidati') }}</option>
                        @foreach (($isDraftPreview ? ['new', 'invalid', 'ambiguous', 'skipped', 'created'] : ['update', 'updated', 'invalid', 'ambiguous', 'skipped']) as $status)<option value="{{ $status }}">{{ $itemStatusLabels[$status] }}</option>@endforeach
                    </select>
                </div>
            </div>
            @if ($previewLimited)<p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('Pregled je dosegao ograničenje od 10.000 ERP artikala. Suzite raspon i pripremite novi pregled. Uvoz i primjena ovog pregleda su blokirani.') }}</p>@endif
            <p class="mt-3 text-xs text-slate-600">{{ $isCsvPreview ? __('CSV iznos prikazan je za provjeru. Uvoz kopira iznos u pohranjenu cijenu, bez preračuna PDV-a. Provjerite cijenu i porez nacrta.') : ($isDraftPreview ? __('Odaberite artikle koje želite uvesti. ERP bruto iznos je prikazan za provjeru; nacrt može zahtijevati doradu cijene i poreza.') : __('Odaberite promjene koje želite primijeniti. Ako je artikl ručno izmijenjen nakon pregleda, promjena će biti preskočena i prikazana u izvještaju.')) }}</p>
            @if ($isPricePreview)<p class="mt-2 text-xs text-slate-500">{{ __('Prije i poslije prikazuje pohranjenu cijenu trgovine. ERP bruto iznos preračunava se prema poreznim postavkama artikla i načinu pohrane cijena.') }}</p>@endif
            <div class="mt-4 overflow-x-auto">
                <table class="admin-items-table min-w-[64rem] text-sm">
                    <thead><tr class="text-left text-xs text-slate-500">
                        <th class="px-3 py-2 font-semibold">{{ __('Odabir') }}</th><th class="px-3 py-2 font-semibold">{{ __('Artikl') }}</th><th class="px-3 py-2 font-semibold">{{ $isCsvPreview ? __('Model / šifra') : __('ERP model / šifra') }}</th><th class="px-3 py-2 font-semibold">{{ __('SKU / barkod') }}</th><th class="px-3 py-2 text-right font-semibold">{{ $isCsvPreview ? __('CSV iznos za provjeru') : __('ERP bruto iznos') }}</th>@if (! $isDraftPreview)<th class="px-3 py-2 font-semibold">{{ $isPricePreview ? __('Pohranjena cijena: prije → poslije') : __('Naziv: prije → poslije') }}</th>@endif<th class="px-3 py-2 font-semibold">{{ __('Provjera') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($candidates as $item)
                            @php $plan = is_array($item->plan) ? $item->plan : []; @endphp
                            <tr wire:key="eracuni-candidate-{{ $item->id }}">
                                <td class="px-3 py-3">
                                    @if ($item->status === ($isDraftPreview ? 'new' : 'update'))<input type="checkbox" wire:model.live="selectedIds" value="{{ $item->id }}" aria-label="{{ __('Odaberi :name', ['name' => $plan['name'] ?? $item->name]) }}" class="h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">@else<span class="text-slate-400">—</span>@endif
                                </td>
                                <td class="max-w-sm px-3 py-3">
                                    <p class="font-semibold text-slate-800">{{ $plan['name'] ?? $item->name }}</p>
                                    @if (! empty($plan['brand']))<p class="mt-1 text-xs text-slate-500">{{ $plan['brand'] }}</p>@endif
                                    @if (! empty($plan['description']))<p class="mt-1 text-xs text-slate-500">{{ \Illuminate\Support\Str::limit(strip_tags($plan['description']), 120) }}</p>@endif
                                    @if (! empty($plan['attributes']))
                                        <details class="mt-2 text-xs"><summary class="cursor-pointer font-semibold text-cyan-800">{{ __('Pregled svojstava') }} ({{ count($plan['attributes']) }})</summary><dl class="mt-2 space-y-1 text-slate-600">@foreach ($plan['attributes'] as $attribute)<div><dt class="inline font-semibold">{{ $attribute['label'] }}:</dt> <dd class="inline">{{ $attribute['value'] }}</dd></div>@endforeach</dl></details>
                                    @else
                                        <p class="mt-1 text-xs text-slate-500">{{ __('Bez ERP svojstava za uvoz.') }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 font-mono text-xs">{{ $plan['model'] ?? $item->identifier }}</td>
                                <td class="px-3 py-3 font-mono text-xs"><p>{{ $plan['sku'] ?? '—' }}</p><p class="mt-1 text-slate-500">{{ $plan['barcode'] ?? '—' }}</p></td>
                                <td class="px-3 py-3 text-right tabular-nums"><p class="font-semibold">{{ $plan['gross_price'] ?? $plan['raw_price'] ?? '—' }} {{ $plan['currency'] ?? '' }}</p>@if (isset($plan['vat_percentage']))<p class="mt-1 text-xs text-slate-500">{{ __('ERP PDV') }}: {{ $plan['vat_percentage'] }}%</p>@endif</td>
                                @if (! $isDraftPreview)<td class="max-w-sm px-3 py-3 text-xs"><p class="text-slate-500">{{ $isPricePreview ? ($plan['old_base_price'] ?? '—') : ($plan['old_name'] ?? '—') }}</p><p class="mt-1 font-semibold text-slate-800">→ {{ $isPricePreview ? ($plan['base_price'] ?? '—') : ($plan['name'] ?? '—') }}</p></td>@endif
                                <td class="max-w-sm px-3 py-3 text-xs"><span class="inline-flex rounded-full px-2 py-1 font-semibold {{ $statusTone($item->status) }}">{{ $itemStatusLabels[$item->status] ?? $item->status }}</span>@if (($isDraftPreview || $isPricePreview) && ($plan['price_state'] ?? null) === 'review')<p class="mt-2 font-semibold text-amber-800">{{ __('Cijenu i porez treba provjeriti.') }}</p>@endif @if ($item->message)<p class="mt-2 text-slate-600">{{ $item->message }}</p>@endif @foreach (($plan['notes'] ?? []) as $note)<p class="mt-1 text-slate-500">{{ $note }}</p>@endforeach</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $isDraftPreview ? 6 : 7 }}" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('Nema kandidata za odabrani filtar.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $candidates->links() }}</div>
            <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4">
                <button type="button" wire:click="{{ $isDraftPreview ? 'importSelected' : 'applySelectedUpdates' }}" wire:loading.attr="disabled" @disabled((! $configured && ! $isCsvPreview) || $previewLimited || count($selectedIds) === 0) class="min-h-11 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40">{{ $isDraftPreview ? __('Uvezi odabrane kao neaktivne nacrte') : __('Primijeni odabrane promjene') }} ({{ count($selectedIds) }})</button>
                <button type="button" wire:click="clearSelection" class="min-h-11 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Očisti odabir') }}</button>
                <p class="text-xs text-slate-500">{{ $isDraftPreview ? __('Najviše 500 artikala po uvozu. Nacrti čekaju vaš pregled i objavu.') : __('Najviše 500 odabranih promjena po obradi.') }}</p>
            </div>
            @error('selectedIds')<p class="mt-3 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
            @error('selectedIds.*')<p class="mt-3 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
        </section>
    @else
        <section class="admin-panel admin-panel-soft p-5"><p class="text-sm text-slate-500">{{ __('Kliknite Pregledaj nove artikle za pripremu prijedloga uvoza. Tek nakon pregleda i odabira kreiraju se nacrti.') }}</p></section>
    @endif

    <section class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <h2 class="admin-section-title">{{ __('Povijest ERP obrade') }}</h2>
            <div class="flex flex-wrap gap-3">
                <div><label for="eracuni-kind-filter" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Vrsta') }}</label><select id="eracuni-kind-filter" wire:model.live="kindFilter" class="admin-select rounded-xl border border-slate-300 px-3 py-2 text-xs"><option value="all">{{ __('Sve vrste') }}</option>@foreach ($kindLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                <div><label for="eracuni-status-filter" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Status') }}</label><select id="eracuni-status-filter" wire:model.live="statusFilter" class="admin-select rounded-xl border border-slate-300 px-3 py-2 text-xs"><option value="all">{{ __('Svi statusi') }}</option>@foreach ($statusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-[48rem] text-sm">
                <thead><tr class="text-left text-xs text-slate-500"><th class="px-3 py-2 font-semibold">{{ __('Obrada / početak') }}</th><th class="px-3 py-2 font-semibold">{{ __('Status') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Dohvaćeno') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Nacrta') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Ažurirano') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Preskočeno') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Izvještaj') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($runs as $run)
                        <tr wire:key="eracuni-run-{{ $run->id }}"><td class="px-3 py-3"><p class="font-semibold text-slate-800">{{ $kindLabels[$run->kind] ?? $run->kind }}</p><p class="mt-1 text-xs text-slate-500">#{{ $run->id }} · {{ $formatTime($run->started_at) }}</p></td><td class="px-3 py-3"><span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $statusTone($run->status) }}">{{ $statusLabels[$run->status] ?? $run->status }}</span></td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($run->fetched_count, 0, ',', '.') }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($run->created_count, 0, ',', '.') }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($run->updated_count, 0, ',', '.') }}</td><td class="px-3 py-3 text-right tabular-nums">{{ number_format($run->skipped_count, 0, ',', '.') }}</td><td class="px-3 py-3 text-right"><button type="button" wire:click="selectRun({{ $run->id }})" class="min-h-9 rounded-lg border border-cyan-200 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-50">{{ __('Otvori') }}</button></td></tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('Još nema ERP obrade za odabrane filtre.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $runs->links() }}</div>
    </section>

    @if ($selectedRun)
        <section wire:key="eracuni-report-{{ $selectedRun->id }}" class="admin-panel admin-panel-soft p-5" aria-labelledby="eracuni-report-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 id="eracuni-report-title" class="admin-section-title">{{ __('Izvještaj') }} #{{ $selectedRun->id }} · {{ $kindLabels[$selectedRun->kind] ?? $selectedRun->kind }}</h2><p class="mt-2 text-xs text-slate-500">{{ __('Početak') }}: {{ $formatTime($selectedRun->started_at) }} · {{ __('Završetak') }}: {{ $formatTime($selectedRun->completed_at) }} · {{ $statusLabels[$selectedRun->status] ?? $selectedRun->status }}</p></div>
                <button type="button" wire:click="closeReport" class="min-h-9 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Zatvori izvještaj') }}</button>
            </div>
            @if ($selectedRun->error_message)<p class="mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ $selectedRun->error_message }}</p>@endif
            @if (data_get($selectedRun->summary, 'warning'))<p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ data_get($selectedRun->summary, 'warning') }}</p>@endif
            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                @foreach (['fetched_count' => __('Dohvaćeno'), 'eligible_count' => __('Spremno za obradu'), 'created_count' => __('Uvezeno nacrta'), 'updated_count' => __('Ažurirano'), 'unchanged_count' => __('Bez promjene'), 'skipped_count' => __('Preskočeno')] as $field => $label)<div class="rounded-xl bg-slate-50 px-3 py-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 text-lg font-semibold text-slate-900">{{ number_format($selectedRun->{$field}, 0, ',', '.') }}</dd></div>@endforeach
            </dl>
            @if (! in_array($selectedRun->kind, ['preview', 'preview_csv', 'preview_prices', 'preview_names'], true))
                <div class="mt-4 overflow-x-auto">
                    <table class="admin-items-table min-w-[40rem] text-sm">
                        <thead><tr class="text-left text-xs text-slate-500"><th class="px-3 py-2 font-semibold">{{ __('ERP šifra') }}</th><th class="px-3 py-2 font-semibold">{{ __('Artikl') }}</th><th class="px-3 py-2 font-semibold">{{ __('ID artikla') }}</th><th class="px-3 py-2 font-semibold">{{ __('Rezultat') }}</th><th class="px-3 py-2 font-semibold">{{ __('Napomena') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($items as $item)<tr wire:key="eracuni-report-item-{{ $item->id }}"><td class="px-3 py-3 font-mono text-xs">{{ $item->identifier }}</td><td class="px-3 py-3 text-xs">{{ $item->name }}</td><td class="px-3 py-3 text-xs">{{ $item->product_id ?? '—' }}</td><td class="px-3 py-3 text-xs">{{ $itemStatusLabels[$item->status] ?? $item->status }}</td><td class="max-w-sm px-3 py-3 text-xs text-slate-500">{{ $item->message ?: '—' }}</td></tr>@empty<tr><td colspan="5" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('Nema stavki za prikaz.') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $items->links() }}</div>
            @endif
        </section>
    @endif
</div>
