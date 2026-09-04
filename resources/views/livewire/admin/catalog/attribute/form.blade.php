@php
    $groupTranslation = $group?->translations->firstWhere('locale', $form['locale'])
        ?? $group?->translations->firstWhere('locale', config('app.locale', 'en'))
        ?? $group?->translations->first();
    $groupName = $groupTranslation?->name
        ?? ($group ? str($group->code)->replace(['_', '-'], ' ')->title() : __('Attribute group'));
    [$sourceLabel, $sourceClass] = match ($attributeSource) {
        'msan_specification' => ['M SAN · '.__('automatic'), 'border-indigo-200 bg-indigo-50 text-indigo-800'],
        'termol.hr description' => ['Termol · '.__('automatic'), 'border-amber-200 bg-amber-50 text-amber-800'],
        'kozo_proizvodi' => ['Kozo · '.__('automatic'), 'border-sky-200 bg-sky-50 text-sky-800'],
        '', 'manual' => [__('Manual'), 'border-slate-200 bg-slate-50 text-slate-700'],
        default => [__('Import'), 'border-violet-200 bg-violet-50 text-violet-800'],
    };
@endphp

<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Attributes') }} / {{ $groupName }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $isEdit ? __('Edit Attribute') : __('Add new attribute') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('This value will be available only inside the selected attribute group.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($isEdit)<span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $sourceClass }}">{{ $sourceLabel }}</span>@endif
                <span class="admin-chip">{{ __('Locale:') }} {{ $form['locale'] }}</span>
                <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to group') }}</button>
            </div>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="admin-panel admin-form-panel p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="admin-section-title">{{ __('Attribute details') }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ __('Group') }}: <span class="font-semibold text-slate-800">{{ $groupName }}</span></p>
                </div>
                <span class="admin-chip">{{ $group?->type === 'multi' ? __('Multiple values allowed') : __('One value per article') }}</span>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Code') }}</label>
                    <input type="text" wire:model="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono" />
                    @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Sort Order') }}</label>
                    <input type="number" min="0" wire:model="form.sort_order" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.sort_order') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                    <select wire:model.live="form.locale" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm lowercase">
                        @foreach ($adminLocaleOptions as $localeOption)
                            <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                        @endforeach
                    </select>
                    @error('form.locale') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end pb-1">
                    <button type="button" wire:click="$toggle('form.is_active')" class="admin-switch" data-state="{{ $form['is_active'] ? 'on' : 'off' }}" role="switch" aria-checked="{{ $form['is_active'] ? 'true' : 'false' }}" aria-label="{{ __('Toggle attribute active state') }}">
                        <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                        <span class="admin-switch-label">{{ $form['is_active'] ? __('admin.common.active') : __('admin.common.inactive') }}</span>
                    </button>
                </div>
            </div>

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
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

            <div class="mt-4">
                <label for="attribute-description-html" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Description') }}</label>
                <x-admin.quill-field id="attribute-description-html" rows="5" wire:model.live.debounce.300ms="form.description" />
            </div>
        </div>

        <details class="admin-panel admin-form-panel p-6">
            <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __('Advanced import data') }}</summary>
            <p class="mt-2 text-xs text-slate-500">{{ __('Change these fields only when maintaining an integration.') }}</p>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Attribute Payload JSON') }}</label>
                    <textarea rows="8" wire:model="form.payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                    @error('form.payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Translation Payload JSON') }}</label>
                    <textarea rows="8" wire:model="form.translation_payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                    @error('form.translation_payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </details>

        <div class="admin-form-actions flex items-center gap-2 pt-2">
            <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">{{ $isEdit ? __('Update Attribute') : __('Create Attribute') }}</button>
            <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Cancel') }}</button>
        </div>
    </form>
</div>
