<?php

namespace App\Livewire\Admin\Catalog\Product;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Catalog\Product\ProductPackage;
use App\Models\Catalog\Product\ProductTranslation;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Settings\Local\TaxRate;
use App\Models\User\CustomerGroup;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Integrations\Msan\EprelClient;
use App\Services\Integrations\Msan\EprelException;
use App\Services\Integrations\Msan\EprelProductLookupService;
use App\Services\Integrations\Msan\MsanSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;

class Form extends Component
{
    public ?int $productId = null;

    public string $activeTab = 'content';

    public string $categorySearch = '';

    public array $attributeSelections = [];

    /** @var array<int, string> */
    public array $addedAttributeGroupCodes = [];

    /** @var array<int, int> */
    public array $msanAttributeIds = [];

    public string $attributeGroupToAdd = '';

    public array $packages = [];

    public array $groupPrices = [];

    public array $energyDeclarations = [];

    public string $eprelLookupGroup = '';

    public string $eprelLookupModel = '';

    public string $eprelLookupBrand = '';

    public string $eprelLookupSearchBy = EprelProductLookupService::SEARCH_AUTO;

    public string $eprelLookupQuery = '';

    public array $form = [
        'code' => '',
        'sku' => '',
        'barcode' => '',
        'unit_of_measure' => 'pcs',
        'minimum_order_quantity' => 1,
        'order_quantity_step' => 1,
        'is_active' => true,
        'manufacturer_id' => null,
        'tax_rate_id' => null,
        'base_price' => 0,
        'stock_qty' => 0,
        'weight_kg' => null,
        'length_cm' => null,
        'width_cm' => null,
        'height_cm' => null,
        'shipping_labels' => [],
        'energy_label_required' => false,
        'payload_text' => '',
        'locale' => 'en',
        'name' => '',
        'slug' => '',
        'excerpt' => '',
        'description' => '',
        'meta_title' => '',
        'meta_description' => '',
        'translation_payload_text' => '',
        'category_ids' => [],
        'attribute_ids' => [],
    ];

    public function mount(?int $productId = null): void
    {
        $this->form['locale'] = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        if ($productId) {
            $this->productId = $productId;
            $this->loadProduct();
        } else {
            $this->form['tax_rate_id'] = $this->defaultTaxRateId();
        }
    }

    public function updatedFormLocale(): void
    {
        $this->loadTranslationForLocale();
    }

    public function updatedEprelLookupGroup(): void
    {
        $this->storeEprelLookupGroupSelection($this->eprelLookupGroup);
    }

    public function saveEprelLookupGroup(string $group): void
    {
        $this->storeEprelLookupGroupSelection($group, notify: true);
    }

    public function updatedEprelLookupModel(): void
    {
        $this->resetErrorBag('eprelLookup');
    }

    public function updatedEprelLookupBrand(): void
    {
        $this->resetErrorBag('eprelLookup');
    }

    public function updatedEprelLookupSearchBy(): void
    {
        $this->resetErrorBag('eprelLookup');
        $searchBy = strtoupper(trim($this->eprelLookupSearchBy));
        if ($searchBy !== EprelProductLookupService::SEARCH_AUTO
            && ! array_key_exists($searchBy, EprelClient::searchByOptions())) {
            $this->eprelLookupSearchBy = EprelProductLookupService::SEARCH_AUTO;
            $this->addError('eprelLookup', __('EPREL vrsta pretrage nije podržana.'));
        }
    }

    public function updatedEprelLookupQuery(): void
    {
        $this->resetErrorBag('eprelLookup');
    }

