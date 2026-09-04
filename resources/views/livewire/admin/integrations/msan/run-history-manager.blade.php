<div class="space-y-6" @if($pollFrequently) wire:poll.visible.10s @endif>
    <section class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('M SAN obrada') }}</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">{{ __('Povijest izvršavanja') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">
                    {{ __('Pregled sinkronizacija i uvoza je samo za čitanje. Neuspješno izvršavanje prvo provjerite u stupcu Greška.') }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ __('Stavki po stranici') }}: <span class="admin-chip">{{ $perPage }}</span>
                </p>
            </div>

            <div class="grid w-full gap-3 sm:grid-cols-[12rem_12rem_auto_auto] lg:w-auto">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-run-kind">{{ __('Vrsta') }}</label>
                    <select id="msan-run-kind" wire:model.live="kind" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="all">{{ __('Sve vrste') }}</option>
                        @foreach ($kinds as $kindOption)
                            <option value="{{ $kindOption['value'] }}">{{ $kindOption['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-run-status">{{ __('Status') }}</label>
                    <select id="msan-run-status" wire:model.live="status" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="all">{{ __('Svi statusi') }}</option>
                        @foreach ($statuses as $statusOption)
                            <option value="{{ $statusOption['value'] }}">{{ $statusOption['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="button" wire:click="clearFilters" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        {{ __('Očisti filtre') }}
                    </button>
                </div>
                <div class="flex items-end">
                    <button type="button" wire:click="$refresh" class="w-full rounded-xl border border-cyan-300 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">
                        {{ __('Osvježi') }}
                    </button>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="admin-section-title">{{ __('Sinkronizacije i uvozi') }}</h2>
            <p class="text-xs text-slate-500">
                {{ __('Prikazano :from–:to od :total', [
                    'from' => $runs->firstItem() ?? 0,
                    'to' => $runs->lastItem() ?? 0,
                    'total' => $runs->total(),
                ]) }}
            </p>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-[88rem] text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Izvršavanje') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Pokrenuo') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Napredak') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Obrađeno') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Rezultat') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Trajanje') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Greška / sažetak') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($runs as $run)
                        @php
                            $statusClass = match ((string) $run->status) {
                                'completed' => 'bg-emerald-100 text-emerald-800',
                                'running' => 'bg-cyan-100 text-cyan-800',
                                'failed' => 'bg-rose-100 text-rose-800',
                                'cancelled' => 'bg-amber-100 text-amber-800',
                                default => 'bg-slate-200 text-slate-700',
                            };
                            $progress = max(0, min(100, (int) $run->progress));
                            $summary = is_array($run->summary) ? $run->summary : [];
                            $summaryLabels = [
                                'datasets' => __('Skupovi podataka'),
                                'products' => __('Artikli'),
                                'categories' => __('Kategorije'),
                                'selected' => __('Odabrano'),
                                'unmapped_categories' => __('Nemapirane kategorije'),
                                'selected_total' => __('Ukupno odabrano'),
                                'chunk_size' => __('Artikala po paketu'),
                                'dispatched_chunks' => __('Poslani paketi'),
                                'dispatched_at' => __('Poslano u red'),
                                'last_attempt_failed_at' => __('Zadnji neuspjeli pokušaj'),
                                'connection' => __('B2B veza'),
                                'ftp_connection' => __('FTPS veza'),
                                'dataset' => __('Skup podataka'),
                                'staging_products' => __('Uvezeni M SAN artikli'),
                                'price_rows_matched' => __('Usklađeni redovi cijena'),
                                'availability_rows_matched' => __('Usklađeni redovi dostupnosti'),
                                'local_products_eligible' => __('Lokalni artikli za ažuriranje'),
                                'local_prices_updated' => __('Ažurirane lokalne cijene'),
                                'local_prices_unchanged' => __('Nepromijenjene lokalne cijene'),
                                'local_prices_missing' => __('Lokalni artikli bez valjane MPC cijene'),
                                'local_stock_updated' => __('Ažurirane lokalne zalihe'),
                                'local_stock_unchanged' => __('Nepromijenjene lokalne zalihe'),
                                'local_supplier_snapshots_updated' => __('Ažurirani M SAN podaci na artiklima'),
                                'local_stale_stock_zeroed' => __('Zalihe zastarjelih M SAN artikala postavljene na nulu'),
                                'local_products_not_msan_owned' => __('Artikli čijim podacima ne upravlja M SAN'),
                                'source' => __('Izvor specifikacija'),
                                'source_bytes' => __('Veličina izvora u bajtovima'),
                                'rows' => __('Redovi u izvoru'),
                                'relevant_rows' => __('Relevantni redovi'),
                                'products_with_specifications' => __('Artikli sa specifikacijama'),
                                'definitions' => __('Definicije specifikacija'),
                                'published_products' => __('Objavljeni artikli'),
                                'published_specifications' => __('Objavljene specifikacije'),
                                'energy_declarations' => __('Energetske deklaracije'),
                                'filter_attributes' => __('Svojstva za filtre'),
                                'eligible_products' => __('Artikli prikladni za EPREL'),
                                'run_limit' => __('Ograničenje po izvršavanju'),
                                'deferred_products' => __('Odgođeni artikli'),
                                'exact_matches' => __('Točna EPREL podudaranja'),
                                'not_matched_or_invalid' => __('Bez podudaranja ili nevaljani'),
                                'fresh_for_days' => __('Podaci vrijede dana'),
                            ];
                            $summaryValueLabels = [
                                'standard' => __('Standardni M SAN skup'),
                                'icecat' => __('M SAN Icecat skup'),
                                'availability' => __('Raspoloživost'),
                                'prices' => __('Cijene'),
                            ];
                            $duration = $run->started_at && $run->completed_at
                                ? $run->started_at->diffForHumans($run->completed_at, true)
                                : null;
                            $kindLabel = match ((string) $run->kind) {
                                'catalog' => __('Katalog'),
                                'prices' => __('Cijene i količine'),
                                'availability' => __('Raspoloživost'),
                                'categories' => __('Kategorije'),
                                'full' => __('Puna sinkronizacija'),
                                'import' => __('Uvoz'),
                                'specifications' => __('Tehničke specifikacije'),
                                'eprel' => __('EPREL energetski podaci'),
                                'connection_test' => __('Provjera B2B veze'),
                                'ftp_connection_test' => __('Provjera FTPS veze'),
                                default => (string) $run->kind,
                            };
                            $statusLabel = match ((string) $run->status) {
                                'pending' => __('Na čekanju'),
                                'running' => __('U tijeku'),
                                'completed' => __('Završeno'),
                                'failed' => __('Neuspješno'),
                                'cancelled' => __('Otkazano'),
                                default => (string) $run->status,
                            };
                        @endphp
                        <tr wire:key="msan-run-row-{{ $run->id }}">
                            <td class="px-3 py-3">
                                <div class="font-semibold text-slate-900">#{{ $run->id }} · {{ $kindLabel }}</div>
                                <div class="mt-1 text-[11px] text-slate-500">{{ $run->created_at?->format('d.m.Y. H:i:s') ?? '—' }}</div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">
                                    {{ $statusLabel }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-slate-700">
                                @if ($run->requested_by_name || $run->requested_by_email)
                                    <div class="font-medium">{{ $run->requested_by_name ?: __('Korisnik #:id', ['id' => $run->requested_by]) }}</div>
                                    <div class="mt-0.5 text-[11px] text-slate-500">{{ $run->requested_by_email }}</div>
                                @else
                                    <span class="text-xs text-slate-500">{{ __('Sustav') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex min-w-40 items-center gap-2">
                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="{{ __('Napredak izvršavanja #:id', ['id' => $run->id]) }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}">
                                        <div class="h-full rounded-full bg-cyan-600" style="width: {{ $progress }}%"></div>
                                    </div>
                                    <span class="w-10 text-right text-xs font-semibold tabular-nums text-slate-700">{{ $progress }}%</span>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums text-slate-700">
                                <div class="font-semibold">{{ number_format((int) $run->processed_count, 0, ',', '.') }}</div>
                                <div class="mt-0.5 text-[11px] text-slate-500">{{ __('od :total', ['total' => number_format((int) $run->total_count, 0, ',', '.')]) }}</div>
                            </td>
                            <td class="px-3 py-3 text-right text-xs tabular-nums">
                                <div class="text-emerald-700">{{ __('Uspjelo: :count', ['count' => number_format((int) $run->succeeded_count, 0, ',', '.')]) }}</div>
                                <div class="mt-0.5 text-rose-700">{{ __('Neuspjelo: :count', ['count' => number_format((int) $run->failed_count, 0, ',', '.')]) }}</div>
                                <div class="mt-0.5 text-slate-500">{{ __('Preskočeno: :count', ['count' => number_format((int) $run->skipped_count, 0, ',', '.')]) }}</div>
                            </td>
                            <td class="px-3 py-3 text-xs text-slate-600">
                                <div>{{ $run->started_at?->format('d.m.Y. H:i:s') ?? __('Nije pokrenuto') }}</div>
                                <div class="mt-0.5 text-slate-400">{{ $duration ? __('Trajanje: :duration', ['duration' => $duration]) : '—' }}</div>
                            </td>
                            <td class="px-3 py-3 text-xs">
                                @if ($run->error_message)
                                    <div class="max-w-[28rem] whitespace-normal text-rose-700" title="{{ $run->error_message }}">
                                        {{ \Illuminate\Support\Str::limit($run->error_message, 220) }}
                                    </div>
                                @elseif ($summary !== [])
                                    <dl class="max-w-[28rem] space-y-1 text-slate-600">
                                        @foreach (array_slice($summary, 0, 4, true) as $summaryKey => $summaryValue)
                                            @php
                                                $displaySummaryValue = is_scalar($summaryValue)
                                                    ? ($summaryValueLabels[(string) $summaryValue] ?? $summaryValue)
                                                    : json_encode($summaryValue, JSON_UNESCAPED_UNICODE);
                                            @endphp
                                            <div class="flex gap-2">
                                                <dt class="shrink-0 font-medium">{{ $summaryLabels[(string) $summaryKey] ?? str_replace('_', ' ', (string) $summaryKey) }}:</dt>
                                                <dd class="truncate" title="{{ (string) $displaySummaryValue }}">
                                                    {{ $displaySummaryValue }}
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-10 text-center text-sm text-slate-500">
                                {{ __('Nema zabilježenih M SAN sinkronizacija ili uvoza prema odabranim filtrima.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $runs->links() }}</div>
    </section>
</div>
