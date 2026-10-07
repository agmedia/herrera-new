<section id="b2b-profile" class="admin-panel admin-form-panel mt-6 p-6" data-b2b-user-profile-editor>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="admin-section-title">{{ __('B2B poslovni profil') }}</h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ __('Profil tvrtke i grupa kupaca ne odobravaju B2B pristup automatski. Za cijene i brzu narudžbu odaberite status Odobreno i aktivnu primarnu cjenovnu grupu, pa spremite ovaj profil.') }}</p>
            @if (! $account)
                <p class="mt-2 text-xs text-slate-500">{{ __('Podaci su preuzeti iz spremljenog profila i adrese korisnika. B2B profil spremate zasebnim gumbom ispod.') }}</p>
            @endif
        </div>
        @if ($account)
            <a href="{{ route('admin.users.b2b', ['account' => $account->id]) }}" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">{{ __('Otvori B2B račun') }}</a>
        @endif
    </div>

    <div class="mt-4 border {{ $accessAvailable ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900' }} p-3 text-sm" data-b2b-access-status>
        @if ($accessAvailable)
            {{ __('B2B pristup je aktivan: cijene i Brza narudžba dostupni su korisniku nakon prijave.') }}
        @elseif ($account)
            {{ __('B2B profil postoji, ali pristup nije aktivan. Provjerite status, primarnu grupu i datume ugovora.') }}
        @else
            {{ __('Korisnik još nema B2B poslovni profil. Kreiranje sa statusom Na čekanju ne omogućuje cijene ni naručivanje.') }}
        @endif
    </div>

    @if ($savedMessage)
        <p class="mt-4 text-sm font-semibold text-emerald-700" role="status">{{ $savedMessage }}</p>
    @endif
    @if (! $canUpdate)
        <p class="mt-4 text-sm text-slate-600">{{ __('Imate pravo pregleda, ali ne i pravo promjene B2B profila.') }}</p>
    @endif
    @if (! $saveAvailable)
        <p class="mt-4 text-sm text-slate-600" role="status">{{ __('Spremanje B2B profila se još priprema. Pokušajte nakon osvježavanja.') }}</p>
    @endif

    <form wire:submit="save" class="mt-5 space-y-5">
        <fieldset @disabled(! $canUpdate) class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div>
                <label for="b2b-status-{{ $userId }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Status') }} *</label>
                <select id="b2b-status-{{ $userId }}" wire:model.live="b2b.status" class="admin-select w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
                @error('b2b.status') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="b2b-group-{{ $userId }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Primarna cjenovna grupa') }}{{ $b2b['status'] === \App\Models\User\B2BAccount::STATUS_APPROVED ? ' *' : '' }}</label>
                <select id="b2b-group-{{ $userId }}" wire:model="b2b.customer_group_id" class="admin-select w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">{{ __('Odaberite aktivnu grupu') }}</option>
                    @if ($account?->customerGroup && ! $account->customerGroup->is_active)
                        <option value="{{ $account->customer_group_id }}" disabled>{{ $account->customerGroup->name }} — {{ __('neaktivna') }}</option>
                    @endif
                    @foreach ($customerGroups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }} ({{ $group->code }})</option>
                    @endforeach
                </select>
                @error('b2b.customer_group_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            @foreach ([
                'company_name' => ['Naziv tvrtke', 'text', true],
                'oib' => ['OIB', 'text', true],
                'vat_id' => ['PDV ID', 'text', false],
                'phone' => ['Telefon', 'tel', false],
                'address_line_1' => ['Adresa', 'text', false],
                'address_line_2' => ['Dodatak adresi', 'text', false],
                'postal_code' => ['Poštanski broj', 'text', false],
                'city' => ['Grad', 'text', false],
                'country_code' => ['Država (oznaka)', 'text', true],
                'contract_number' => ['Broj ugovora', 'text', false],
                'contract_starts_at' => ['Ugovor vrijedi od', 'date', false],
                'contract_ends_at' => ['Ugovor vrijedi do', 'date', false],
                'payment_terms_days' => ['Rok plaćanja (dana)', 'number', false],
                'erp_customer_id' => ['ERP kupac ID', 'text', false],
                'erp_company_code' => ['ERP oznaka tvrtke', 'text', false],
            ] as $field => [$label, $type, $required])
                <div>
                    <label for="b2b-{{ $field }}-{{ $userId }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($label) }}{{ $required ? ' *' : '' }}</label>
                    <input id="b2b-{{ $field }}-{{ $userId }}" type="{{ $type }}" wire:model="b2b.{{ $field }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" @if ($field === 'oib') inputmode="numeric" maxlength="11" @endif @if ($field === 'country_code') maxlength="2" @endif @if ($field === 'payment_terms_days') min="0" max="365" @endif />
                    @error('b2b.'.$field) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            @endforeach
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" wire:model="b2b.purchase_order_required" class="rounded border-slate-300" />
                {{ __('Obvezan broj narudžbenice') }}
            </label>
            <div class="md:col-span-2 xl:col-span-3">
                <label for="b2b-reason-{{ $userId }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Napomena o statusu') }}</label>
                <textarea id="b2b-reason-{{ $userId }}" wire:model="b2b.status_reason" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                @error('b2b.status_reason') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </fieldset>
        @if ($canUpdate)
            <button type="submit" @disabled(! $saveAvailable) wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ $account ? __('Spremi B2B profil') : __('Kreiraj B2B profil') }}</button>
        @endif
    </form>
</section>
