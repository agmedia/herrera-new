<div class="space-y-5">
    <div class="admin-panel admin-search-panel p-5 sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Katalog / Artikli') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ __('Artikli') }}</h1>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('Pretražite, filtrirajte i upravljajte artiklima na jednom mjestu.') }}</p>
                <p class="mt-2 text-xs text-slate-500">
                    {{ __('Pronađeno') }}: <span class="font-semibold text-slate-700">{{ $rows->total() }}</span>
                    <span class="mx-1 text-slate-300">•</span>
                    {{ __('Po stranici') }}: <span class="font-semibold text-slate-700">{{ $perPage }}</span>
                </p>
            </div>
            <a href="{{ route('admin.products.create', ['locale' => $locale]) }}" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-cyan-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-cyan-800">
                {{ __('Kreiraj artikl') }}
            </a>
        </div>

        <div class="mt-5 grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-12">
            <div class="sm:col-span-2 xl:col-span-5">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.search') }}</label>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Kod, SKU, naziv ili kategorija...') }}"
                    class="admin-search-input w-full rounded-xl border px-3 py-2 text-sm"
                />
            </div>
            <div class="xl:col-span-1">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                <select wire:model.live="locale" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm lowercase">
                    @foreach ($adminLocaleOptions as $localeOption)
                        <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                    @endforeach
                </select>
            </div>
            <div class="xl:col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.state') }}</label>
                <select wire:model.live="stateFilter" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    @foreach ($stateOptions as $stateKey => $stateLabel)
                        <option value="{{ $stateKey }}">{{ $stateLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="xl:col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Stock') }}</label>
                <select wire:model.live="stockFilter" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    @foreach ($stockOptions as $stockKey => $stockLabel)
                        <option value="{{ $stockKey }}">{{ $stockLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="xl:col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.sort') }}</label>
                <select wire:model.live="sortBy" data-tom-select data-tom-no-search="1" class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    @foreach ($sortOptions as $sortKey => $sortLabel)
                        <option value="{{ $sortKey }}">{{ $sortLabel }}</option>
                    @endforeach
                </select>
            </div>

            @if ($features['catalog_use_manufacturers'])
                <div class="sm:col-span-1 xl:col-span-4">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Manufacturer') }}</label>
                    <select wire:model.live="manufacturerFilter" data-tom-select class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                        <option value="all">{{ __('All') }}</option>
                        @foreach ($this->manufacturerOptions as $manufacturer)
                            @php $manufacturerTr = $manufacturer->translations->first(); @endphp
                            <option value="{{ $manufacturer->id }}">{{ $manufacturerTr?->name ?? $manufacturer->code }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="sm:col-span-1 {{ $features['catalog_use_manufacturers'] ? 'xl:col-span-6' : 'xl:col-span-10' }}">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Category') }}</label>
                <select wire:model.live="categoryFilter" data-tom-select class="admin-search-input admin-select w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="all">{{ __('All') }}</option>
                    @foreach ($this->categoryOptions as $category)
                        <option value="{{ $category['id'] }}">{{ $category['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="xl:col-span-2">
                <button type="button" wire:click="clearFilters" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                    {{ __('Očisti filtre') }}
                </button>
            </div>
        </div>

        <div class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">{{ __('Moduli') }}</span>
                <span class="admin-chip">{{ __('Atributi') }}: {{ $features['catalog_use_attributes'] ? __('ON') : __('OFF') }}</span>
                <span class="admin-chip">{{ __('Opcije') }}: {{ $features['catalog_use_options'] ? __('ON') : __('OFF') }}</span>
                <span class="admin-chip">{{ __('Brendovi') }}: {{ $features['catalog_use_manufacturers'] ? __('ON') : __('OFF') }}</span>
                <span class="admin-chip">{{ __('Akcije') }}: {{ ($features['catalog_use_actions'] ?? false) ? __('ON') : __('OFF') }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.settings.system.catalog-features') }}" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Postavke kataloga') }}</a>
                @if ($features['catalog_use_attributes'])
                    <a href="{{ route('admin.attributes', ['locale' => $locale]) }}" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Atributi') }}</a>
                @endif
                @if ($features['catalog_use_manufacturers'])
                    <a href="{{ route('admin.manufacturers', ['locale' => $locale]) }}" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Brendovi') }}</a>
                @endif
                @if ($features['catalog_use_actions'] ?? false)
                    <a href="{{ route('admin.actions', ['locale' => $locale]) }}" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Akcije') }}</a>
                @endif
            </div>
        </div>
    </div>

    <div class="admin-panel admin-panel-soft overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <h2 class="admin-section-title">{{ __('Popis artikala') }}</h2>
            <span class="text-xs text-slate-500">{{ __('Stranica') }} {{ $rows->currentPage() }} / {{ max(1, $rows->lastPage()) }}</span>
        </div>

        <div class="overflow-x-auto">
            <table class="admin-items-table min-w-[64rem] border-0 text-sm">
                <thead class="text-slate-600">
                    <tr>
                        <th class="w-20 px-4 py-3 text-center font-semibold">{{ __('Slika') }}</th>
                        <th class="min-w-[22rem] px-4 py-3 text-left font-semibold">{{ __('Artikl') }}</th>
                        <th class="min-w-[16rem] px-4 py-3 text-left font-semibold">{{ __('Kategorije') }}</th>
                        <th class="w-32 px-4 py-3 text-right font-semibold">{{ __('Cijena') }}</th>
                        <th class="w-24 px-4 py-3 text-center font-semibold">{{ __('Zaliha') }}</th>
                        <th class="w-28 px-4 py-3 text-center font-semibold">{{ __('Status') }}</th>
                        <th class="w-40 px-4 py-3 text-right font-semibold">{{ __('Akcije') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $tr = $row->translations->first();
                            $mainMedia = $row->getFirstMedia('product_main') ?: $row->getFirstMedia('product_gallery');
                            $mainThumb = $mainMedia
                                ? ($mainMedia->hasGeneratedConversion('thumb_100x100') ? $mainMedia->getUrl('thumb_100x100') : $mainMedia->getUrl())
                                : null;
                            $categoryRows = $row->categories->values();
                        @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3 text-center">
                                @if ($mainThumb)
                                    <img
                                        src="{{ $mainThumb }}"
                                        alt=""
                                        title="{{ $tr?->name ?? $row->code }}"
                                        class="mx-auto h-12 w-12 rounded-lg border border-slate-200 object-cover"
                                        loading="lazy"
                                    />
                                @else
                                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                        n/a
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-800">
                                <a href="{{ route('admin.products.edit', ['product' => $row->id, 'locale' => $locale]) }}" class="line-clamp-2 font-semibold leading-5 text-slate-900 hover:text-cyan-700">
                                    {{ $tr?->name ?? __('(missing name)') }}
                                </a>
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                                    <span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono">{{ $row->code }}</span>
                                    @if ($row->sku)
                                        <span>SKU: {{ $row->sku }}</span>
                                    @endif
                                </div>
                                @if (($features['catalog_use_manufacturers'] ?? false) && $row->manufacturer)
                                    @php $manufacturerTr = $row->manufacturer->translations->first(); @endphp
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ __('Brend') }}: <span class="font-medium text-slate-700">{{ $manufacturerTr?->name ?? $row->manufacturer->code }}</span>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                @if ($categoryRows->isNotEmpty())
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($categoryRows->take(2) as $category)
                                            @php $categoryName = $category->translations->first()?->name ?? $category->code; @endphp
                                            <span class="inline-flex rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-medium text-slate-700">
                                                {{ $categoryName }}
                                            </span>
                                        @endforeach
                                        @if ($categoryRows->count() > 2)
                                            <span class="inline-flex rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-500">
                                                +{{ $categoryRows->count() - 2 }}
                                            </span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">{{ __('Bez kategorije') }}</span>
                                @endif
                                @if ($features['catalog_use_attributes'])
                                    <div class="mt-2 text-[11px] text-slate-500">{{ __('Atributi') }}: {{ $row->attributes_count }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-slate-800">
                                {{ \App\Support\Currency::format((float) $row->base_price, $row->currency_code ?? null) }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex min-w-9 justify-center rounded-full px-2 py-1 text-xs font-semibold tabular-nums {{ $row->stock_qty <= 0 ? 'bg-rose-100 text-rose-700' : ($row->stock_qty <= 5 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800') }}">
                                    {{ $row->stock_qty }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $row->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                                    {{ $row->is_active ? __('admin.common.active') : __('admin.common.inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    @if ($tr?->slug)
                                        <a
                                            href="{{ route('products.show', ['slug' => $tr->slug]) }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label="{{ __('Preview') }}: {{ $tr?->name ?? $row->code }}"
                                            title="{{ __('Preview') }}"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-cyan-200 text-cyan-800 hover:bg-cyan-50"
                                        >
                                            <x-fa-icon name="eye" class="h-4 w-4" />
                                        </a>
                                    @endif
                                    <a
                                        href="{{ route('admin.products.edit', ['product' => $row->id, 'locale' => $locale]) }}"
                                        aria-label="{{ __('admin.common.edit') }}: {{ $tr?->name ?? $row->code }}"
                                        title="{{ __('admin.common.edit') }}"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100"
                                    >
                                        <x-fa-icon name="pen-to-square" class="h-4 w-4" />
                                    </a>
                                    @if ($features['catalog_use_options'])
                                        <a href="{{ route('admin.products.options', ['product' => $row->id, 'locale' => $locale]) }}" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                            {{ __('Option Values') }}
                                        </a>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="delete({{ (int) $row->id }})"
                                        wire:confirm="{{ __('Delete product \':name\'?', ['name' => $tr?->name ?? $row->code]) }}"
                                        aria-label="{{ __('admin.common.delete') }}: {{ $tr?->name ?? $row->code }}"
                                        title="{{ __('admin.common.delete') }}"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-rose-300 text-rose-700 hover:bg-rose-50"
                                    >
                                        <x-fa-icon name="trash" class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">{{ __('Nema artikala za odabrane filtre.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-200 px-5 py-4">
            {{ $rows->links() }}
        </div>
    </div>
</div>
