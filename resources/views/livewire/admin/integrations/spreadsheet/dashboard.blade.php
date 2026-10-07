<div class="space-y-6">
    @php
        $formatTime = static fn ($time) => $time?->copy()->timezone(config('app.timezone'))->format('d.m.Y. H:i:s') ?? '—';
        $statusTone = static fn ($status) => match ($status) {
            'completed', 'ready', 'applied' => 'bg-emerald-100 text-emerald-800',
            'failed', 'invalid', 'conflict' => 'bg-rose-100 text-rose-800',
            'preview' => 'bg-cyan-100 text-cyan-800',
            'unmatched' => 'bg-amber-100 text-amber-800',
            default => 'bg-slate-100 text-slate-700',
        };
    @endphp

    <section class="admin-panel admin-search-panel p-5 sm:p-6">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Integracije') }}</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">{{ __('Excel i CSV uvoz') }}</h1>
        <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ __('Učitajte datoteku, povežite stupce i pregledajte promjene cijena ili količina prije primjene.') }}</p>
        <p class="mt-2 text-xs text-slate-500">{{ __('Obrađuje se aktivni Excel list ili CSV, do 10.000 podatkovnih redova. Uvoz osvježava postojeće artikle.') }}</p>
    </section>

    <form wire:submit="previewFile" class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="admin-section-title">{{ __('Datoteka i mapiranje') }}</h2>
            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700"><input type="checkbox" wire:model.live="options.enabled" class="h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">{{ __('Omogući obradu datoteke') }}</label>
        </div>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div><label for="spreadsheet-upload" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Excel / CSV datoteka') }}</label><input id="spreadsheet-upload" type="file" wire:model="upload" accept=".xlsx,.xls,.csv" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700"><p class="mt-1 text-xs text-slate-500">{{ __('XLSX, XLS ili CSV, najviše 10 MB.') }}</p><p wire:loading wire:target="upload" class="mt-2 text-xs font-semibold text-cyan-800">{{ __('Datoteka se učitava…') }}</p></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label for="spreadsheet-first-row" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Prvi podatkovni red') }}</label><input id="spreadsheet-first-row" type="number" wire:model.live="options.first_row" min="1" max="1048576" class="admin-input w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label for="spreadsheet-identifier-column" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Stupac oznake artikla') }}</label><input id="spreadsheet-identifier-column" type="number" wire:model.live="options.identifier_column" min="1" max="16384" class="admin-input w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                <div class="col-span-2"><label for="spreadsheet-identifier-type" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Povezivanje artikala po') }}</label><select id="spreadsheet-identifier-type" wire:model.live="options.identifier_type" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">@foreach ($identifierLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500">{{ __('Stupci su numerirani od 1: A = 1, B = 2, C = 3. Ako prvi red sadrži zaglavlja, prvi podatkovni red je 2.') }}</p>

        <div class="mt-5 grid gap-4 lg:grid-cols-3">
            <fieldset class="rounded-xl border border-slate-200 p-4">
                <legend class="px-1 text-sm font-semibold text-slate-800">{{ __('Cijene') }}</legend>
                <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700"><input type="checkbox" wire:model.live="options.update_prices" class="h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">{{ __('Osvježi pohranjene cijene') }}</label>
                <div class="mt-3"><label for="spreadsheet-price-column" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Stupac cijene') }}</label><input id="spreadsheet-price-column" type="number" wire:model.live="options.price_column" min="1" max="16384" class="admin-input w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                <div class="mt-3"><label for="spreadsheet-markup" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Uvećanje cijene (%)') }}</label><input id="spreadsheet-markup" type="number" wire:model.live="options.markup_percentage" min="0" max="10000" step="0.01" class="admin-input w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                <p class="mt-3 text-xs text-slate-500">{{ __('Iznos iz datoteke upisuje se izravno kao pohranjena cijena trgovine, u valuti trgovine. Uvoz ne preračunava PDV. Pripremite iznos prema načinu pohrane cijena na stranici.') }}</p>
                <p class="mt-2 text-xs font-semibold text-slate-700">{{ __('Trenutačna pohrana cijena') }}: {{ $storeCurrency }}, {{ $pricesIncludeTax ? __('s PDV-om') : __('bez PDV-a') }}.</p>
            </fieldset>
            <fieldset class="rounded-xl border border-slate-200 p-4">
                <legend class="px-1 text-sm font-semibold text-slate-800">{{ __('Vlastita zaliha') }}</legend>
                <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700"><input type="checkbox" wire:model.live="options.update_stock" class="h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">{{ __('Osvježi vlastitu zalihu') }}</label>
                <div class="mt-3"><label for="spreadsheet-stock-column" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Stupac vlastite zalihe') }}</label><input id="spreadsheet-stock-column" type="number" wire:model.live="options.stock_column" min="1" max="16384" class="admin-input w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                <p class="mt-3 text-xs text-slate-500">{{ __('Količina mora biti cijeli broj. Prazno polje odabranog stupca količine upisuje 0.') }}</p>
            </fieldset>
            <fieldset class="rounded-xl border border-slate-200 p-4">
                <legend class="px-1 text-sm font-semibold text-slate-800">{{ __('Zaliha dobavljača') }}</legend>
                <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700"><input type="checkbox" wire:model.live="options.update_supplier_stock" class="h-4 w-4 rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">{{ __('Osvježi zalihu dobavljača') }}</label>
                <div class="mt-3"><label for="spreadsheet-supplier-column" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Stupac zalihe dobavljača') }}</label><input id="spreadsheet-supplier-column" type="number" wire:model.live="options.supplier_stock_column" min="1" max="16384" class="admin-input w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                <p class="mt-3 text-xs text-slate-500">{{ __('Ova količina predstavlja artikle dostupne kod dobavljača.') }}</p>
            </fieldset>
        </div>
        <div class="mt-5 flex flex-wrap items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="min-h-11 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Pripremi pregled promjena') }}</button>
            <p class="text-xs text-slate-500">{{ __('Priprema pregleda ne mijenja artikle. Promjena mapiranja zahtijeva novi pregled.') }}</p>
        </div>
        <p role="status" wire:loading wire:target="previewFile,applyPreview" class="mt-4 rounded-xl bg-cyan-50 px-4 py-3 text-sm text-cyan-800">{{ __('Obrada datoteke je u tijeku…') }}</p>
        @if ($errors->any())<ul class="mt-4 space-y-1 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    </form>

    @if ($selectedRun)
        <section wire:key="spreadsheet-report-{{ $selectedRun->id }}" class="admin-panel admin-panel-soft p-5" aria-labelledby="spreadsheet-report-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 id="spreadsheet-report-title" class="admin-section-title">{{ $selectedRun->status === 'preview' ? __('Pregled prije primjene') : __('Izvještaj uvoza') }} #{{ $selectedRun->id }}</h2><p class="mt-2 text-xs text-slate-500">{{ $formatTime($selectedRun->started_at) }} · {{ $statusLabels[$selectedRun->status] ?? $selectedRun->status }} · {{ __('Povezivanje') }}: {{ $identifierLabels[$selectedRun->options['identifier_type'] ?? 'model'] ?? '—' }}</p></div>
                <button type="button" wire:click="closeReport" class="min-h-9 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Zatvori izvještaj') }}</button>
            </div>
            @if ($selectedRun->error_message)<p class="mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ $selectedRun->error_message }}</p>@endif
            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                @foreach (['total_count' => __('Redova'), 'updated_count' => ($selectedRun->status === 'preview' ? __('Planirano promjena') : __('Ažurirano')), 'unchanged_count' => __('Bez promjene'), 'invalid_count' => __('Nevaljano'), 'unmatched_count' => __('Bez podudaranja'), 'conflict_count' => __('Sukoba')] as $field => $label)<div class="rounded-xl bg-slate-50 px-3 py-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 text-lg font-semibold text-slate-900">{{ number_format($selectedRun->{$field}, 0, ',', '.') }}</dd></div>@endforeach
            </dl>
            @if ($selectedRun->status === 'preview')
                @if ($selectedRun->invalid_count > 0 || $selectedRun->conflict_count > 0)<p class="mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ __('Primjena je blokirana zbog nevaljanih redova ili sukoba. Ispravite datoteku pa pripremite novi pregled.') }}</p>@elseif ($previewRunId !== $selectedRun->id)<p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('Datoteka ili mapiranje su promijenjeni. Pripremite novi pregled prije primjene.') }}</p>@endif
                @if ($selectedRun->unmatched_count > 0)<p class="mt-3 text-xs text-amber-800">{{ __('Redovi za koje artikl nije pronađen bit će preskočeni i zabilježeni u izvještaju.') }}</p>@endif
            @endif
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-500">{{ __('Svaki red prikazuje staru i predloženu vrijednost.') }}</p>
                <div class="flex items-center gap-2"><label for="spreadsheet-item-filter" class="text-xs font-semibold text-slate-500">{{ __('Redovi') }}</label><select id="spreadsheet-item-filter" wire:model.live="itemFilter" class="admin-select rounded-xl border border-slate-300 px-3 py-2 text-xs"><option value="all">{{ __('Svi redovi') }}</option>@foreach ($itemStatusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="admin-items-table min-w-[48rem] text-sm">
                    <thead><tr class="text-left text-xs text-slate-500"><th class="px-3 py-2 font-semibold">{{ __('Red / oznaka') }}</th><th class="px-3 py-2 font-semibold">{{ __('ID artikla') }}</th><th class="px-3 py-2 font-semibold">{{ __('Stanje') }}</th><th class="px-3 py-2 font-semibold">{{ __('Prije → poslije') }}</th><th class="px-3 py-2 font-semibold">{{ __('Napomena') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($items as $item)
                            <tr wire:key="spreadsheet-item-{{ $item->id }}"><td class="px-3 py-3"><p class="text-xs text-slate-500">{{ __('Red') }} {{ $item->row_number }}</p><p class="mt-1 font-mono text-xs text-slate-800">{{ $item->identifier ?: '—' }}</p></td><td class="px-3 py-3 text-xs">{{ $item->product_id ?? '—' }}</td><td class="px-3 py-3"><span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $statusTone($item->status) }}">{{ $itemStatusLabels[$item->status] ?? $item->status }}</span></td><td class="px-3 py-3 text-xs">@forelse (($item->new_values ?? []) as $field => $value)<p class="mt-1"><span class="font-semibold">{{ $valueLabels[$field] ?? $field }}:</span> {{ $item->old_values[$field] ?? '—' }} → {{ $value }}</p>@empty<span class="text-slate-400">—</span>@endforelse</td><td class="max-w-sm px-3 py-3 text-xs text-slate-500">{{ $item->message ?: '—' }}</td></tr>
                        @empty<tr><td colspan="5" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('Nema redova za odabrani filtar.') }}</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $items->links() }}</div>
            @if ($selectedRun->status === 'preview')<div class="mt-5 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4"><button type="button" wire:click="applyPreview" wire:loading.attr="disabled" @disabled(! $canApply) class="min-h-11 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Primijeni pregledane promjene') }}</button><p class="text-xs text-slate-500">{{ __('Primjenjuju se redovi spremni za obradu. Artikli izmijenjeni nakon pregleda zahtijevaju novi pregled.') }}</p></div>@endif
        </section>
    @endif

    <section class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-wrap items-end justify-between gap-3"><h2 class="admin-section-title">{{ __('Povijest uvoza datoteka') }}</h2><div><label for="spreadsheet-status-filter" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Status') }}</label><select id="spreadsheet-status-filter" wire:model.live="statusFilter" class="admin-select rounded-xl border border-slate-300 px-3 py-2 text-xs"><option value="all">{{ __('Sva izvršavanja') }}</option>@foreach ($statusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div></div>
        <div class="mt-4 overflow-x-auto"><table class="admin-items-table min-w-[48rem] text-sm"><thead><tr class="text-left text-xs text-slate-500"><th class="px-3 py-2 font-semibold">{{ __('Izvršavanje / početak') }}</th><th class="px-3 py-2 font-semibold">{{ __('Status') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Redova') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Promjena') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Nevaljano / sukobi') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Izvještaj') }}</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse ($runs as $run)<tr wire:key="spreadsheet-run-{{ $run->id }}"><td class="px-3 py-3"><p class="font-semibold text-slate-800">#{{ $run->id }} · {{ $run->kind === 'import' ? __('Uvoz') : __('Pregled') }}</p><p class="mt-1 text-xs text-slate-500">{{ $formatTime($run->started_at) }}</p></td><td class="px-3 py-3"><span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $statusTone($run->status) }}">{{ $statusLabels[$run->status] ?? $run->status }}</span></td><td class="px-3 py-3 text-right tabular-nums">{{ $run->total_count }}</td><td class="px-3 py-3 text-right tabular-nums">{{ $run->updated_count }}</td><td class="px-3 py-3 text-right tabular-nums">{{ $run->invalid_count }} / {{ $run->conflict_count }}</td><td class="px-3 py-3 text-right"><button type="button" wire:click="selectRun({{ $run->id }})" class="min-h-9 rounded-lg border border-cyan-200 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-50">{{ __('Otvori') }}</button></td></tr>@empty<tr><td colspan="6" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('Još nema obrade datoteka za odabrani filtar.') }}</td></tr>@endforelse</tbody></table></div>
        <div class="mt-4">{{ $runs->links() }}</div>
    </section>
</div>
