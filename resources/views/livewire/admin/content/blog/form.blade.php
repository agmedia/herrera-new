<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Content / Blog') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $isEdit ? __('Edit Blog Post') : __('Create Blog Post') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('Core post data, locale content and SEO.') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="admin-chip">{{ __('Locale:') }} {{ $form['locale'] }}</span>
                <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">{{ __('Back to List') }}</button>
            </div>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="admin-panel admin-form-panel p-3 sm:p-4">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="setTab('content')" class="rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] {{ $activeTab === 'content' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}">
                    {{ __('Sadržaj') }}
                </button>
                <button type="button" wire:click="setTab('seo')" class="rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] {{ $activeTab === 'seo' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}">
                    {{ __('SEO') }}
                </button>
                <button type="button" wire:click="setTab('media')" class="rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] {{ $activeTab === 'media' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}">
                    {{ __('Media') }}
                </button>
            </div>
        </div>

        @if ($activeTab === 'content')
            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">{{ __('Core Data') }}</p>

                <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                    <div class="sm:col-span-4" style="grid-column: span 4;">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Title') }}</label>
                        <input type="text" wire:model.live.debounce.250ms="form.title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.title') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-3" style="grid-column: span 3;">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Code') }}</label>
                        <input type="text" wire:model.live.debounce.250ms="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono lowercase" />
                        @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-3" style="grid-column: span 3;">
                        <div class="flex items-center justify-between">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Slug') }}</label>
                            <button type="button" wire:click="generateSlug" class="text-xs font-semibold text-slate-600 hover:text-slate-900">{{ __('Generate') }}</button>
                        </div>
                        <input type="text" wire:model.live.debounce.250ms="form.slug" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm lowercase" />
                        @error('form.slug') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2" style="grid-column: span 2;">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                        <select wire:model.live="form.locale" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm lowercase">
                            @foreach ($adminLocaleOptions as $localeOption)
                                <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                            @endforeach
                        </select>
                        @error('form.locale') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-3 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                    <div class="sm:col-span-3" style="grid-column: span 3;">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Published At') }}</label>
                        <input type="date" wire:model="form.published_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.published_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2" style="grid-column: span 2;">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Sort Order') }}</label>
                        <input type="number" min="0" wire:model="form.sort_order" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.sort_order') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-7 flex items-end gap-3" style="grid-column: span 7;">
                        <button
                            type="button"
                            wire:click="$toggle('form.is_active')"
                            class="admin-switch"
                            data-state="{{ $form['is_active'] ? 'on' : 'off' }}"
                            role="switch"
                            aria-checked="{{ $form['is_active'] ? 'true' : 'false' }}"
                            aria-label="{{ __('Toggle blog post active state') }}"
                        >
                            <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                            <span class="admin-switch-label">{{ $form['is_active'] ? __('admin.common.active') : __('admin.common.inactive') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="$toggle('form.is_featured')"
                            class="admin-switch"
                            data-state="{{ $form['is_featured'] ? 'on' : 'off' }}"
                            role="switch"
                            aria-checked="{{ $form['is_featured'] ? 'true' : 'false' }}"
                            aria-label="{{ __('Toggle featured state') }}"
                        >
                            <span class="admin-switch-track"><span class="admin-switch-thumb"></span></span>
                            <span class="admin-switch-label">{{ $form['is_featured'] ? __('Featured') : __('Normal') }}</span>
                        </button>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Excerpt') }}</label>
                    <textarea rows="3" wire:model="form.excerpt" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>

                <div class="mt-4" wire:key="blog-post-body-{{ $postId ?? 'new' }}-{{ $form['locale'] }}">
                    <label for="blog-post-body-html" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Body') }}</label>
                    <x-admin.quill-field id="blog-post-body-html" rows="14" wire:model.live.debounce.300ms="form.body_html" />
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Smart Link') }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Odaberi tip i cilj, pa umetni link na trenutno označeni tekst u editoru.') }}</p>
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Tip') }}</label>
                            <select wire:model.live="linkType" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="category">{{ __('Kategorija artikala') }}</option>
                                <option value="product">{{ __('Artikl') }}</option>
                                <option value="blog">{{ __('Blog') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Pretraga') }}</label>
                            <input type="text" wire:model.live.debounce.250ms="linkSearch" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('Upiši naziv/slug/SKU...') }}">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Rezultat') }}</label>
                            <select wire:model="linkTargetId" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="">{{ __('Odaberi') }}</option>
                                @foreach ($this->linkTargetOptions as $row)
                                    <option value="{{ $row['id'] }}">{{ $row['label'] }} @if(!empty($row['hint'])) ({{ $row['hint'] }}) @endif</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                        @if ($this->linkTargetOptions->count() > 0)
                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700">
                                {{ $this->linkTargetOptions->count() }} {{ __('rezultata') }}
                            </span>
                        @else
                            <span class="rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 font-semibold text-rose-700">
                                {{ __('Nema rezultata') }}
                            </span>
                        @endif
                        @php
                            $selectedLinkRow = $this->linkTargetOptions->firstWhere('id', (int) ($linkTargetId ?? 0));
                        @endphp
                        @if ($selectedLinkRow)
                            <span class="text-slate-600">
                                {{ __('Odabrano:') }} <strong>{{ $selectedLinkRow['label'] }}</strong>
                            </span>
                        @endif
                    </div>
                    <div class="mt-3">
                        <button type="button" wire:click="insertEditorLink" class="rounded-lg border border-slate-900 bg-slate-900 px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] text-white hover:bg-slate-700">
                            {{ __('Umetni link u editor') }}
                        </button>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 xl:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Blog kategorije') }}</p>
                        <input type="text" wire:model.live.debounce.250ms="categorySearch" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('Pretraga kategorija...') }}">
                        <div class="mt-3 max-h-60 overflow-auto rounded-xl border border-slate-200 bg-white p-2">
                            @forelse ($this->filteredCategoryOptions as $category)
                                <button type="button" wire:click="addCategory({{ $category['id'] }})" class="mb-1 flex w-full items-center justify-between rounded-lg border border-slate-200 px-2 py-1.5 text-left text-sm text-slate-700 hover:bg-slate-50">
                                    <span>{{ $category['label'] }}</span>
                                    <span class="text-xs font-semibold text-slate-500">+</span>
                                </button>
                            @empty
                                <p class="px-1 py-1 text-xs text-slate-500">{{ __('Nema rezultata') }}</p>
                            @endforelse
                        </div>
                        <div class="mt-3">
                            <p class="mb-1 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Odabrano') }}</p>
                            <div class="space-y-1">
                                @forelse ($this->selectedCategoryRows as $row)
                                    <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-2 py-1.5 text-sm">
                                        <span>{{ $row['label'] }}</span>
                                        <button type="button" wire:click="removeCategory({{ $row['id'] }})" class="text-xs font-semibold text-rose-600 hover:text-rose-700">{{ __('Makni') }}</button>
                                    </div>
                                @empty
                                    <p class="text-xs text-slate-500">{{ __('Nema odabranih kategorija.') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Vezani artikli') }}</p>
                        <input type="text" wire:model.live.debounce.250ms="productSearch" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="{{ __('Pretraga po nazivu, kodu ili SKU...') }}">
                        <div class="mt-3 max-h-60 overflow-auto rounded-xl border border-slate-200 bg-white p-2">
                            @forelse ($this->filteredProductOptions as $product)
                                <button type="button" wire:click="addRelatedProduct({{ $product['id'] }})" class="mb-1 flex w-full items-center justify-between rounded-lg border border-slate-200 px-2 py-1.5 text-left text-sm text-slate-700 hover:bg-slate-50">
                                    <span>{{ $product['label'] }} @if($product['sku'] !== '') / {{ $product['sku'] }} @endif ({{ $product['code'] }})</span>
                                    <span class="text-xs font-semibold text-slate-500">+</span>
                                </button>
                            @empty
                                <p class="px-1 py-1 text-xs text-slate-500">{{ __('Nema rezultata') }}</p>
                            @endforelse
                        </div>
                        <div class="mt-3">
                            <p class="mb-1 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Odabrano (redoslijed slidera)') }}</p>
                            <div class="space-y-1">
                                @forelse ($this->selectedRelatedProductRows as $row)
                                    <div class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-2 py-1.5 text-sm">
                                        <span>{{ $row['label'] }}</span>
                                        <button type="button" wire:click="removeRelatedProduct({{ $row['id'] }})" class="text-xs font-semibold text-rose-600 hover:text-rose-700">{{ __('Makni') }}</button>
                                    </div>
                                @empty
                                    <p class="text-xs text-slate-500">{{ __('Nema odabranih artikala.') }}</p>
                                @endforelse
                            </div>
                        </div>
                        @error('form.related_product_ids.*') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        @endif

        @if ($activeTab === 'seo')
            <div class="admin-panel admin-form-panel p-6">
                <p class="admin-section-title">{{ __('SEO & Payload') }}</p>
                <div class="mt-4">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Meta Title') }}</label>
                    <input type="text" wire:model.live.debounce.250ms="form.meta_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.meta_title') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Meta Description') }}</label>
                    <textarea rows="4" wire:model.live.debounce.250ms="form.meta_description" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
                <div class="mt-3">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Post Payload JSON') }}</label>
                    <textarea rows="8" wire:model="form.payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                    @error('form.payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-3">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Translation Payload JSON') }}</label>
                    <textarea rows="8" wire:model="form.translation_payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                    @error('form.translation_payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        @if ($activeTab === 'media')
            <livewire:admin.media.manager
                :model-class="\App\Models\Content\Blog\BlogPost::class"
                :model-id="$postId"
                :locale="$form['locale']"
                :wire:key="'blog-post-media-manager-'.($postId ?? 'new').'-'.$form['locale']"
            />
        @endif

        <div class="admin-form-actions flex items-center gap-2 pt-2">
            <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                {{ $isEdit ? __('Update Blog Post') : __('Create Blog Post') }}
            </button>
            <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                {{ __('Cancel') }}
            </button>
        </div>
    </form>
</div>
