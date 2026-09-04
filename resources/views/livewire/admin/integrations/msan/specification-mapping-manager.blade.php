<div class="space-y-6">
    @unless ($editPage)
    <section class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('M SAN katalog') }}</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">{{ __('Pravila specifikacija') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">
                    {{ __('Odredite koje se M SAN specifikacije uvoze, koje se koriste kao filtri te kako se njihove oznake prikazuju u webshopu.') }}
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ __('Stavki po stranici') }}: <span class="admin-chip">{{ $perPage }}</span>
                </p>
            </div>

            <div class="w-full space-y-3 xl:max-w-5xl">
                <form wire:submit="applySearch" class="flex gap-2">
                    <div class="min-w-0 flex-1">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-specification-search">
                            {{ __('Pretraga') }}
                        </label>
                        <input
                            id="msan-specification-search"
                            type="search"
                            wire:model="searchInput"
                            minlength="2"
                            maxlength="120"
                            placeholder="{{ __('Početak grupe, naziva ili izvorne šifre...') }}"
                            class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                        >
                        @error('searchInput') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end">
                        <button type="submit" wire:loading.attr="disabled" wire:target="applySearch" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-wait disabled:opacity-60">
                            {{ __('Traži') }}
                        </button>
                    </div>
                </form>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-specification-import-state">{{ __('Uvoz') }}</label>
                        <select id="msan-specification-import-state" wire:model.live="importState" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="all">{{ __('Sve specifikacije') }}</option>
                            <option value="enabled">{{ __('Uvoz uključen') }}</option>
                            <option value="disabled">{{ __('Uvoz isključen') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-specification-role">{{ __('Namjena podatka') }}</label>
                        <select id="msan-specification-role" wire:model.live="role" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="all">{{ __('Sve namjene') }}</option>
                            @foreach ($roleOptions as $roleValue => $roleLabel)
                                <option value="{{ $roleValue }}">{{ __($roleLabel) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500" for="msan-specification-stale-state">{{ __('Aktualnost') }}</label>
                        <select id="msan-specification-stale-state" wire:model.live="staleState" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="current">{{ __('Samo aktualne') }}</option>
                            <option value="all">{{ __('Aktualne i zastarjele') }}</option>
                            <option value="stale">{{ __('Samo zastarjele') }}</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="clearFilters" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        {{ __('Očisti filtre') }}
                    </button>
                </div>
            </div>
        </div>
    </section>
    @endunless

    @if ($editingDefinitionId && $canManageMapping)
        <section class="admin-panel admin-form-panel p-6" wire:key="msan-specification-editor-{{ $editingDefinitionId }}">
            <form wire:submit="saveDefinition" class="space-y-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-800">{{ __('Uredi pravilo specifikacije') }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ $editingDefinitionLabel }}</h3>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Prazna prikazna oznaka zadržava izvornu M SAN vrijednost.') }}</p>
                    </div>
                    <div class="flex gap-2">
                        @if ($editPage)
                            <a href="{{ route('admin.integrations.msan.specifications') }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Odustani') }}</a>
                        @else
                            <button type="button" wire:click="cancelEdit" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Odustani') }}</button>
                        @endif
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveDefinition" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-wait disabled:opacity-60">{{ __('Spremi pravilo') }}</button>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-display-group">{{ __('Prikazni naziv grupe') }}</label>
                        <input id="msan-display-group" type="text" maxlength="255" wire:model="displayGroupName" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @error('displayGroupName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-display-item">{{ __('Prikazni naziv stavke') }}</label>
                        <input id="msan-display-item" type="text" maxlength="255" wire:model="displayItemName" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @error('displayItemName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-display-measure">{{ __('Prikazna mjerna jedinica') }}</label>
                        <input id="msan-display-measure" type="text" maxlength="100" wire:model="displayMeasure" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @error('displayMeasure') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600" for="msan-data-role">{{ __('Namjena podatka') }}</label>
                        <select id="msan-data-role" wire:model="dataRole" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($roleOptions as $roleValue => $roleLabel)
                                <option value="{{ $roleValue }}">{{ __($roleLabel) }}</option>
                            @endforeach
                        </select>
                        @error('dataRole') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <strong class="text-sm text-slate-900">{{ __('Uvezi podatak') }}</strong>
                                <p class="mt-1 text-xs text-slate-600">{{ __('Isključeni podatak neće se objaviti na artiklu.') }}</p>
                            </div>
                            <button type="button" wire:click="$toggle('importEnabled')" class="admin-switch" data-state="{{ $importEnabled ? 'on' : 'off' }}" role="switch" aria-checked="{{ $importEnabled ? 'true' : 'false' }}">
                                <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                                <span class="sr-only">{{ __('Uvezi podatak') }}</span>
                            </button>
                        </div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <strong class="text-sm text-slate-900">{{ __('Koristi kao filtar') }}</strong>
                                <p class="mt-1 text-xs text-slate-600">{{ __('Vrijednosti se pretvaraju u filtrirajuća svojstva artikla.') }}</p>
                            </div>
                            <button type="button" wire:click="$toggle('useAsFilter')" class="admin-switch" data-state="{{ $useAsFilter ? 'on' : 'off' }}" role="switch" aria-checked="{{ $useAsFilter ? 'true' : 'false' }}">
                                <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                                <span class="sr-only">{{ __('Koristi kao filtar') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </section>
    @endif

    @unless ($editPage)
    <section class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="admin-section-title">{{ __('M SAN specifikacije') }}</h2>
            <p class="text-xs text-slate-500">
                {{ __('Prikazano :from–:to od :total', [
                    'from' => $definitions->firstItem() ?? 0,
                    'to' => $definitions->lastItem() ?? 0,
                    'total' => $definitions->total(),
                ]) }}
            </p>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-[88rem] text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Izvorna specifikacija') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Prikaz u webshopu') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Primjeri vrijednosti') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Artikli') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Uvoz i filtar') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Namjena') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Zadnje viđeno') }}</th>
                        @if ($canManageMapping)
                            <th class="px-3 py-2 text-right font-semibold">{{ __('Radnje') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($definitions as $definition)
                        @php
                            $samples = collect($definition->sample_values ?? [])->filter(fn ($value) => is_scalar($value))->take(3);
                            $displayGroup = $definition->display_group_name ?: $definition->group_name;
                            $displayItem = $definition->display_item_name ?: $definition->item_name;
                            $displayUnit = $definition->display_measure ?: $definition->measure;
                        @endphp
                        <tr wire:key="msan-specification-row-{{ $definition->id }}" @class(['bg-cyan-50/60' => $editingDefinitionId === $definition->id])>
                            <td class="px-3 py-3">
                                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $definition->group_name }}</div>
                                <div class="mt-1 font-semibold text-slate-900">{{ $definition->item_name }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $definition->measure ?: __('Bez mjerne jedinice') }}</div>
                                <div class="mt-1 font-mono text-[10px] text-slate-400">{{ $definition->source_key }}</div>
                            </td>
                            <td class="px-3 py-3 text-slate-700">
                                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $displayGroup }}</div>
                                <div class="mt-1 font-medium">{{ $displayItem }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $displayUnit ?: __('Bez mjerne jedinice') }}</div>
                            </td>
                            <td class="px-3 py-3 text-xs text-slate-600">
                                @forelse ($samples as $sample)
                                    <span class="mb-1 mr-1 inline-flex max-w-52 rounded-lg bg-slate-100 px-2 py-1" title="{{ (string) $sample }}">{{ \Illuminate\Support\Str::limit((string) $sample, 45) }}</span>
                                @empty
                                    <span class="text-slate-400">—</span>
                                @endforelse
                            </td>
                            <td class="px-3 py-3 text-right font-semibold tabular-nums text-slate-700">{{ number_format((int) $definition->product_count, 0, ',', '.') }}</td>
                            <td class="px-3 py-3 text-xs">
                                <div class="font-semibold {{ $definition->import_enabled ? 'text-emerald-700' : 'text-slate-500' }}">{{ $definition->import_enabled ? __('Uvoz uključen') : __('Uvoz isključen') }}</div>
                                <div class="mt-1 {{ $definition->use_as_filter ? 'text-cyan-700' : 'text-slate-500' }}">{{ $definition->use_as_filter ? __('Koristi se kao filtar') : __('Ne koristi se kao filtar') }}</div>
                                @if ($definition->source_for_filter)
                                    <div class="mt-1 text-[11px] text-slate-400">{{ __('M SAN predlaže filtar') }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-xs text-slate-700">{{ __($roleOptions[$definition->data_role] ?? $definition->data_role) }}</td>
                            <td class="px-3 py-3 text-xs text-slate-600">
                                <div>{{ $definition->last_seen_at?->format('d.m.Y. H:i') ?? '—' }}</div>
                                @if ($definition->is_stale)
                                    <span class="mt-1 inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700">{{ __('Zastarjelo') }}</span>
                                @else
                                    <span class="mt-1 inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">{{ __('Aktualno') }}</span>
                                @endif
                            </td>
                            @if ($canManageMapping)
                                <td class="px-3 py-3 text-right">
                                    <a href="{{ route('admin.integrations.msan.specifications.edit', ['definition' => $definition->id]) }}" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Uredi') }}</a>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canManageMapping ? 8 : 7 }}" class="px-3 py-10 text-center text-sm text-slate-500">
                                {{ __('Nema M SAN specifikacija prema odabranim filtrima. Najprije pokrenite dohvat specifikacija.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $definitions->links() }}</div>
    </section>
    @endunless
</div>
