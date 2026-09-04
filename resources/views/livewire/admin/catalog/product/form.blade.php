<div class="space-y-6">
    <div class="admin-panel admin-search-panel p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Catalog / Products') }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $isEdit ? __('Edit Product') : __('Create Product') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('Core product fields, translation, and category assignments.') }}</p>
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
                <button type="button" wire:click="setTab('energy')" class="rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] {{ $activeTab === 'energy' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}">
                    {{ __('Energetske oznake') }}
                </button>
                <button type="button" wire:click="setTab('catalog')" class="rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] {{ $activeTab === 'catalog' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}">
                    {{ __('Kategorije') }}
                </button>
                @if ($useAttributes)
                    <button type="button" wire:click="setTab('attributes')" class="rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] {{ $activeTab === 'attributes' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}">
                        {{ __('Atributi') }}
                    </button>
                @endif
                <button type="button" wire:click="setTab('logistics')" class="rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] {{ $activeTab === 'logistics' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}">
                    {{ __('Logistika') }}
                </button>
                <button type="button" wire:click="setTab('b2b')" class="rounded-lg border px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] {{ $activeTab === 'b2b' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' }}">
                    {{ __('B2B cijene') }}
                </button>
            </div>
        </div>

        @if ($activeTab === 'content')
        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Core Data') }}</p>
            <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('SKU') }}</label>
                    <input type="text" wire:model="form.sku" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono" />
                    @error('form.sku') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 3;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Code') }}</label>
                    <input type="text" wire:model="form.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono" />
                    @error('form.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('admin.common.locale') }}</label>
                    <select wire:model.live="form.locale" data-tom-select data-tom-no-search="1" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm lowercase">
                          @foreach ($adminLocaleOptions as $localeOption)
                                <option value="{{ $localeOption }}">{{ $localeOption }}</option>
                            @endforeach
                    </select>
                    @error('form.locale') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Base Price') }}</label>
                    <input type="number" min="0" step="0.01" wire:model="form.base_price" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.base_price') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div style="grid-column: span 2;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Stock Qty') }}</label>
                    <input type="number" min="0" wire:model="form.stock_qty" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.stock_qty') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-3 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                <div style="grid-column: span 4;">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Tax Class') }}</label>
                    <select wire:model="form.tax_rate_id" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($this->taxRateOptions as $taxRate)
                            <option value="{{ $taxRate->id }}">{{ $taxRate->name }} ({{ rtrim(rtrim(number_format((float) $taxRate->rate, 2), '0'), '.') }}{{ $taxRate->rate_type === 'percent' ? '%' : '' }})</option>
                        @endforeach
                    </select>
                    @error('form.tax_rate_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
            @if ($useManufacturers)
                <div class="mt-3 grid gap-3" style="grid-template-columns: repeat(12, minmax(0, 1fr));">
                    <div style="grid-column: span 5;">
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Manufacturer') }}</label>
                            <a href="{{ route('admin.manufacturers', ['locale' => $form['locale']]) }}" class="text-xs font-semibold text-slate-600 hover:text-slate-900">{{ __('Manage') }}</a>
                        </div>
                        <select wire:model="form.manufacturer_id" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="">{{ __('No manufacturer') }}</option>
                            @foreach ($this->manufacturerOptions as $manufacturer)
                                @php
                                    $tr = $manufacturer->translations->first();
                                    $label = $tr?->name ?? ($manufacturer->code ?: __('Manufacturer #:id', ['id' => $manufacturer->id]));
                                @endphp
                                <option value="{{ $manufacturer->id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('form.manufacturer_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif
            <div class="mt-4">
                <button
                    type="button"
                    wire:click="$toggle('form.is_active')"
                    class="admin-switch"
                    data-state="{{ $form['is_active'] ? 'on' : 'off' }}"
                    role="switch"
                    aria-checked="{{ $form['is_active'] ? 'true' : 'false' }}"
                    aria-label="{{ __('Toggle product active state') }}"
                >
                    <span class="admin-switch-track">
                        <span class="admin-switch-thumb"></span>
                    </span>
                    <span class="admin-switch-label">{{ $form['is_active'] ? __('admin.common.active') : __('admin.common.inactive') }}</span>
                </button>
            </div>
        </div>
        @endif

        @if ($activeTab === 'content')
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
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Excerpt') }}</label>
                <textarea rows="3" wire:model="form.excerpt" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <div class="mt-3">
                <label for="product-description-html" class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Description') }}</label>
                <x-admin.quill-field id="product-description-html" rows="8" wire:model.live.debounce.300ms="form.description" />
            </div>
        </div>
        @endif

        @if ($activeTab === 'seo')
        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('SEO & Payload') }}</p>
            <div class="mt-4">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Meta Title') }}</label>
                <input type="text" wire:model="form.meta_title" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <div class="mt-3">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Meta Description') }}</label>
                <textarea rows="3" wire:model="form.meta_description" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div class="mt-3">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Product Payload JSON') }}</label>
                <textarea rows="6" wire:model="form.payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                @error('form.payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="mt-3">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Translation Payload JSON') }}</label>
                <textarea rows="6" wire:model="form.translation_payload_text" class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono text-xs"></textarea>
                @error('form.translation_payload_text') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>
        @endif

        @if ($activeTab === 'media')
        <livewire:admin.media.manager
            :model-class="\App\Models\Catalog\Product\Product::class"
            :model-id="$productId"
            :locale="$form['locale']"
            :wire:key="'product-media-manager-'.($productId ?? 'new').'-'.$form['locale']"
        />
        @endif

        @if ($activeTab === 'energy')
        @php
            $energyMediaCollections = $this->energyMediaCollections;
            $eprelLookupContext = $this->eprelLookupContext;
            $eprelLookupReady = $this->eprelLookupReady;
            $eprelRegistrationCriteria = $eprelLookupContext['registrationNumbers'] ?? [];
            $eprelGtinCriteria = $eprelLookupContext['gtins'] ?? [];
            $eprelModelCriteria = $eprelLookupContext['models'] ?? [];
            $eprelBrandCriteria = $eprelLookupContext['brands'] ?? [];
            $hiddenEprelCriteriaCount = max(0, count($eprelRegistrationCriteria) - 2)
                + max(0, count($eprelGtinCriteria) - 3)
                + max(0, count($eprelModelCriteria) - 4)
                + max(0, count($eprelBrandCriteria) - 2);
            $currentAdmin = auth()->user();
            $canManageEprelSettings = $currentAdmin
                && ($currentAdmin->isA('superadmin') || $currentAdmin->can('integrations.msan.settings.manage'));
            $isValidHttpsUrl = static function ($value): bool {
                $value = trim((string) $value);
                $parts = filter_var($value, FILTER_VALIDATE_URL) !== false ? parse_url($value) : false;

                return is_array($parts)
                    && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
                    && ! isset($parts['user'], $parts['pass']);
            };
            $hasEprelAssetIdentity = collect($energyDeclarations)->contains(function ($row): bool {
                $source = (string) ($row['source'] ?? '');
                if (! in_array($source, [
                    \App\Models\Catalog\Product\ProductEnergyDeclaration::SOURCE_EPREL,
                    \App\Models\Catalog\Product\ProductEnergyDeclaration::SOURCE_MSAN,
                ], true)) {
                    return false;
                }

                $group = strtolower(trim((string) ($row['eprel_product_group'] ?? '')));
                $registration = trim((string) ($row['eprel_registration_number'] ?? ''));

                return preg_match('/^[a-z0-9-]{2,100}$/', $group) === 1
                    && preg_match('/^\d{1,20}$/', $registration) === 1;
            });
            $hasSavedEnergyLabel = in_array('product_energy_label', $energyMediaCollections, true)
                || collect($energyDeclarations)->contains(fn ($row) => $isValidHttpsUrl($row['energy_label_url'] ?? ''))
                || $hasEprelAssetIdentity;
            $hasSavedInformationSheet = in_array('product_information_sheet', $energyMediaCollections, true)
                || collect($energyDeclarations)->contains(fn ($row) => $isValidHttpsUrl($row['product_information_sheet_url'] ?? ''))
                || $hasEprelAssetIdentity;
        @endphp
        <div class="admin-panel admin-form-panel p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="admin-section-title">{{ __('Energetske oznake i informacijski listovi') }}</p>
                    <p class="mt-1 max-w-3xl text-sm text-slate-600">{{ __('Za svaki kontekst proizvoda možete spremiti zasebnu energetsku klasu. Uvezene M SAN/EPREL deklaracije su samo za čitanje; ručne deklaracije ostaju sačuvane pri sljedećoj sinkronizaciji.') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="$toggle('form.energy_label_required')"
                        class="rounded-xl border px-3 py-2 text-xs font-semibold {{ $form['energy_label_required'] ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-slate-300 bg-white text-slate-700' }}"
                        role="switch"
                        aria-checked="{{ $form['energy_label_required'] ? 'true' : 'false' }}"
                    >
                        {{ $form['energy_label_required'] ? __('Energetska oznaka: obavezna') : __('Energetska oznaka: nije obavezna') }}
                    </button>
                    <button type="button" wire:click="addEnergyDeclaration" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        {{ __('Ručni unos (rezervno)') }}
                    </button>
                </div>
            </div>

            <section class="mt-5 overflow-visible rounded-2xl border border-cyan-200 bg-gradient-to-br from-cyan-50 via-white to-sky-50 shadow-sm" data-eprel-lookup>
                <div class="p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-3xl">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-semibold text-slate-950">{{ __('Automatski dohvati iz EPREL-a') }}</h3>
                                <span class="rounded-full border border-cyan-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-cyan-800">{{ __('Službeni EU podaci') }}</span>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ __('Jednim klikom tražimo točno podudaranje po EPREL broju, GTIN/EAN barkodu, M SAN modelu, part numberu, SKU-u i šifri artikla. Klasa, raspon i službene poveznice popunit će se automatski.') }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-900">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            {{ __('Sprema se samo jednoznačan rezultat') }}
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-200/80 bg-white/80 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Automatski pronađeni kriteriji') }}</p>
                        <div class="mt-2 flex flex-wrap gap-2" data-eprel-lookup-criteria>
                            @foreach (array_slice($eprelRegistrationCriteria, 0, 2) as $value)
                                <span class="rounded-lg bg-indigo-50 px-2.5 py-1.5 text-xs font-medium text-indigo-800"><span class="text-indigo-500">{{ __('EPREL') }}</span> {{ $value }}</span>
                            @endforeach
                            @foreach (array_slice($eprelGtinCriteria, 0, 3) as $value)
                                <span class="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-medium text-emerald-800"><span class="text-emerald-500">{{ __('Barkod') }}</span> {{ $value }}</span>
                            @endforeach
                            @foreach (array_slice($eprelModelCriteria, 0, 4) as $value)
                                <span class="rounded-lg bg-sky-50 px-2.5 py-1.5 text-xs font-medium text-sky-800"><span class="text-sky-500">{{ __('Model / šifra') }}</span> {{ $value }}</span>
                            @endforeach
                            @foreach (array_slice($eprelBrandCriteria, 0, 2) as $value)
                                <span class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-medium text-slate-700"><span class="text-slate-400">{{ __('Marka') }}</span> {{ $value }}</span>
                            @endforeach
                            @if ($hiddenEprelCriteriaCount > 0)
                                <span class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600">{{ __('Još kriterija: :count', ['count' => $hiddenEprelCriteriaCount]) }}</span>
                            @endif
                            @if ($eprelRegistrationCriteria === [] && $eprelGtinCriteria === [] && $eprelModelCriteria === [])
                                <span class="text-sm text-amber-800">{{ __('Nema prepoznatih identifikatora. Spremite barem barkod, SKU ili šifru artikla.') }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.15fr)_minmax(0,1.15fr)_auto] xl:items-end">
                        <div>
                            <label for="eprel-lookup-search-by" class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Pretraži prema') }}</label>
                            <select id="eprel-lookup-search-by" wire:model.live="eprelLookupSearchBy" aria-describedby="eprel-lookup-search-by-help" @disabled(! $isEdit || ! $eprelLookupReady) class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm disabled:cursor-not-allowed disabled:bg-slate-100" data-native-select data-eprel-search-by>
                                <option value="{{ \App\Services\Integrations\Msan\EprelProductLookupService::SEARCH_AUTO }}" @selected($eprelLookupSearchBy === \App\Services\Integrations\Msan\EprelProductLookupService::SEARCH_AUTO)>{{ __('Automatski kriteriji') }}</option>
                                @foreach ($eprelSearchByOptions as $searchValue => $searchLabel)
                                    <option value="{{ $searchValue }}" @selected($eprelLookupSearchBy === $searchValue)>{{ $searchLabel }}</option>
                                @endforeach
                            </select>
                            <p id="eprel-lookup-search-by-help" class="mt-1.5 text-xs text-slate-500">
                                @if ($eprelLookupSearchBy === \App\Services\Integrations\Msan\EprelClient::SEARCH_REGISTRATION_NUMBER)
                                    {{ __('Najbrži izbor kada već imate broj sa službene EPREL stranice.') }}
                                @else
                                    {{ __('Iste vrste pretrage kao na službenom EPREL portalu.') }}
                                @endif
                            </p>
                        </div>
                        <div>
                            <label for="eprel-lookup-query" class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Pojam za pretragu') }}</label>
                            <input id="eprel-lookup-query" type="text" maxlength="191" wire:model.blur="eprelLookupQuery" placeholder="{{ $eprelLookupSearchBy === \App\Services\Integrations\Msan\EprelProductLookupService::SEARCH_AUTO ? __('Koriste se kriteriji iznad') : ($eprelLookupSearchBy === \App\Services\Integrations\Msan\EprelClient::SEARCH_REGISTRATION_NUMBER ? __('npr. 2210952') : __('Unesite potpuno točnu vrijednost')) }}" aria-describedby="eprel-lookup-query-help" @disabled(! $isEdit || ! $eprelLookupReady || $eprelLookupSearchBy === \App\Services\Integrations\Msan\EprelProductLookupService::SEARCH_AUTO) class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm disabled:cursor-not-allowed disabled:bg-slate-100" data-eprel-search-query>
                            <p id="eprel-lookup-query-help" class="mt-1.5 text-xs text-slate-500">
                                {{ $eprelLookupSearchBy === \App\Services\Integrations\Msan\EprelClient::SEARCH_REGISTRATION_NUMBER
                                    ? __('Upišite samo broj; grupa će se pronaći i spremiti automatski.')
                                    : __('Ručna pretraga prihvaća samo potpuno točan i jednoznačan rezultat.') }}
                            </p>
                        </div>
                        <div>
                            <label for="eprel-lookup-group" class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Grupa proizvoda') }}</label>
                            <select id="eprel-lookup-group" wire:change="saveEprelLookupGroup($event.target.value)" wire:loading.attr="disabled" wire:target="saveEprelLookupGroup" aria-describedby="eprel-lookup-group-help" @disabled(! $isEdit || ! $eprelLookupReady) class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm disabled:cursor-not-allowed disabled:bg-slate-100" data-native-select data-eprel-lookup-group>
                                <option value="" @selected($eprelLookupGroup === '')>{{ __('Automatski — iz barkoda ili mapirane kategorije') }}</option>
                                @foreach ($eprelProductGroupOptions as $groupSlug => $groupCode)
                                    <option value="{{ $groupSlug }}" @selected($eprelLookupGroup === $groupSlug)>{{ str_replace('_', ' ', $groupCode) }} · {{ $groupSlug }}</option>
                                @endforeach
                            </select>
                            <p id="eprel-lookup-group-help" class="mt-1.5 text-xs text-slate-500">
                                @if ($eprelLookupSearchBy === \App\Services\Integrations\Msan\EprelClient::SEARCH_REGISTRATION_NUMBER)
                                    {{ __('Za pretragu po EPREL broju ne morate odabrati grupu.') }}
                                @elseif (($eprelLookupContext['groups'] ?? []) !== [] && $eprelLookupGroup === '')
                                    {{ __('Automatski prepoznato: :groups', ['groups' => implode(', ', $eprelLookupContext['groups'])]) }}
                                @else
                                    {{ __('Odabrana grupa odmah se sprema uz artikl. Ostavite prazno za automatsko određivanje.') }}
                                @endif
                            </p>
                        </div>
                        <button
                            type="button"
                            wire:click="lookupEprel"
                            wire:loading.attr="disabled"
                            wire:target="lookupEprel"
                            @disabled(! $eprelLookupReady)
                            class="inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="lookupEprel">{{ $hasEprelAssetIdentity ? __('Osvježi službene podatke') : __('Pronađi i preuzmi podatke') }}</span>
                            <span wire:loading wire:target="lookupEprel" class="inline-flex items-center gap-2" role="status" aria-live="polite">
                                <span class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                                {{ __('Pretražujem EPREL…') }}
                            </span>
                        </button>
                    </div>

                    @if (! $isEdit)
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                            {{ __('Najprije spremite osnovne podatke artikla. Nakon toga će automatski EPREL dohvat biti dostupan na ovom mjestu.') }}
                        </div>
                    @elseif (! $eprelLookupReady)
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                            {{ __('Za automatski dohvat uključite EPREL i spremite API ključ u postavkama integracije.') }}
                            @if ($canManageEprelSettings)
                                <a href="{{ route('admin.integrations.msan.settings') }}" class="ml-1 font-semibold underline underline-offset-2">{{ __('Otvori postavke') }}</a>
                            @else
                                <span class="ml-1 font-medium">{{ __('Obratite se administratoru sustava.') }}</span>
                            @endif
                        </div>
                    @endif
                    @error('eprelLookup')
                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900" role="alert" data-eprel-lookup-error>{{ $message }}</div>
                    @enderror
                </div>
                <div class="rounded-b-2xl border-t border-cyan-100 bg-white/60 px-5 py-3 text-xs leading-5 text-slate-500">
                    {{ __('Radi sigurnosti prihvaća se samo potpuno točan i jednoznačan zapis iste marke. Potvrđeni službeni zapis sprema se odmah; promjene u deklaracijama zato prvo spremite tipkom „Ažuriraj artikl”. Ostale nespremljene izmjene obrasca ostaju netaknute.') }}
                </div>
            </section>

            @if ($form['energy_label_required'] && (! $hasSavedEnergyLabel || ! $hasSavedInformationSheet))
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="alert" data-energy-compliance-warning>
                    <p class="font-semibold">{{ __('Energetska dokumentacija nije potpuna.') }}</p>
                    <p class="mt-1">
                        @if (! $hasSavedEnergyLabel && ! $hasSavedInformationSheet)
                            {{ __('Nedostaju službena energetska oznaka i informacijski list proizvoda (PIS).') }}
                        @elseif (! $hasSavedEnergyLabel)
                            {{ __('Nedostaje službena energetska oznaka.') }}
                        @else
                            {{ __('Nedostaje informacijski list proizvoda (PIS).') }}
                        @endif
                    </p>
                </div>
            @endif

            <div class="mt-4 rounded-xl border border-cyan-100 bg-cyan-50 px-4 py-3 text-sm text-cyan-950">
                {{ __('Lokalnu službenu oznaku i PIS učitajte u kartici Media. Datoteke se ne koriste kao glavna slika proizvoda. Za više konteksta unesite zaseban HTTPS URL u odgovarajuću deklaraciju.') }}
                <button type="button" wire:click="setTab('media')" class="ml-1 font-semibold underline underline-offset-2">{{ __('Otvori Media') }}</button>
            </div>

            <div class="mt-5 space-y-4">
                @forelse ($energyDeclarations as $index => $row)
                    @php
                        $isManualEnergyRow = (string) ($row['source'] ?? 'manual') === \App\Models\Catalog\Product\ProductEnergyDeclaration::SOURCE_MANUAL;
                    @endphp
                    <section wire:key="product-energy-row-{{ $row['id'] ?? $index }}" class="rounded-xl border {{ $row['is_primary'] ? 'border-cyan-300 bg-cyan-50/40' : 'border-slate-200 bg-white' }} p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold text-slate-900">{{ $row['label'] ?: ($row['context_code'] ?: __('Energetska deklaracija')) }}</h3>
                                    <span class="admin-chip">{{ strtoupper((string) ($row['source'] ?? 'manual')) }}</span>
                                    @if ($row['is_primary'])
                                        <span class="admin-chip">{{ __('Primarna') }}</span>
                                    @endif
                                </div>
                                @if (! empty($row['synced_at']))
                                    <p class="mt-1 text-xs text-slate-500">{{ __('Sinkronizirano') }}: {{ $row['synced_at'] }}</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($isValidHttpsUrl($row['energy_label_url'] ?? ''))
                                    <a href="{{ $row['energy_label_url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-cyan-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-cyan-800 hover:bg-cyan-50">{{ __('Otvori oznaku') }}</a>
                                @endif
                                @if ($isValidHttpsUrl($row['product_information_sheet_url'] ?? ''))
                                    <a href="{{ $row['product_information_sheet_url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-cyan-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-cyan-800 hover:bg-cyan-50">{{ __('Otvori PIS') }}</a>
                                @endif
                                @if (! $row['is_primary'])
                                    <button type="button" wire:click="setPrimaryEnergyDeclaration({{ $index }})" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ __('Postavi kao primarnu') }}</button>
                                @endif
                                @if ($isManualEnergyRow)
                                    <button type="button" wire:click="removeEnergyDeclaration({{ $index }})" wire:confirm="{{ __('Obrisati ručnu energetsku deklaraciju?') }}" class="rounded-lg border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Obriši') }}</button>
                                @endif
                            </div>
                        </div>

                        @if ($isManualEnergyRow)
                            <div class="mt-4 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Kontekst') }}</label>
                                    <input type="text" wire:model="energyDeclarations.{{ $index }}.context_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono">
                                    @error("energyDeclarations.$index.context_code") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Naziv / opis konteksta') }}</label>
                                    <input type="text" wire:model="energyDeclarations.{{ $index }}.label" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Energetska klasa') }}</label>
                                    <select wire:model="energyDeclarations.{{ $index }}.energy_class" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                        <option value="">{{ __('Nije uneseno') }}</option>
                                        @foreach ($energyClassOptions as $energyClass)
                                            <option value="{{ $energyClass }}">{{ $energyClass }}</option>
                                        @endforeach
                                    </select>
                                    @error("energyDeclarations.$index.energy_class") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Raspon od') }}</label>
                                    <select wire:model="energyDeclarations.{{ $index }}.scale_min" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                        <option value="">{{ __('Nije uneseno') }}</option>
                                        @foreach ($energyClassOptions as $energyClass)
                                            <option value="{{ $energyClass }}">{{ $energyClass }}</option>
                                        @endforeach
                                    </select>
                                    @error("energyDeclarations.$index.scale_min") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Raspon do') }}</label>
                                    <select wire:model="energyDeclarations.{{ $index }}.scale_max" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                        <option value="">{{ __('Nije uneseno') }}</option>
                                        @foreach ($energyClassOptions as $energyClass)
                                            <option value="{{ $energyClass }}">{{ $energyClass }}</option>
                                        @endforeach
                                    </select>
                                    @error("energyDeclarations.$index.scale_max") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('EPREL broj') }}</label>
                                    <input type="text" inputmode="numeric" wire:model="energyDeclarations.{{ $index }}.eprel_registration_number" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono">
                                    @error("energyDeclarations.$index.eprel_registration_number") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('EPREL grupa') }}</label>
                                    <select wire:model="energyDeclarations.{{ $index }}.eprel_product_group" class="admin-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">
                                        <option value="">{{ __('Nije odabrano') }}</option>
                                        @foreach ($eprelProductGroupOptions as $groupSlug => $groupCode)
                                            <option value="{{ $groupSlug }}">{{ str_replace('_', ' ', $groupCode) }} · {{ $groupSlug }}</option>
                                        @endforeach
                                    </select>
                                    @error("energyDeclarations.$index.eprel_product_group") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-2 lg:col-span-3">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('HTTPS URL službene energetske oznake') }}</label>
                                    <input type="url" wire:model="energyDeclarations.{{ $index }}.energy_label_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    @error("energyDeclarations.$index.energy_label_url") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-2 lg:col-span-4">
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('HTTPS URL informacijskog lista proizvoda (PIS)') }}</label>
                                    <input type="url" wire:model="energyDeclarations.{{ $index }}.product_information_sheet_url" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    @error("energyDeclarations.$index.product_information_sheet_url") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @else
                            <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2 lg:grid-cols-4">
                                <div><dt class="text-xs uppercase text-slate-500">{{ __('Kontekst') }}</dt><dd class="mt-1 font-mono text-slate-800">{{ $row['context_code'] }}</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ __('Klasa / raspon') }}</dt><dd class="mt-1 font-semibold text-slate-800">{{ $row['energy_class'] ?: '—' }} / {{ ($row['scale_min'] && $row['scale_max']) ? $row['scale_min'].'–'.$row['scale_max'] : '—' }}</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ __('EPREL broj') }}</dt><dd class="mt-1 font-mono text-slate-800">{{ $row['eprel_registration_number'] ?: '—' }}</dd></div>
                                <div><dt class="text-xs uppercase text-slate-500">{{ __('EPREL grupa') }}</dt><dd class="mt-1 font-mono text-slate-800">{{ $row['eprel_product_group'] ?: '—' }}</dd></div>
                            </dl>
                        @endif
                    </section>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-600">
                        {{ __('Još nema energetske deklaracije. Najprije upotrijebite automatski EPREL dohvat iznad; ručni unos je potreban samo ako službeni zapis nije dostupan.') }}
                    </div>
                @endforelse
            </div>
            @error('energyDeclarations') <p class="mt-3 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        @endif

        @if ($activeTab === 'logistics')
        <div class="admin-panel admin-form-panel p-6">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="admin-section-title">{{ __('Logistika i naručivanje') }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ __('Barkod, mjerna jedinica, dimenzije, dostavne oznake i pakiranja proizvoda.') }}</p>
                </div>
                <button type="button" wire:click="addPackage" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    {{ __('Dodaj pakiranje') }}
                </button>
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Barkod proizvoda') }}</label>
                    <input type="text" wire:model="form.barcode" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono" />
                    @error('form.barcode') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Jedinica mjere') }}</label>
                    <select wire:model="form.unit_of_measure" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($unitOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('form.unit_of_measure') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Težina (kg)') }}</label>
                    <input type="number" min="0" step="0.001" wire:model="form.weight_kg" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.weight_kg') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Minimalna količina') }}</label>
                    <input type="number" min="1" wire:model="form.minimum_order_quantity" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.minimum_order_quantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Korak količine') }}</label>
                    <input type="number" min="1" wire:model="form.order_quantity_step" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                    @error('form.order_quantity_step') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                @foreach (['length_cm' => 'Duljina (cm)', 'width_cm' => 'Širina (cm)', 'height_cm' => 'Visina (cm)'] as $field => $label)
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __($label) }}</label>
                        <input type="number" min="0" step="0.01" wire:model="form.{{ $field }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                        @error('form.'.$field) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Dostavne oznake') }}</p>
                <div class="mt-3 flex flex-wrap gap-3">
                    @foreach ($shippingLabelOptions as $value => $label)
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" value="{{ $value }}" wire:model="form.shipping_labels" class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('form.shipping_labels.*') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="mt-6 space-y-4">
                @forelse ($packages as $index => $package)
                    <div wire:key="product-package-{{ $package['id'] ?? 'new-'.$index }}" class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <p class="text-sm font-semibold text-slate-800">{{ __('Pakiranje #:number', ['number' => $index + 1]) }}</p>
                            <button type="button" wire:click="removePackage({{ $index }})" class="text-xs font-semibold text-rose-600 hover:text-rose-700">{{ __('Ukloni') }}</button>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Šifra') }}</label>
                                <input type="text" wire:model="packages.{{ $index }}.code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono uppercase">
                                @error('packages.'.$index.'.code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Naziv') }}</label>
                                <input type="text" wire:model="packages.{{ $index }}.name" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                @error('packages.'.$index.'.name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Barkod') }}</label>
                                <input type="text" wire:model="packages.{{ $index }}.barcode" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-mono">
                                @error('packages.'.$index.'.barcode') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Vrsta') }}</label>
                                <select wire:model="packages.{{ $index }}.package_type" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    @foreach ($packageTypeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Količina u pakiranju') }}</label>
                                <input type="number" min="0.001" step="0.001" wire:model="packages.{{ $index }}.quantity" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                @error('packages.'.$index.'.quantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Jedinica mjere') }}</label>
                                <select wire:model="packages.{{ $index }}.unit_of_measure" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    @foreach ($unitOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Težina (kg)') }}</label>
                                <input type="number" min="0" step="0.001" wire:model="packages.{{ $index }}.weight_kg" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            </div>
                            <div class="flex items-end gap-4 pb-2">
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model="packages.{{ $index }}.is_default" class="rounded border-slate-300 text-cyan-700">
                                    {{ __('Zadano') }}
                                </label>
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" wire:model="packages.{{ $index }}.is_active" class="rounded border-slate-300 text-cyan-700">
                                    {{ __('Aktivno') }}
                                </label>
                            </div>
                            @foreach (['length_cm' => 'Duljina (cm)', 'width_cm' => 'Širina (cm)', 'height_cm' => 'Visina (cm)'] as $field => $label)
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __($label) }}</label>
                                    <input type="number" min="0" step="0.01" wire:model="packages.{{ $index }}.{{ $field }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-600">
                        {{ __('Nema definiranih pakiranja. Proizvod se prodaje u osnovnoj jedinici mjere.') }}
                    </div>
                @endforelse
            </div>
        </div>
        @endif

        @if ($activeTab === 'b2b')
        <div class="space-y-6">
            <div class="admin-panel admin-form-panel p-6">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="admin-section-title">{{ __('B2B cjenik') }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Cijene po grupi kupaca, pakiranju, količinskom pragu i razdoblju valjanosti.') }}</p>
                    </div>
                    <button type="button" wire:click="addGroupPrice" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        {{ __('Dodaj B2B cijenu') }}
                    </button>
                </div>
                <div class="mt-4 flex flex-col gap-2 rounded-xl border border-cyan-100 bg-cyan-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-cyan-950">{{ __('Ovdje se uređuju izravne cijene ovog artikla. Pravila za grupe, brendove i kategorije uređuju se u modulu B2B cjenici.') }}</p>
                    <a href="{{ route('admin.b2b-prices') }}" class="shrink-0 text-sm font-semibold text-cyan-800 hover:text-cyan-950">{{ __('Otvori B2B cjenike') }} →</a>
                </div>

                <div class="mt-5 space-y-3">
                    @forelse ($groupPrices as $index => $price)
                        <div wire:key="product-group-price-{{ $price['id'] ?? 'new-'.$index }}" class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Grupa kupaca') }}</label>
                                    <select wire:model="groupPrices.{{ $index }}.customer_group_id" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                        <option value="">{{ __('Odaberite grupu') }}</option>
                                        @foreach ($this->customerGroupOptions as $group)
                                            <option value="{{ $group->id }}">{{ $group->name }} ({{ $group->code }})</option>
                                        @endforeach
                                    </select>
                                    @error('groupPrices.'.$index.'.customer_group_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Pakiranje') }}</label>
                                    <select wire:model="groupPrices.{{ $index }}.package_code" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                        <option value="">{{ __('Osnovna jedinica') }}</option>
                                        @foreach ($packages as $package)
                                            @if (trim((string) ($package['code'] ?? '')) !== '')
                                                <option value="{{ strtoupper(trim((string) $package['code'])) }}">{{ $package['name'] ?: $package['code'] }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @error('groupPrices.'.$index.'.package_code') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Minimalna količina') }}</label>
                                    <input type="number" min="1" wire:model="groupPrices.{{ $index }}.minimum_quantity" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    @error('groupPrices.'.$index.'.minimum_quantity') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="grid grid-cols-[1fr_5rem] gap-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Cijena') }}</label>
                                        <input type="number" min="0" step="0.0001" wire:model="groupPrices.{{ $index }}.price" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Valuta') }}</label>
                                        <input type="text" maxlength="3" wire:model="groupPrices.{{ $index }}.currency_code" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm uppercase">
                                    </div>
                                    @error('groupPrices.'.$index.'.price') <p class="col-span-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Vrijedi od') }}</label>
                                    <input type="datetime-local" wire:model="groupPrices.{{ $index }}.starts_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Vrijedi do') }}</label>
                                    <input type="datetime-local" wire:model="groupPrices.{{ $index }}.ends_at" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    @error('groupPrices.'.$index.'.ends_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex items-end pb-2">
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" wire:model="groupPrices.{{ $index }}.is_active" class="rounded border-slate-300 text-cyan-700">
                                        {{ __('Aktivno') }}
                                    </label>
                                </div>
                                <div class="flex items-end justify-end pb-1">
                                    <button type="button" wire:click="removeGroupPrice({{ $index }})" class="text-xs font-semibold text-rose-600 hover:text-rose-700">{{ __('Ukloni cijenu') }}</button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-600">
                            {{ __('Nema definiranih B2B cijena. Svim kupcima prikazuje se osnovna cijena proizvoda.') }}
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="admin-panel admin-form-panel overflow-hidden">
                <div class="border-b border-slate-200 p-6">
                    <p class="admin-section-title">{{ __('Povijest cijena') }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ __('Automatski zapis promjena osnovne, varijantne i B2B cijene. Prikazuje se zadnjih 50 zapisa.') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">
                            <tr>
                                <th class="px-4 py-3">{{ __('Vrijeme') }}</th>
                                <th class="px-4 py-3">{{ __('Vrsta') }}</th>
                                <th class="px-4 py-3">{{ __('Grupa / pakiranje') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Stara cijena') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Nova cijena') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($this->priceHistoryRows as $history)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $history->effective_at?->format('d.m.Y. H:i') }}</td>
                                    <td class="px-4 py-3 font-semibold text-slate-700">{{ strtoupper($history->price_type) }}</td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ $history->customerGroup?->name ?? '—' }}
                                        @if ($history->productPackage)
                                            / {{ $history->productPackage->name }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ $history->old_price !== null ? number_format((float) $history->old_price, 4, ',', '.') : '—' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums font-semibold text-slate-800">{{ $history->new_price !== null ? number_format((float) $history->new_price, 4, ',', '.') : '—' }} {{ $history->currency_code }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-slate-500">{{ __('Povijest cijena još nije zabilježena.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

        @if ($activeTab === 'catalog')
        <div class="admin-panel admin-form-panel p-6">
            <p class="admin-section-title">{{ __('Kategorije i opcije') }}</p>
            <div class="mt-4 rounded-xl border border-slate-200 p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Categories (order defines primary)') }}</p>
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
                @error('form.category_ids.*') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            @if ($useOptions)
                <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Product Option Values') }}</p>
                            <p class="text-xs text-slate-600">{{ __('Assign option groups and manage per-value SKU/price/stock combinations.') }}</p>
                        </div>
                        @if ($isEdit && $productId)
                            <a href="{{ route('admin.products.options', ['product' => $productId, 'locale' => $form['locale']]) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                {{ __('Manage Option Values') }}
                            </a>
                        @else
                            <span class="text-xs text-slate-500">{{ __('Save product first') }}</span>
                        @endif
                    </div>
                    @if ($isEdit && $productId)
                        @php $hiddenOptionValueRows = $this->hiddenOptionValueRows; @endphp
                        <div class="mt-3 border-t border-slate-200 pt-3">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Filter-only values on this product') }}</p>
                            @if ($hiddenOptionValueRows->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($hiddenOptionValueRows as $row)
                                        <span class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-700">
                                            {{ $row['option_label'] }}: {{ $row['value_label'] }}
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <p class="mt-1 text-xs text-slate-500">{{ __('No hidden filter-only values are currently assigned.') }}</p>
                            @endif
                            <p class="mt-2 text-xs text-slate-500">{{ __('Color swatch images are managed on Options > Values; these values power category filters and product color variants.') }}</p>
                        </div>
                    @endif
                </div>
            @endif

        </div>
        @endif

        @if ($activeTab === 'attributes' && $useAttributes)
        <div class="admin-panel admin-form-panel p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="admin-section-title">{{ __('Atributi artikla') }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ __('Odaberite tehničke i prodajne karakteristike ovog artikla po grupama atributa.') }}</p>
                </div>
                <a href="{{ route('admin.attributes', ['locale' => $form['locale']]) }}" class="text-sm font-semibold text-cyan-700 hover:text-cyan-800">{{ __('Upravljaj atributima') }}</a>
            </div>

            <div class="mt-5 grid gap-2 lg:grid-cols-[minmax(16rem,28rem)_auto]">
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{{ __('Grupa atributa') }}</label>
                    <select wire:model.live="attributeGroupToAdd" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        <option value="">{{ __('Odaberite grupu...') }}</option>
                        @foreach ($this->attributeGroupOptions as $groupOption)
                            <option value="{{ $groupOption['group_code'] }}">
                                {{ $groupOption['group_name'] }} ({{ $groupOption['item_count'] }})
                            </option>
                        @endforeach
                    </select>
                    @error('attributeGroupToAdd') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end">
                    <button
                        type="button"
                        wire:click="addAttributeGroup"
                        @disabled(empty($this->attributeGroupOptions))
                        class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('Dodaj atribut') }}
                    </button>
                </div>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @forelse ($this->visibleAttributeGroups as $group)
                    @php
                        $groupCode = (string) $group['group_code'];
                        $groupType = (string) $group['type'];
                    @endphp
                    <div wire:key="product-attribute-group-{{ $groupCode }}" class="rounded-xl border border-slate-200 bg-white p-3">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-slate-800">{{ $group['group_name'] }}</p>
                            <div class="flex items-center gap-2">
                                @if ($group['is_msan_assigned'])
                                    <span class="rounded-full bg-cyan-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-cyan-800">{{ __('Automatski · M SAN') }}</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">{{ $groupType === 'multi' ? __('Višestruki odabir') : __('Jedan odabir') }}</span>
                                    <button type="button" wire:click='removeAttributeGroup(@js($groupCode))' class="text-xs font-semibold text-rose-600 hover:text-rose-700">
                                        {{ __('Ukloni') }}
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($group['is_msan_assigned'])
                            <div class="flex min-h-10 flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                @forelse ($group['selected_items'] as $item)
                                    <span class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-sm text-slate-700">{{ $item['name'] }}</span>
                                @empty
                                    <span class="text-sm text-slate-500">{{ __('Nema vrijednosti') }}</span>
                                @endforelse
                            </div>
                            <p class="mt-2 text-xs text-slate-500">{{ __('Vrijednost održava M SAN integracija i ovdje je samo za čitanje.') }}</p>
                        @elseif ($groupType === 'multi')
                            <select wire:model="attributeSelections.{{ $groupCode }}" multiple size="5" class="admin-multiselect w-full rounded-xl border border-slate-300 text-sm">
                                @foreach ($group['items'] as $item)
                                    <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <select wire:model="attributeSelections.{{ $groupCode }}" class="admin-select w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                <option value="">{{ __('Nema vrijednosti') }}</option>
                                @foreach ($group['items'] as $item)
                                    <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
                                @endforeach
                            </select>
                        @endif

                        @error('attributeSelections.'.$groupCode) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600 lg:col-span-2">
                        {{ __('Artikl još nema dodijeljenih atributa. Odaberite grupu i dodajte atribut.') }}
                    </div>
                @endforelse
            </div>
            @error('form.attribute_ids') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
            @error('form.attribute_ids.*') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        @endif

        <div class="admin-form-actions mt-5 flex items-center gap-2 pt-2">
            <button type="submit" class="rounded-xl bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">
                {{ $isEdit ? __('Update Product') : __('Create Product') }}
            </button>
            <button type="button" wire:click="backToList" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                {{ __('Cancel') }}
            </button>
        </div>
    </form>
</div>
