<div class="space-y-6">
    <div class="admin-panel admin-form-panel p-6">
        <p class="admin-section-title">{{ __('Manual Adjustment') }}</p>
        <div class="mt-4 grid gap-3" style="grid-template-columns: minmax(18rem, 1.2fr) minmax(18rem, 1.2fr) 8rem minmax(20rem, 1.6fr) 8rem;">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('User Search') }}</label>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="adjustUserSearch"
                    placeholder="{{ __('Name or email...') }}"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
                <select wire:model="adjustment.user_id" data-tom-select class="mt-2 admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="">{{ __('Select user') }}</option>
                    @foreach ($this->adjustUserOptions as $optionUser)
                        <option value="{{ $optionUser->id }}">{{ $optionUser->name }} ({{ $optionUser->email }})</option>
                    @endforeach
                </select>
                @error('adjustment.user_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Order Search (Optional)') }}</label>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="adjustOrderSearch"
                    placeholder="{{ __('Order number/customer...') }}"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
                <select wire:model="adjustment.order_id" data-tom-select class="mt-2 admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="">{{ __('No order link') }}</option>
                    @foreach ($this->adjustOrderOptions as $optionOrder)
                        <option value="{{ $optionOrder->id }}">{{ $optionOrder->order_number }} - {{ $optionOrder->customer_name }}</option>
                    @endforeach
                </select>
                @error('adjustment.order_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Points +/-') }}</label>
                <input
                    type="number"
                    wire:model="adjustment.points"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
                @error('adjustment.points') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Reason') }}</label>
                <input
                    type="text"
                    wire:model="adjustment.reason"
                    placeholder="{{ __('Explain why this adjustment is needed...') }}"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
                @error('adjustment.reason') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button type="button" wire:click="saveManualAdjustment" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('Save') }}
                </button>
            </div>
        </div>
    </div>

    <div class="admin-panel admin-search-panel p-6">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">{{ __('User Loyalty') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ __('Ledger of loyalty points with user, type, date, and points filters.') }}</p>
            <p class="mt-2 text-xs text-slate-500">{{ __('Items per page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
            @if ($selectedUser)
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                    <span class="admin-chip">{{ __('Scoped User:') }} {{ $selectedUser->name }}</span>
                    <span class="admin-chip">{{ __('Balance:') }} {{ $selectedUserBalance }} {{ __('pts') }}</span>
                    <a href="{{ route('admin.users.show', ['user' => $selectedUser->id]) }}" class="rounded-full border border-slate-300 px-2.5 py-1 font-semibold text-slate-700 hover:bg-slate-100">
                        {{ __('Open user') }}
                    </a>
                </div>
            @endif
        </div>

        <div class="mt-4 grid items-end gap-3" style="grid-template-columns: minmax(24rem, 1.4fr) 8rem 12rem 10rem 10rem 8rem 8rem;">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('User') }}</label>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Name, email, event key...') }}"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('User ID') }}</label>
                <input
                    type="number"
                    min="1"
                    wire:model.live.debounce.300ms="userId"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Type') }}</label>
                <select wire:model.live="type" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    @foreach ($typeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Date From') }}</label>
                <input type="date" wire:model.live="dateFrom" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Date To') }}</label>
                <input type="date" wire:model.live="dateTo" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Points Min') }}</label>
                <input type="number" wire:model.live.debounce.300ms="minPoints" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Points Max') }}</label>
                <input type="number" wire:model.live.debounce.300ms="maxPoints" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <span class="admin-chip">{{ __('Rows:') }} {{ $stats['rows'] }}</span>
            <span class="admin-chip">{{ __('Points Sum:') }} {{ $stats['points_sum'] }}</span>
            <span class="admin-chip">{{ __('Users:') }} {{ $stats['users_count'] }}</span>
        </div>
    </div>

    <div class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('admin.common.items') }}</h2>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Time') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('User') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Type') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Points') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Order') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Actor') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Note') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 text-center text-xs text-slate-600">{{ $row->created_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-800">
                                @if ($row->user)
                                    <div class="font-medium">{{ $row->user->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $row->user->email }}</div>
                                @else
                                    <span class="text-xs text-slate-500">{{ __('Deleted user') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ str_replace('_', ' ', $row->type) }}</span>
                                <div class="mt-1 text-[11px] text-slate-500">{{ $row->event_key }}</div>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->points >= 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                                    {{ $row->points }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                @if ($row->order)
                                    <a href="{{ route('admin.orders.show', ['order' => $row->order->id]) }}" class="text-xs font-semibold text-cyan-700 hover:text-cyan-900">
                                        {{ $row->order->order_number }}
                                    </a>
                                    <div class="text-xs text-slate-500">{{ \App\Support\Currency::format((float) $row->order->grand_total, $row->order->currency_code) }}</div>
                                @else
                                    <span class="text-xs text-slate-500">-</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-700">
                                @if ($row->creator)
                                    {{ $row->creator->name }}
                                @else
                                    {{ __('System') }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-700">{{ $row->note ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('No loyalty transactions found.') }}</td>
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
