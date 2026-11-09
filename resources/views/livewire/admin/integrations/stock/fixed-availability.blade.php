<section class="admin-panel p-5 sm:p-6">
    <h2 class="admin-section-title">Dobavljači bez XML-a — fiksna raspoloživost</h2>
    <p class="mt-2 text-sm text-slate-600">Za izričito odabrane dobavljače bez XML sinkronizacije (npr. Nopallux, as-Schwabe, Knauf) postavite 100.000 kom. Vlastita zaliha ostaje sačuvana. Automatski XML dohvat i narudžbe ne mijenjaju ovu dobavljačku količinu. Novi artikli odabranog dobavljača dobivaju isto pravilo.</p>
    <label class="mt-4 block text-xs font-semibold text-slate-600">Odaberite samo dobavljače bez XML-a
        <select multiple wire:model.live="manufacturerIds" class="mt-1 block min-h-36 w-full rounded-xl border-slate-300 text-sm">
            @foreach ($manufacturers as $manufacturer)
                <option value="{{ $manufacturer->id }}">{{ $manufacturer->translations->firstWhere('locale', 'hr')?->name ?: $manufacturer->code }}</option>
            @endforeach
        </select>
    </label>
    @error('ids')<p class="mt-2 text-sm text-rose-700">{{ $message }}</p>@enderror
    <button type="button" wire:click="preview" wire:loading.attr="disabled" class="mt-3 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold">Pregled prije spremanja</button>
    @if ($preview !== null)
        <p class="mt-3 text-sm text-slate-700">Obuhvaćeno: {{ $preview['products'] }} artikala. Sačuvano artikala povezanih s XML-om: {{ $preview['synchronized_preserved'] }}.</p>
        <button type="button" wire:click="apply" wire:confirm="Spremiti fiksnu dobavljačku raspoloživost 100.000 kom za pregledani odabir?" wire:loading.attr="disabled" class="mt-3 rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">Spremi pregledano pravilo</button>
    @endif
    @if ($notice !== '')<p role="status" class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{{ $notice }}</p>@endif
    <p class="mt-3 text-xs text-slate-500">Povremeni stvarni izvještaj dobavljača može se uvesti privatnim CSV-om sku,quantity uz pregled prije spremanja. Uvoz mijenja isključivo odabranu dobavljačku količinu, bez XML ažuriranja.</p>
</section>
