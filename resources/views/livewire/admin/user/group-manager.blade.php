<div class="space-y-6">
    @unless ($editPage || $createPage)
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ __('User Groups') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('Manage segmentation groups for audience rules, pricing, and campaigns.') }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('Items per page:') }} <span class="admin-chip">{{ $perPage }}</span></p>
            </div>
            <div class="flex w-full items-end gap-3 sm:w-auto">
                <div class="w-full sm:w-80">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Code, name or description...') }}"
                        class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                    />
                </div>
                <a href="{{ route('admin.users.groups.create', array_filter([
                    'search' => $search !== '' ? $search : null,
                    'adminUserGroupsPage' => $rows->currentPage() > 1 ? $rows->currentPage() : null,
                ], static fn (int|string|null $value): bool => $value !== null)) }}" class="whitespace-nowrap rounded-xl bg-cyan-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('Create Group') }}
                </a>
            </div>
        </div>
    </div>
    @endunless

    <div class="admin-stack">
        @if ($editPage || $createPage)
        <div class="admin-panel admin-form-panel p-6">
            <h2 class="admin-section-title">{{ $editingId ? __('Edit Group') : __('Create Group') }}</h2>

            <form wire:submit="save" class="admin-form mt-4 space-y-4">
                <div class="grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                    <div style="grid-column: span 3;">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Code') }}</label>
                        <input type="text" wire:model="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div style="grid-column: span 4;">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Name') }}</label>
                        <input type="text" wire:model="form.name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.sort') }}</label>
                        <input type="number" wire:model="form.sort_order" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div style="grid-column: span 3;">
                        <div class="mt-6 flex flex-wrap gap-3">
                            <button
                                type="button"
                                wire:click="$toggle('form.is_active')"
                                class="admin-switch"
                                data-state="{{ $form['is_active'] ? 'on' : 'off' }}"
                                role="switch"
                            >
                                <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                                <span class="admin-switch-label">{{ $form['is_active'] ? __('admin.common.active') : __('admin.common.inactive') }}</span>
                            </button>

                            <button
                                type="button"
                                wire:click="$toggle('form.is_default')"
                                class="admin-switch"
                                data-state="{{ $form['is_default'] ? 'on' : 'off' }}"
                                role="switch"
                            >
                                <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                                <span class="admin-switch-label">{{ $form['is_default'] ? __('Default') : __('Not Default') }}</span>
                            </button>
                        </div>
                    </div>
                    <div style="grid-column: span 12;">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Description') }}</label>
                        <textarea wire:model="form.description" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                    </div>
                </div>

                <div class="admin-form-actions flex items-center gap-2 pt-2">
                    <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                        {{ $editingId ? __('Update Group') : __('Create Group') }}
                    </button>
                    @if ($editPage || $createPage)
                        <a href="{{ route('admin.users.groups', array_filter([
                            'search' => $search !== '' ? $search : null,
                            'adminUserGroupsPage' => $returnPage > 1 ? $returnPage : null,
                        ], static fn (int|string|null $value): bool => $value !== null)) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            {{ __('Cancel') }}
                        </a>
                    @elseif ($editingId)
                        <button type="button" wire:click="cancelEdit" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            {{ __('Cancel') }}
                        </button>
                    @endif
                </div>
            </form>
        </div>
        @endif

        @unless ($editPage || $createPage)
        <div class="admin-panel admin-panel-soft p-5">
            <h2 class="admin-section-title">{{ __('admin.common.items') }}</h2>

            <div class="mt-4 overflow-x-auto">
                <table class="admin-items-table min-w-full text-sm">
                    <thead class="text-slate-600">
                        <tr>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('ID') }}</th>
                            <th class="px-3 py-2 text-left font-semibold">{{ __('Code') }}</th>
                            <th class="px-3 py-2 text-left font-semibold">{{ __('Name') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('Users') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.sort') }}</th>
                            <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.state') }}</th>
                            <th class="px-3 py-2 text-right font-semibold">{{ __('admin.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-3 py-2 text-center font-mono text-xs text-slate-700">{{ $row->id }}</td>
                                <td class="px-3 py-2 text-slate-800">{{ $row->code }}</td>
                                <td class="px-3 py-2 text-slate-800">
                                    <div>{{ $row->name }}</div>
                                    @if ($row->description)
                                        <div class="text-xs text-slate-500">{{ $row->description }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center text-slate-700">{{ $row->users_count }}</td>
                                <td class="px-3 py-2 text-center text-slate-700">{{ $row->sort_order }}</td>
                                <td class="px-3 py-2 text-center">
                                    <button type="button" wire:click="toggleActive({{ $row->id }})" class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                        {{ $row->is_active ? __('admin.common.active') : __('admin.common.inactive') }}
                                    </button>
                                    @if ($row->is_default)
                                        <span class="ml-1 rounded-full bg-cyan-100 px-2 py-0.5 text-xs font-semibold text-cyan-800">{{ __('Default') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        @if (!$row->is_default)
                                            <button type="button" wire:click="makeDefault({{ $row->id }})" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Default') }}</button>
                                        @endif
                                        <a href="{{ route('admin.users.groups.edit', array_filter([
                                            'customerGroup' => $row->id,
                                            'search' => $search !== '' ? $search : null,
                                            'adminUserGroupsPage' => $rows->currentPage() > 1 ? $rows->currentPage() : null,
                                        ], static fn (int|string|null $value): bool => $value !== null)) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('admin.common.edit') }}</a>
                                        <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('Delete this group?') }}" class="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('admin.common.delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('No groups yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $rows->links() }}
            </div>
        </div>
        @endunless
    </div>
</div>
