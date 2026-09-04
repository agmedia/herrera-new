<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Katalog / B2B') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ __('B2B cjenici') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ __('Ugovorene cijene po korisničkoj grupi ili pojedinom B2B kupcu za sve proizvode, brendove, kategorije ili odabrane proizvode.') }}
                </p>
            </div>
            <a href="{{ route('admin.b2b-prices.create') }}" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-cyan-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-cyan-800">
                {{ __('Novo B2B pravilo') }}
            </a>
        </div>

        <div class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(16rem,1fr)_11rem_13rem_13rem_10rem]">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Pretraga') }}</label>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Naziv ili šifra pravila...') }}" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Publika') }}</label>
                <select wire:model.live="audienceFilter" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="all">{{ __('Sve') }}</option>
                    <option value="group">{{ __('Grupe') }}</option>
                    <option value="customer">{{ __('Pojedini kupci') }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Korisnička grupa') }}</label>
                <select wire:model.live="groupFilter" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="all">{{ __('Sve grupe') }}</option>
                    @foreach (\App\Models\User\CustomerGroup::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']) as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Primjena') }}</label>
                <select wire:model.live="targetFilter" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="all">{{ __('Sve vrste') }}</option>
                    @foreach ($targetTypeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Status') }}</label>
                <select wire:model.live="stateFilter" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="active">{{ __('Aktivno') }}</option>
                    <option value="inactive">{{ __('Neaktivno') }}</option>
                    <option value="all">{{ __('Sve') }}</option>
                </select>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500">{{ __('Stavki po stranici') }}: <span class="admin-chip">{{ $perPage }}</span></p>
    </div>

    <div class="admin-panel admin-panel-soft overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="admin-section-title">{{ __('Pravila cjenika') }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">{{ __('Pravilo') }}</th>
                        <th class="px-4 py-3 text-left font-semibold">{{ __('Publika') }}</th>
                        <th class="px-4 py-3 text-left font-semibold">{{ __('Cijena') }}</th>
                        <th class="px-4 py-3 text-left font-semibold">{{ __('Primjena') }}</th>
                        <th class="px-4 py-3 text-center font-semibold">{{ __('Min. količina') }}</th>
                        <th class="px-4 py-3 text-center font-semibold">{{ __('Prioritet') }}</th>
                        <th class="px-4 py-3 text-center font-semibold">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right font-semibold">{{ __('Akcije') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-900">{{ $row->name }}</div>
                                <div class="mt-0.5 font-mono text-xs text-slate-500">{{ $row->code }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                @if ($row->user_id)
                                    <div class="font-medium">{{ $row->user?->b2bAccount?->company_name ?? $row->user?->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $row->user?->email }}</div>
                                @else
                                    <div class="font-medium">{{ $row->customerGroup?->name ?? '—' }}</div>
                                    <div class="text-xs text-slate-500">{{ $row->customerGroup?->code }}</div>
                                @endif
                                @if ($row->contract_number)
                                    <div class="mt-1 text-xs text-slate-500">{{ __('Ugovor') }}: {{ $row->contract_number }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                <div class="font-semibold">
                                    @if ($row->calculation_type === \App\Models\Catalog\Pricing\B2BPriceRule::TYPE_PERCENTAGE_DISCOUNT)
                                        −{{ rtrim(rtrim(number_format((float) $row->value, 2, '.', ''), '0'), '.') }}%
                                    @elseif ($row->calculation_type === \App\Models\Catalog\Pricing\B2BPriceRule::TYPE_FIXED_DISCOUNT)
                                        −{{ number_format((float) $row->value, 2, ',', '.') }} {{ $row->currency_code }}
                                    @else
                                        {{ number_format((float) $row->value, 2, ',', '.') }} {{ $row->currency_code }}
                                    @endif
                                </div>
                                <div class="text-xs text-slate-500">{{ $calculationTypeOptions[$row->calculation_type] ?? $row->calculation_type }}</div>
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                <div>{{ $targetTypeOptions[$row->target_type] ?? $row->target_type }}</div>
                                @if ($row->target_type !== \App\Models\Catalog\Pricing\B2BPriceRule::TARGET_ALL)
                                    <div class="text-xs text-slate-500">{{ trans_choice(':count odabir|:count odabira|:count odabira', $row->targets_count, ['count' => $row->targets_count]) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center tabular-nums text-slate-700">{{ $row->minimum_quantity }}</td>
                            <td class="px-4 py-3 text-center tabular-nums text-slate-700">{{ $row->priority }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $row->is_active ? __('Aktivno') : __('Neaktivno') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('admin.b2b-prices.edit', ['rule' => $row->id]) }}" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        {{ __('Uredi') }}
                                    </a>
                                    <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('Obrisati B2B pravilo \":name\"?', ['name' => $row->name]) }}" class="rounded-lg border border-rose-300 px-2.5 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                        {{ __('Obriši') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center">
                                <p class="font-medium text-slate-700">{{ __('Nema B2B pravila za odabrane filtre.') }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ __('Kreirajte prvo pravilo i povežite ga s korisničkom grupom.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 px-5 py-4">
            {{ $rows->links() }}
        </div>
    </div>
</div>
