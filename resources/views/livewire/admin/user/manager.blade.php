<div class="space-y-6">
    @php
        $isAssignedCustomerView = app(\App\Services\Admin\OrderManagerAccess::class)->isRestricted();
    @endphp
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ $isAssignedCustomerView ? __('Moji kupci') : __('Users') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $isAssignedCustomerView ? __('Prikazani su samo kupci koji su vam dodijeljeni. Za izradu narudžbe odaberite „Naruči za kupca”.') : __('User administration with role-based access and account controls.') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('Items per page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
            </div>

            <div class="flex w-[64rem] max-w-full items-end justify-end gap-3">
                <div class="grid w-full max-w-[68rem] items-end gap-3" style="grid-template-columns: minmax(30rem, 1fr) 12rem 14rem;">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Tvrtka, OIB, ime kontakta ili e-mail...') }}"
                            class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Role') }}</label>
                        <select wire:model.live="role" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border px-3 py-2 text-sm">
                            <option value="">{{ __('All roles') }}</option>
                            @foreach ($roles as $roleItem)
                                <option value="{{ $roleItem->name }}">{{ $roleItem->title ?: ucfirst($roleItem->name) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Segment') }}</label>
                        <select wire:model.live="segment" data-tom-select class="admin-select w-full rounded-xl border px-3 py-2 text-sm">
                            <option value="">{{ __('All segments') }}</option>
                            @foreach ($segments as $segmentItem)
                                <option value="{{ $segmentItem->id }}">{{ $segmentItem->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <label class="text-xs font-semibold text-slate-500">{{ __('Komercijalist') }}<select wire:model.live="representative" class="admin-select mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="">{{ __('Svi dostupni') }}</option>@if (! $isAssignedCustomerView)<option value="unassigned">{{ __('Bez komercijalista') }}</option>@endif @foreach ($representatives as $rep)<option value="{{ $rep->id }}">{{ $rep->name }}</option>@endforeach</select></label>
            <label class="text-xs font-semibold text-slate-500">{{ __('Mjesto') }}<input wire:model.live.debounce.300ms="city" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"></label>
            <label class="text-xs font-semibold text-slate-500">{{ __('Poštanski broj') }}<input wire:model.live.debounce.300ms="postalCode" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"></label>
            <label class="text-xs font-semibold text-slate-500">{{ __('Županija') }}<input wire:model.live.debounce.300ms="county" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"></label>
            <label class="text-xs font-semibold text-slate-500">{{ __('Tip kupca') }}<select wire:model.live="customerType" class="admin-select mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="">{{ __('Svi tipovi') }}</option>@foreach ($customerTypes as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select></label>
            <label class="text-xs font-semibold text-slate-500">{{ __('Posjeti kupcu') }}<select wire:model.live="visit" class="admin-select mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="">{{ __('Svi kupci') }}</option><option value="visited">{{ __('Posjećeni') }}</option><option value="unvisited">{{ __('Neposjećeni') }}</option></select></label>
            <label class="text-xs font-semibold text-slate-500">{{ __('Provjeri posjete od') }}<input type="date" wire:model.live="visitSince" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm">@error('visitSince')<span class="mt-1 block text-rose-600">{{ $message }}</span>@enderror</label>
            <label class="text-xs font-semibold text-slate-500">{{ __('Promet webshopa — godina') }}<input type="number" min="1900" max="2200" placeholder="{{ __('Sve godine') }}" wire:model.live.debounce.500ms="turnoverYear" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm">@error('turnoverYear')<span class="mt-1 block text-rose-600">{{ $message }}</span>@enderror</label>
            <label class="text-xs font-semibold text-slate-500">{{ __('Valuta prometa') }}<input maxlength="3" wire:model.live.debounce.500ms="turnoverCurrency" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm uppercase"></label>
            <div class="flex items-end"><a href="{{ route('admin.users.crm') }}" class="rounded-lg border px-3 py-2 text-sm font-semibold text-cyan-700">{{ __('CRM i izvještaj komercijalista') }}</a></div>
        </div>
    </div>

    @if ($canAssign || $canSegment)
        <div class="admin-panel space-y-3 p-5">
            <p class="text-sm font-semibold">{{ __('Grupno uređivanje odabranih kupaca') }} <span class="admin-chip">{{ count($selectedCustomers) }}</span></p>
            <p class="text-xs text-slate-500">{{ __('Označite kupce u tablici. Promjena se primjenjuje samo na označene kupce.') }}</p>
            <div class="flex flex-wrap items-end gap-3">
                @if ($canAssign)
                    <label class="min-w-52 text-xs font-semibold text-slate-500">{{ __('Novi komercijalist') }}<select wire:model="bulkRepresentative" class="admin-select mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="">{{ __('Odaberite komercijalista') }}</option>@foreach ($representatives as $rep)<option value="{{ $rep->id }}">{{ $rep->name }}</option>@endforeach</select></label>
                    <button type="button" wire:click="assignSelectedRepresentatives" wire:confirm="{{ __('Zamijeniti sve postojeće komercijaliste označenih kupaca odabranim komercijalistom?') }}" class="rounded-lg border px-3 py-2 text-sm font-semibold">{{ __('Zamijeni komercijalista') }}</button>
                @endif
                @if ($canSegment)
                    <label class="min-w-52 text-xs font-semibold text-slate-500">{{ __('Djelatnost / divizija') }}<select wire:model="bulkSegment" class="admin-select mt-1 w-full rounded-lg border px-3 py-2 text-sm"><option value="">{{ __('Odaberite grupu') }}</option>@foreach ($businessSegments as $segment)<option value="{{ $segment->id }}">{{ $segment->name }}</option>@endforeach</select></label>
                    <select aria-label="{{ __('Način razvrstavanja') }}" wire:model="segmentMode" class="admin-select rounded-lg border px-3 py-2 text-sm"><option value="add">{{ __('Dodaj odabranu grupu') }}</option><option value="remove">{{ __('Ukloni odabranu grupu') }}</option></select>
                    <button type="button" wire:click="assignSelectedSegments" class="rounded-lg border px-3 py-2 text-sm font-semibold">{{ __('Primijeni razvrstavanje') }}</button>
                    <a href="{{ route('admin.users.groups') }}" class="rounded-lg border px-3 py-2 text-sm">{{ __('Upravljaj grupama') }}</a>
                @endif
            </div>
            @foreach (['selectedCustomers', 'bulkRepresentative', 'bulkSegment', 'segmentMode'] as $field) @error($field)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror @endforeach
        </div>
    @endif

    <div class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('admin.common.items') }}</h2>
        <p class="mt-2 text-xs text-slate-500">{{ __('Promet je zbroj webshop narudžbi bez PDV-a i bez otkazanih narudžbi, u odabranoj valuti. Fakturirani ERP promet nije uključen.') }}</p>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        @if ($canAssign || $canSegment)<th class="px-3 py-2"><span class="sr-only">{{ __('Odaberi kupca') }}</span></th>@endif
                        <th class="px-3 py-2 text-center font-semibold">
                            <button type="button" wire:click="sort('id')" class="inline-flex items-center gap-1">
                                {{ __('ID') }} <span class="text-xs">{{ $sortBy === 'id' ? ($sortDir === 'asc' ? '^' : 'v') : '<>' }}</span>
                            </button>
                        </th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Tvrtka / OIB') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">
                            <button type="button" wire:click="sort('name')" class="inline-flex items-center gap-1">
                                {{ __('Kontakt') }} <span class="text-xs">{{ $sortBy === 'name' ? ($sortDir === 'asc' ? '^' : 'v') : '<>' }}</span>
                            </button>
                            <span class="mx-1 text-slate-400">/</span>
                            <button type="button" wire:click="sort('email')" class="inline-flex items-center gap-1">
                                {{ __('Email') }} <span class="text-xs">{{ $sortBy === 'email' ? ($sortDir === 'asc' ? '^' : 'v') : '<>' }}</span>
                            </button>
                        </th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Komercijalist / lokacija') }}</th>
                        <th class="px-3 py-2 text-right font-semibold"><button type="button" wire:click="sort('webshop_turnover')">{{ __('Promet webshopa bez PDV-a') }} {{ $sortBy === 'webshop_turnover' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}</button></th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Role') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Segments') }}</th>
                        @if ($loyaltyEnabled)
                            <th class="px-3 py-2 text-center font-semibold">{{ __('Loyalty') }}</th>
                        @endif
                        <th class="px-3 py-2 text-center font-semibold">
                            <button type="button" wire:click="sort('email_verified_at')" class="inline-flex items-center gap-1">
                                {{ __('Verified') }} <span class="text-xs">{{ $sortBy === 'email_verified_at' ? ($sortDir === 'asc' ? '^' : 'v') : '<>' }}</span>
                            </button>
                        </th>
                        <th class="px-3 py-2 text-center font-semibold">
                            <button type="button" wire:click="sort('created_at')" class="inline-flex items-center gap-1">
                                {{ __('Created') }} <span class="text-xs">{{ $sortBy === 'created_at' ? ($sortDir === 'asc' ? '^' : 'v') : '<>' }}</span>
                            </button>
                        </th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $displayRole = $row->roles->sortBy('id')->first();
                            $roleName = $displayRole?->name ?? 'customer';
                            $roleTitle = $displayRole?->title ?? ucfirst($roleName);
                            $isCurrent = auth()->id() === $row->id;
                            $billing = $row->addresses->first();
                            $company = trim((string) $row->b2bAccount?->company_name)
                                ?: trim((string) $row->profile?->company)
                                ?: trim((string) $billing?->company);
                            $oib = trim((string) $row->b2bAccount?->oib)
                                ?: trim((string) $row->profile?->oib)
                                ?: trim((string) $billing?->oib);
                        @endphp
                        <tr>
                            @if ($canAssign || $canSegment)<td class="px-3 py-2">@if ($row->account_type === 'customer' && ! $row->can('admin.access') && ! $row->isA('superadmin', 'admin', 'editor', 'order_manager'))<input type="checkbox" wire:model.live="selectedCustomers" value="{{ $row->id }}" aria-label="{{ __('Odaberi kupca') }} {{ $company ?: $row->name }}">@endif</td>@endif
                            <td class="px-3 py-2 text-center font-mono text-xs text-slate-700">{{ $row->id }}</td>
                            <td class="px-3 py-2 text-slate-800" style="min-width: 15rem;">
                                <a href="{{ route('admin.users.show', ['user' => $row->id]) }}" class="font-semibold text-slate-900 hover:text-cyan-700">{{ $row->customerDisplayName() }}</a>
                                <div class="mt-1 font-mono text-xs text-slate-600">{{ __('OIB') }}: {{ $oib ?: '—' }}</div>
                                <span class="inline-flex rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{{ $row->commerceCustomerType() }}</span>
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                <div class="font-medium">{{ $row->name }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $row->email }}</div>
                                @if ($isCurrent)
                                    <div class="text-xs text-cyan-700">{{ __('Current user') }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-600">
                                <div class="font-semibold text-slate-800">{{ $row->salesRepresentatives->pluck('name')->join(', ') ?: '—' }}</div>
                                <div class="mt-1">{{ $row->b2bAccount?->postal_code ?: $billing?->postal_code }} {{ $row->b2bAccount?->city ?: $billing?->city }}</div>
                                <div>{{ $row->profile?->county ?: $billing?->state }}</div>
                                @if ($row->profile?->customer_type)<div class="mt-1 text-cyan-700">{{ $row->profile->customer_type }}</div>@endif
                            </td>
                            <td class="px-3 py-2 text-right text-xs font-semibold text-slate-700">{{ \App\Support\Currency::format((float) $row->webshop_turnover, strtoupper($turnoverCurrency)) }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $roleTitle }}</span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <div class="flex flex-wrap items-center justify-center gap-1.5">
                                    @forelse ($row->customerGroups as $group)
                                        <span class="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-700">{{ $group->name }}</span>
                                    @empty
                                        <span class="text-xs text-slate-400">-</span>
                                    @endforelse
                                </div>
                            </td>
                            @if ($loyaltyEnabled)
                                <td class="px-3 py-2 text-center">
                                    @if ((bool) ($row->loyalty_available ?? false))
                                        <a href="{{ route('admin.users.loyalty', ['user_id' => $row->id]) }}" class="inline-block rounded-lg border border-slate-300 px-2 py-1 hover:bg-slate-100">
                                            <div class="text-xs font-semibold {{ ((int) ($row->loyalty_points_balance ?? 0)) >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                                {{ (int) ($row->loyalty_points_balance ?? 0) }} {{ __('pts') }}
                                            </div>
                                            <div class="text-[11px] text-slate-500">
                                                {{ (int) ($row->loyalty_transactions_count ?? 0) }} {{ __('entries') }}
                                            </div>
                                        </a>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->email_verified_at ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $row->email_verified_at ? __('admin.common.yes') : __('admin.common.no') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-center text-slate-600">{{ optional($row->created_at)->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex items-center gap-1">
                                    @include('admin.users.partials.impersonate-button', ['customer' => $row])
                                    <a href="{{ route('admin.users.show', ['user' => $row->id]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        {{ __('Show') }}
                                    </a>
                                    <a href="{{ route('admin.users.edit', ['user' => $row->id]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        {{ __('admin.common.edit') }}
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ ($loyaltyEnabled ? 11 : 10) + (($canAssign || $canSegment) ? 1 : 0) }}" class="px-3 py-8 text-center text-sm text-slate-500">{{ $isAssignedCustomerView ? __('Nema dodijeljenih kupaca koji odgovaraju pretrazi.') : __('No users found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </div>
</div>
