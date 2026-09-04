@php
    $shippingListParameters = array_filter([
        'tab' => 'methods',
        'search' => $search !== '' ? $search : null,
        'page' => $returnPage > 1 ? $returnPage : null,
    ], static fn (int|string|null $value): bool => $value !== null);
@endphp

<div class="space-y-6">
    @unless ($editPage || $createPage)
    <section class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Postavke / Lokalno / Dostava') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Modul dostave') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('Upravljajte zonama, cijenama, težinom, dimenzijama i ograničenjima prijevoznika.') }}</p>
            </div>
            <div class="flex w-full flex-col gap-3 sm:flex-row xl:w-auto xl:items-end">
                <div class="w-full sm:w-72">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Pretraga') }}</label>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Naziv, šifra ili prijevoznik...') }}" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
                </div>
                <a href="{{ route('admin.shipping.create', array_filter([
                    'search' => $search !== '' ? $search : null,
                    'page' => $rows->currentPage() > 1 ? $rows->currentPage() : null,
                ], static fn (int|string|null $value): bool => $value !== null)) }}" class="rounded-xl bg-cyan-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('Nova metoda') }}
                </a>
            </div>
        </div>
    </section>

    <section class="admin-panel border border-cyan-200 bg-cyan-50 p-5">
        <form wire:submit="saveIslandPolicy" class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-800">{{ __('Kopno i otoci') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ __('Kako obračunati otočnu dostavu?') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('Kupac ništa ne odabire. Sustav prema adresi automatski prikazuje MBE tarifu za kopno ili otoke.') }}</p>
            </div>
            <div class="flex w-full flex-col gap-2 sm:flex-row sm:items-end xl:w-auto">
                <div class="w-full sm:w-80">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Pravilo klasifikacije') }}</label>
                    <select wire:model="islandPolicy" class="admin-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                        @foreach ($islandPolicyOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('islandPolicy') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('Spremi pravilo') }}
                </button>
            </div>
        </form>
    </section>

    <section class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="admin-section-title">{{ __('Načini dostave') }}</h2>
            <p class="text-xs text-slate-500">{{ trans_choice(':count stavka|:count stavke|:count stavki', $rows->total(), ['count' => $rows->total()]) }}</p>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-[80rem] text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Način dostave') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Prijevoznik') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Usluga') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Zona') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Cijena') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Ograničenja') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Akcije') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $carrierLabel = $carrierOptions[$row->carrier] ?? $row->carrier;
                            $serviceLabel = $serviceTypeOptions[$row->service_type] ?? $row->service_type;
                            $priceLabel = match ((string) $row->pricing_type) {
                                'free' => __('Besplatno'),
                                'quote' => __('Na upit'),
                                'weight_tiers' => trans_choice(':count raspon|:count raspona|:count raspona', $row->rates_count, ['count' => $row->rates_count]),
                                default => \App\Support\Currency::format((float) $row->price),
                            };
                            $hasDimensions = $row->max_length_cm !== null || $row->max_width_cm !== null || $row->max_height_cm !== null;
                        @endphp
                        <tr wire:key="shipping-method-{{ $row->id }}">
                            <td class="px-3 py-3">
                                <div class="font-semibold text-slate-900">{{ $row->name }}</div>
                                <div class="mt-1 font-mono text-xs text-slate-500">{{ $row->code }}</div>
                            </td>
                            <td class="px-3 py-3 text-slate-700">{{ $carrierLabel }}</td>
                            <td class="px-3 py-3 text-slate-700">{{ $serviceLabel }}</td>
                            <td class="px-3 py-3 text-slate-700">{{ $row->geoZone?->name ?? __('Sve zone') }}</td>
                            <td class="px-3 py-3 text-right font-semibold text-slate-900">{{ $priceLabel }}</td>
                            <td class="px-3 py-3 text-xs text-slate-600">
                                @if ($row->max_weight_kg !== null)
                                    <div>{{ __('Težina do') }} {{ number_format((float) $row->max_weight_kg, 3) }} kg</div>
                                @endif
                                @if ($hasDimensions)
                                    <div @class(['mt-1' => $row->max_weight_kg !== null])>
                                        {{ __('Dimenzije do') }} {{ $row->max_length_cm ?? '∞' }} × {{ $row->max_width_cm ?? '∞' }} × {{ $row->max_height_cm ?? '∞' }} cm
                                    </div>
                                @endif
                                @if ($row->max_weight_kg === null && ! $hasDimensions)
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">
                                    {{ $row->is_active ? __('Aktivno') : __('Neaktivno') }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <div class="inline-flex items-center justify-end gap-1 whitespace-nowrap">
                                    <a href="{{ route('admin.shipping.edit', array_filter([
                                        'shippingMethod' => $row->id,
                                        'search' => $search !== '' ? $search : null,
                                        'page' => $rows->currentPage() > 1 ? $rows->currentPage() : null,
                                    ], static fn (int|string|null $value): bool => $value !== null)) }}" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Uredi') }}</a>
                                    <button type="button" wire:click="toggleActive({{ $row->id }})" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        {{ $row->is_active ? __('Isključi') : __('Uključi') }}
                                    </button>
                                    <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('Obrisati način dostave :name?', ['name' => $row->name]) }}" class="rounded-lg border border-rose-300 px-2.5 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Obriši') }}</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-10 text-center text-sm text-slate-500">{{ __('Nema načina dostave.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $rows->links() }}</div>
    </section>
    @endunless

    @if ($editPage || $createPage)
    <section class="admin-panel admin-form-panel p-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="admin-section-title">{{ $editingId ? __('Uredi način dostave') : __('Novi način dostave') }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ __('Sva pravila provjeravaju se na checkoutu i ponovno prilikom kreiranja narudžbe.') }}</p>
            </div>
            @if ($editPage || $createPage)
                <a href="{{ route('admin.shipping.index', $shippingListParameters) }}" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ $createPage ? __('Odustani') : __('Odustani od uređivanja') }}</a>
            @elseif ($editingId)
                <button type="button" wire:click="create" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Odustani od uređivanja') }}</button>
            @endif
        </div>

        <form wire:submit="save" class="mt-5 space-y-6">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Šifra') }}</label>
                    <input type="text" wire:model="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-sm lowercase">
                    @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-1 xl:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Naziv') }}</label>
                    <input type="text" wire:model="form.name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Redoslijed') }}</label>
                    <input type="number" min="0" wire:model="form.sort_order" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Prijevoznik') }}</label>
                    <select wire:model.live="form.carrier" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($carrierOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Vrsta usluge') }}</label>
                    <select wire:model.live="form.service_type" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($serviceTypeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Način obračuna') }}</label>
                    <select wire:model.live="form.pricing_type" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($pricingTypeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Geo zona') }}</label>
                    <select wire:model="form.geo_zone_id" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="">{{ __('Sve zone') }}</option>
                        @foreach ($geoZones as $zone)<option value="{{ $zone->id }}">{{ $zone->name }}</option>@endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Opis za kupca') }}</label>
                <textarea rows="2" wire:model="form.description" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                @error('form.description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Cijena i vrijednost košarice') }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Fiksna cijena (€)') }}</label>
                            <input type="number" min="0" step="0.01" wire:model="form.price" @disabled(($form['pricing_type'] ?? 'flat') !== 'flat') class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Besplatno iznad (€)') }}</label>
                            <input type="number" min="0" step="0.01" wire:model="form.free_over" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Min. košarica (€)') }}</label>
                            <input type="number" min="0" step="0.01" wire:model="form.min_subtotal" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Maks. košarica (€)') }}</label>
                            <input type="number" min="0" step="0.01" wire:model="form.max_subtotal" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Težina i dimenzije') }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Min. težina (kg)') }}</label>
                            <input type="number" min="0" step="0.001" wire:model="form.min_weight_kg" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Maks. težina (kg)') }}</label>
                            <input type="number" min="0" step="0.001" wire:model="form.max_weight_kg" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        @foreach (['max_length_cm' => 'Maks. duljina (cm)', 'max_width_cm' => 'Maks. širina (cm)', 'max_height_cm' => 'Maks. visina (cm)'] as $field => $label)
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __($label) }}</label>
                                <input type="number" min="0" step="0.01" wire:model="form.{{ $field }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            </div>
                        @endforeach
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Ako mjere nedostaju') }}</label>
                            <select wire:model="form.missing_measurements_policy" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="allow">{{ __('Dopusti dostavu') }}</option>
                                <option value="block">{{ __('Sakrij dostavu') }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Posebna roba') }}</p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    @foreach ([
                        'fragile' => ['label' => 'Lomljivo', 'allow' => 'allows_fragile', 'surcharge' => 'fragile_surcharge'],
                        'oversized' => ['label' => 'Vangabaritno', 'allow' => 'allows_oversized', 'surcharge' => 'oversized_surcharge'],
                        'heavy' => ['label' => 'Teško', 'allow' => 'allows_heavy', 'surcharge' => 'heavy_surcharge'],
                    ] as $type => $config)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <button
                                type="button"
                                wire:click="$toggle('form.{{ $config['allow'] }}')"
                                class="admin-switch"
                                data-state="{{ $form[$config['allow']] ? 'on' : 'off' }}"
                                role="switch"
                                aria-checked="{{ $form[$config['allow']] ? 'true' : 'false' }}"
                            >
                                <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                                <span class="admin-switch-label">{{ __($config['label']) }}: {{ $form[$config['allow']] ? __('dopušteno') : __('blokirano') }}</span>
                            </button>
                            <label class="mt-3 block text-xs font-semibold text-slate-600">{{ __('Doplata (€)') }}</label>
                            <input type="number" min="0" step="0.01" wire:model="form.{{ $config['surcharge'] }}" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                    @endforeach
                </div>
            </div>

            @if (($form['carrier'] ?? '') === 'boxnow')
                <div class="rounded-2xl border border-cyan-200 bg-cyan-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-800">BOX NOW</p>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Partner ID') }}</label>
                            <input type="text" wire:model="form.boxnow_partner_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-sm">
                            @error('form.boxnow_partner_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Warehouse ID') }}</label>
                            <input type="text" wire:model="form.boxnow_warehouse_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-sm">
                            @error('form.boxnow_warehouse_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('API URL') }}</label>
                            <input type="url" wire:model="form.boxnow_api_url" placeholder="https://api-production.boxnow.hr" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-sm">
                            @error('form.boxnow_api_url') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('OAuth Client ID') }}</label>
                            <input type="text" wire:model="form.boxnow_oauth_client_id" autocomplete="off" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-sm">
                            @error('form.boxnow_oauth_client_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <div class="mb-1 flex items-center justify-between gap-2">
                                <label class="block text-xs font-semibold text-slate-600">{{ __('OAuth Client Secret') }}</label>
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $boxNowOauthClientSecretStored ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">
                                    {{ $boxNowOauthClientSecretStored ? __('Spremljeno') : __('Nije spremljeno') }}
                                </span>
                            </div>
                            <input
                                type="password"
                                wire:model="form.boxnow_oauth_client_secret"
                                autocomplete="new-password"
                                placeholder="{{ $boxNowOauthClientSecretStored ? __('Ostavite prazno za zadržavanje postojeće tajne') : '' }}"
                                class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-sm"
                            >
                            @error('form.boxnow_oauth_client_secret') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endif

            @if (($form['pricing_type'] ?? '') === 'weight_tiers')
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Cjenik prema težini') }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Rasponi se ne smiju preklapati. Prazan maksimum znači bez gornje granice.') }}</p>
                        </div>
                        <button type="button" wire:click="addRate" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Dodaj raspon') }}</button>
                    </div>
                    @error('rates') <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    <div class="mt-4 space-y-2">
                        @foreach ($rates as $index => $rate)
                            <div wire:key="shipping-rate-{{ $rate['id'] ?? 'new-'.$index }}" class="grid items-end gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Od kg') }}</label>
                                    <input type="number" min="0" step="0.001" wire:model="rates.{{ $index }}.min_weight_kg" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Do kg') }}</label>
                                    <input type="number" min="0" step="0.001" wire:model="rates.{{ $index }}.max_weight_kg" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    @error('rates.'.$index.'.max_weight_kg') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Cijena (€)') }}</label>
                                    <input type="number" min="0" step="0.01" wire:model="rates.{{ $index }}.price" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <button type="button" wire:click="removeRate({{ $index }})" class="rounded-lg border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Ukloni') }}</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                <button
                    type="button"
                    wire:click="$toggle('form.is_active')"
                    class="admin-switch"
                    data-state="{{ $form['is_active'] ? 'on' : 'off' }}"
                    role="switch"
                    aria-checked="{{ $form['is_active'] ? 'true' : 'false' }}"
                >
                    <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                    <span class="admin-switch-label">{{ $form['is_active'] ? __('Aktivno') : __('Neaktivno') }}</span>
                </button>
                <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ $editingId ? __('Spremi promjene') : __('Kreiraj metodu') }}
                </button>
            </div>
        </form>
    </section>
    @endif
</div>
