<div class="space-y-6">
    @unless ($editPage || $createPage)
    <div class="admin-panel admin-search-panel p-6">
        @php
            $optionTranslation = $option->translations->first();
            $optionName = $optionTranslation?->name ?? $option->code;
        @endphp

        <div class="flex items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{{ __('Option Values') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('Option') }}: <span class="font-semibold text-slate-800">{{ $optionName }}</span> ({{ $option->code }})</p>
                <p class="mt-2 text-xs text-slate-500">{{ __('Items per page') }}: <span class="admin-chip">{{ $perPage }}</span></p>
            </div>

            <div class="flex w-[64rem] max-w-full items-end justify-end gap-3">
                <div class="grid w-full max-w-[56rem] items-end gap-3" style="grid-template-columns: minmax(26rem, 1fr) 8rem;">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Code, name or slug...') }}"
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
                <a href="{{ route('admin.options.values.create', array_filter([
                    'option' => $option->id,
                    'locale' => $locale,
                    'search' => $search !== '' ? $search : null,
                    'page' => $rows->currentPage() > 1 ? $rows->currentPage() : null,
                ], static fn (int|string|null $value): bool => $value !== null)) }}" class="whitespace-nowrap rounded-xl bg-cyan-700 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ __('Create Value') }}
                </a>
                <a href="{{ route('admin.options.edit', ['option' => $option->id, 'locale' => $locale]) }}" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                    {{ __('Back to Option') }}
                </a>
            </div>
        </div>
    </div>
    @endunless

    @unless ($editPage || $createPage)
    <div class="admin-panel admin-panel-soft p-5">
        <h2 class="admin-section-title">{{ __('admin.common.items') }}</h2>

        <div class="mt-4 overflow-x-auto">
            <table class="admin-items-table min-w-full text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Value') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Slug') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.sort') }}</th>
                        <th class="px-3 py-2 text-center font-semibold">{{ __('admin.common.state') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $tr = $row->translations->first();
                            $swatchPath = trim((string) data_get($row->payload, 'swatch_image_path', ''));
                            $swatchUrl = $swatchPath !== ''
                                ? (str_starts_with($swatchPath, 'http://') || str_starts_with($swatchPath, 'https://') || str_starts_with($swatchPath, '//') || str_starts_with($swatchPath, '/')
                                    ? $swatchPath
                                    : \Illuminate\Support\Facades\Storage::disk('public')->url($swatchPath))
                                : '';
                        @endphp
                        <tr>
                            <td class="px-3 py-2 text-slate-800">
                                <div class="flex items-center gap-3">
                                    @if ($swatchUrl !== '')
                                        <img src="{{ $swatchUrl }}" alt="" class="h-10 w-10 rounded-lg border border-slate-200 bg-slate-100 object-cover" />
                                    @endif
                                    <div>
                                        <div class="font-medium">{{ $tr?->name ?? __('(missing name)') }}</div>
                                        <div class="text-xs text-slate-500">{{ $row->code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-slate-700">{{ $tr?->slug ?? '-' }}</td>
                            <td class="px-3 py-2 text-center text-slate-700">{{ $row->sort_order }}</td>
                            <td class="px-3 py-2 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $row->is_active ? __('admin.common.active') : __('admin.common.inactive') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('admin.options.values.edit', array_filter([
                                        'option' => $option->id,
                                        'value' => $row->id,
                                        'locale' => $locale,
                                        'search' => $search !== '' ? $search : null,
                                        'page' => $rows->currentPage() > 1 ? $rows->currentPage() : null,
                                    ], static fn (int|string|null $value): bool => $value !== null)) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('admin.common.edit') }}</a>
                                    <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('Delete this value?') }}" class="rounded-lg border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('admin.common.delete') }}</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-8 text-center text-sm text-slate-500">{{ __('No values yet.') }}</td>
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

    @if ($editPage || $createPage)
    <div class="admin-panel admin-form-panel p-6">
        <h2 class="admin-section-title">{{ $editingId ? __('Edit value') : __('Create value') }}</h2>

        <form wire:submit="save" class="admin-form mt-4 space-y-4">
            <div class="grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Code') }}</label>
                    <input type="text" wire:model="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono" />
                    @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Sort Order') }}</label>
                    <input type="number" min="0" wire:model="form.sort_order" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.sort_order') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                    <select disabled data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-sm lowercase text-slate-600">
                        @foreach ($adminLocaleOptions as $localeOption)
                            <option value="{{ $localeOption }}" @selected($localeOption === $locale)>{{ $localeOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="grid-column: span 4;" class="flex items-end">
                    <button
                        type="button"
                        wire:click="$toggle('form.is_active')"
                        class="admin-switch"
                        data-state="{{ $form['is_active'] ? 'on' : 'off' }}"
                        role="switch"
                        aria-checked="{{ $form['is_active'] ? 'true' : 'false' }}"
                        aria-label="{{ __('Toggle option value active state') }}"
                    >
                        <span class="admin-switch-track">
                            <span class="admin-switch-thumb"></span>
                        </span>
                        <span class="admin-switch-label">{{ $form['is_active'] ? __('admin.common.active') : __('admin.common.inactive') }}</span>
                    </button>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Name') }}</label>
                    <input type="text" wire:model="form.name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Slug') }}</label>
                        <button type="button" wire:click="generateSlug" class="text-xs font-semibold text-slate-600 hover:text-slate-900">{{ __('Generate') }}</button>
                    </div>
                    <input type="text" wire:model="form.slug" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm lowercase" />
                    @error('form.slug') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            @php $swatchPreviewUrl = $this->swatchPreviewUrl; @endphp
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="grid gap-4 md:grid-cols-[8rem_minmax(0,1fr)] md:items-start">
                    <div class="flex flex-col items-start gap-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Swatch Preview') }}</span>
                        <div class="flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-white">
                            @if ($swatchPreviewUrl)
                                <img src="{{ $swatchPreviewUrl }}" alt="" class="h-full w-full object-cover" />
                            @else
                                <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{{ __('No image') }}</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Swatch / Thumbnail Image') }}</label>
                        <input type="file" wire:model="swatchImageUpload" accept="image/*" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm" />
                        <p class="mt-1 text-xs text-slate-500">{{ __('Used in storefront color filters when you want a real image thumbnail instead of an autogenerated swatch.') }}</p>
                        @if ($currentSwatchImagePath)
                            <p class="mt-1 text-xs text-slate-500">{{ __('Current:') }} {{ $currentSwatchImagePath }}</p>
                        @endif
                        @error('swatchImageUpload') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

                        @if ($swatchPreviewUrl)
                            <button type="button" wire:click="clearSwatchImage" class="mt-3 rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                {{ __('Remove image') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Value Payload JSON') }}</label>
                    <textarea rows="5" wire:model="form.payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                    @error('form.payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Translation Payload JSON') }}</label>
                    <textarea rows="5" wire:model="form.translation_payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                    @error('form.translation_payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="admin-form-actions flex items-center gap-2 pt-2">
                <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ $editingId ? __('Update Value') : __('Create Value') }}
                </button>
                @if ($editPage || $createPage)
                    <a href="{{ route('admin.options.values', array_filter([
                        'option' => $option->id,
                        'locale' => $locale,
                        'search' => $search !== '' ? $search : null,
                        'page' => $returnPage > 1 ? $returnPage : null,
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
</div>
