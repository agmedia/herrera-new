<div class="space-y-6" wire:poll.visible.30s x-data>
    @php
        $statusTone = static fn ($status) => match ($status) {
            'completed' => 'bg-emerald-100 text-emerald-800',
            'running' => 'bg-cyan-100 text-cyan-800',
            'failed' => 'bg-rose-100 text-rose-800',
            default => 'bg-slate-100 text-slate-700',
        };
        $formatTime = static fn ($time) => $time?->copy()->timezone(config('app.timezone'))->format('d.m.Y. H:i:s') ?? '—';
        $runNeedsRetry = static fn ($run) => $run?->status === 'running' && ($run->started_at === null || $run->started_at->lte(now()->subMinutes(10)));
    @endphp

    <section class="admin-panel admin-search-panel p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Integracije') }}</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">{{ __('Zalihe i cronovi') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ __('Ručno osvježite količine, provjerite zadnji cron i pregledajte promjene po artiklu.') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('Vrijeme izvještaja') }}: {{ config('app.timezone') }}</p>
            </div>
            <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="min-h-10 rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50">{{ __('Osvježi pregled') }}</button>
        </div>
        <p role="status" wire:loading wire:target="runSupplier" class="mt-4 rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm text-cyan-800">{{ __('Dohvaćam zalihe i obrađujem artikle. Izvještaj će se prikazati nakon završetka.') }}</p>
    </section>

    <section class="grid gap-4 lg:grid-cols-2" aria-label="{{ __('Izvori zaliha') }}">
        @foreach ($sources as $supplier => $source)
            @php
                $lastCron = $source['last_cron_attempt'];
                $lastSuccess = $source['last_cron_success'];
                $isRunning = $source['is_running'];
            @endphp
            <article wire:key="stock-source-{{ $supplier }}" class="admin-panel admin-panel-soft p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="admin-section-title">{{ $source['label'] }}</h2>
                        <p class="mt-1 text-xs text-slate-500">{{ $source['target'] === 'stock_qty' ? __('Vlastita zaliha skladišta') : __('Zaliha kod dobavljača') }}</p>
                    </div>
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $source['cron_enabled'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $source['cron_enabled'] ? __('Cron uključen') : __('Cron isključen') }}</span>
                </div>

                @if (! $source['configured'])
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ __('Veza s izvorom nije podešena. Osvježavanje će biti dostupno nakon podešavanja.') }}</p>
                @elseif ($isRunning)
                    <p class="mt-3 rounded-lg bg-cyan-50 px-3 py-2 text-xs text-cyan-800">{{ __('Osvježavanje je u tijeku.') }}</p>
                @elseif ($source['is_interrupted'])
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ __('Prethodno osvježavanje nije završeno. Možete ga pokrenuti ponovno.') }}</p>
                @endif

                <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-xs">
                    <dt class="text-slate-500">{{ __('Zadnji cron pokušaj') }}</dt>
                    <dd class="text-right font-medium text-slate-800">
                        {{ $formatTime($lastCron?->started_at) }}
                        @if ($lastCron)<span class="ml-1 inline-flex rounded-full px-2 py-0.5 {{ $runNeedsRetry($lastCron) ? 'bg-amber-100 text-amber-800' : $statusTone($lastCron->status) }}">{{ $runNeedsRetry($lastCron) ? __('Potrebno ponoviti') : ($statusLabels[$lastCron->status] ?? $lastCron->status) }}</span>@endif
                    </dd>
                    <dt class="text-slate-500">{{ __('Zadnji uspješan cron') }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $formatTime($lastSuccess?->completed_at) }}</dd>
                    <dt class="text-slate-500">{{ __('Promijenjeno zadnjim cronom') }}</dt>
                    <dd class="text-right font-medium text-slate-800">{{ $lastSuccess ? number_format($lastSuccess->updated_count, 0, ',', '.') : '—' }}</dd>
                    <dt class="text-slate-500">{{ __('Raspored iz EasyCrona') }}</dt>
                    <dd class="text-right font-mono text-slate-800">{{ $source['schedule'] }}</dd>
                </dl>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="runSupplier('{{ $supplier }}')" wire:loading.attr="disabled" @disabled(! $source['configured'] || $isRunning) class="min-h-10 rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40">{{ __('Osvježi sada') }}</button>
                    <button type="button" wire:click="toggleCron('{{ $supplier }}', {{ $source['cron_enabled'] ? 'false' : 'true' }})" wire:loading.attr="disabled" @disabled(! $source['configured'] && ! $source['cron_enabled']) class="min-h-10 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">{{ $source['cron_enabled'] ? __('Isključi cron') : __('Uključi cron') }}</button>
                    @if ($source['latest_run'])
                        <button type="button" wire:click="selectRun({{ $source['latest_run']->id }})" class="min-h-10 rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">{{ __('Zadnji izvještaj') }}</button>
                    @endif
                </div>

                <details class="mt-4 border-t border-slate-200 pt-3">
                    <summary class="cursor-pointer text-xs font-semibold text-slate-600">{{ __('URL za EasyCron') }}</summary>
                    @if ($source['cron_url'] !== '')
                        <label for="stock-cron-url-{{ $supplier }}" class="mt-3 block text-xs text-slate-500">{{ __('U postojeći EasyCron posao upišite ovaj URL. Klik na polje odabire cijeli URL.') }}</label>
                        <input id="stock-cron-url-{{ $supplier }}" type="text" readonly value="{{ $source['cron_url'] }}" x-on:click="$event.target.select()" class="mt-2 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">
                        <p class="mt-2 text-xs text-slate-500">{{ __('Raspored poziva podešava se u EasyCronu. Ovdje uključujete ili isključujete obradu na stranici.') }}</p>
                    @else
                        <p class="mt-3 text-xs text-amber-800">{{ __('Cron URL će biti dostupan nakon podešavanja pristupnog ključa.') }}</p>
                    @endif
                </details>
            </article>
        @endforeach
    </section>

    <section class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="admin-section-title">{{ __('Povijest izvršavanja') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('Ručno i automatsko osvježavanje dijele iste izvještaje.') }}</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <div>
                    <label for="stock-source-filter" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Izvor') }}</label>
                    <select id="stock-source-filter" wire:model.live="supplierFilter" class="admin-select rounded-xl border border-slate-300 px-3 py-2 text-xs">
                        <option value="all">{{ __('Svi izvori') }}</option>
                        @foreach ($sources as $supplier => $source)<option value="{{ $supplier }}">{{ $source['label'] }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="stock-trigger-filter" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Pokretanje') }}</label>
                    <select id="stock-trigger-filter" wire:model.live="triggerFilter" class="admin-select rounded-xl border border-slate-300 px-3 py-2 text-xs">
                        <option value="all">{{ __('Sva pokretanja') }}</option><option value="cron">{{ __('Cron') }}</option><option value="manual">{{ __('Ručno') }}</option>
                    </select>
                </div>
                <div>
                    <label for="stock-status-filter" class="mb-1 block text-xs font-semibold text-slate-500">{{ __('Status') }}</label>
                    <select id="stock-status-filter" wire:model.live="statusFilter" class="admin-select rounded-xl border border-slate-300 px-3 py-2 text-xs">
                        <option value="all">{{ __('Svi statusi') }}</option>
                        @foreach ($statusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <button type="button" wire:click="clearFilters" class="self-end rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Očisti filtre') }}</button>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-[62rem] text-sm">
                <thead><tr class="text-left text-xs text-slate-500">
                    <th class="px-3 py-2 font-semibold">{{ __('Izvor / početak') }}</th>
                    <th class="px-3 py-2 font-semibold">{{ __('Pokretanje') }}</th>
                    <th class="px-3 py-2 font-semibold">{{ __('Status') }}</th>
                    <th class="px-3 py-2 text-right font-semibold">{{ __('Dohvaćeno') }}</th>
                    <th class="px-3 py-2 text-right font-semibold">{{ __('Ažurirano') }}</th>
                    <th class="px-3 py-2 text-right font-semibold">{{ __('Bez promjene') }}</th>
                    <th class="px-3 py-2 text-right font-semibold">{{ __('Bez podudaranja') }}</th>
                    <th class="px-3 py-2 text-right font-semibold">{{ __('Nevaljano') }}</th>
                    <th class="px-3 py-2 text-right font-semibold">{{ __('Izvještaj') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($runs as $run)
                        <tr wire:key="stock-run-{{ $run->id }}">
                            <td class="px-3 py-3"><p class="font-semibold text-slate-800">{{ $sources[$run->supplier]['label'] ?? $run->supplier }}</p><p class="mt-1 text-xs text-slate-500">#{{ $run->id }} · {{ $formatTime($run->started_at) }}</p></td>
                            <td class="px-3 py-3 text-xs text-slate-600">{{ $run->trigger === 'cron' ? __('Cron') : __('Ručno') }}</td>
                            <td class="px-3 py-3"><span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $runNeedsRetry($run) ? 'bg-amber-100 text-amber-800' : $statusTone($run->status) }}">{{ $runNeedsRetry($run) ? __('Potrebno ponoviti') : ($statusLabels[$run->status] ?? $run->status) }}</span></td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ number_format($run->fetched_count, 0, ',', '.') }}</td>
                            <td class="px-3 py-3 text-right font-semibold text-emerald-700 tabular-nums">{{ number_format($run->updated_count, 0, ',', '.') }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ number_format($run->unchanged_count, 0, ',', '.') }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ number_format($run->unmatched_count, 0, ',', '.') }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ number_format($run->invalid_count, 0, ',', '.') }}</td>
                            <td class="px-3 py-3 text-right"><button type="button" wire:click="selectRun({{ $run->id }})" class="min-h-9 rounded-lg border border-cyan-200 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-50">{{ __('Otvori') }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('Još nema izvršavanja za odabrane filtre.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $runs->links() }}</div>
    </section>

    @if ($selectedRun)
        <section wire:key="stock-report-{{ $selectedRun->id }}" class="admin-panel admin-panel-soft p-5" aria-labelledby="stock-report-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="stock-report-title" class="admin-section-title">{{ __('Izvještaj') }} #{{ $selectedRun->id }} · {{ $sources[$selectedRun->supplier]['label'] ?? $selectedRun->supplier }}</h2>
                    <p class="mt-2 text-xs text-slate-500">{{ $selectedRun->trigger === 'cron' ? __('Cron') : __('Ručno') }} · {{ __('Početak') }}: {{ $formatTime($selectedRun->started_at) }} · {{ __('Završetak') }}: {{ $formatTime($selectedRun->completed_at) }}</p>
                </div>
                <button type="button" wire:click="closeReport" class="min-h-9 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Zatvori izvještaj') }}</button>
            </div>
            @if ($selectedRun->error_message)
                <p class="mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ $selectedRun->error_message }}</p>
            @endif
            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                @foreach (['fetched_count' => __('Dohvaćeno'), 'matched_count' => __('Pronađeni artikli'), 'updated_count' => __('Ažurirano'), 'unchanged_count' => __('Bez promjene'), 'unmatched_count' => __('Bez podudaranja'), 'invalid_count' => __('Nevaljano')] as $field => $label)
                    <div class="rounded-xl bg-slate-50 px-3 py-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 text-lg font-semibold text-slate-900">{{ number_format($selectedRun->{$field}, 0, ',', '.') }}</dd></div>
                @endforeach
            </dl>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                @foreach (['skipped_count' => __('Preskočeno iz izvora'), 'clamped_count' => __('Ograničene količine'), 'duplicate_count' => __('Ponovljene oznake'), 'reset_count' => __('Zalihe postavljene na 0')] as $field => $label)<div><dt class="text-slate-500">{{ $label }}</dt><dd class="mt-1 font-semibold text-slate-800">{{ number_format((int) data_get($selectedRun->summary, $field, 0), 0, ',', '.') }}</dd></div>@endforeach
            </dl>
            @if (data_get($selectedRun->summary, 'no_matches', false))<p class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('Nijedan artikl iz izvora nije povezan s artiklima na stranici. Provjerite oznake artikala.') }}</p>@endif
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-500">{{ __('Promjene i preskočene stavke prikazane su po artiklu.') }}</p>
                <div class="flex items-center gap-2">
                    <label for="stock-item-status" class="text-xs font-semibold text-slate-500">{{ __('Stavke') }}</label>
                    <select id="stock-item-status" wire:model.live="itemStatus" class="admin-select rounded-xl border border-slate-300 px-3 py-2 text-xs"><option value="all">{{ __('Sve stavke') }}</option>@foreach ($itemStatusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                </div>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="admin-items-table min-w-[40rem] text-sm">
                    <thead><tr class="text-left text-xs text-slate-500"><th class="px-3 py-2 font-semibold">{{ __('Šifra / EAN') }}</th><th class="px-3 py-2 font-semibold">{{ __('ID artikla') }}</th><th class="px-3 py-2 font-semibold">{{ __('Rezultat') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Prije') }}</th><th class="px-3 py-2 text-right font-semibold">{{ __('Poslije') }}</th><th class="px-3 py-2 font-semibold">{{ __('Napomena') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($items as $item)
                            <tr wire:key="stock-item-{{ $item->id }}"><td class="px-3 py-3 font-mono text-xs">{{ $item->identifier ?: '—' }}</td><td class="px-3 py-3 text-xs">{{ $item->product_id ?? '—' }}</td><td class="px-3 py-3 text-xs">{{ $itemStatusLabels[$item->status] ?? $item->status }}</td><td class="px-3 py-3 text-right tabular-nums">{{ $item->old_quantity ?? '—' }}</td><td class="px-3 py-3 text-right tabular-nums">{{ $item->new_quantity ?? '—' }}</td><td class="max-w-sm px-3 py-3 text-xs text-slate-500">{{ $item->message ?: '—' }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('Nema stavki za prikaz.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $items->links() }}</div>
        </section>
    @endif
</div>
