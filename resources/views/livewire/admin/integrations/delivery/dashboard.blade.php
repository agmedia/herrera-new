<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Rokovi isporuke dobavljača</h1>
        <p class="mt-2 text-sm text-slate-600">Procjena = čekanje do sljedećeg naručivanja + rok dobavljača + 2 kalendarska dana za dostavu kupcu. Od 20.12. do 8.1. dodaje se 5 dana dobavljačkom roku. Za preuzimanje u skladištu nema dodatna 2 dana.</p>
        <p class="mt-2 text-sm text-slate-600">Početni raspored preuzet je iz potvrđenog PDF-a od 9.11.2026. Promjene u izvornoj tablici ovdje unesite ručno. Na dan naručivanja računa se isti dan; ne koristi se dnevni sat presjeka. Ručni datum vrijedi jednom; istekom se bez ponavljajućeg rasporeda prikazuje „na upit”.</p>
    </div>
    @if ($errors->any())<div role="alert" class="rounded bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
    <div class="space-y-3">
        @foreach ($schedules as $schedule)
            <form wire:submit="save({{ $schedule->id }})" wire:key="delivery-{{ $schedule->id }}" class="rounded-xl border border-slate-200 bg-white p-4">
                <h2 class="font-semibold">{{ $schedule->name }} <span class="text-xs font-normal text-slate-500">{{ $schedule->supplier }}</span></h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="text-sm">Dani u mjesecu<input type="text" wire:model="forms.{{ $schedule->id }}.days" placeholder="1, 15" class="mt-1 w-full rounded border-slate-300"></label>
                    <label class="text-sm">Dan u tjednu<select wire:model="forms.{{ $schedule->id }}.weekday" class="mt-1 w-full rounded border-slate-300"><option value="">Bez tjednog termina</option>@foreach ([1=>'Ponedjeljak',2=>'Utorak',3=>'Srijeda',4=>'Četvrtak',5=>'Petak',6=>'Subota',7=>'Nedjelja'] as $day=>$label)<option value="{{ $day }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="text-sm">Sljedeći ručni termin<input type="date" wire:model="forms.{{ $schedule->id }}.next_order_date" class="mt-1 w-full rounded border-slate-300"></label>
                    <label class="text-sm">Rok dobavljača (dana)<input type="number" min="0" max="365" wire:model="forms.{{ $schedule->id }}.lead_days" class="mt-1 w-full rounded border-slate-300"></label>
                    <label class="text-sm sm:col-span-2">Nazivi brendova / alternativni nazivi, odvojeni zarezom<input type="text" wire:model="forms.{{ $schedule->id }}.aliases" class="mt-1 w-full rounded border-slate-300"></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="forms.{{ $schedule->id }}.is_active">Aktivno</label>
                    <button type="submit" class="self-end rounded bg-slate-900 px-4 py-2 text-sm text-white" wire:loading.attr="disabled">Spremi</button>
                </div>
            </form>
        @endforeach
    </div>
    <form wire:submit="create" class="rounded-xl border border-slate-200 bg-white p-4">
        <h2 class="font-semibold">Novi dobavljač</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <label class="text-sm">Oznaka (npr. knauf)<input wire:model="newSupplier" required class="mt-1 w-full rounded border-slate-300"></label>
            <label class="text-sm">Naziv<input wire:model="newName" required class="mt-1 w-full rounded border-slate-300"></label>
            <button class="self-end rounded bg-slate-900 px-4 py-2 text-sm text-white">Dodaj raspored</button>
        </div>
    </form>
</div>
