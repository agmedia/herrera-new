<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Catalog / Products / Option Values') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $productName }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('Assign option groups and define per-product option value rows with SKU, stock and optional price override.') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="admin-chip">{{ __('Code:') }} {{ $productCode }}</span>
                    <span class="admin-chip">{{ __('SKU:') }} {{ $productSku !== '' ? $productSku : __('n/a') }}</span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.products', ['locale' => $locale]) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                    {{ __('Back to Products') }}
                </a>
                <button type="button" wire:click="backToProduct" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                    {{ __('Edit Product') }}
                </button>
            </div>
        </div>
    </div>

    <div class="admin-panel admin-form-panel p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="w-full max-w-3xl">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Assigned Option Groups') }}</label>
                <div class="max-h-64 space-y-1 overflow-auto rounded-xl border border-slate-300 bg-white p-2">
                    @foreach ($availableOptions as $option)
                        <label class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                            <input type="checkbox" value="{{ $option['id'] }}" wire:model="selectedOptionIds" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-500">
                            <span>{{ $option['label'] }} ({{ $option['values_count'] }} values)</span>
                        </label>
                    @endforeach
                </div>
                @error('selectedOptionIds.*') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                <p class="mt-2 text-xs text-slate-500">{{ __('Order controls primary/secondary defaults in linked mode. Save groups before editing rows.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.options', ['locale' => $locale]) }}" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    {{ __('Manage Options') }}
                </a>
                <button type="button" wire:click="saveOptionGroups" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('Save Option Groups') }}
                </button>
            </div>
        </div>
    </div>

    @if (empty($assignedOptions) && empty($filterOnlyOptions))
        <div class="admin-panel admin-form-panel p-6">
            <p class="text-sm text-amber-800">{{ __('No option groups assigned to this product yet.') }}</p>
        </div>
    @else
        @if (! empty($filterOnlyOptions))
            <form wire:submit="saveFilterOnlyOptions" class="admin-panel admin-form-panel p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="w-full max-w-3xl">
                        <p class="admin-section-title">{{ __('Filter-only option values') }}</p>
                        <p class="mt-2 text-xs text-slate-500">
                            {{ __('Use this for color/filter values on the product. These values power category filters and color variants, but do not create SKU, stock, or price rows.') }}
                        </p>

                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            @foreach ($filterOnlyOptions as $optionIndex => $option)
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ $option['label'] }}</label>
                                    <select wire:model.number="filterOnlyOptions.{{ $optionIndex }}.selected_value_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                        <option value="">{{ __('No value') }}</option>
                                        @foreach ($option['values'] as $value)
                                            <option value="{{ $value['id'] }}" @disabled(!$value['is_active'])>{{ $value['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('filterOnlyOptions.'.$optionIndex.'.selected_value_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                            {{ __('Save filter-only values') }}
                        </button>
                    </div>
                </div>
            </form>
        @endif

        @if (empty($assignedOptions))
            <div class="admin-panel admin-form-panel p-6">
                <p class="text-sm text-slate-600">{{ __('Only filter-only option groups are assigned to this product. Add a product-page option group, such as size, if this product needs SKU or stock rows.') }}</p>
            </div>
        @else
        <form wire:submit="save" class="space-y-6">
            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">{{ __('Mode') }}</p>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        wire:click="$set('mode', 'single')"
                    class="rounded-xl border px-4 py-2 text-sm font-semibold {{ $mode === 'single' ? 'border-slate-800 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}"
                    >
                        {{ __('Single Option') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('mode', 'linked')"
                    class="rounded-xl border px-4 py-2 text-sm font-semibold {{ $mode === 'linked' ? 'border-slate-800 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}"
                    >
                        {{ __('Linked Two Options') }}
                    </button>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    {{ __('Single mode is one value list. Linked mode is primary + secondary value combinations. Filter-only options are edited in the separate block above.') }}
                </p>
            </div>

            <div class="admin-panel admin-form-panel p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr)); width: min(74rem, 100%);">
                        @if ($mode === 'single')
                            <div style="grid-column: span 6;">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Option Group') }}</label>
                                <select wire:model.live.number="singleOptionId" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    @foreach ($assignedOptions as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('singleOptionId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        @else
                            <div style="grid-column: span 4;">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Primary Option') }}</label>
                                <select wire:model.live.number="primaryOptionId" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">{{ __('Select...') }}</option>
                                    @foreach ($assignedOptions as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('primaryOptionId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div style="grid-column: span 4;">
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Secondary Option') }}</label>
                                <select wire:model.live.number="secondaryOptionId" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">{{ __('Select...') }}</option>
                                    @foreach ($assignedOptions as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                @error('secondaryOptionId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="addRow" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                            {{ __('Add Row') }}
                        </button>
                        @if ($mode === 'single')
                            <button type="button" wire:click="addAllSingleValues" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                {{ __('Add All Values') }}
                            </button>
                        @else
                            <button type="button" wire:click="generateLinkedMatrix" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                {{ __('Generate Matrix') }}
                            </button>
                        @endif
                        <button type="button" wire:click="clearRows" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                            {{ __('Clear Rows') }}
                        </button>
                    </div>
                </div>

                <div class="mt-5 overflow-x-auto overflow-y-visible">
                    <table class="admin-items-table min-w-full text-sm" style="overflow: visible;">
                        <thead class="text-slate-600">
                            <tr>
                                @if ($mode === 'linked')
                                    <th class="px-3 py-2 text-left font-semibold">{{ __('Primary Value') }}</th>
                                @endif
                                <th class="px-3 py-2 text-left font-semibold">{{ $mode === 'linked' ? __('Secondary Value') : __('Value') }}</th>
                                <th class="px-3 py-2 text-left font-semibold">{{ __('SKU') }}</th>
                                <th class="px-3 py-2 text-center font-semibold">{{ __('Stock') }}</th>
                                <th class="px-3 py-2 text-center font-semibold">{{ __('Price') }}</th>
                                <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.state') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($rows as $index => $row)
                                <tr>
                                    @if ($mode === 'linked')
                                        <td class="overflow-visible px-3 py-2 align-top">
                                            <select wire:model.number="rows.{{ $index }}.parent_option_value_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                                <option value="">{{ __('Select...') }}</option>
                                                @foreach ($primaryValues as $value)
                                                    <option value="{{ $value['id'] }}" @disabled(!$value['is_active'])>{{ $value['label'] }}</option>
                                                @endforeach
                                            </select>
                                            @error('rows.'.$index.'.parent_option_value_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                        </td>
                                    @endif
                                    <td class="overflow-visible px-3 py-2 align-top">
                                        <select wire:model.number="rows.{{ $index }}.option_value_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                            <option value="">{{ __('Select...') }}</option>
                                            @foreach ($mode === 'linked' ? $secondaryValues : $singleValues as $value)
                                                <option value="{{ $value['id'] }}" @disabled(!$value['is_active'])>{{ $value['label'] }}</option>
                                            @endforeach
                                        </select>
                                        @error('rows.'.$index.'.option_value_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <input type="text" wire:model="rows.{{ $index }}.sku" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono" />
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <input type="number" min="0" wire:model="rows.{{ $index }}.stock_qty" class="w-24 rounded-xl border border-slate-300 px-3 py-2 text-sm text-center" />
                                        @error('rows.'.$index.'.stock_qty') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    </td>
                                    <td class="px-3 py-2 align-top">
                                        <input type="text" wire:model="rows.{{ $index }}.price_override" placeholder="{{ __('base') }}" class="w-28 rounded-xl border border-slate-300 px-3 py-2 text-sm text-center" />
                                        @error('rows.'.$index.'.price_override') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                    </td>
                                    <td class="px-3 py-2 align-top text-center">
                                        <button
                                            type="button"
                                            wire:click="$toggle('rows.{{ $index }}.is_active')"
                                            class="admin-switch"
                                            data-state="{{ ($row['is_active'] ?? false) ? 'on' : 'off' }}"
                                        >
                                            <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                                            <span class="admin-switch-label">{{ ($row['is_active'] ?? false) ? __('On') : __('Off') }}</span>
                                        </button>
                                    </td>
                                    <td class="px-3 py-2 align-top text-right">
                                        <button type="button" wire:click="removeRow({{ $index }})" class="rounded-lg border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                            {{ __('Remove') }}
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $mode === 'linked' ? 7 : 6 }}" class="px-3 py-8 text-center text-sm text-slate-500">
                                        {{ __('No rows yet. Add rows manually or use quick actions above.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="admin-form-actions mt-5 flex items-center gap-2 pt-2">
                    <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                        {{ __('Save Option Values') }}
                    </button>
                    <button type="button" wire:click="backToProduct" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                        {{ __('Cancel') }}
                    </button>
                </div>
            </div>
        </form>
        @endif
    @endif
</div>
