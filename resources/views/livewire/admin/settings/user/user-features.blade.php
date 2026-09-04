<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <h1 class="text-xl font-semibold tracking-tight">{{ __('User Settings') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Namespace:') }} <code>Settings/User</code></p>
        <p class="mt-2 text-xs text-slate-500">{{ __('Control user tracking capture and loyalty engine behavior.') }}</p>
    </div>

    <div class="admin-panel admin-form-panel p-6">
        <form wire:submit="save" class="admin-form mt-1 space-y-4">
            <div>
                <p class="admin-section-title">{{ __('Feature Switches') }}</p>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    @php
                        $switches = [
                            'user_tracking_enabled' => [
                                'title' => __('User Tracking'),
                                'description' => __('Stores user/front interaction events for analytics and personalization.'),
                            ],
                            'user_loyalty_enabled' => [
                                'title' => __('Loyalty System'),
                                'description' => __('Awards loyalty points from order totals when eligible statuses are reached.'),
                            ],
                        ];
                    @endphp

                    @foreach ($switches as $key => $item)
                        @php $enabled = (bool) ($form[$key] ?? false); @endphp
                        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <strong class="block text-slate-900">{{ $item['title'] }}</strong>
                                    <p class="mt-1 text-sm text-slate-600">{{ $item['description'] }}</p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="toggle('{{ $key }}')"
                                    class="admin-switch"
                                    data-state="{{ $enabled ? 'on' : 'off' }}"
                                    role="switch"
                                    aria-checked="{{ $enabled ? 'true' : 'false' }}"
                                    aria-label="{{ $item['title'] }}"
                                >
                                    <span class="admin-switch-track">
                                        <span class="admin-switch-thumb"></span>
                                    </span>
                                    <span class="admin-switch-label">{{ $enabled ? __('On') : __('Off') }}</span>
                                </button>
                            </div>
                            <p class="mt-2 text-xs font-semibold uppercase tracking-[0.12em] {{ $enabled ? 'text-emerald-700' : 'text-slate-500' }}">
                                {{ $enabled ? __('Enabled') : __('Disabled') }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ((bool) ($form['user_loyalty_enabled'] ?? false))
                <div>
                    <p class="admin-section-title">{{ __('Loyalty Rules') }}</p>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Points Per Currency Unit') }}</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                wire:model="form.loyalty_points_per_currency"
                                class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                            />
                            @error('form.loyalty_points_per_currency') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Minimum Order Total for Earning Points') }}</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                wire:model="form.loyalty_min_order_total"
                                class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                            />
                            @error('form.loyalty_min_order_total') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Value Per Point') }}</label>
                            <input
                                type="number"
                                step="0.0001"
                                min="0"
                                wire:model="form.loyalty_currency_value_per_point"
                                class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                            />
                            @error('form.loyalty_currency_value_per_point') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Reversal Policy') }}</label>
                            <select wire:model="form.loyalty_reversal_mode" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="zero_out">{{ __('Zero out settlement row') }}</option>
                                <option value="separate_entry">{{ __('Create separate reversal entry') }}</option>
                            </select>
                            @error('form.loyalty_reversal_mode') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mt-4 max-w-2xl">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Eligible Customer Groups') }}</label>
                        <select
                            wire:model="form.loyalty_customer_group_ids"
                            multiple
                            data-tom-select
                            data-tom-placeholder="{{ __('All customer groups') }}"
                            class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"
                        >
                            @foreach (($customerGroupOptions ?? collect()) as $group)
                                <option
                                    value="{{ $group->id }}"
                                    @selected(in_array((int) $group->id, array_map('intval', (array) ($form['loyalty_customer_group_ids'] ?? [])), true))
                                >{{ $group->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Choose which customer groups can earn and use points. Leave empty to allow all customer groups.') }}</p>
                        @error('form.loyalty_customer_group_ids') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        @error('form.loyalty_customer_group_ids.*') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        {{ __('Earning:') }} <code>round(order grand total * points per currency unit)</code>.
                        {{ __('Redemption discount:') }} <code>redeemed points * value per point</code>.
                        {{ __('Only paid, non-cancelled orders meeting the minimum total earn points. The reversal policy applies if an eligible order is later cancelled or refunded.') }}
                    </div>
                </div>
            @endif

            <div class="admin-form-actions flex items-center gap-2">
                <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('admin.common.save') }}</button>
                <button type="button" wire:click="resetToDefaults" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Reset Defaults') }}</button>
            </div>
        </form>
    </div>
</div>
