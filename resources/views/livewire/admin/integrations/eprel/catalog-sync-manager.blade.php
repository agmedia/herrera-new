<div class="space-y-6" @if ($pollFrequently) wire:poll.visible.5s @endif data-eprel-catalog-manager>
    <section class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-700">{{ __('Službeni energetski podaci') }}</p>
                <h1 class="mt-2 text-xl font-semibold text-slate-950">{{ __('EPREL povezivanje kataloga') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('Najprije provjerite mali paket, zatim možete pokrenuti cijeli katalog u pozadini. Povezuju se samo točna i jednoznačna podudaranja; cijene, zalihe i ručne deklaracije ostaju sačuvane.') }}</p>
            </div>
            <a href="{{ route('admin.integrations.eprel.settings') }}" class="shrink-0 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('EPREL postavke') }}</a>
        </div>
        @if (! $backendReady)
            <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('EPREL obrada se još priprema. Osvježite stranicu.') }}</p>
        @elseif (! $eprelConfigured)
            <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('Najprije uključite EPREL i spremite API ključ u EPREL postavkama. Ključ se nikad ne prikazuje u izvještaju.') }}</p>
        @endif
        <form wire:submit="start" class="mt-5 grid items-end gap-4 lg:grid-cols-[minmax(0,1fr)_10rem_auto]">
            <div>
                <label for="eprel-catalog-category" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Opseg kataloga') }}</label>
                <select id="eprel-catalog-category" wire:model="categoryId" class="admin-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm" data-native-select>
                    <option value="">{{ __('Sve aktivne kategorije') }}</option>
                    @foreach ($categories as $category)
                        @php
                            $categoryName = $category->translations->firstWhere('locale', app()->getLocale())?->name ?? $category->translations->first()?->name ?? $category->code;
                        @endphp
                        <option value="{{ $category->id }}">{{ $categoryName }} · #{{ $category->id }}</option>
                    @endforeach
                </select>
                @error('categoryId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="eprel-catalog-limit" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Probni paket (1–50)') }}</label>
                <input id="eprel-catalog-limit" type="number" min="1" max="50" step="1" wire:model="limit" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                @error('limit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" @disabled(! $backendReady || ! $eprelConfigured || $activeRunExists) wire:loading.attr="disabled" wire:target="start,startAll" class="rounded-xl bg-cyan-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-50">{{ __('Pokreni probni paket') }}</button>
        </form>
        <div class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="max-w-3xl text-xs leading-5 text-slate-500">{{ __('Rasvjeta i njezine podkategorije koriste LIGHT_SOURCE pri sigurnoj pretrazi modela. Drugi artikli trebaju pouzdan identifikator ili već odabranu EPREL grupu. Artikli bez toga se preskaču, bez mrežnog dohvata; rezultat nije zajamčen za svaki artikl.') }}</p>
            <button type="button" wire:click="startAll" wire:confirm="{{ __('Pokrenuti odabrani opseg cijelog kataloga u pozadini? Obrada ide redom, može trajati i možete je otkazati. Postojeće potvrđene i ručne deklaracije ostaju sačuvane.') }}" @disabled(! $backendReady || ! $eprelConfigured || $activeRunExists) wire:loading.attr="disabled" wire:target="start,startAll" class="shrink-0 rounded-xl border border-cyan-300 bg-cyan-50 px-4 py-2.5 text-sm font-semibold text-cyan-900 hover:bg-cyan-100 disabled:cursor-not-allowed disabled:opacity-50">{{ __('Prođi cijeli katalog') }}</button>
        </div>
        @if ($activeRunExists)
            <p class="mt-3 text-sm text-cyan-800">{{ __('Postoji nedovršena obrada. Nastavite je ili otkažite prije pokretanja novog paketa.') }}</p>
        @endif
        @error('operation') <p role="alert" class="mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</p> @enderror
    </section>

    @if ($selectedRun)
        @php
            $total = max(0, (int) $selectedRun->total_count);
            $processed = max(0, (int) $selectedRun->processed_count);
            $planning = ! $selectedRun->planning_complete && in_array($selectedRun->status, ['pending', 'running'], true);
            $progress = $planning ? 0 : ($total > 0 ? max(0, min(100, (int) round(100 * $processed / $total))) : ($selectedRun->status === 'completed' ? 100 : 0));
        @endphp
        <section class="admin-panel p-5" data-eprel-selected-run="{{ $selectedRun->id }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-slate-950">{{ __('Obrada #:id', ['id' => $selectedRun->id]) }} · {{ $planning ? __('Priprema kataloga') : $this->statusLabel($selectedRun->status) }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $selectedRun->created_at?->format('d.m.Y. H:i') }} · {{ $planning ? __('Dosad dodano :total artikala', ['total' => $total]) : __('Obrađeno :done od :total', ['done' => $processed, 'total' => $total]) }}</p>
                    @if ($planning)
                        <p class="mt-2 max-w-3xl text-sm leading-5 text-cyan-800">{{ __('Popis artikala još raste. EPREL API pretraga počinje nakon završetka pripreme kataloga.') }}</p>
                    @endif
                </div>
                <div class="flex gap-2">
                    @if (in_array($selectedRun->status, ['paused', 'failed'], true))
                        <button type="button" wire:click="resume({{ $selectedRun->id }})" wire:loading.attr="disabled" @disabled(! $eprelConfigured) class="rounded-xl bg-cyan-700 px-4 py-2 text-xs font-semibold text-white disabled:opacity-50">{{ __('Nastavi obradu') }}</button>
                    @endif
                    @if (in_array($selectedRun->status, ['pending', 'running', 'paused'], true))
                        <button type="button" wire:click="cancel({{ $selectedRun->id }})" wire:confirm="{{ __('Otkazati ovu obradu? Već povezane deklaracije ostaju sačuvane.') }}" wire:loading.attr="disabled" class="rounded-xl border border-rose-200 px-4 py-2 text-xs font-semibold text-rose-700">{{ __('Otkaži obradu') }}</button>
                    @endif
                    <button type="button" wire:click="$refresh" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700">{{ __('Osvježi') }}</button>
                </div>
            </div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="{{ $planning ? __('Priprema kataloga') : __('Napredak EPREL obrade') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}" @if ($planning) aria-busy="true" @endif><div class="h-full bg-cyan-600" style="width: {{ $progress }}%"></div></div>
            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                @foreach (['matched_count' => __('Povezano'), 'not_found_count' => __('Bez podudaranja'), 'skipped_count' => __('Preskočeno'), 'failed_count' => __('Greške'), 'processed_count' => __('Obrađeno')] as $field => $label)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3"><dt class="text-xs text-slate-500">{{ $planning && $field === 'processed_count' ? __('Pripremljeno') : $label }}</dt><dd class="mt-1 text-lg font-semibold text-slate-900">{{ $planning && $field === 'processed_count' ? $total : max(0, (int) $selectedRun->{$field}) }}</dd></div>
                @endforeach
            </dl>
            @if ($selectedRun->error_message || in_array($selectedRun->status, ['paused', 'failed'], true))
                <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('Obrada je zaustavljena. Provjerite EPREL postavke i API ograničenja prije nastavka. Već potvrđeni rezultati ostaju sačuvani.') }}</p>
            @endif
            <div class="mt-5 overflow-x-auto">
                <table class="admin-items-table min-w-[42rem] text-sm">
                    <thead><tr><th class="px-3 py-2 text-left">{{ __('Artikl') }}</th><th class="px-3 py-2 text-left">{{ __('Rezultat') }}</th><th class="px-3 py-2 text-left">{{ __('EPREL broj') }}</th><th class="px-3 py-2 text-left">{{ __('Objašnjenje') }}</th></tr></thead>
                    <tbody>
                        @forelse ($items as $item)
                            @php
                                $product = $products->get($item->product_id);
                                $name = $product?->translations->firstWhere('locale', app()->getLocale())?->name ?? $product?->translations->first()?->name ?? __('Artikl #:id', ['id' => $item->product_id]);
                            @endphp
                            <tr wire:key="eprel-report-item-{{ $item->id }}">
                                <td class="px-3 py-3"><div class="font-medium text-slate-900">@if ($product && $canEditProducts)<a href="{{ route('admin.products.edit', $product->id) }}" class="text-cyan-800 underline underline-offset-2">{{ $name }}</a>@else{{ $name }}@endif</div><div class="mt-1 font-mono text-xs text-slate-500">{{ $product?->sku ?: $product?->code }}</div></td>
                                <td class="px-3 py-3"><span class="text-xs font-semibold {{ $item->status === 'matched' ? 'text-emerald-700' : (in_array($item->status, ['error', 'conflict'], true) ? 'text-rose-700' : 'text-slate-600') }}">{{ $this->statusLabel($item->status) }}</span></td>
                                <td class="px-3 py-3 font-mono text-xs">{{ $item->status === 'matched' ? $item->matched_registration : '—' }}</td>
                                <td class="max-w-lg px-3 py-3 text-xs leading-5 text-slate-600">{{ $this->itemExplanation($item->status) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-5 text-sm text-slate-500">{{ __('Ovaj paket još nema stavki za prikaz.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($items->hasPages()) <div class="mt-4">{{ $items->links() }}</div> @endif
        </section>
    @endif

    <section class="admin-panel p-5">
        <h2 class="text-base font-semibold text-slate-950">{{ __('Povijest EPREL obrade') }}</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-[35rem] text-sm">
                <thead><tr><th class="px-3 py-2 text-left">{{ __('Paket') }}</th><th class="px-3 py-2 text-left">{{ __('Status') }}</th><th class="px-3 py-2 text-left">{{ __('Opseg') }}</th><th class="px-3 py-2 text-right">{{ __('Obrađeno / ukupno') }}</th><th class="px-3 py-2 text-right">{{ __('Povezano') }}</th></tr></thead>
                <tbody>
                    @forelse ($runs as $run)
                        @php $runPlanning = ! $run->planning_complete && in_array($run->status, ['pending', 'running'], true); @endphp
                        <tr wire:key="eprel-history-run-{{ $run->id }}"><td class="px-3 py-3"><button type="button" wire:click="selectRun({{ $run->id }})" class="font-semibold text-cyan-800 underline underline-offset-2">#{{ $run->id }}</button><div class="mt-1 text-xs text-slate-500">{{ $run->created_at?->format('d.m.Y. H:i') }}</div></td><td class="px-3 py-3 text-xs font-semibold">{{ $runPlanning ? __('Priprema kataloga') : $this->statusLabel($run->status) }}</td><td class="px-3 py-3 text-xs text-slate-500">{{ $run->category_id ? __('Kategorija #:id', ['id' => $run->category_id]) : __('Sve kategorije') }}</td><td class="px-3 py-3 text-right">@if ($runPlanning){{ __(':count pripremljeno', ['count' => max(0, (int) $run->total_count)]) }}@else{{ max(0, (int) $run->processed_count) }} / {{ max(0, (int) $run->total_count) }}@endif</td><td class="px-3 py-3 text-right font-semibold text-emerald-700">{{ max(0, (int) $run->matched_count) }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-5 text-sm text-slate-500">{{ __('Još nije pokrenuta EPREL obrada kataloga.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($runs->hasPages()) <div class="mt-4">{{ $runs->links() }}</div> @endif
    </section>
</div>
