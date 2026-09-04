<div class="space-y-5" @if($pollFrequently) wire:poll.visible.10s @else wire:poll.visible.60s @endif>
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" aria-label="{{ __('Sažetak M SAN modula') }}">
        @foreach ([
            ['label' => __('Artikli u katalogu'), 'value' => $counts['products'], 'url' => route('admin.integrations.msan.products'), 'tone' => 'slate'],
            ['label' => __('Uključeni za uvoz'), 'value' => $counts['selected'], 'url' => route('admin.integrations.msan.products', ['selection' => 'selected']), 'tone' => 'cyan'],
            ['label' => __('Uvezeni artikli'), 'value' => $counts['imported'], 'url' => route('admin.integrations.msan.products', ['importStatus' => 'imported']), 'tone' => 'emerald'],
            ['label' => __('M SAN kategorije'), 'value' => $counts['categories'], 'url' => route('admin.integrations.msan.categories', ['status' => 'all']), 'tone' => 'slate'],
            ['label' => __('Nemapirane kategorije'), 'value' => $counts['unmapped'], 'url' => route('admin.integrations.msan.categories', ['status' => 'unmapped']), 'tone' => $counts['unmapped'] > 0 ? 'amber' : 'emerald'],
            ['label' => __('Aktualne specifikacije'), 'value' => $counts['specifications'], 'url' => route('admin.integrations.msan.specifications'), 'tone' => 'slate'],
        ] as $card)
            @php
                $cardClass = match ($card['tone']) {
                    'cyan' => 'border-cyan-200 bg-cyan-50/70 hover:border-cyan-400',
                    'emerald' => 'border-emerald-200 bg-emerald-50/70 hover:border-emerald-400',
                    'amber' => 'border-amber-300 bg-amber-50 hover:border-amber-500',
                    default => 'border-slate-200 bg-white hover:border-slate-400',
                };
            @endphp
            <a href="{{ $card['url'] }}" class="admin-panel group block p-4 transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-600 {{ $cardClass }}">
                <div class="flex items-start justify-between gap-2"><p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">{{ $card['label'] }}</p><span class="text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-slate-700" aria-hidden="true">→</span></div>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900">{{ number_format($card['value'], 0, ',', '.') }}</p>
            </a>
        @endforeach
    </section>

    <section class="admin-panel admin-form-panel p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-slate-900">{{ __('Sinkronizacija M SAN podataka') }}</h2>
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $ready ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $ready ? __('Spremno') : __('Nije konfigurirano') }}</span>
                </div>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ __('Dohvat radi u pozadini, puni XML čita streamingom i ažurira lokalnu radnu kopiju u paketima. Dohvat sam po sebi ne objavljuje niti uvozi artikle.') }}</p>
                @if (! $enabled)<p class="mt-2 text-xs text-amber-700">{{ __('Integracija je isključena u Postavkama.') }}</p>@endif
                @if ($ready && ! $specificationsEnabled)<p class="mt-2 text-xs text-amber-700">{{ __('Dohvat specifikacija isključen je u Postavkama.') }}</p>@endif
                @if ($ready && $specificationsEnabled && $counts['specification_targets'] === 0)<p class="mt-2 text-xs text-amber-700">{{ __('Za specifikacije najprije odaberite ili uvezite barem jedan M SAN artikl.') }}</p>@endif
                @if ($ready && ! $eprelEnabled)<p class="mt-2 text-xs text-slate-500">{{ __('EPREL dohvat je isključen u Postavkama.') }}</p>@endif
            </div>
            @if ($canSync)
                <div class="flex shrink-0 flex-wrap gap-2 lg:max-w-xl lg:justify-end">
                    <button type="button" wire:click="testConnection" wire:loading.attr="disabled" @disabled(!$ready) class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50">{{ __('Provjeri vezu') }}</button>
                    <button type="button" wire:click="syncPricesAndStock" wire:confirm="{{ __('Pokrenuti osvježavanje cijena i količina za uvezene M SAN artikle?') }}" wire:loading.attr="disabled" @disabled(!$ready || $counts['products'] === 0) class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50">{{ __('Osvježi cijene i količine') }}</button>
                    <button type="button" wire:click="syncSpecifications" wire:loading.attr="disabled" @disabled(!$specificationsReady) class="rounded-xl border border-cyan-300 bg-cyan-50 px-4 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-100 disabled:opacity-50">{{ __('Dohvati specifikacije') }}</button>
                    <button type="button" wire:click="syncEprel" wire:loading.attr="disabled" @disabled(!$eprelReady) class="rounded-xl border border-cyan-300 bg-cyan-50 px-4 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-100 disabled:opacity-50">{{ __('Osvježi EPREL') }}</button>
                    <button type="button" wire:click="syncCatalog" wire:confirm="{{ __('Pokrenuti puni M SAN dohvat u lokalni privremeni katalog?') }}" wire:loading.attr="disabled" wire:target="syncCatalog" @disabled(!$ready) class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:opacity-50"><span wire:loading.remove wire:target="syncCatalog">{{ __('Dohvati katalog') }}</span><span wire:loading wire:target="syncCatalog">{{ __('Pokrećem...') }}</span></button>
                </div>
            @endif
        </div>
    </section>

    @if ($latestRun)
        @php
            $latestKindLabel = match ((string) $latestRun->kind) {
                'full' => __('Puna sinkronizacija'),
                'import' => __('Uvoz'),
                'catalog' => __('Katalog'),
                'prices' => __('Cijene i količine'),
                'availability' => __('Raspoloživost'),
                'categories' => __('Kategorije'),
                'specifications' => __('Tehničke specifikacije'),
                'eprel' => __('EPREL energetski podaci'),
                'connection_test' => __('Provjera B2B veze'),
                'ftp_connection_test' => __('Provjera FTPS veze'),
                default => (string) $latestRun->kind,
            };
            $latestStatusLabel = match ((string) $latestRun->status) {
                'pending' => __('Na čekanju'),
                'running' => __('U tijeku'),
                'completed' => __('Završeno'),
                'failed' => __('Neuspješno'),
                'cancelled' => __('Otkazano'),
                default => (string) $latestRun->status,
            };
        @endphp
        <section class="admin-panel overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4"><h2 class="admin-section-title">{{ __('Posljednje izvršavanje') }}</h2><a href="{{ route('admin.integrations.msan.runs') }}" class="text-xs font-semibold text-cyan-700 hover:text-cyan-900">{{ __('Sva izvršavanja') }} →</a></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-5">
                <div><p class="text-xs uppercase text-slate-500">{{ __('Vrsta') }}</p><p class="mt-1 font-semibold">{{ $latestKindLabel }}</p></div>
                <div><p class="text-xs uppercase text-slate-500">{{ __('Status') }}</p><p class="mt-1 font-semibold">{{ $latestStatusLabel }}</p></div>
                <div><p class="text-xs uppercase text-slate-500">{{ __('Napredak') }}</p><p class="mt-1 font-semibold">{{ $latestRun->progress }}%</p></div>
                <div><p class="text-xs uppercase text-slate-500">{{ __('Obrađeno') }}</p><p class="mt-1 font-semibold">{{ $latestRun->processed_count }} / {{ $latestRun->total_count }}</p></div>
                <div><p class="text-xs uppercase text-slate-500">{{ __('Pokrenuto') }}</p><p class="mt-1 font-semibold">{{ $latestRun->started_at?->format('d.m.Y. H:i') ?? '—' }}</p></div>
            </div>
            <div class="mx-5 mb-5 h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="{{ __('Napredak posljednjeg izvršavanja') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ max(0, min(100, (int) $latestRun->progress)) }}"><div class="h-full rounded-full {{ $latestRun->status === 'failed' ? 'bg-rose-600' : 'bg-cyan-600' }}" style="width: {{ max(0, min(100, (int) $latestRun->progress)) }}%"></div></div>
            @if ($latestRun->error_message)<p class="border-t border-rose-200 bg-rose-50 px-5 py-3 text-sm text-rose-800">{{ $latestRun->error_message }}</p>@endif
        </section>
    @endif

    <section class="admin-panel overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4"><h2 class="admin-section-title">{{ __('Ograničenja M SAN izvora podataka') }}</h2></div>
        <div class="overflow-x-auto">
            <table class="admin-items-table min-w-[46rem] border-0 text-sm">
                <thead><tr><th class="px-4 py-3 text-left">{{ __('Skup podataka') }}</th><th class="px-4 py-3 text-left">{{ __('Zadnji uspjeh') }}</th><th class="px-4 py-3 text-left">{{ __('Sljedeći dopušteni poziv') }}</th><th class="px-4 py-3 text-left">{{ __('Status') }}</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($endpointStates as $state)
                        <tr><td class="px-4 py-3"><div class="font-medium text-slate-800">{{ $endpointLabels[$state->endpoint] ?? $state->endpoint }}</div><div class="mt-0.5 font-mono text-[10px] text-slate-400">{{ $state->endpoint }}</div></td><td class="px-4 py-3">{{ $state->last_success_at?->format('d.m.Y. H:i:s') ?? '—' }}</td><td class="px-4 py-3">{{ $state->next_allowed_at?->format('d.m.Y. H:i:s') ?? __('Odmah') }}</td><td class="px-4 py-3 text-xs {{ $state->last_error ? 'text-rose-700' : 'text-emerald-700' }}">{{ $state->last_error ?: __('U redu') }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">{{ __('Još nema zabilježenih M SAN poziva.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
