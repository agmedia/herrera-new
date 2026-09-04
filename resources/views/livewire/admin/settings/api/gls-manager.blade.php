<div class="space-y-6">
    <section class="admin-panel admin-search-panel p-6">
        <h1 class="text-xl font-semibold tracking-tight">{{ __('GLS integracija') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('MyGLS Croatia slanje pošiljki i generiranje PDF naljepnica iz narudžbe.') }}</p>
        <p class="mt-2 text-xs text-slate-500">{{ __('Lozinka se sprema šifrirano. Test i produkcijski endpoint biraju se automatski prema modu.') }}</p>
    </section>

    <section class="admin-panel admin-form-panel p-6">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-3 md:grid-cols-3">
                @foreach ([
                    'gls_api_enabled' => ['Uključi GLS API', 'Omogućava slanje pošiljke i preuzimanje naljepnice iz narudžbe.'],
                    'gls_api_verify_tls' => ['Provjera TLS certifikata', 'Ostavite uključeno u testnom i produkcijskom radu.'],
                    'gls_api_show_print_dialog' => ['GLS print dijalog', 'Najčešće ostaje isključeno za serverski PDF.'],
                ] as $field => [$label, $description])
                    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <strong class="block text-sm text-slate-900">{{ __($label) }}</strong>
                                <p class="mt-1 text-xs text-slate-600">{{ __($description) }}</p>
                            </div>
                            <button
                                type="button"
                                wire:click="$toggle('form.{{ $field }}')"
                                class="admin-switch"
                                data-state="{{ ($form[$field] ?? false) ? 'on' : 'off' }}"
                                role="switch"
                                aria-checked="{{ ($form[$field] ?? false) ? 'true' : 'false' }}"
                            >
                                <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                                <span class="admin-switch-label">{{ ($form[$field] ?? false) ? __('On') : __('Off') }}</span>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-3 md:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Mod') }}</label>
                    <select wire:model.live="form.gls_api_mode" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="test">{{ __('Test') }}</option>
                        <option value="live">{{ __('Produkcija') }}</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('MyGLS korisničko ime') }}</label>
                    <input type="text" wire:model="form.gls_api_username" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Broj klijenta') }}</label>
                    <input type="text" wire:model="form.gls_api_client_number" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('MyGLS lozinka') }}</label>
                <input type="password" wire:model="form.gls_api_password" autocomplete="new-password" placeholder="{{ $passwordConfigured ? __('Lozinka je spremljena — ostavite prazno za zadržavanje') : __('Unesite GLS lozinku') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-slate-500">{{ $passwordConfigured ? __('Lozinka je sigurno spremljena.') : __('Lozinka još nije spremljena.') }}</p>
                @error('form.gls_api_password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                <strong>{{ __('Aktivni endpoint') }}:</strong> <span class="break-all font-mono">{{ $endpoint }}</span>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Adresa preuzimanja') }}</p>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Naziv pošiljatelja') }}</label><input type="text" wire:model="form.gls_api_pickup_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Kontakt osoba') }}</label><input type="text" wire:model="form.gls_api_pickup_contact_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Telefon') }}</label><input type="text" wire:model="form.gls_api_pickup_contact_phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('E-mail') }}</label><input type="email" wire:model="form.gls_api_pickup_contact_email" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                    <div class="md:col-span-2"><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Ulica i kućni broj') }}</label><input type="text" wire:model="form.gls_api_pickup_street" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Dodatak adresi') }}</label><input type="text" wire:model="form.gls_api_pickup_address_line_2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Država') }}</label><input type="text" maxlength="2" wire:model="form.gls_api_pickup_country_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase"></div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Grad') }}</label><input type="text" wire:model="form.gls_api_pickup_city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                    <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Poštanski broj') }}</label><input type="text" wire:model="form.gls_api_pickup_postal_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Format pisača') }}</label><input type="text" wire:model="form.gls_api_printer_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
                <div><label class="mb-1 block text-xs font-semibold text-slate-600">{{ __('Pozicija ispisa') }}</label><input type="number" min="1" max="4" wire:model="form.gls_api_print_position" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></div>
            </div>

            <div class="flex justify-end border-t border-slate-200 pt-4">
                <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('Spremi GLS postavke') }}</button>
            </div>
        </form>
    </section>
</div>
