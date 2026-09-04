<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Katalog / Promocije') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Actions & Discounts') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Product actions and cart discounts with audience/target rules.') }}</p>
            </div>
            <a href="{{ route('admin.actions.create', ['locale' => $locale]) }}" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-cyan-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-cyan-800">
                {{ __('Kreiraj akciju') }}
            </a>
        </div>

        <div class="mt-6 grid items-end gap-3 md:grid-cols-2 xl:grid-cols-[minmax(20rem,1fr)_11rem_13rem_11rem_8rem]">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Code, title, coupon...') }}"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Scope') }}</label>
                <select wire:model.live="scopeFilter" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="all">{{ __('All') }}</option>
                    @foreach ($scopeLabels as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Type') }}</label>
                <select wire:model.live="typeFilter" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="all">{{ __('All') }}</option>
                    @foreach ($typeLabels as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.state') }}</label>
                <select wire:model.live="stateFilter" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    @foreach ($stateLabels as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                <select wire:model.live="locale" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm lowercase">
                    @foreach ($adminLocaleOptions as $localeOption)
                        <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <p class="mt-3 text-xs text-slate-500">{{ __('Items per page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
    </div>

    <div class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('admin.common.items') }}</h2>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Action') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Scope / Type') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Target / Audience') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Schedule') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Priority') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.state') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $tr = $row->translations->first();
                            $scopeLabel = $scopeLabels[$row->scope] ?? ucfirst($row->scope);
                            $typeLabel = $typeLabels[$row->type] ?? $row->type;
                            $targetLabel = $targetLabels[$row->target_type] ?? ucfirst($row->target_type);
                            $audienceLabel = $audienceLabels[$row->audience_type] ?? ucfirst($row->audience_type);
                            $groupText = $row->audienceCustomerGroup?->name;
                            $userText = $row->audienceUser?->name ? $row->audienceUser->name.' ('.$row->audienceUser->email.')' : null;
                        @endphp
                        <tr>
                            <td class="px-3 py-2 text-slate-800">
                                <div class="font-medium">{{ $tr?->title ?? __('(missing title)') }}</div>
                                <div class="font-mono text-xs text-slate-500">{{ $row->code }}</div>
                                @if ($row->coupon_code)
                                    <div class="mt-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">{{ __('Coupon') }}: {{ $row->coupon_code }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                <div>{{ $scopeLabel }}</div>
                                <div class="text-xs text-slate-500">{{ $typeLabel }}</div>
                                @if ($row->discount_value !== null)
                                    <div class="mt-1 text-xs font-semibold text-slate-700">
                                        {{ $row->type === \App\Models\Catalog\Action\CatalogAction::TYPE_PERCENTAGE ? rtrim(rtrim((string) $row->discount_value, '0'), '.') . '%' : number_format((float) $row->discount_value, 2) }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                <div>{{ $targetLabel }} @if($row->targets_count > 0)<span class="text-xs text-slate-500">({{ $row->targets_count }})</span>@endif</div>
                                <div class="text-xs text-slate-500">{{ $audienceLabel }}</div>
                                @if ($row->audience_type === \App\Models\Catalog\Action\CatalogAction::AUDIENCE_USER_GROUP && $groupText)
                                    <div class="text-xs text-slate-500">{{ $groupText }}</div>
                                @endif
                                @if ($row->audience_type === \App\Models\Catalog\Action\CatalogAction::AUDIENCE_USER && $userText)
                                    <div class="text-xs text-slate-500">{{ $userText }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center text-xs text-slate-600">
                                <div>{{ $row->starts_at?->format('Y-m-d H:i') ?? __('No start') }}</div>
                                <div>{{ $row->ends_at?->format('Y-m-d H:i') ?? __('No end') }}</div>
                            </td>
                            <td class="px-3 py-2 text-center text-slate-700">{{ (int) $row->priority }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $row->is_active ? __('admin.common.active') : __('admin.common.inactive') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('admin.actions.edit', ['action' => $row->id, 'locale' => $locale]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        {{ __('admin.common.edit') }}
                                    </a>
                                    <button
                                        type="button"
                                        wire:click="delete({{ (int) $row->id }})"
                                        wire:confirm="{{ __('Delete action \':name\'?', ['name' => $tr?->title ?? $row->code]) }}"
                                        class="rounded-lg border border-rose-300 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                    >
                                        {{ __('admin.common.delete') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-12 text-center">
                                <p class="font-medium text-slate-700">{{ __('No actions or discounts yet.') }}</p>
                                <a href="{{ route('admin.actions.create', ['locale' => $locale]) }}" class="mt-2 inline-flex text-sm font-semibold text-cyan-700 hover:text-cyan-900">{{ __('Kreiraj prvu akciju') }} →</a>
                            </td>
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
