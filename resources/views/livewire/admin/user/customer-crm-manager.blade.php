<div class="space-y-6">
    <div class="admin-panel p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">
                    {{ __('CRM i komercijalisti') }}
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ __('Poslovni kontakti evidentiraju se odvojeno od prijava u webshop račun kupca.') }}
                </p>
            </div>
            <a href="{{ route('admin.users', ['visit' => 'unvisited', 'visitSince' => $dateFrom, 'representative' => $representative]) }}" class="rounded-lg border px-3 py-2 text-sm font-semibold">
                {{ __('Neposjećeni kupci') }}
            </a>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-5">
            <label class="text-xs font-semibold text-slate-500">
                {{ __('Evidencija') }}
                <select wire:model.live="source" class="admin-select mt-1 w-full rounded-lg border px-3 py-2 text-sm">
                    <option value="visits">
                        {{ __('Poslovni kontakti') }}
                    </option>
                    <option value="sessions">
                        {{ __('Prijave u račun kupca') }}
                    </option>
                </select>
            </label>
            <label class="text-xs font-semibold text-slate-500">
                {{ __('Komercijalist') }}
                <select wire:model.live="representative" class="admin-select mt-1 w-full rounded-lg border px-3 py-2 text-sm">
                    <option value="">
                        {{ __('Svi dostupni') }}
                    </option>
                    @foreach ($representatives as $rep)
                        <option value="{{ $rep->id }}">
                            {{ $rep->name }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs font-semibold text-slate-500">
                {{ __('Od') }}
                <input type="date" wire:model.live="dateFrom" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
            </label>
            <label class="text-xs font-semibold text-slate-500">
                {{ __('Do') }}
                <input type="date" wire:model.live="dateTo" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
            </label>
            <label class="text-xs font-semibold text-slate-500">
                {{ __('Ishod') }}
                <select wire:model.live="outcome" class="admin-select mt-1 w-full rounded-lg border px-3 py-2 text-sm">
                    <option value="">
                        {{ __('Svi ishodi') }}
                    </option>
                    <option value="order">
                        {{ __('Narudžba') }}
                    </option>
                    @if ($source === 'visits')
                        <option value="quote">
                            {{ __('Ponuda') }}
                        </option>
                    @else
                        <option value="open">
                            {{ __('Bez zabilježenog završetka') }}
                        </option>
                        <option value="unknown">
                            {{ __('Nepotvrđen ishod') }}
                        </option>
                    @endif
                    <option value="nothing">
                        {{ __('Bez narudžbe / ponude') }}
                    </option>
                </select>
            </label>
        </div>
        <label class="mt-3 flex items-center gap-2 text-xs text-slate-500">
            <input type="checkbox" wire:model.live="includePreviews">
            {{ __('Uključi rad administratora u ulozi komercijalista') }}
        </label>
        @foreach (['dateFrom', 'dateTo'] as $field)
            @error($field)
                <p class="mt-1 text-xs text-rose-600">
                    {{ $message }}
                </p>
            @enderror
        @endforeach
        <div class="mt-4 flex flex-wrap gap-3">
            @foreach ($representatives as $rep)
                @php($totals = $summary->where('sales_rep_id', $rep->id))
                @if ($totals->sum('total'))
                    <div class="rounded-lg border border-slate-200 px-3 py-2 text-xs">
                        <strong>
                            {{ $rep->name }}
                        </strong>
                        <div class="mt-1">
                            {{ __('Ukupno') }}: {{ $totals->sum('total') }} · {{ __('Narudžba') }}: {{ $totals->where('outcome', 'order')->sum('total') }} ·
                            @if ($source === 'visits')
                                {{ __('Ponuda') }}: {{ $totals->where('outcome', 'quote')->sum('total') }} ·
                            @endif
                            {{ __('Bez ishoda') }}: {{ $totals->where('outcome', 'nothing')->sum('total') }}
                            @if ($source === 'sessions')
                                · {{ __('Bez završetka') }}: {{ $totals->where('outcome', 'open')->sum('total') }}
                                · {{ __('Nepotvrđen ishod') }}: {{ $totals->where('outcome', 'unknown')->sum('total') }}
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
    @if ($customerId && $canUpdate)
        <form wire:submit="saveVisit" class="admin-panel space-y-3 p-5">
            <h3 class="font-semibold">
                {{ __('Evidentiraj kontakt s kupcem') }}
            </h3>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-sm">
                    {{ __('Datum i vrijeme') }}
                    <input type="datetime-local" wire:model="visit.visited_at" class="mt-1 w-full rounded-lg border px-3 py-2">
                </label>
                <label class="text-sm">
                    {{ __('Vrsta kontakta') }}
                    <select wire:model="visit.contact_type" class="admin-select mt-1 w-full rounded-lg border px-3 py-2">
                        <option value="visit">
                            {{ __('Posjet kupcu') }}
                        </option>
                        <option value="phone">
                            {{ __('Telefonski razgovor') }}
                        </option>
                        <option value="email">
                            {{ __('E-mail kontakt') }}
                        </option>
                    </select>
                </label>
                <label class="text-sm">
                    {{ __('Ishod') }}
                    <select wire:model.live="visit.outcome" class="admin-select mt-1 w-full rounded-lg border px-3 py-2">
                        <option value="nothing">
                            {{ __('Bez narudžbe / ponude') }}
                        </option>
                        <option value="order">
                            {{ __('Narudžba') }}
                        </option>
                        <option value="quote">
                            {{ __('Ponuda') }}
                        </option>
                    </select>
                </label>
            </div>
            @if (($visit['outcome'] ?? '') === 'order')
                <label class="block text-sm">
                    {{ __('Povezana narudžba kupca') }}
                    <select wire:model="visit.order_id" class="admin-select mt-1 w-full rounded-lg border px-3 py-2">
                        <option value="">
                            {{ __('Odaberite narudžbu') }}
                        </option>
                        @foreach ($orders as $order)
                            <option value="{{ $order->id }}">
                                {{ $order->order_number }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endif
            @if (($visit['outcome'] ?? '') === 'quote')
                <label class="block text-sm">
                    {{ __('Broj ili referenca stvarno poslane ponude') }}
                    <input wire:model="visit.quote_reference" class="mt-1 w-full rounded-lg border px-3 py-2">
                </label>
                <p class="text-xs text-slate-500">
                    {{ __('Unesite referencu ponude izrađene u vanjskom sustavu.') }}
                </p>
            @endif
            <label class="block text-sm">
                {{ __('Bilješka') }}
                <textarea wire:model="visit.note" rows="3" class="mt-1 w-full rounded-lg border px-3 py-2">
                </textarea>
            </label>
            @foreach ($errors->getMessages() as $key => $messages)
                @if (str_starts_with($key, 'visit.'))
                    @foreach ($messages as $message)
                        <p class="text-xs text-rose-600">
                            {{ $message }}
                        </p>
                    @endforeach
                @endif
            @endforeach
            <button class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white">
                {{ __('Spremi kontakt') }}
            </button>
        </form>
    @endif
    <div class="admin-panel overflow-x-auto p-5">
        <table class="admin-items-table min-w-full text-sm">
            <thead>
                <tr>
                    <th class="px-3 py-2 text-left">
                        {{ __('Datum') }}
                    </th>
                    <th class="px-3 py-2 text-left">
                        {{ __('Tvrtka') }}
                    </th>
                    <th class="px-3 py-2 text-left">
                        {{ __('Komercijalist') }}
                    </th>
                    <th class="px-3 py-2 text-left">
                        {{ __('Ishod') }}
                    </th>
                    <th class="px-3 py-2 text-left">
                        {{ __('Bilješka / trajanje') }}
                    </th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="px-3 py-3">
                            {{ ($source === 'sessions' ? $row->started_at : $row->visited_at)?->format('d.m.Y. H:i') }}
                            @if ($source === 'visits')
                                <div class="mt-1 text-xs text-slate-500">
                                    {{ __(['visit' => 'Posjet kupcu', 'phone' => 'Telefon', 'email' => 'E-mail'][$row->contact_type] ?? $row->contact_type) }}
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            <a class="font-semibold text-cyan-700" href="{{ route('admin.users.show', $row->customer_user_id) }}">
                                {{ $row->customer?->customerDisplayName() }}
                            </a>
                        </td>
                        <td class="px-3 py-3">
                            {{ $row->salesRep?->name ?: '—' }}
                            @if ($row->performedBy && (int) $row->performed_by_id !== (int) $row->sales_rep_id)
                                <div class="mt-1 text-xs text-slate-500">
                                    {{ __('Unio administrator') }}: {{ $row->performedBy->name }} {{ __('u ulozi komercijalista') }}
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            {{ __(['order' => 'Narudžba', 'quote' => 'Ponuda', 'nothing' => 'Bez narudžbe / ponude', 'open' => 'Bez zabilježenog završetka', 'unknown' => 'Nepotvrđen ishod'][$row->outcome] ?? $row->outcome) }}
                            @if ($row->order && (auth()->user()->isA('superadmin') || auth()->user()->can('sales.orders.view')))
                                <a class="mt-1 block text-xs text-cyan-700" href="{{ route('admin.orders.show', $row->order) }}">
                                    {{ $row->order->order_number }}
                                </a>
                            @endif
                            @if ($source === 'visits' && $row->quote_reference)
                                <div class="mt-1 text-xs">
                                    {{ $row->quote_reference }}
                                </div>
                            @endif
                        </td>
                        <td class="max-w-md whitespace-normal px-3 py-3">
                            @if ($source === 'visits')
                                {{ $row->note }}
                            @else
                                {{ $row->ended_at ? $row->ended_at->format('d.m.Y. H:i') : __('Sesija nije završena') }}
                                @if (is_string(data_get($row->payload, 'opencart.napomena')) && data_get($row->payload, 'opencart.napomena') !== '')
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ data_get($row->payload, 'opencart.napomena') }}
                                    </div>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-8 text-center text-slate-500">
                            {{ __('Nema evidentiranih kontakata za odabrane filtre.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </div>
</div>
