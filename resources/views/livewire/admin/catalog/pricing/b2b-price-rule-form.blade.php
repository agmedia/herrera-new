<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Katalog / B2B cjenici') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $isEdit ? __('Uredi B2B pravilo') : __('Novo B2B pravilo') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('Odredite korisničku grupu ili pojedinog B2B kupca, način izračuna i proizvode na koje se cijena primjenjuje.') }}</p>
            </div>
            <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Natrag na popis') }}</button>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(22rem,0.9fr)]">
            <div class="space-y-6">
                <div class="admin-panel admin-form-panel p-6">
                    <p class="admin-section-title">{{ __('Osnovni podaci') }}</p>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Naziv pravila') }}</label>
                            <input type="text" wire:model="form.name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Šifra pravila') }}</label>
                            <input type="text" wire:model="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono uppercase">
                            @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Cjenik za') }}</label>
                            <select wire:model.live="form.audience_type" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="group">{{ __('Korisničku grupu') }}</option>
                                <option value="customer">{{ __('Pojedinog B2B kupca') }}</option>
                            </select>
                            @error('form.audience_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        @if ($form['audience_type'] === 'customer')
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('B2B kupac') }}</label>
                                <select wire:model="form.user_id" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">{{ __('Odaberite odobrenog kupca') }}</option>
                                    @foreach ($this->customerOptions as $customer)
                                        <option value="{{ $customer->id }}">{{ $customer->b2bAccount?->company_name }} — {{ $customer->name }} ({{ $customer->email }})</option>
                                    @endforeach
                                </select>
                                @error('form.user_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        @else
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Korisnička grupa') }}</label>
                                <select wire:model="form.customer_group_id" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">{{ __('Odaberite grupu') }}</option>
                                    @foreach ($this->customerGroupOptions as $group)
                                        <option value="{{ $group->id }}">{{ $group->name }} ({{ $group->code }})</option>
                                    @endforeach
                                </select>
                                @error('form.customer_group_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Broj ugovora') }}</label>
                            <input type="text" wire:model="form.contract_number" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('Opcionalno') }}">
                            @error('form.contract_number') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Minimalna količina') }}</label>
                            <input type="number" min="1" wire:model="form.minimum_quantity" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @error('form.minimum_quantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="admin-panel admin-form-panel p-6">
                    <p class="admin-section-title">{{ __('Izračun cijene') }}</p>
                    <div class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_7rem]">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Način izračuna') }}</label>
                            <select wire:model.live="form.calculation_type" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                @foreach ($calculationTypeOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('form.calculation_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Vrijednost') }}</label>
                            <input type="number" min="0" step="0.0001" wire:model="form.value" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @error('form.value') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Valuta') }}</label>
                            <input type="text" maxlength="3" wire:model="form.currency_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase">
                        </div>
                    </div>
                    <div class="mt-3 rounded-xl border border-cyan-100 bg-cyan-50 p-3 text-xs leading-5 text-cyan-900">
                        {{ __('Fiksna cijena je dostupna samo za cilj “Proizvodi”. Za kategorije i brendove koristite postotni ili fiksni popust.') }}
                    </div>
                </div>

                <div class="admin-panel admin-form-panel p-6">
                    <p class="admin-section-title">{{ __('Primjena pravila') }}</p>
                    <div class="mt-4">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Primijeni na') }}</label>
                        <select wire:model.live="form.target_type" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($targetTypeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('form.target_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    @if ($form['target_type'] !== \App\Models\Catalog\Pricing\B2BPriceRule::TARGET_ALL)
                        <div class="mt-4 grid gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Pretraži') }}</label>
                                <input type="search" wire:model.live.debounce.300ms="targetSearch" placeholder="{{ __('Naziv, šifra, SKU ili barkod...') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Odabir') }}</label>
                                <select wire:model="form.target_ids" multiple size="10" class="admin-multiselect w-full rounded-xl border border-slate-300 text-sm">
                                    @foreach ($this->targetOptions as $row)
                                        @php
                                            $translation = $row->translations->first();
                                            $label = $translation?->name ?? $row->code;
                                        @endphp
                                        @if ($form['target_type'] === \App\Models\Catalog\Pricing\B2BPriceRule::TARGET_PRODUCT)
                                            <option value="{{ $row->id }}">{{ $label }} [{{ $row->sku ?: $row->code }}]</option>
                                        @elseif ($form['target_type'] === \App\Models\Catalog\Pricing\B2BPriceRule::TARGET_CATEGORY)
                                            <option value="{{ $row->id }}">{{ str_repeat('— ', max(0, (int) ($row->depth ?? 0))).$label }}</option>
                                        @else
                                            <option value="{{ $row->id }}">{{ $label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                @error('form.target_ids') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                @error('form.target_ids.*') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="space-y-6">
                <div class="admin-panel admin-form-panel p-6">
                    <p class="admin-section-title">{{ __('Raspored i prioritet') }}</p>
                    <div class="mt-4 space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Vrijedi od') }}</label>
                            <input type="datetime-local" wire:model="form.starts_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Vrijedi do') }}</label>
                            <input type="datetime-local" wire:model="form.ends_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @error('form.ends_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Prioritet') }}</label>
                            <input type="number" min="0" wire:model="form.priority" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('Kod jednakog targeta prvo se primjenjuje veći prioritet, zatim viši količinski prag.') }}</p>
                        </div>
                    </div>
                </div>

                <div class="admin-panel admin-form-panel p-6">
                    <p class="admin-section-title">{{ __('Status') }}</p>
                    <button type="button" wire:click="$toggle('form.is_active')" class="admin-switch mt-4" data-state="{{ $form['is_active'] ? 'on' : 'off' }}" role="switch" aria-checked="{{ $form['is_active'] ? 'true' : 'false' }}">
                        <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                        <span class="admin-switch-label">{{ $form['is_active'] ? __('Aktivno') : __('Neaktivno') }}</span>
                    </button>
                </div>

                <div class="admin-panel admin-form-panel p-6">
                    <p class="admin-section-title">{{ __('Napredni podaci') }}</p>
                    <div class="mt-4">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Payload JSON') }}</label>
                        <textarea rows="6" wire:model="form.payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                        @error('form.payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-form-actions flex items-center gap-2 pt-2">
            <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ $isEdit ? __('Spremi promjene') : __('Kreiraj pravilo') }}</button>
            <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Odustani') }}</button>
        </div>
    </form>
</div>
