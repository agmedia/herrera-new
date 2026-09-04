<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Catalog / Categories') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $isEdit ? __('Edit Category') : __('Create Category') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('Dedicated edit page for large trees and cleaner workflow.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="admin-chip">
                    {{ __('Scope:') }}
                    {{ match ($form['scope']) {
                        'catalog' => __('Catalog'),
                        'blog' => __('Blog'),
                        'page' => __('Pages'),
                        default => str($form['scope'])->replace('_', ' ')->title()->toString(),
                    } }}
                </span>
                <span class="admin-chip">{{ __('Locale:') }} {{ $form['locale'] }}</span>
                <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to List') }}</button>
            </div>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Core Data') }}</p>

            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Scope') }}</label>
                    <select wire:model.live="form.scope" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($this->scopeOptions as $scope)
                            @php
                                $scopeLabel = match ($scope) {
                                    'catalog' => __('Catalog'),
                                    'blog' => __('Blog'),
                                    'page' => __('Pages'),
                                    default => str($scope)->replace('_', ' ')->title()->toString(),
                                };
                            @endphp
                            <option value="{{ $scope }}">{{ $scopeLabel }}</option>
                        @endforeach
                    </select>
                    @error('form.scope') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Parent Category') }}</label>
                    <select wire:model="form.parent_id" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="">{{ __('(Root)') }}</option>
                        @foreach ($this->parentOptions as $parent)
                            @php
                                $parentTranslation = $parent->translations->first();
                                $parentName = $parentTranslation?->name ?? ($parent->code ?: __('Category #:id', ['id' => $parent->id]));
                                $pad = str_repeat('— ', max(0, (int) $parent->depth));
                            @endphp
                            <option value="{{ $parent->id }}">{{ $pad.$parentName }}</option>
                        @endforeach
                    </select>
                    @error('form.parent_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Code') }}</label>
                    <input type="text" wire:model="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono" />
                    @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                    <select wire:model.live="form.locale" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm lowercase">
                        @foreach ($this->localeOptions as $localeOption)
                            <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                        @endforeach
                    </select>
                    @error('form.locale') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Sort Order') }}</label>
                    <input type="number" wire:model="form.sort_order" min="0" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.sort_order') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Starts At') }}</label>
                    <input type="datetime-local" wire:model="form.starts_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.starts_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Ends At') }}</label>
                    <input type="datetime-local" wire:model="form.ends_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.ends_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    wire:click="$toggle('form.is_active')"
                    class="admin-switch"
                    data-state="{{ $form['is_active'] ? 'on' : 'off' }}"
                    role="switch"
                    aria-checked="{{ $form['is_active'] ? 'true' : 'false' }}"
                    aria-label="{{ __('Toggle category active state') }}"
                >
                    <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                    <span class="admin-switch-label">{{ $form['is_active'] ? __('admin.common.active') : __('admin.common.inactive') }}</span>
                </button>

                <button
                    type="button"
                    wire:click="$toggle('form.show_in_menu')"
                    class="admin-switch"
                    data-state="{{ $form['show_in_menu'] ? 'on' : 'off' }}"
                    role="switch"
                    aria-checked="{{ $form['show_in_menu'] ? 'true' : 'false' }}"
                    aria-label="{{ __('Toggle category menu visibility') }}"
                >
                    <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                    <span class="admin-switch-label">{{ $form['show_in_menu'] ? __('In Menu') : __('Hidden') }}</span>
                </button>
            </div>

            @if ($form['scope'] === \App\Models\Catalog\Category\Category::SCOPE_CATALOG)
                <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Category Page') }}</p>
                    <p class="mt-2 text-sm text-slate-600">{{ __('Use category page as classic product listing or as widget landing page with content blocks.') }}</p>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            wire:click="$toggle('form.catalog_show_filters')"
                            class="admin-switch"
                            data-state="{{ $form['catalog_show_filters'] ? 'on' : 'off' }}"
                            role="switch"
                            aria-checked="{{ $form['catalog_show_filters'] ? 'true' : 'false' }}"
                            aria-label="{{ __('Toggle category filters visibility') }}"
                        >
                            <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                            <span class="admin-switch-label">{{ $form['catalog_show_filters'] ? __('Show Filters') : __('Hide Filters') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="$toggle('form.catalog_show_products')"
                            class="admin-switch"
                            data-state="{{ $form['catalog_show_products'] ? 'on' : 'off' }}"
                            role="switch"
                            aria-checked="{{ $form['catalog_show_products'] ? 'true' : 'false' }}"
                            aria-label="{{ __('Toggle category product listing visibility') }}"
                        >
                            <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                            <span class="admin-switch-label">{{ $form['catalog_show_products'] ? __('Show Products') : __('Hide Products') }}</span>
                        </button>
                    </div>

                    <div class="mt-5 border-t border-slate-200 pt-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Pravila dostave kategorije') }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Oznake se primjenjuju na sve artikle u ovoj kategoriji i kombiniraju s oznakama pojedinog artikla.') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($shippingLabelOptions as $value => $label)
                                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                    <input type="checkbox" value="{{ $value }}" wire:model="form.shipping_labels" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('form.shipping_labels.*') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">{{ __('Content') }}</p>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
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

                <div class="mt-3">
                    <label for="category-description-html" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Description') }}</label>
                    <x-admin.quill-field id="category-description-html" rows="6" wire:model.live.debounce.300ms="form.description" />
                </div>
            </div>

            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">{{ __('SEO & Payload') }}</p>

                <div class="mt-4">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Meta Title') }}</label>
                    <input type="text" wire:model="form.meta_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.meta_title') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-3">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Meta Description') }}</label>
                    <textarea rows="3" wire:model="form.meta_description" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                    @error('form.meta_description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-3">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Category Payload JSON') }}</label>
                    <textarea rows="5" wire:model="form.payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                    @error('form.payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-3">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Translation Payload JSON') }}</label>
                    <textarea rows="5" wire:model="form.translation_payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                    @error('form.translation_payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <livewire:admin.media.manager
            :model-class="\App\Models\Catalog\Category\Category::class"
            :model-id="$categoryId"
            :locale="$form['locale']"
            :wire:key="'category-media-manager-'.($categoryId ?? 'new').'-'.$form['locale']"
        />

        <div class="admin-panel admin-form-panel p-6">
            <div class="admin-form-actions flex items-center gap-2 pt-0">
                <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                    {{ $isEdit ? __('Update Category') : __('Create Category') }}
                </button>
                <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                    {{ __('Cancel') }}
                </button>
            </div>
        </div>
    </form>
</div>