    public function generateSlug(): void
    {
        $name = trim((string) $this->form['name']);
        if ($name !== '') {
            $this->form['slug'] = Str::slug($name);
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['content', 'seo', 'media', 'energy', 'catalog', 'attributes', 'logistics', 'b2b'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function addAttributeGroup(): void
    {
        $groupCode = trim($this->attributeGroupToAdd);
        $group = collect($this->attributeGroups)
            ->first(fn (array $candidate): bool => (string) $candidate['group_code'] === $groupCode);

        if (! $group || (bool) ($group['is_msan'] ?? false)) {
            $this->addError('attributeGroupToAdd', __('Odaberite valjanu grupu atributa.'));

            return;
        }

        $this->resetErrorBag('attributeGroupToAdd');
        if (! in_array($groupCode, $this->addedAttributeGroupCodes, true)) {
            $this->addedAttributeGroupCodes[] = $groupCode;
        }

        if (! array_key_exists($groupCode, $this->attributeSelections)) {
            $this->attributeSelections[$groupCode] = (string) $group['type'] === Attribute::TYPE_MULTI
                ? []
                : '';
        }

        $this->attributeGroupToAdd = '';
    }

    public function removeAttributeGroup(string $groupCode): void
    {
        $groupCode = trim($groupCode);
        if ($groupCode === '') {
            return;
        }

        $group = collect($this->visibleAttributeGroups)
            ->first(fn (array $candidate): bool => (string) $candidate['group_code'] === $groupCode);
        if (! $group) {
            return;
        }

        if ((bool) ($group['is_msan_assigned'] ?? false)) {
            $this->dispatch(
                'notify',
                type: 'warning',
                message: __('M SAN atributi održavaju se automatski kroz integraciju.'),
            );

            return;
        }

        unset($this->attributeSelections[$groupCode]);
        $this->addedAttributeGroupCodes = array_values(array_filter(
            $this->addedAttributeGroupCodes,
            fn (string $code): bool => $code !== $groupCode,
        ));
        $this->resetErrorBag('attributeSelections.'.$groupCode);
    }

    public function save()
    {
        if ($this->useAttributes()) {
            $resolvedAttributeIds = $this->resolveAttributeIdsForSave();
            if ($resolvedAttributeIds === false) {
                return null;
            }

            $this->form['attribute_ids'] = $resolvedAttributeIds;
        }

        $validated = $this->validate($this->rules());
        if (! $this->validateCommerceRows($validated) || ! $this->validateEnergyRows($validated)) {
            return null;
        }

        $wasEditing = (bool) $this->productId;

        $payload = $this->decodeJsonField('form.payload_text');
        if ($payload === false) {
            return null;
        }

        $translationPayload = $this->decodeJsonField('form.translation_payload_text');
        if ($translationPayload === false) {
            return null;
        }

        $userId = auth()->id();

        DB::transaction(function () use ($validated, $payload, $translationPayload, $userId, $wasEditing): void {
            $productData = [
                'code' => trim((string) $validated['form']['code']),
                'sku' => trim((string) $validated['form']['sku']) !== '' ? trim((string) $validated['form']['sku']) : null,
                'barcode' => trim((string) ($validated['form']['barcode'] ?? '')) ?: null,
                'unit_of_measure' => (string) $validated['form']['unit_of_measure'],
                'minimum_order_quantity' => (int) $validated['form']['minimum_order_quantity'],
                'order_quantity_step' => (int) $validated['form']['order_quantity_step'],
                'is_active' => (bool) $validated['form']['is_active'],
                'tax_rate_id' => ($validated['form']['tax_rate_id'] ?? null)
                    ? (int) $validated['form']['tax_rate_id']
                    : $this->defaultTaxRateId(),
                'base_price' => (float) $validated['form']['base_price'],
                'stock_qty' => (int) $validated['form']['stock_qty'],
                'weight_kg' => $validated['form']['weight_kg'] ?? null,
                'length_cm' => $validated['form']['length_cm'] ?? null,
                'width_cm' => $validated['form']['width_cm'] ?? null,
                'height_cm' => $validated['form']['height_cm'] ?? null,
                'shipping_labels' => array_values($validated['form']['shipping_labels'] ?? []),
                'energy_label_required' => (bool) ($validated['form']['energy_label_required'] ?? false),
                'payload' => $payload,
                'updated_by' => $userId,
            ];
            if ($this->useManufacturers()) {
                $productData['manufacturer_id'] = $validated['form']['manufacturer_id'] ?: null;
            }

            if ($this->productId) {
                $product = Product::query()->findOrFail($this->productId);
                $product->fill($productData)->save();
            } else {
                $product = Product::query()->create($productData + ['created_by' => $userId]);
                $this->productId = $product->id;
            }

            $product->translations()->updateOrCreate(
                ['locale' => $validated['form']['locale']],
                [
                    'name' => $validated['form']['name'],
                    'slug' => $validated['form']['slug'],
                    'excerpt' => $validated['form']['excerpt'] ?: null,
                    'description' => $validated['form']['description'] ?: null,
                    'meta_title' => $validated['form']['meta_title'] ?: null,
                    'meta_description' => $validated['form']['meta_description'] ?: null,
                    'payload' => $translationPayload,
                ]
            );

            $syncPayload = [];
            foreach (array_values($validated['form']['category_ids'] ?? []) as $index => $categoryId) {
                $syncPayload[(int) $categoryId] = [
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ];
            }
            $product->categories()->sync($syncPayload);

            if ($this->useAttributes()) {
                $this->syncManualAttributes(
                    $product,
                    array_values($validated['form']['attribute_ids'] ?? []),
                );
            }

            $packageIdsByCode = $this->syncPackages(
                $product,
                $validated['packages'] ?? [],
                $userId,
            );
            $this->syncGroupPrices(
                $product,
                $validated['groupPrices'] ?? [],
                $packageIdsByCode,
                $userId,
            );
            $this->syncEnergyDeclarations(
                $product,
                $validated['energyDeclarations'] ?? [],
            );

            activity('catalog_products')
                ->performedOn($product)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'updated' : 'created')
                ->withProperties([
                    'locale' => $validated['form']['locale'],
                    'slug' => $validated['form']['slug'],
                    'category_count' => count($syncPayload),
                    'attribute_count' => count($validated['form']['attribute_ids'] ?? []),
                ])
                ->log('Product saved');
        });

        if ($wasEditing) {
            return redirect()
                ->route('admin.products.edit', ['product' => $this->productId, 'locale' => $this->form['locale']])
                ->with('notify', [
                    'type' => 'success',
                    'message' => __('Product updated.'),
                ]);
        }

        return redirect()
            ->route('admin.products.edit', ['product' => $this->productId, 'locale' => $this->form['locale']])
            ->with('notify', [
                'type' => 'success',
                'message' => __('Product created. Now upload product images.'),
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.products', ['locale' => $this->form['locale']]);
    }

    public function addEnergyDeclaration(): void
    {
        $this->energyDeclarations[] = $this->blankEnergyDeclarationRow();
    }

    public function removeEnergyDeclaration(int $index): void
    {
        $row = $this->energyDeclarations[$index] ?? null;
        if (! is_array($row) || (string) ($row['source'] ?? ProductEnergyDeclaration::SOURCE_MANUAL) !== ProductEnergyDeclaration::SOURCE_MANUAL) {
            return;
        }

        unset($this->energyDeclarations[$index]);
        $this->energyDeclarations = array_values($this->energyDeclarations);
    }

    public function setPrimaryEnergyDeclaration(int $index): void
    {
        if (! isset($this->energyDeclarations[$index])) {
            return;
        }

        foreach ($this->energyDeclarations as $rowIndex => $row) {
            $this->energyDeclarations[$rowIndex]['is_primary'] = $rowIndex === $index;
        }
    }

    public function lookupEprel(EprelProductLookupService $lookup): void
    {
        $this->resetErrorBag('eprelLookup');
        if (! $this->productId) {
            $this->addError('eprelLookup', __('Najprije spremite artikl, a zatim pokrenite EPREL dohvat.'));

            return;
        }

        /** @var Product|null $product */
        $product = Product::query()->find($this->productId);
        if (! $product) {
            $this->addError('eprelLookup', __('Artikl više ne postoji.'));

            return;
        }

        $dirtyIdentityFields = $this->dirtyEprelIdentityFields($product);
        if ($dirtyIdentityFields !== []) {
            $message = __('Prije EPREL dohvata spremite promjene ovih podataka: :fields.', [
                'fields' => implode(', ', $dirtyIdentityFields),
            ]);
            $this->addError('eprelLookup', $message);

            return;
        }
        if ($this->energyDeclarationsAreDirty($product)) {
            $message = __('Prije EPREL dohvata spremite ili odbacite promjene u energetskim deklaracijama.');
            $this->addError('eprelLookup', $message);

            return;
        }

        try {
            $outcome = $lookup->lookup($product, $this->eprelLookupOverrides());
            if ($outcome['status'] === EprelProductLookupService::STATUS_MATCHED) {
                $data = $outcome['data'] ?? [];
                $matchedGroup = strtolower(trim((string) ($data['eprel_product_group'] ?? '')));
                if (array_key_exists($matchedGroup, EprelClient::productGroupOptions())) {
                    $this->eprelLookupGroup = $matchedGroup;
                    $this->persistEprelLookupGroup($matchedGroup);
                }
                $this->loadEnergyDeclarations($product->fresh());
                $this->form['energy_label_required'] = true;
                $registration = (string) ($data['eprel_registration_number'] ?? '—');
                $eprelIsPrimary = collect($this->energyDeclarations)->contains(
                    static fn (array $row): bool => (string) ($row['source'] ?? '') === ProductEnergyDeclaration::SOURCE_EPREL
                        && (string) ($row['eprel_registration_number'] ?? '') === $registration
                        && (bool) ($row['is_primary'] ?? false),
                );
                $this->dispatch(
                    'notify',
                    type: 'success',
                    message: $eprelIsPrimary
                        ? __('Službena EPREL deklaracija je pronađena i odmah spremljena. EPREL broj: :number', ['number' => $registration])
                        : __('Službena EPREL deklaracija je odmah spremljena kao sekundarna; ručna primarna deklaracija ostala je aktivna. EPREL broj: :number', ['number' => $registration]),
                );

                return;
            }

            $manualSearch = $this->eprelLookupSearchBy !== EprelProductLookupService::SEARCH_AUTO;
            $message = match ($outcome['status']) {
                EprelProductLookupService::STATUS_NEEDS_GROUP => $manualSearch
                    ? __('Za ručnu EPREL pretragu odaberite grupu proizvoda. Odabir će se odmah spremiti uz artikl.')
                    : __('Automatska detekcija grupe nije uspjela. Odaberite EPREL grupu proizvoda; odabir će se odmah spremiti uz artikl.'),
                EprelProductLookupService::STATUS_NEEDS_BRAND => __('Za sigurnu pretragu po modelu potrebna je marka. Odaberite proizvođača artikla ili unesite marku u polje za EPREL dohvat.'),
                EprelProductLookupService::STATUS_NO_IDENTIFIERS => __('Nema dostupnog EPREL broja, valjanog barkoda, modela, SKU-a ni šifre za pretragu.'),
                default => $manualSearch
                    ? __('EPREL nije pronašao potpuno točan i jednoznačan rezultat za uneseni pojam i odabranu grupu.')
                    : __('EPREL nije pronašao točno podudaranje za dostupni barkod, model i kataloške brojeve.'),
            };
            $this->addError('eprelLookup', $message);
            $this->dispatch('notify', type: 'warning', message: $message);
        } catch (EprelException|\InvalidArgumentException $exception) {
            $message = trim($exception->getMessage()) ?: __('EPREL dohvat trenutačno nije uspio.');
            $this->addError('eprelLookup', $message);
            $this->dispatch('notify', type: 'error', message: $message);
        } catch (Throwable $exception) {
            report($exception);
            $message = __('EPREL dohvat trenutačno nije uspio. Pokušajte ponovno ili provjerite zapis u sustavu.');
            $this->addError('eprelLookup', $message);
            $this->dispatch('notify', type: 'error', message: $message);
        }
    }

    public function getCategoryOptionsProperty(): Collection
    {
        return Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->withDepth()
            ->defaultOrder()
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->where('locale', $this->form['locale']),
            ])
            ->get();
    }

    public function getFilteredCategoryOptionsProperty(): Collection
    {
        $search = Str::lower(trim($this->categorySearch));
        $selected = collect($this->form['category_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->all();
        $labels = $this->categoryLabelMap;

        return $this->categoryOptions
            ->reject(fn ($category) => in_array((int) $category->id, $selected, true))
            ->map(function ($category) use ($labels): array {
                $id = (int) $category->id;

                return [
                    'id' => $id,
                    'label' => (string) ($labels[$id] ?? ('#'.$id)),
                ];
            })
            ->filter(function (array $row) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                return Str::contains(Str::lower($row['label']), $search);
            })
            ->values()
            ->take(120);
    }

    public function getSelectedCategoryRowsProperty(): Collection
    {
        $labels = $this->categoryLabelMap;

        return collect($this->form['category_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->map(function (int $id) use ($labels): array {
                return ['id' => $id, 'label' => (string) ($labels[$id] ?? ('#'.$id))];
            });
    }

    public function getHiddenOptionValueRowsProperty(): Collection
    {
        if (! $this->productId || ! $this->useOptions()) {
            return collect();
        }

        $locale = (string) ($this->form['locale'] ?: config('app.locale', 'en'));
        $fallbackLocale = (string) config('app.locale', 'en');
        $rows = ProductOptionValue::query()
            ->where('product_id', $this->productId)
            ->where('is_active', true)
            ->with([
                'optionValue.option.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValue.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'parentOptionValue.option.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'parentOptionValue.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $seen = [];
        $items = [];

        foreach ($rows as $row) {
            foreach ([$row->parentOptionValue, $row->optionValue] as $value) {
                if (! $value || ! $value->option || $value->option->showsOnProductPage()) {
                    continue;
                }

                $key = (int) $value->option->id.':'.(int) $value->id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $items[] = [
                    'option_label' => $this->localizedOptionLabel($value->option),
                    'value_label' => $this->localizedOptionValueLabel($value),
                ];
            }
        }

        return collect($items);
    }

    /**
     * @return array<int, string>
     */
    public function getCategoryLabelMapProperty(): array
    {
        $categories = $this->categoryOptions;
        $nameById = $categories->mapWithKeys(function ($category): array {
            $name = (string) ($category->translations->first()?->name ?? ($category->code ?: ('#'.$category->id)));

            return [(int) $category->id => $name];
        });
        $byId = $categories->keyBy(fn ($category): int => (int) $category->id);
        $labels = [];

        $build = function (int $id) use (&$build, &$labels, $byId, $nameById): string {
            if (isset($labels[$id])) {
                return $labels[$id];
            }

            $current = $byId->get($id);
            if ($current === null) {
                return '#'.$id;
            }

            $name = (string) ($nameById[$id] ?? ('#'.$id));
            $parentId = (int) ($current->parent_id ?? 0);

            if ($parentId > 0 && $byId->has($parentId)) {
                $labels[$id] = $build($parentId).' > '.$name;
            } else {
                $labels[$id] = $name;
            }

            return $labels[$id];
        };

        foreach ($byId->keys() as $id) {
            $build((int) $id);
        }

        return $labels;
    }

    public function addCategory(int $categoryId): void
    {
        $ids = collect($this->form['category_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        if ($ids->contains($categoryId)) {
            return;
        }

        $ids->push($categoryId);
        $this->form['category_ids'] = $ids->all();
    }

    public function removeCategory(int $categoryId): void
    {
        $this->form['category_ids'] = collect($this->form['category_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $categoryId)
            ->values()
            ->all();
    }

    public function addPackage(): void
    {
        $this->packages[] = $this->blankPackageRow();
    }

    public function removePackage(int $index): void
    {
        unset($this->packages[$index]);
        $this->packages = array_values($this->packages);
    }

    public function addGroupPrice(): void
    {
        $this->groupPrices[] = $this->blankGroupPriceRow();
    }

    public function removeGroupPrice(int $index): void
    {
        unset($this->groupPrices[$index]);
        $this->groupPrices = array_values($this->groupPrices);
    }

    public function getCustomerGroupOptionsProperty(): Collection
    {
        return CustomerGroup::query()
            ->where(function ($query): void {
                $query->where('is_active', true);

                $selectedIds = collect($this->groupPrices)
                    ->pluck('customer_group_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->filter()
                    ->all();

                if ($selectedIds !== []) {
                    $query->orWhereIn('id', $selectedIds);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getPriceHistoryRowsProperty(): Collection
    {
        if (! $this->productId) {
            return collect();
        }

        return Product::query()
            ->findOrFail($this->productId)
            ->priceHistory()
            ->with(['customerGroup:id,name', 'productPackage:id,name,code'])
            ->limit(50)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function getEnergyMediaCollectionsProperty(): array
    {
        if (! $this->productId) {
            return [];
        }

        $product = Product::query()->find($this->productId);
        if (! $product) {
            return [];
        }

        return $product->media()
            ->whereIn('collection_name', ['product_energy_label', 'product_information_sheet'])
            ->pluck('collection_name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   registrationNumbers:list<string>,
     *   gtins:list<string>,
     *   models:list<string>,
     *   brands:list<string>,
     *   groups:list<string>
     * }
     */
    public function getEprelLookupContextProperty(): array
    {
        $empty = [
            'registrationNumbers' => [],
            'gtins' => [],
            'models' => [],
            'brands' => [],
            'groups' => [],
        ];
        if (! $this->productId) {
            return $empty;
        }

        $product = Product::query()->find($this->productId);
        if (! $product) {
            return $empty;
        }

        try {
            return app(EprelProductLookupService::class)->criteria($product, $this->eprelLookupOverrides());
        } catch (Throwable) {
            return $empty;
        }
    }

    public function getEprelLookupReadyProperty(): bool
    {
        if (! $this->productId) {
            return false;
        }

        $settings = app(MsanSettingsService::class);

        return $settings->eprelEnabled() && $settings->hasEprelApiKey();
    }

    public function render()
    {
        return view('livewire.admin.catalog.product.form', [
            'isEdit' => (bool) $this->productId,
            'useAttributes' => $this->useAttributes(),
            'useOptions' => $this->useOptions(),
            'useManufacturers' => $this->useManufacturers(),
            'unitOptions' => Product::unitOptions(),
            'shippingLabelOptions' => Product::shippingLabelOptions(),
            'packageTypeOptions' => ProductPackage::typeOptions(),
            'energyClassOptions' => ProductEnergyDeclaration::energyClassOptions(),
            'eprelProductGroupOptions' => EprelClient::productGroupOptions(),
            'eprelSearchByOptions' => EprelClient::searchByOptions(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $rules = [
            'form.code' => [
                'required',
                'string',
                'max:120',
                Rule::unique('products', 'code')->ignore($this->productId),
            ],
            'form.sku' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('products', 'sku')->ignore($this->productId),
            ],
            'form.barcode' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('products', 'barcode')->ignore($this->productId),
            ],
            'form.unit_of_measure' => ['required', Rule::in(array_keys(Product::unitOptions()))],
            'form.minimum_order_quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'form.order_quantity_step' => ['required', 'integer', 'min:1', 'max:999999'],
            'form.is_active' => ['boolean'],
            'form.tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')],
            'form.base_price' => ['required', 'numeric', 'min:0'],
            'form.stock_qty' => ['required', 'integer', 'min:0'],
            'form.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'form.length_cm' => ['nullable', 'numeric', 'min:0'],
            'form.width_cm' => ['nullable', 'numeric', 'min:0'],
            'form.height_cm' => ['nullable', 'numeric', 'min:0'],
            'form.shipping_labels' => ['nullable', 'array'],
            'form.shipping_labels.*' => [Rule::in(array_keys(Product::shippingLabelOptions()))],
            'form.energy_label_required' => ['boolean'],
            'form.payload_text' => ['nullable', 'string'],
            'form.manufacturer_id' => ['nullable'],

            'form.locale' => ['required', 'string', 'max:12'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.slug' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('product_translations', 'slug')
                    ->where(fn ($q) => $q->where('locale', $this->form['locale']))
                    ->ignore($this->productId, 'product_id'),
            ],
            'form.excerpt' => ['nullable', 'string'],
            'form.description' => ['nullable', 'string'],
            'form.meta_title' => ['nullable', 'string', 'max:255'],
            'form.meta_description' => ['nullable', 'string'],
            'form.translation_payload_text' => ['nullable', 'string'],

            'form.category_ids' => ['nullable', 'array'],
            'form.category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')->where(fn ($q) => $q->where('scope', Category::SCOPE_CATALOG)),
            ],
            'form.attribute_ids' => ['nullable', 'array'],
            'packages' => ['array'],
            'packages.*.id' => ['nullable', 'integer'],
            'packages.*.code' => ['required', 'string', 'max:120'],
            'packages.*.name' => ['required', 'string', 'max:120'],
            'packages.*.barcode' => ['nullable', 'string', 'max:80'],
            'packages.*.package_type' => ['required', Rule::in(array_keys(ProductPackage::typeOptions()))],
            'packages.*.unit_of_measure' => ['required', Rule::in(array_keys(Product::unitOptions()))],
            'packages.*.quantity' => ['required', 'numeric', 'gt:0'],
            'packages.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'packages.*.length_cm' => ['nullable', 'numeric', 'min:0'],
            'packages.*.width_cm' => ['nullable', 'numeric', 'min:0'],
            'packages.*.height_cm' => ['nullable', 'numeric', 'min:0'],
            'packages.*.is_default' => ['boolean'],
            'packages.*.is_active' => ['boolean'],
            'groupPrices' => ['array'],
            'groupPrices.*.id' => ['nullable', 'integer'],
            'groupPrices.*.customer_group_id' => ['required', 'integer', Rule::exists('customer_groups', 'id')],
            'groupPrices.*.package_code' => ['nullable', 'string', 'max:120'],
            'groupPrices.*.minimum_quantity' => ['required', 'integer', 'min:1'],
            'groupPrices.*.price' => ['required', 'numeric', 'min:0'],
            'groupPrices.*.currency_code' => ['required', 'string', 'size:3'],
            'groupPrices.*.starts_at' => ['nullable', 'date'],
            'groupPrices.*.ends_at' => ['nullable', 'date'],
            'groupPrices.*.is_active' => ['boolean'],
            'energyDeclarations' => ['array'],
            'energyDeclarations.*.id' => ['nullable', 'integer'],
            'energyDeclarations.*.context_code' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'energyDeclarations.*.label' => ['nullable', 'string', 'max:255'],
            'energyDeclarations.*.energy_class' => ['nullable', Rule::in(ProductEnergyDeclaration::energyClassOptions())],
            'energyDeclarations.*.scale_min' => ['nullable', Rule::in(ProductEnergyDeclaration::energyClassOptions())],
            'energyDeclarations.*.scale_max' => ['nullable', Rule::in(ProductEnergyDeclaration::energyClassOptions())],
            'energyDeclarations.*.eprel_registration_number' => ['nullable', 'string', 'regex:/^[0-9]{1,20}$/'],
            'energyDeclarations.*.eprel_product_group' => ['nullable', Rule::in(array_keys(EprelClient::productGroupOptions()))],
            'energyDeclarations.*.energy_label_image' => ['nullable', 'string', 'max:191'],
            'energyDeclarations.*.energy_label_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'energyDeclarations.*.product_information_sheet_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'energyDeclarations.*.is_primary' => ['boolean'],
            'energyDeclarations.*.source' => ['required', Rule::in([
                ProductEnergyDeclaration::SOURCE_MANUAL,
                ProductEnergyDeclaration::SOURCE_MSAN,
                ProductEnergyDeclaration::SOURCE_EPREL,
            ])],
        ];

        if ($this->useManufacturers()) {
            $rules['form.manufacturer_id'] = [
                'nullable',
                'integer',
                Rule::exists('catalog_manufacturers', 'id'),
            ];
        }

        if ($this->useAttributes()) {
            $rules['form.attribute_ids.*'] = [
                'integer',
                Rule::exists('catalog_attributes', 'id'),
            ];
        }

        return $rules;
    }

    private function loadProduct(): void
    {
        if (! $this->productId) {
            return;
        }

        $productQuery = Product::query()
            ->with('translations')
            ->with(['packages', 'groupPrices.productPackage'])
            ->with('energyDeclarations')
            ->with(['categories' => fn ($q) => $q->orderBy('category_product.sort_order')]);

        if ($this->useAttributes()) {
            $productQuery->with(['attributes' => fn ($q) => $q->orderBy('catalog_attribute_product.sort_order')]);
        }

        $product = $productQuery->findOrFail($this->productId);

        $preferredLocale = $this->form['locale'] ?: config('app.locale', 'en');
        $translation = $product->translations->firstWhere('locale', $preferredLocale)
            ?? $product->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $product->translations->first();

        $this->form['code'] = $product->code;
        $this->form['sku'] = $product->sku ?? '';
        $this->form['barcode'] = $product->barcode ?? '';
        $this->form['unit_of_measure'] = $product->unit_of_measure ?: 'pcs';
        $this->form['minimum_order_quantity'] = max(1, (int) $product->minimum_order_quantity);
        $this->form['order_quantity_step'] = max(1, (int) $product->order_quantity_step);
        $this->form['is_active'] = (bool) $product->is_active;
        $this->form['tax_rate_id'] = $product->tax_rate_id ? (int) $product->tax_rate_id : $this->defaultTaxRateId();
        $this->form['base_price'] = (float) $product->base_price;
        $this->form['stock_qty'] = (int) $product->stock_qty;
        $this->form['weight_kg'] = $product->weight_kg;
        $this->form['length_cm'] = $product->length_cm;
        $this->form['width_cm'] = $product->width_cm;
        $this->form['height_cm'] = $product->height_cm;
        $this->form['shipping_labels'] = $product->shipping_labels ?? [];
        $this->form['energy_label_required'] = (bool) $product->energy_label_required;
        $this->eprelLookupGroup = strtolower(trim((string) $product->eprel_lookup_product_group));
        if ($this->useManufacturers()) {
            $this->form['manufacturer_id'] = $product->manufacturer_id ? (int) $product->manufacturer_id : null;
        }
        $this->form['payload_text'] = $product->payload
            ? json_encode($product->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
        $this->form['category_ids'] = $product->categories->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($this->useAttributes()) {
            $this->form['attribute_ids'] = $product->attributes->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->hydrateAttributeSelectionsFromAttributeIds($this->form['attribute_ids']);
        }

        if ($translation) {
            $this->form['locale'] = $translation->locale;
            $this->form['name'] = $translation->name;
            $this->form['slug'] = $translation->slug;
            $this->form['excerpt'] = $translation->excerpt ?? '';
            $this->form['description'] = $translation->description ?? '';
            $this->form['meta_title'] = $translation->meta_title ?? '';
            $this->form['meta_description'] = $translation->meta_description ?? '';
            $this->form['translation_payload_text'] = $translation->payload
                ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : '';
        }

        $this->packages = $product->packages
            ->map(fn (ProductPackage $package): array => [
                'id' => $package->id,
                'code' => $package->code,
                'name' => $package->name,
                'barcode' => $package->barcode ?? '',
                'package_type' => $package->package_type,
                'unit_of_measure' => $package->unit_of_measure,
                'quantity' => (float) $package->quantity,
                'weight_kg' => $package->weight_kg,
                'length_cm' => $package->length_cm,
                'width_cm' => $package->width_cm,
                'height_cm' => $package->height_cm,
                'is_default' => (bool) $package->is_default,
                'is_active' => (bool) $package->is_active,
            ])
            ->values()
            ->all();

        $this->groupPrices = $product->groupPrices
            ->map(fn (ProductGroupPrice $price): array => [
                'id' => $price->id,
                'customer_group_id' => $price->customer_group_id,
                'package_code' => $price->productPackage?->code ?? '',
                'minimum_quantity' => (int) $price->minimum_quantity,
                'price' => (float) $price->price,
                'currency_code' => $price->currency_code,
                'starts_at' => $price->starts_at?->format('Y-m-d\TH:i') ?? '',
                'ends_at' => $price->ends_at?->format('Y-m-d\TH:i') ?? '',
                'is_active' => (bool) $price->is_active,
            ])
            ->values()
            ->all();

        $this->loadEnergyDeclarations($product);
    }

    private function loadEnergyDeclarations(?Product $product): void
    {
        if (! $product) {
            $this->energyDeclarations = [];

            return;
        }

        $this->energyDeclarations = $this->energyDeclarationRows($product);
    }

    /** @return array<int, array<string, mixed>> */
    private function energyDeclarationRows(Product $product): array
    {
        $product->load('energyDeclarations');

        return $product->energyDeclarations
            ->map(fn (ProductEnergyDeclaration $declaration): array => [
                'id' => (int) $declaration->id,
                'context_code' => (string) $declaration->context_code,
                'label' => (string) ($declaration->label ?? ''),
                'energy_class' => (string) ($declaration->energy_class ?? ''),
                'scale_min' => (string) ($declaration->scale_min ?? ''),
                'scale_max' => (string) ($declaration->scale_max ?? ''),
                'eprel_registration_number' => (string) ($declaration->eprel_registration_number ?? ''),
                'eprel_product_group' => (string) ($declaration->eprel_product_group ?? ''),
                'energy_label_image' => (string) ($declaration->energy_label_image ?? ''),
                'energy_label_url' => (string) ($declaration->energy_label_url ?? ''),
                'product_information_sheet_url' => (string) ($declaration->product_information_sheet_url ?? ''),
                'is_primary' => (bool) $declaration->is_primary,
                'source' => (string) $declaration->source,
                'synced_at' => $declaration->synced_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function energyDeclarationsAreDirty(Product $product): bool
    {
        $normalize = static fn (array $rows): array => collect($rows)
            ->map(static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'context_code' => trim((string) ($row['context_code'] ?? '')),
                'label' => trim((string) ($row['label'] ?? '')),
                'energy_class' => trim((string) ($row['energy_class'] ?? '')),
                'scale_min' => trim((string) ($row['scale_min'] ?? '')),
                'scale_max' => trim((string) ($row['scale_max'] ?? '')),
                'eprel_registration_number' => trim((string) ($row['eprel_registration_number'] ?? '')),
                'eprel_product_group' => trim((string) ($row['eprel_product_group'] ?? '')),
                'energy_label_image' => trim((string) ($row['energy_label_image'] ?? '')),
                'energy_label_url' => trim((string) ($row['energy_label_url'] ?? '')),
                'product_information_sheet_url' => trim((string) ($row['product_information_sheet_url'] ?? '')),
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'source' => (string) ($row['source'] ?? ProductEnergyDeclaration::SOURCE_MANUAL),
            ])
            ->sortBy(static fn (array $row): string => sprintf(
                '%020d|%s|%s',
                $row['id'],
                $row['source'],
                $row['context_code'],
            ))
            ->values()
            ->all();

        return $normalize($this->energyDeclarations) !== $normalize($this->energyDeclarationRows($product));
    }

    /** @return array{model:string,brand:string,eprel_product_group:string,search_by:string,search_query:string} */
    private function eprelLookupOverrides(): array
    {
        return [
            'model' => $this->eprelLookupModel,
            'brand' => $this->eprelLookupBrand,
            'eprel_product_group' => $this->eprelLookupGroup,
            'search_by' => $this->eprelLookupSearchBy,
            'search_query' => $this->eprelLookupQuery,
        ];
    }

    private function storeEprelLookupGroupSelection(string $selection, bool $notify = false): void
    {
        $this->resetErrorBag('eprelLookup');
        $group = strtolower(trim($selection));
        if ($group !== '' && ! array_key_exists($group, EprelClient::productGroupOptions())) {
            $this->eprelLookupGroup = $this->storedEprelLookupGroup();
            $this->addError('eprelLookup', __('Odabrana EPREL grupa proizvoda nije podržana.'));

            return;
        }

        $this->eprelLookupGroup = $group;
        if (! $this->persistEprelLookupGroup($group)) {
            $this->eprelLookupGroup = '';
            $this->addError('eprelLookup', __('Artikl više ne postoji. Osvježite stranicu i pokušajte ponovno.'));

            return;
        }

        if ($notify) {
            $this->dispatch(
                'notify',
                type: 'success',
                message: $group === ''
                    ? __('EPREL grupa vraćena je na automatsko određivanje.')
                    : __('Odabrana EPREL grupa odmah je spremljena uz artikl.'),
            );
        }
    }

    private function persistEprelLookupGroup(string $group): bool
    {
        if (! $this->productId) {
            return false;
        }

        $value = $group !== '' ? $group : null;
        $updated = Product::query()
            ->whereKey($this->productId)
            ->update([
                'eprel_lookup_product_group' => $value,
                'updated_at' => now(),
            ]);

        return $updated === 1 || Product::query()
            ->whereKey($this->productId)
            ->where('eprel_lookup_product_group', $value)
            ->exists();
    }

    private function storedEprelLookupGroup(): string
    {
        if (! $this->productId) {
            return '';
        }

        $group = strtolower(trim((string) Product::query()
            ->whereKey($this->productId)
            ->value('eprel_lookup_product_group')));

        return array_key_exists($group, EprelClient::productGroupOptions()) ? $group : '';
    }

    /** @return list<string> */
    private function dirtyEprelIdentityFields(Product $product): array
    {
        $fields = [];
        foreach ([
            'code' => __('šifra artikla'),
            'sku' => __('SKU'),
            'barcode' => __('barkod'),
        ] as $key => $label) {
            if (trim((string) ($this->form[$key] ?? '')) !== trim((string) $product->{$key})) {
                $fields[] = $label;
            }
        }

        if ($this->useManufacturers()
            && (int) ($this->form['manufacturer_id'] ?? 0) !== (int) ($product->manufacturer_id ?? 0)) {
            $fields[] = __('proizvođač');
        }

        return $fields;
    }

    public function getManufacturerOptionsProperty(): Collection
    {
        if (! $this->useManufacturers()) {
            return collect();
        }

        return Manufacturer::query()
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->form['locale']),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function getTaxRateOptionsProperty(): Collection
    {
        return TaxRate::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function getAttributeOptionsProperty(): Collection
    {
        if (! $this->useAttributes()) {
            return collect();
        }

        $selectedIds = collect($this->form['attribute_ids'] ?? [])
            ->merge($this->msanAttributeIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Attribute::query()
            ->where(function ($q) use ($selectedIds): void {
                $q->where('is_active', true);

                if ($selectedIds !== []) {
                    $q->orWhereIn('id', $selectedIds);
                }
            })
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->form['locale']),
            ])
            ->orderBy('group_code')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, array{group_code:string,group_name:string,type:string,is_msan:bool,items:array<int, array{id:int,name:string,is_msan:bool}>}>
     */
    public function getAttributeGroupsProperty(): array
    {
        if (! $this->useAttributes()) {
            return [];
        }

        $groups = [];

        foreach ($this->attributeOptions as $attribute) {
            $tr = $attribute->translations->first();
            $groupCode = (string) $attribute->group_code;
            $isMsan = $attribute->isMsanManaged();

            if (! isset($groups[$groupCode])) {
                $groups[$groupCode] = [
                    'group_code' => $groupCode,
                    'group_name' => (string) ($tr?->group_name ?? str($groupCode)->replace('_', ' ')->title()),
                    'type' => (string) $attribute->type,
                    'is_msan' => false,
                    'items' => [],
                ];
            }

            $groups[$groupCode]['is_msan'] = $groups[$groupCode]['is_msan'] || $isMsan;

            $groups[$groupCode]['items'][] = [
                'id' => (int) $attribute->id,
                'name' => (string) ($tr?->name ?? $attribute->code),
                'is_msan' => $isMsan,
            ];
        }

        return array_values($groups);
    }

    /**
     * @return array<int, array{group_code:string,group_name:string,type:string,item_count:int}>
     */
    public function getAttributeGroupOptionsProperty(): array
    {
        return collect($this->attributeGroups)
            ->reject(fn (array $group): bool => (bool) ($group['is_msan'] ?? false))
            ->reject(fn (array $group): bool => in_array(
                (string) $group['group_code'],
                $this->addedAttributeGroupCodes,
                true,
            ))
            ->map(fn (array $group): array => [
                'group_code' => (string) $group['group_code'],
                'group_name' => (string) $group['group_name'],
                'type' => (string) $group['type'],
                'item_count' => count($group['items']),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{group_code:string,group_name:string,type:string,is_msan:bool,is_msan_assigned:bool,selected_items:array<int, array{id:int,name:string,is_msan:bool}>,items:array<int, array{id:int,name:string,is_msan:bool}>}>
     */
    public function getVisibleAttributeGroupsProperty(): array
    {
        $msanIds = array_flip(array_map('intval', $this->msanAttributeIds));

        return collect($this->attributeGroups)
            ->filter(fn (array $group): bool => in_array(
                (string) $group['group_code'],
                $this->addedAttributeGroupCodes,
                true,
            ))
            ->map(function (array $group) use ($msanIds): array {
                $groupCode = (string) $group['group_code'];
                $manualIds = array_flip($this->attributeIdsFromSelection(
                    $this->attributeSelections[$groupCode] ?? null,
                ));
                $groupItemIds = array_flip(array_map(
                    fn (array $item): int => (int) $item['id'],
                    $group['items'],
                ));
                $assignedMsanIds = array_intersect_key($msanIds, $groupItemIds);
                $selectedIds = $manualIds + $assignedMsanIds;

                $group['is_msan_assigned'] = $assignedMsanIds !== [];
                $group['selected_items'] = array_values(array_filter(
                    $group['items'],
                    fn (array $item): bool => isset($selectedIds[(int) $item['id']]),
                ));

                return $group;
            })
            ->values()
            ->all();
    }

    private function loadTranslationForLocale(): void
    {
        if (! $this->productId) {
            $this->clearTranslationFields();

            return;
        }

        $translation = ProductTranslation::query()
            ->where('product_id', $this->productId)
            ->where('locale', $this->form['locale'])
            ->first();

        if (! $translation) {
            $this->clearTranslationFields();

            return;
        }

        $this->form['name'] = $translation->name;
        $this->form['slug'] = $translation->slug;
        $this->form['excerpt'] = $translation->excerpt ?? '';
        $this->form['description'] = $translation->description ?? '';
        $this->form['meta_title'] = $translation->meta_title ?? '';
        $this->form['meta_description'] = $translation->meta_description ?? '';
        $this->form['translation_payload_text'] = $translation->payload
            ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
    }

    private function clearTranslationFields(): void
    {
        $this->form['name'] = '';
        $this->form['slug'] = '';
        $this->form['excerpt'] = '';
        $this->form['description'] = '';
        $this->form['meta_title'] = '';
        $this->form['meta_description'] = '';
        $this->form['translation_payload_text'] = '';
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateCommerceRows(array $validated): bool
    {
        $valid = true;
        $packageCodes = [];
        $barcodes = [];
        $packageIds = [];

        foreach ($validated['packages'] ?? [] as $index => $row) {
            $code = Str::upper(trim((string) ($row['code'] ?? '')));
            if (isset($packageCodes[$code])) {
                $this->addError("packages.{$index}.code", __('Package code must be unique per product.'));
                $valid = false;
            }
            $packageCodes[$code] = true;

            $barcode = trim((string) ($row['barcode'] ?? ''));
            if ($barcode !== '') {
                if (isset($barcodes[$barcode])) {
                    $this->addError("packages.{$index}.barcode", __('Package barcode must be unique.'));
                    $valid = false;
                }
                $barcodes[$barcode] = $index;
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $packageIds[] = $id;
            }
        }

        if ($barcodes !== []) {
            $usedBarcodes = ProductPackage::query()
                ->whereIn('barcode', array_keys($barcodes))
                ->when($packageIds !== [], fn ($query) => $query->whereNotIn('id', $packageIds))
                ->pluck('barcode')
                ->all();

            foreach ($usedBarcodes as $barcode) {
                $index = $barcodes[(string) $barcode] ?? null;
                if ($index !== null) {
                    $this->addError("packages.{$index}.barcode", __('Package barcode is already in use.'));
                    $valid = false;
                }
            }
        }

        foreach ($validated['groupPrices'] ?? [] as $index => $row) {
            $packageCode = Str::upper(trim((string) ($row['package_code'] ?? '')));
            if ($packageCode !== '' && ! isset($packageCodes[$packageCode])) {
                $this->addError("groupPrices.{$index}.package_code", __('Selected package does not exist.'));
                $valid = false;
            }

            $startsAt = trim((string) ($row['starts_at'] ?? ''));
            $endsAt = trim((string) ($row['ends_at'] ?? ''));
            if ($startsAt !== '' && $endsAt !== '' && strtotime($endsAt) < strtotime($startsAt)) {
                $this->addError("groupPrices.{$index}.ends_at", __('End date must be after start date.'));
                $valid = false;
            }
        }

        if (! $valid) {
            $this->dispatch('notify', type: 'danger', message: __('Check logistics and B2B price data.'));
        }

        return $valid;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateEnergyRows(array $validated): bool
    {
        $valid = true;
        $contexts = [];
        $primaryCount = 0;
        $rows = array_values($validated['energyDeclarations'] ?? []);
        $ids = collect($rows)->pluck('id')->map(fn ($id): int => (int) $id)->filter()->all();
        $persistedById = $this->productId && $ids !== []
            ? ProductEnergyDeclaration::query()
                ->where('product_id', $this->productId)
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id')
            : collect();

        foreach ($rows as $index => $row) {
            $context = strtolower(trim((string) ($row['context_code'] ?? '')));
            if (isset($contexts[$context])) {
                $this->addError("energyDeclarations.{$index}.context_code", __('Kontekst energetske oznake mora biti jedinstven za proizvod.'));
                $valid = false;
            }
            $contexts[$context] = true;

            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $persisted = $persistedById->get($id);
                if (! $persisted || (string) $persisted->source !== (string) ($row['source'] ?? '')) {
                    $this->addError("energyDeclarations.{$index}.id", __('Energetska deklaracija ne pripada ovom proizvodu.'));
                    $valid = false;
                }
            }

            if ((bool) ($row['is_primary'] ?? false)) {
                $primaryCount++;
            }

            if ((string) ($row['source'] ?? '') !== ProductEnergyDeclaration::SOURCE_MANUAL) {
                continue;
            }

            $energyClass = trim((string) ($row['energy_class'] ?? ''));
            $scaleMin = trim((string) ($row['scale_min'] ?? ''));
            $scaleMax = trim((string) ($row['scale_max'] ?? ''));
            if ($energyClass !== '' && ($scaleMin === '' || $scaleMax === '')) {
                $this->addError("energyDeclarations.{$index}.scale_min", __('Za energetsku klasu unesite cijeli raspon oznake.'));
                $valid = false;
            }
            if (($scaleMin !== '' || $scaleMax !== '') && $energyClass === '') {
                $this->addError("energyDeclarations.{$index}.energy_class", __('Energetska klasa je obavezna uz raspon oznake.'));
                $valid = false;
            }
            if ($energyClass !== '' && $scaleMin !== '' && $scaleMax !== '') {
                $classIndex = array_search($energyClass, ProductEnergyDeclaration::ENERGY_CLASSES, true);
                $minimumIndex = array_search($scaleMin, ProductEnergyDeclaration::ENERGY_CLASSES, true);
                $maximumIndex = array_search($scaleMax, ProductEnergyDeclaration::ENERGY_CLASSES, true);
                if ($classIndex === false
                    || $minimumIndex === false
                    || $maximumIndex === false
                    || $minimumIndex > $classIndex
                    || $classIndex > $maximumIndex) {
                    $this->addError("energyDeclarations.{$index}.energy_class", __('Energetska klasa mora biti unutar unesenog raspona oznake.'));
                    $valid = false;
                }
            }

            $conflict = ProductEnergyDeclaration::query()
                ->where('product_id', $this->productId ?: 0)
                ->where('context_code', $context)
                ->when($id > 0, fn ($query) => $query->whereKeyNot($id))
                ->exists();
            if ($conflict) {
                $this->addError("energyDeclarations.{$index}.context_code", __('Taj kontekst energetske oznake već postoji.'));
                $valid = false;
            }
        }

        if ($primaryCount > 1) {
            $this->addError('energyDeclarations', __('Odaberite samo jednu primarnu energetsku deklaraciju.'));
            $valid = false;
        }

        if (! $valid) {
            $this->dispatch('notify', type: 'danger', message: __('Provjerite podatke energetskih deklaracija.'));
        }

        return $valid;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function syncPackages(Product $product, array $rows, ?int $userId): array
    {
        $existing = $product->packages()->get()->keyBy('id');
        $retainedIds = [];
        $idsByCode = [];
        $defaultIndex = collect($rows)->search(
            static fn (array $row): bool => (bool) ($row['is_default'] ?? false),
        );
        $defaultIndex = $defaultIndex === false && $rows !== [] ? 0 : $defaultIndex;

        foreach (array_values($rows) as $index => $row) {
            $id = (int) ($row['id'] ?? 0);
            $package = $existing->get($id) ?? new ProductPackage([
                'product_id' => $product->getKey(),
                'created_by' => $userId,
            ]);
            $code = Str::upper(trim((string) $row['code']));

            $package->fill([
                'product_id' => $product->getKey(),
                'code' => $code,
                'name' => trim((string) $row['name']),
                'barcode' => trim((string) ($row['barcode'] ?? '')) ?: null,
                'package_type' => (string) $row['package_type'],
                'unit_of_measure' => (string) $row['unit_of_measure'],
                'quantity' => (float) $row['quantity'],
                'weight_kg' => $row['weight_kg'] ?? null,
                'length_cm' => $row['length_cm'] ?? null,
                'width_cm' => $row['width_cm'] ?? null,
                'height_cm' => $row['height_cm'] ?? null,
                'is_default' => $index === $defaultIndex,
                'is_active' => (bool) ($row['is_active'] ?? false),
                'sort_order' => $index,
                'updated_by' => $userId,
            ])->save();

            $retainedIds[] = (int) $package->getKey();
            $idsByCode[$code] = (int) $package->getKey();
        }

        $product->packages()
            ->when($retainedIds !== [], fn ($query) => $query->whereNotIn('id', $retainedIds))
            ->get()
            ->each
            ->delete();

        return $idsByCode;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $packageIdsByCode
     */
    private function syncGroupPrices(
        Product $product,
        array $rows,
        array $packageIdsByCode,
        ?int $userId,
    ): void {
        $existing = $product->groupPrices()->get()->keyBy('id');
        $retainedIds = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $price = $existing->get($id) ?? new ProductGroupPrice([
                'product_id' => $product->getKey(),
                'created_by' => $userId,
            ]);
            $packageCode = Str::upper(trim((string) ($row['package_code'] ?? '')));

            $price->fill([
                'product_id' => $product->getKey(),
                'customer_group_id' => (int) $row['customer_group_id'],
                'product_package_id' => $packageCode !== ''
                    ? ($packageIdsByCode[$packageCode] ?? null)
                    : null,
                'minimum_quantity' => (int) $row['minimum_quantity'],
                'price' => (float) $row['price'],
                'currency_code' => Str::upper((string) $row['currency_code']),
                'starts_at' => trim((string) ($row['starts_at'] ?? '')) ?: null,
                'ends_at' => trim((string) ($row['ends_at'] ?? '')) ?: null,
                'is_active' => (bool) ($row['is_active'] ?? false),
                'updated_by' => $userId,
            ])->save();

            $retainedIds[] = (int) $price->getKey();
        }

        $product->groupPrices()
            ->when($retainedIds !== [], fn ($query) => $query->whereNotIn('id', $retainedIds))
            ->get()
            ->each
            ->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncEnergyDeclarations(Product $product, array $rows): void
    {
        $previousEprelIdentity = [
            trim((string) $product->eprel_registration_number),
            trim((string) $product->eprel_product_group),
        ];
        $existing = $product->energyDeclarations()->get()->keyBy('id');
        $retainedManualIds = [];
        $savedIdsByIndex = [];

        foreach (array_values($rows) as $index => $row) {
            $id = (int) ($row['id'] ?? 0);
            $persisted = $existing->get($id);
            $source = $persisted
                ? (string) $persisted->source
                : (string) ($row['source'] ?? ProductEnergyDeclaration::SOURCE_MANUAL);

            if ($source !== ProductEnergyDeclaration::SOURCE_MANUAL) {
                if ($persisted) {
                    $savedIdsByIndex[$index] = (int) $persisted->id;
                }

                continue;
            }

            $declaration = $persisted instanceof ProductEnergyDeclaration
                && $persisted->source === ProductEnergyDeclaration::SOURCE_MANUAL
                    ? $persisted
                    : new ProductEnergyDeclaration(['product_id' => $product->id]);
            $declaration->fill([
                'product_id' => $product->id,
                'context_code' => strtolower(trim((string) $row['context_code'])),
                'label' => trim((string) ($row['label'] ?? '')) ?: null,
                'energy_class' => trim((string) ($row['energy_class'] ?? '')) ?: null,
                'scale_min' => trim((string) ($row['scale_min'] ?? '')) ?: null,
                'scale_max' => trim((string) ($row['scale_max'] ?? '')) ?: null,
                'eprel_registration_number' => trim((string) ($row['eprel_registration_number'] ?? '')) ?: null,
                'eprel_product_group' => trim((string) ($row['eprel_product_group'] ?? '')) ?: null,
                'energy_label_image' => null,
                'energy_label_url' => trim((string) ($row['energy_label_url'] ?? '')) ?: null,
                'product_information_sheet_url' => trim((string) ($row['product_information_sheet_url'] ?? '')) ?: null,
                'is_primary' => false,
                'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
                'synced_at' => null,
            ])->save();

            $retainedManualIds[] = (int) $declaration->id;
            $savedIdsByIndex[$index] = (int) $declaration->id;
        }

        $product->energyDeclarations()
            ->where('source', ProductEnergyDeclaration::SOURCE_MANUAL)
            ->when($retainedManualIds !== [], fn ($query) => $query->whereNotIn('id', $retainedManualIds))
            ->get()
            ->each
            ->delete();

        $selectedIndex = collect(array_values($rows))
            ->search(fn (array $row): bool => (bool) ($row['is_primary'] ?? false));
        $selectedId = $selectedIndex !== false
            ? ($savedIdsByIndex[(int) $selectedIndex] ?? null)
            : null;

        if ($selectedId) {
            $product->energyDeclarations()->update(['is_primary' => false]);
            $product->energyDeclarations()->whereKey($selectedId)->update(['is_primary' => true]);
        } elseif (! $product->energyDeclarations()->where('is_primary', true)->exists()) {
            $fallback = $product->energyDeclarations()->orderBy('id')->first();
            $fallback?->forceFill(['is_primary' => true])->save();
        }

        $primary = $product->energyDeclarations()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();
        $product->forceFill([
            'energy_efficiency_class' => $primary?->energy_class,
            'energy_efficiency_scale' => $primary && ($primary->scale_min || $primary->scale_max)
                ? trim((string) $primary->scale_min.'-'.(string) $primary->scale_max, '-')
                : null,
            'eprel_registration_number' => $primary?->eprel_registration_number,
            'eprel_product_group' => $primary?->eprel_product_group,
            'eprel_energy_label_image' => $primary?->energy_label_image,
            'energy_label_url' => $primary?->energy_label_url,
            'product_information_sheet_url' => $primary?->product_information_sheet_url,
            'energy_data_synced_at' => $primary?->synced_at,
        ])->save();

        $currentEprelIdentity = [
            trim((string) $product->eprel_registration_number),
            trim((string) $product->eprel_product_group),
        ];
        if ($previousEprelIdentity !== $currentEprelIdentity) {
            MsanProduct::query()
                ->where('local_product_id', $product->id)
                ->update([
                    'eprel_match_status' => MsanProduct::EPREL_PENDING,
                    'eprel_identifier_checksum' => null,
                    'eprel_checked_at' => null,
                ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function blankPackageRow(): array
    {
        return [
            'id' => null,
            'code' => '',
            'name' => '',
            'barcode' => '',
            'package_type' => 'box',
            'unit_of_measure' => $this->form['unit_of_measure'] ?: 'pcs',
            'quantity' => 1,
            'weight_kg' => null,
            'length_cm' => null,
            'width_cm' => null,
            'height_cm' => null,
            'is_default' => $this->packages === [],
            'is_active' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankGroupPriceRow(): array
    {
        return [
            'id' => null,
            'customer_group_id' => null,
            'package_code' => '',
            'minimum_quantity' => 1,
            'price' => 0,
            'currency_code' => 'EUR',
            'starts_at' => '',
            'ends_at' => '',
            'is_active' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankEnergyDeclarationRow(): array
    {
        return [
            'id' => null,
            'context_code' => 'manual-'.Str::lower(Str::random(8)),
            'label' => '',
            'energy_class' => '',
            'scale_min' => 'A',
            'scale_max' => 'G',
            'eprel_registration_number' => '',
            'eprel_product_group' => '',
            'energy_label_image' => '',
            'energy_label_url' => '',
            'product_information_sheet_url' => '',
            'is_primary' => $this->energyDeclarations === [],
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            'synced_at' => null,
        ];
    }

    private function localizedOptionLabel($option): string
    {
        $locale = (string) ($this->form['locale'] ?: config('app.locale', 'en'));
        $fallbackLocale = (string) config('app.locale', 'en');
        $translation = $option->translations?->firstWhere('locale', $locale)
            ?? $option->translations?->firstWhere('locale', $fallbackLocale)
            ?? $option->translations?->first();

        return trim((string) ($translation?->name ?? $option->code));
    }

    private function localizedOptionValueLabel($value): string
    {
        $locale = (string) ($this->form['locale'] ?: config('app.locale', 'en'));
        $fallbackLocale = (string) config('app.locale', 'en');
        $translation = $value->translations?->firstWhere('locale', $locale)
            ?? $value->translations?->firstWhere('locale', $fallbackLocale)
            ?? $value->translations?->first();

        return trim((string) ($translation?->name ?? $value->code));
    }

    /**
     * @return array<mixed>|null|false
     */
    private function decodeJsonField(string $field): array|null|false
    {
        $value = trim((string) data_get($this, $field));
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError($field, __('Invalid JSON payload.'));
            $this->dispatch('notify', type: 'danger', message: __('Invalid JSON payload.'));

            return false;
        }

        if (! is_array($decoded)) {
            $this->addError($field, __('JSON payload must decode to object/array.'));
            $this->dispatch('notify', type: 'danger', message: __('JSON payload must decode to object/array.'));

            return false;
        }

        return $decoded;
    }

    /**
     * @return array<int>|false
     */
    private function resolveAttributeIdsForSave(): array|false
    {
        if (! $this->useAttributes()) {
            return [];
        }

        return $this->resolveGroupedAttributeSelections();
    }

    private function defaultTaxRateId(): ?int
    {
        return TaxRate::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');
    }

    /**
     * @return array<int>|false
     */
    private function resolveGroupedAttributeSelections(): array|false
    {
        $normalizedByGroup = [];
        $allIds = [];

        foreach ($this->attributeSelections as $groupCode => $value) {
            $ids = $this->attributeIdsFromSelection($value);

            if (! empty($ids)) {
                $normalizedByGroup[(string) $groupCode] = $ids;
                foreach ($ids as $id) {
                    $allIds[] = $id;
                }
            }
        }

        $allIds = array_values(array_unique($allIds));
        if (empty($allIds)) {
            return [];
        }

        $rows = Attribute::query()
            ->whereIn('id', $allIds)
            ->get(['id', 'group_code', 'type']);

        if ($rows->count() !== count($allIds)) {
            $this->addError('form.attribute_ids', __('Invalid attribute selection.'));
            $this->dispatch('notify', type: 'danger', message: __('Invalid attribute selection.'));

            return false;
        }

        $byId = $rows->keyBy('id');

        foreach ($normalizedByGroup as $groupCode => $ids) {
            $groupType = null;

            foreach ($ids as $id) {
                /** @var Attribute|null $row */
                $row = $byId->get($id);

                if (! $row) {
                    $this->addError('form.attribute_ids', __('Invalid attribute selection.'));
                    $this->dispatch('notify', type: 'danger', message: __('Invalid attribute selection.'));

                    return false;
                }

                if ((string) $row->group_code !== $groupCode) {
                    $this->addError('attributeSelections.'.$groupCode, __('Selected value does not belong to this group.'));
                    $this->dispatch('notify', type: 'danger', message: __('Attribute group/value mismatch detected.'));

                    return false;
                }

                $groupType ??= (string) $row->type;
            }

            if (($groupType ?? Attribute::TYPE_SELECT) === Attribute::TYPE_SELECT && count($ids) > 1) {
                $this->addError('attributeSelections.'.$groupCode, __('Only one value can be selected for this group.'));
                $this->dispatch('notify', type: 'danger', message: __('Only one value is allowed for select-type attribute groups.'));

                return false;
            }
        }

        return $allIds;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function hydrateAttributeSelectionsFromAttributeIds(array $ids): void
    {
        $this->attributeSelections = [];
        $this->addedAttributeGroupCodes = [];
        $this->msanAttributeIds = [];

        if (empty($ids)) {
            return;
        }

        $attributes = Attribute::query()
            ->whereIn('id', $ids)
            ->orderBy('group_code')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'group_code', 'type', 'payload']);

        foreach ($attributes as $attribute) {
            $groupCode = (string) $attribute->group_code;
            $id = (int) $attribute->id;

            if (! in_array($groupCode, $this->addedAttributeGroupCodes, true)) {
                $this->addedAttributeGroupCodes[] = $groupCode;
            }

            if ($attribute->isMsanManaged()) {
                $this->msanAttributeIds[] = $id;

                continue;
            }

            if ((string) $attribute->type === Attribute::TYPE_MULTI) {
                $existing = $this->attributeSelections[$groupCode] ?? [];
                if (! is_array($existing)) {
                    $existing = [];
                }
                $existing[] = $id;
                $this->attributeSelections[$groupCode] = array_values(array_unique(array_map('intval', $existing)));

                continue;
            }

            if (! isset($this->attributeSelections[$groupCode]) || (int) $this->attributeSelections[$groupCode] <= 0) {
                $this->attributeSelections[$groupCode] = $id;
            }
        }

        $this->msanAttributeIds = array_values(array_unique($this->msanAttributeIds));
    }

    /**
     * @return array<int, int>
     */
    private function attributeIdsFromSelection(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_unique(array_filter(
                array_map('intval', $value),
                fn (int $id): bool => $id > 0,
            )));
        }

        $id = (int) $value;

        return $id > 0 ? [$id] : [];
    }

    /**
     * @param  array<int, int|string>  $submittedIds
     */
    private function syncManualAttributes(Product $product, array $submittedIds): void
    {
        $submittedIds = array_values(array_unique(array_filter(
            array_map('intval', $submittedIds),
            fn (int $id): bool => $id > 0,
        )));
        $submittedAttributes = Attribute::query()
            ->whereIn('id', $submittedIds)
            ->get(['id', 'payload'])
            ->keyBy('id');
        $manualPayload = [];
        $manualSortOrder = 0;

        foreach ($submittedIds as $attributeId) {
            /** @var Attribute|null $attribute */
            $attribute = $submittedAttributes->get($attributeId);
            if (! $attribute || $attribute->isMsanManaged()) {
                continue;
            }

            $manualPayload[$attributeId] = ['sort_order' => $manualSortOrder++];
        }

        $currentManualIds = $product->attributes()
            ->get(['catalog_attributes.id', 'catalog_attributes.payload'])
            ->reject(fn (Attribute $attribute): bool => $attribute->isMsanManaged())
            ->modelKeys();

        $manualIdsToDetach = array_values(array_diff(
            array_map('intval', $currentManualIds),
            array_map('intval', array_keys($manualPayload)),
        ));

        if ($manualIdsToDetach !== []) {
            $product->attributes()->detach($manualIdsToDetach);
        }

        if ($manualPayload !== []) {
            $product->attributes()->syncWithoutDetaching($manualPayload);
        }
    }

    private function useOptions(): bool
    {
        return app(CatalogFeatureService::class)->useOptions();
    }

    private function useAttributes(): bool
    {
        return app(CatalogFeatureService::class)->useAttributes();
    }

    private function useManufacturers(): bool
    {
        return app(CatalogFeatureService::class)->useManufacturers();
    }
}
