<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Korisnici / B2B') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ __('B2B zahtjevi i računi') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Provjerite tvrtku, odobrite pristup, dodijelite cjenovnu grupu i unesite ugovorne ili ERP identifikatore.') }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-900">
                <strong>{{ __('ERP povezivanje nije aktivno.') }}</strong>
                {{ __('ERP ID možete spremiti sada, a sinkronizacija se dodaje nakon dostave dokumentacije.') }}
            </div>
        </div>

        <div class="mt-6 grid gap-3 md:grid-cols-[minmax(18rem,1fr)_15rem]">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Pretraga') }}</label>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Tvrtka, OIB, korisnik, e-mail ili ERP ID...') }}" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Status') }}</label>
                <select wire:model.live="statusFilter" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="all">{{ __('Svi statusi') }}</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500">{{ __('Stavki po stranici') }}: <span class="admin-chip">{{ $perPage }}</span></p>
    </div>

    <div class="grid items-start gap-6 {{ $selectedId ? 'xl:grid-cols-[minmax(0,1.1fr)_minmax(26rem,0.9fr)]' : '' }}">
        <div class="admin-panel admin-panel-soft overflow-hidden">
            <div class="overflow-x-auto">
                <table class="admin-items-table min-w-full text-sm">
                    <thead class="text-slate-600">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Tvrtka') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Kontakt') }}</th>
                            <th class="px-4 py-3 text-left font-semibold">{{ __('Grupa / ugovor') }}</th>
                            <th class="px-4 py-3 text-center font-semibold">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right font-semibold">{{ __('Akcija') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($rows as $row)
                            @php
                                $statusClasses = match ($row->status) {
                                    \App\Models\User\B2BAccount::STATUS_APPROVED => 'bg-emerald-100 text-emerald-800',
                                    \App\Models\User\B2BAccount::STATUS_REJECTED => 'bg-rose-100 text-rose-800',
                                    \App\Models\User\B2BAccount::STATUS_SUSPENDED => 'bg-slate-200 text-slate-700',
                                    default => 'bg-amber-100 text-amber-800',
                                };
                            @endphp
                            <tr class="{{ $selectedId === $row->id ? 'bg-cyan-50/70' : '' }}">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-900">{{ $row->company_name }}</div>
                                    <div class="mt-0.5 text-xs text-slate-500">OIB {{ $row->oib }}</div>
                                    @if ($row->erp_customer_id)
                                        <div class="mt-0.5 font-mono text-xs text-cyan-700">ERP {{ $row->erp_customer_id }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    <div class="font-medium">{{ $row->user?->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $row->user?->email }}</div>
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    <div>{{ $row->customerGroup?->name ?? '—' }}</div>
                                    <div class="text-xs text-slate-500">{{ $row->contract_number ?: __('Bez broja ugovora') }}</div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">{{ __($statusOptions[$row->status] ?? $row->status) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" wire:click="selectAccount({{ $row->id }})" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        {{ __('Otvori') }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-12 text-center text-slate-500">{{ __('Nema B2B zahtjeva za odabrane filtre.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-4">{{ $rows->links() }}</div>
        </div>

        @if ($selectedId)
            <form wire:submit="save" class="admin-panel admin-form-panel space-y-5 p-6 xl:sticky xl:top-20">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="admin-section-title">{{ __('Obrada B2B računa') }}</p>
                        <p class="mt-1 text-xs text-slate-500">#{{ $selectedId }}</p>
                    </div>
                    <button type="button" wire:click="closeEditor" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Zatvori') }}</button>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Status') }}</label>
                        <select wire:model.live="form.status" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Cjenovna grupa') }}</label>
                        <select wire:model="form.customer_group_id" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="">{{ __('Bez grupe') }}</option>
                            @foreach ($customerGroups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }} ({{ $group->code }})</option>
                            @endforeach
                        </select>
                        @error('form.customer_group_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Naziv tvrtke') }}</label>
                        <input type="text" wire:model="form.company_name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @error('form.company_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('OIB') }}</label>
                        <input type="text" wire:model="form.oib" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @error('form.oib') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('PDV ID') }}</label>
                        <input type="text" wire:model="form.vat_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Telefon') }}</label>
                        <input type="text" wire:model="form.phone" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Država') }}</label>
                        <input type="text" maxlength="2" wire:model="form.country_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Adresa') }}</label>
                        <input type="text" wire:model="form.address_line_1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Poštanski broj') }}</label>
                        <input type="text" wire:model="form.postal_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Grad') }}</label>
                        <input type="text" wire:model="form.city" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>

                <div class="border-t border-slate-200 pt-5">
                    <p class="admin-section-title">{{ __('Ugovor i ERP priprema') }}</p>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ERP kupac ID') }}</label>
                            <input type="text" wire:model="form.erp_customer_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ERP tvrtka') }}</label>
                            <input type="text" wire:model="form.erp_company_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono">
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Broj ugovora') }}</label>
                            <input type="text" wire:model="form.contract_number" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Ugovor vrijedi od') }}</label>
                            <input type="date" wire:model="form.contract_starts_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Ugovor vrijedi do') }}</label>
                            <input type="date" wire:model="form.contract_ends_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @error('form.contract_ends_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Rok plaćanja (dana)') }}</label>
                            <input type="number" min="0" max="365" wire:model="form.payment_terms_days" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <label class="flex items-center gap-2 self-end rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="form.purchase_order_required">
                            <span>{{ __('Obavezan broj narudžbenice') }}</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Napomena odluke') }}</label>
                    <textarea rows="3" wire:model="form.status_reason" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>

                @if ($canMutate)
                    <button type="submit" class="rounded-lg bg-cyan-700 px-3 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('Spremi B2B račun') }}</button>
                @else
                    <p class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">{{ __('Imate pristup samo za pregled.') }}</p>
                @endif
            </form>
        @endif
    </div>
</div>
