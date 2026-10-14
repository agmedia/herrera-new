<div class="space-y-6">
    <div class="admin-panel p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ __('supplier_catalogs.title') }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ __('supplier_catalogs.admin_description') }}</p>
            </div>
            <a href="{{ route('supplier-catalogs.index') }}" target="_blank" rel="noopener" class="text-sm font-semibold text-cyan-700">{{ __('supplier_catalogs.view_section') }} ↗</a>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="admin-panel space-y-5 p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="admin-section-title">{{ $catalogId ? __('supplier_catalogs.edit') : __('supplier_catalogs.new') }}</h2>
                @if ($catalogId)<button type="button" wire:click="newCatalog" class="text-sm font-semibold text-cyan-700">{{ __('supplier_catalogs.new') }}</button>@endif
            </div>
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="supplier-catalog-code" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.code') }}</label>
                        <input id="supplier-catalog-code" wire:model="form.code" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm" required>
                        @error('form.code')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="supplier-catalog-brand" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.brand') }}</label>
                        <input id="supplier-catalog-brand" wire:model="form.brand" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm" required>
                        @error('form.brand')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <label for="supplier-catalog-title" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.catalog_title') }}</label>
                    <input id="supplier-catalog-title" wire:model="form.title" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm" required>
                    @error('form.title')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="supplier-catalog-description" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.description') }}</label>
                    <textarea id="supplier-catalog-description" wire:model="form.description" rows="3" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm"></textarea>
                    @error('form.description')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="supplier-catalog-year" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.year') }}</label>
                        <input id="supplier-catalog-year" type="number" min="2000" max="2100" wire:model="form.year" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm">
                        @error('form.year')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="supplier-catalog-language" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.language') }}</label>
                        <input id="supplier-catalog-language" wire:model="form.language" placeholder="EN / HR" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm">
                        @error('form.language')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="supplier-catalog-sort" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.sort_order') }}</label>
                        <input id="supplier-catalog-sort" type="number" min="0" wire:model="form.sort_order" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm">
                        @error('form.sort_order')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <label for="supplier-catalog-source" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.source_type') }}</label>
                    <select id="supplier-catalog-source" wire:model.live="form.source_type" class="admin-select w-full rounded-lg border px-3 py-2 text-sm">
                        <option value="external">{{ __('supplier_catalogs.external') }}</option>
                        <option value="upload">{{ __('supplier_catalogs.upload') }}</option>
                    </select>
                    @error('form.source_type')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                @if ($form['source_type'] === 'external')
                    <div>
                        <label for="supplier-catalog-url" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.external_url') }}</label>
                        <input id="supplier-catalog-url" type="url" wire:model="form.external_url" placeholder="https://" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm" required>
                        @error('form.external_url')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="supplier-catalog-format" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.format') }}</label>
                        <select id="supplier-catalog-format" wire:model="form.format" class="admin-select w-full rounded-lg border px-3 py-2 text-sm">
                            <option value="pdf">{{ __('supplier_catalogs.pdf') }}</option>
                            <option value="web">{{ __('supplier_catalogs.web') }}</option>
                        </select>
                        @error('form.format')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div>
                    <label for="supplier-catalog-source-url" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.source_url') }}</label>
                    <input id="supplier-catalog-source-url" type="url" wire:model="form.source_url" placeholder="https://" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm">
                    @error('form.source_url')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="supplier-catalog-cover-url" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.cover_url') }}</label>
                    <input id="supplier-catalog-cover-url" wire:model="form.cover_url" placeholder="https://" class="admin-search-input w-full rounded-lg border px-3 py-2 text-sm">
                    @error('form.cover_url')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold"><input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-cyan-700">{{ __('supplier_catalogs.publish') }}</label>
                    @error('form.is_active')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-cyan-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-cyan-800 disabled:opacity-50">{{ __('supplier_catalogs.save') }}</button>
            </form>

            <div class="border-t border-slate-200 pt-5">
                <h3 class="mb-3 font-semibold">{{ __('supplier_catalogs.files') }}</h3>
                @if ($editingCatalog)
                    <form method="POST" action="{{ route('admin.content.supplier-catalogs.upload', $editingCatalog) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <input type="hidden" name="MAX_FILE_SIZE" value="{{ $uploadLimitBytes }}">
                        <div>
                            <label for="supplier-catalog-pdf" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.pdf_file') }}</label>
                            <input id="supplier-catalog-pdf" type="file" name="document" accept="application/pdf,.pdf" class="w-full text-sm">
                            <p class="mt-1 text-xs text-slate-500">{{ __('supplier_catalogs.file_hint', ['size' => $uploadLimitMb]) }}</p>
                            @if ($editingCatalog->document_name)<p class="mt-1 break-all text-xs text-slate-600">{{ $editingCatalog->document_name }}</p>@endif
                            @error('document')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="supplier-catalog-cover" class="mb-1 block text-sm font-semibold">{{ __('supplier_catalogs.cover_file') }}</label>
                            <input id="supplier-catalog-cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp" class="w-full text-sm">
                            @error('cover')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                        </div>
                        <p class="text-xs text-slate-500">{{ __('supplier_catalogs.replace_hint') }}</p>
                        <button type="submit" class="rounded-xl border border-cyan-700 px-4 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-50">{{ __('supplier_catalogs.upload_files') }}</button>
                    </form>
                @else
                    <p class="text-sm text-slate-500">{{ __('supplier_catalogs.save_before_upload') }}</p>
                @endif
            </div>
        </div>

        <div class="admin-panel p-6">
            <label for="supplier-catalog-search" class="mb-2 block text-sm font-semibold">{{ __('supplier_catalogs.search') }}</label>
            <input id="supplier-catalog-search" wire:model.live.debounce.300ms="search" class="admin-search-input mb-5 w-full rounded-lg border px-3 py-2 text-sm">
            <div class="divide-y divide-slate-200">
                @forelse ($rows as $row)
                    <div class="flex flex-wrap items-start justify-between gap-3 py-4" wire:key="supplier-catalog-{{ $row->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700">{{ $row->brand }} @if ($row->year) · {{ $row->year }} @endif</p>
                            <p class="mt-1 font-semibold text-slate-900">{{ $row->title }}</p>
                            <p class="mt-1 text-xs {{ $row->is_active ? 'text-emerald-700' : 'text-slate-500' }}">{{ __($row->is_active ? 'supplier_catalogs.active' : 'supplier_catalogs.inactive') }} · {{ __($row->source_type === 'external' ? 'supplier_catalogs.external' : 'supplier_catalogs.upload') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="edit({{ $row->id }})" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold">{{ __('supplier_catalogs.edit') }}</button>
                            <button type="button" wire:click="delete({{ $row->id }})" wire:confirm="{{ __('supplier_catalogs.delete_confirm') }}" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700">{{ __('supplier_catalogs.delete') }}</button>
                        </div>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-slate-500">{{ __('supplier_catalogs.admin_empty') }}</p>
                @endforelse
            </div>
            <div class="mt-5">{{ $rows->links() }}</div>
        </div>
    </div>
</div>
