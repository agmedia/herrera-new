@php
    $groupTranslation = $group?->translations->first();
    $groupName = $groupTranslation?->name
        ?? ($group ? str($group->code)->replace(['_', '-'], ' ')->title() : __('Attributes'));
    $groupIsMsanManaged = $group?->isMsanManaged() ?? false;
@endphp

<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Attributes') }} / {{ __('Group') }}</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $groupName }}</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ $groupTranslation?->description ?: __('Manage the attributes that belong to this group.') }}
                    </p>
                    @if ($group)
                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-500">
                            <span class="admin-chip">{{ __('Code') }}: {{ $group->code }}</span>
                            <span class="admin-chip">{{ $group->type === 'multi' ? __('Multiple values') : __('One value') }}</span>
                            @if ($groupIsMsanManaged)<span class="admin-chip">M SAN · {{ __('automatic') }}</span>@endif
                            <span class="admin-chip">{{ __('Items per page') }}: {{ $perPage }}</span>
                        </div>
                    @endif
                </div>

                @if ($group)
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.attributes', ['locale' => $locale]) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to groups') }}</a>
                        <a href="{{ route('admin.attributes.groups.edit', ['attributeGroup' => $group->id, 'locale' => $locale]) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Edit group') }}</a>
                        @unless ($groupIsMsanManaged)
                            <a href="{{ route('admin.attributes.groups.attributes.create', ['attributeGroup' => $group->id, 'locale' => $locale]) }}" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ __('Add new attribute') }}</a>
                        @endunless
                    </div>
                @endif
            </div>

            <div class="grid gap-3 sm:grid-cols-[minmax(16rem,1fr)_8rem]">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('Code, name or slug...') }}" class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm" />
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
        </div>
    </div>

    <div class="admin-panel admin-panel-soft p-5">
        <div class="flex items-center justify-between gap-4">
            <h2 class="admin-section-title">{{ __('Attributes in this group') }}</h2>
            <p class="text-xs text-slate-500">{{ __('Imported attributes remain here with a visible source label.') }}</p>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Attribute') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Slug') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Source') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('Products') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.state') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.sort') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $translation = $row->translations->first();
                            $source = $row->sourceCode();
                            [$sourceLabel, $sourceClass] = match ($source) {
                                'msan_specification' => ['M SAN', 'bg-indigo-100 text-indigo-800'],
                                'termol.hr description' => ['Termol', 'bg-amber-100 text-amber-800'],
                                'kozo_proizvodi' => ['Kozo', 'bg-sky-100 text-sky-800'],
                                'manual' => [__('Manual'), 'bg-slate-100 text-slate-700'],
                                default => [__('Import'), 'bg-violet-100 text-violet-800'],
                            };
                        @endphp
                        <tr>
                            <td class="px-3 py-3 text-slate-800">
                                <div class="font-medium">{{ $translation?->name ?? __('(missing name)') }}</div>
                                <div class="text-xs text-slate-500">{{ $row->code }}</div>
                            </td>
                            <td class="px-3 py-3 font-mono text-xs text-slate-700">{{ $translation?->slug ?? '-' }}</td>
                            <td class="px-3 py-3 text-center"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $sourceClass }}">{{ $sourceLabel }}</span></td>
                            <td class="px-3 py-3 text-center text-slate-700">{{ $row->products_count }}</td>
                            <td class="px-3 py-3 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">{{ $row->is_active ? __('admin.common.active') : __('admin.common.inactive') }}</span>
                            </td>
                            <td class="px-3 py-3 text-center text-slate-700">{{ $row->sort_order }}</td>
                            <td class="px-3 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    @if ($group)
                                        <a href="{{ route('admin.attributes.groups.attributes.edit', ['attributeGroup' => $group->id, 'attribute' => $row->id, 'locale' => $locale]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('admin.common.edit') }}</a>
                                    @endif
                                    <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('Delete this attribute?') }}" class="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('admin.common.delete') }}</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-sm text-slate-500">
                                {{ __('This group does not have any attributes yet.') }}
                                @if ($group && ! $groupIsMsanManaged)
                                    <a href="{{ route('admin.attributes.groups.attributes.create', ['attributeGroup' => $group->id, 'locale' => $locale]) }}" class="ml-1 font-semibold text-cyan-700 hover:underline">{{ __('Add the first attribute') }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $rows->links() }}</div>
    </div>
</div>
