<div class="space-y-6">
    <div class="admin-panel admin-form-panel p-6">
        <h1 class="text-xl font-semibold tracking-tight">{{ __('Roles & Abilities') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('Create abilities and assign them per role. Changes in matrix are saved automatically.') }}</p>

        <form wire:submit="createAbility" class="mt-4 grid items-end gap-3" style="grid-template-columns: minmax(22rem, 1.4fr) minmax(16rem, 1fr) 12rem 8rem;">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Name (slug)') }}</label>
                <input
                    type="text"
                    wire:model="form.name"
                    placeholder="{{ __('users.view') }}"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm font-mono lowercase"
                />
                @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Title') }}</label>
                <input
                    type="text"
                    wire:model="form.title"
                    placeholder="{{ __('View users') }}"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
                @error('form.title') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Group') }}</label>
                <select wire:model="form.group" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    @foreach ($groupOptions as $groupKey => $groupLabel)
                        <option value="{{ $groupKey }}">{{ $groupLabel }}</option>
                    @endforeach
                </select>
                @error('form.group') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    Add
                </button>
            </div>
        </form>

        <div class="mt-4 grid items-end gap-3" style="grid-template-columns: minmax(28rem, 1fr) auto;">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Search Abilities') }}</label>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('users.view, users.manage...') }}"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
            </div>
            <div class="pb-1 text-xs text-slate-500">
                Changes are saved automatically.
            </div>
        </div>
    </div>

    <div class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('Ability Matrix') }}</h2>
        <p class="mt-2 text-sm text-slate-600">
            Super Administrator is not shown in the matrix because it always has wildcard access to all abilities.
        </p>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Ability') }}</th>
                        @foreach ($roles as $role)
                            <th class="px-3 py-2 text-center font-semibold uppercase">{{ $role->title ?: $role->name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($abilityGroups as $groupKey => $group)
                        <tr class="bg-slate-50">
                            <td class="px-3 py-2 font-semibold text-slate-800">
                                {{ $group['label'] }}
                            </td>
                            <td colspan="{{ max(1, $roles->count()) }}" class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    wire:click="toggleGroup('{{ $groupKey }}')"
                                    class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                                >
                                    {{ ($collapsedGroups[$groupKey] ?? false) ? 'Show' : 'Hide' }}
                                </button>
                            </td>
                        </tr>

                        @if (!($collapsedGroups[$groupKey] ?? false))
                            @foreach ($group['abilities'] as $ability)
                                <tr>
                                    <td class="px-3 py-2 text-slate-800">
                                        <div class="font-medium">{{ $ability->title ?: \Illuminate\Support\Str::of($ability->name)->replace(['.', '_', '-'], ' ')->title() }}</div>
                                        <div class="font-mono text-xs text-slate-500">{{ $ability->name }}</div>
                                    </td>
                                    @foreach ($roles as $role)
                                        <td class="px-3 py-2 text-center">
                                            <input
                                                type="checkbox"
                                                class="h-5 w-5 rounded border-slate-300 text-cyan-700 focus:ring-cyan-200"
                                                @checked(($permissionMap[$ability->id][$role->id] ?? false))
                                                wire:change="togglePermission({{ $ability->id }}, {{ $role->id }})"
                                            />
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ 1 + $roles->count() }}" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('No abilities yet. Add your first ability above.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
