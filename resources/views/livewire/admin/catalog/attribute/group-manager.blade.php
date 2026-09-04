<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ __('Attribute groups') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('Open a group to manage only the attributes that belong to it.') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('Items per page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
            </div>

            <div class="flex w-full max-w-4xl flex-col gap-3 sm:flex-row sm:items-end sm:justify-end">
                <div class="grid flex-1 gap-3 sm:grid-cols-[minmax(16rem,1fr)_8rem]">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Group, code or attribute...') }}"
                            class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                        />
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
                <a href="{{ route('admin.attributes.groups.create', ['locale' => $locale]) }}" class="whitespace-nowrap rounded-xl bg-cyan-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('Add new group') }}
                </a>
            </div>
        </div>
    </div>

    <div class="admin-panel admin-panel-soft p-5">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="admin-section-title">{{ __('Groups') }}</h2>
            <p class="text-xs text-slate-500">{{ __('Manual and imported filter attributes are shown together and marked by source.') }}</p>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Group') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Code') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Type') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Attributes') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Products') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Source') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $translation = $row->translations->first();
                            $name = $translation?->name ?? str($row->code)->replace(['_', '-'], ' ')->title();
                            $sources = $row->attributes->map->sourceCode()->unique()->values();
                            $sourceLabel = match (true) {
                                $sources->isEmpty() => __('Manual'),
                                $sources->count() > 1 => __('Mixed'),
                                $sources->first() === 'msan_specification' => 'M SAN',
                                $sources->first() === 'termol.hr description' => 'Termol',
                                $sources->first() === 'kozo_proizvodi' => 'Kozo',
                                $sources->first() === 'manual' => __('Manual'),
                                default => __('Import'),
                            };
                            $sourceClass = match ($sourceLabel) {
                                'M SAN' => 'bg-indigo-100 text-indigo-800',
                                'Termol' => 'bg-amber-100 text-amber-800',
                                'Kozo' => 'bg-sky-100 text-sky-800',
                                __('Import') => 'bg-violet-100 text-violet-800',
                                default => 'bg-slate-100 text-slate-700',
                            };
                        @endphp
                        <tr>
                            <td class="px-3 py-3 text-slate-800">
                                <a href="{{ route('admin.attributes.groups.show', ['attributeGroup' => $row->id, 'locale' => $locale]) }}" class="font-semibold text-cyan-800 hover:text-cyan-950 hover:underline">
                                    {{ $name }}
                                </a>
                                @if ($translation?->description)
                                    <p class="mt-0.5 max-w-xl text-xs text-slate-500">{{ str($translation->description)->stripTags()->limit(100) }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-3 font-mono text-xs text-slate-700">{{ $row->code }}</td>
                            <td class="px-3 py-3 text-center text-slate-700">{{ $row->type === 'multi' ? __('Multiple') : __('Single') }}</td>
                            <td class="px-3 py-3 text-center text-slate-700">{{ $row->attributes_count }}</td>
                            <td class="px-3 py-3 text-center text-slate-700">{{ (int) $row->products_count }}</td>
                            <td class="px-3 py-3 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $sourceClass }}">{{ $sourceLabel }}</span>
                            </td>
                            <td class="px-3 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('admin.attributes.groups.show', ['attributeGroup' => $row->id, 'locale' => $locale]) }}" class="rounded-lg border border-cyan-200 bg-cyan-50 px-2 py-1 text-xs font-semibold text-cyan-800 hover:bg-cyan-100">
                                        {{ __('Open attributes') }}
                                    </a>
                                    <a href="{{ route('admin.attributes.groups.edit', ['attributeGroup' => $row->id, 'locale' => $locale]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                        {{ __('admin.common.edit') }}
                                    </a>
                                    @if ((int) $row->attributes_count === 0)
                                        <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('Delete this group?') }}" class="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                            {{ __('admin.common.delete') }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('No attribute groups yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $rows->links() }}</div>
    </div>
</div>
