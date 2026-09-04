<div class="admin-stack">
    <div class="admin-panel admin-search-panel p-6">
        <p class="admin-section-title">Prodaja / zakonske obveze</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">Postavke jednostranog raskida ugovora</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
            Potvrda primitka uvijek se šalje korisniku na adresu unesenu u obrazac, a zasebna obavijest šalje se administratoru.
        </p>
    </div>

    <form wire:submit="save" class="admin-panel admin-form-panel space-y-5 p-6">
        <div>
            <label for="withdrawal-admin-email" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                E-mail administratora
            </label>
            <input id="withdrawal-admin-email" type="email" wire:model="adminEmail" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="webshop@termol.hr">
            <p class="mt-1 text-xs text-slate-500">Na ovu adresu stiže svaki novi zahtjev za raskid ugovora.</p>
            @error('adminEmail') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="withdrawal-return-address" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                Adresa za povrat robe
            </label>
            <textarea id="withdrawal-return-address" wire:model="returnAddress" rows="3" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="TERMOL d.o.o., Lapovačka 11A, 32100 Vinkovci"></textarea>
            <p class="mt-1 text-xs text-slate-500">Prikazuje se u potvrdi koju korisnik dobiva na trajnom mediju.</p>
            @error('returnAddress') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="withdrawal-instructions" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                Dodatne upute korisniku
            </label>
            <textarea id="withdrawal-instructions" wire:model="instructions" rows="7" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" placeholder="Upute za pakiranje, označavanje i slanje robe..."></textarea>
            <p class="mt-1 text-xs text-slate-500">Ne unosite uvjete koji ograničavaju zakonsko pravo potrošača.</p>
            @error('instructions') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            Opće SMTP postavke i pošiljatelj uređuju se u
            <a href="{{ route('admin.settings.system.store-settings') }}" class="font-semibold underline">Postavke trgovine / Email</a>.
        </div>

        <div class="flex justify-end">
            <button type="submit" class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                Spremi postavke
            </button>
        </div>
    </form>
</div>
