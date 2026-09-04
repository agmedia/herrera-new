<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Content / Slots') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $this->isEdit ? __('Edit Slot') : __('Create Slot') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('Placement binding for blocks. Add multiple target refs in one save using comma/newline.') }}</p>
            </div>
            <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to List') }}</button>
        </div>
    </div>

    <div class="admin-panel admin-form-panel p-6">
        <form wire:submit="save" class="admin-form space-y-4">
            @php
                $selectedBlock = $blockOptions->firstWhere('id', (int) ($form['content_block_id'] ?? 0));
            @endphp

            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Block') }}</label>
                    <select wire:model="form.content_block_id" data-tom-select placeholder="{{ __('Choose block...') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                        <option value="">{{ __('Choose block...') }}</option>
                        @foreach ($blockOptions as $block)
                            <option value="{{ $block->id }}">{{ $block->name }} ({{ $block->code }})</option>
                        @endforeach
                    </select>
                    @error('form.content_block_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Placement') }}</label>
                    <select wire:model="form.placement" data-tom-select class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                        @foreach ($placements as $placementKey => $placementLabel)
                            <option value="{{ $placementKey }}">{{ $placementLabel }} ({{ $placementKey }})</option>
                        @endforeach
                    </select>
                    @error('form.placement') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Surface') }}</label>
                    <select wire:model="form.frontend_variant" data-tom-select data-tom-no-search="1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                        @foreach ($frontendVariants as $frontendVariantKey => $frontendVariantLabel)
                            <option value="{{ $frontendVariantKey }}">{{ $frontendVariantLabel }}</option>
                        @endforeach
                    </select>
                    @error('form.frontend_variant') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($selectedBlock)
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Visual Preview') }}</p>
                        <span class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                            {{ config('content_blocks.types.'.$selectedBlock->type, $selectedBlock->type) }}
                        </span>
                    </div>
                    <div class="grid gap-3 md:grid-cols-[14rem_1fr]">
                        @include('admin.content.partials.block-type-preview', ['type' => $selectedBlock->type, 'size' => 'sm'])
                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                            <p><span class="font-semibold text-slate-700">{{ __('Placement:') }}</span> {{ $form['placement'] ?? '-' }}</p>
                            <p class="mt-1"><span class="font-semibold text-slate-700">{{ __('Target:') }}</span> {{ ($form['target_type'] ?: __('global')) }} @if(!empty($form['target_ref'])) / {{ $form['target_ref'] }} @endif</p>
                            <p class="mt-1"><span class="font-semibold text-slate-700">{{ __('Block:') }}</span> {{ $selectedBlock->name }} ({{ $selectedBlock->code }})</p>
                        </div>
                    </div>
                </div>
            @endif

            <div class="grid gap-3 md:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Target Type') }}</label>
                    <select wire:model="form.target_type" data-tom-select data-tom-no-search="1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring">
                        @foreach ($targetTypes as $targetKey => $targetLabel)
                            <option value="{{ $targetKey }}">{{ $targetLabel }}</option>
                        @endforeach
                    </select>
                    @error('form.target_type') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Target Ref(s) (slug/id)') }}</label>
                    <textarea rows="3" wire:model="form.target_ref" placeholder="{{ __('Single: asian-food') }}&#10;{{ __('Multiple: asian-food, rice-noodles, sauces-spices') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring"></textarea>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Use comma or new line for multiple refs.') }}</p>
                    @error('form.target_ref') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Sort Order') }}</label>
                    <input type="number" wire:model="form.sort_order" min="0" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                    @error('form.sort_order') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Starts At') }}</label>
                    <input type="datetime-local" wire:model="form.starts_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                    @error('form.starts_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Ends At') }}</label>
                    <input type="datetime-local" wire:model="form.ends_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring" />
                    @error('form.ends_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <button
                    type="button"
                    wire:click="$toggle('form.is_active')"
                    class="admin-switch"
                    data-state="{{ $form['is_active'] ? 'on' : 'off' }}"
                    role="switch"
                    aria-checked="{{ $form['is_active'] ? 'true' : 'false' }}"
                    aria-label="{{ __('Toggle slot active state') }}"
                >
                    <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                    <span class="admin-switch-label">{{ $form['is_active'] ? __('Active') : __('Inactive') }}</span>
                </button>
            </div>

            <div class="flex items-center gap-2 pt-1">
                <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ $this->isEdit ? __('Update') : __('Create') }}
                </button>
                <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                    {{ __('Cancel') }}
                </button>
            </div>
        </form>
    </div>
</div>
