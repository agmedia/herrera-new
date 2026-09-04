<?php

namespace App\Livewire\Admin\Catalog\Product;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class OptionValuesManager extends Component
{
    public int $productId;

    public string $locale = 'en';

    public string $productCode = '';

    public string $productSku = '';

    public string $productName = '';

    public string $mode = 'single';

    public ?int $singleOptionId = null;

    public ?int $primaryOptionId = null;

    public ?int $secondaryOptionId = null;

    /** @var array<int, array{id:int,label:string,values_count:int}> */
    public array $availableOptions = [];

    /** @var array<int, int> */
    public array $selectedOptionIds = [];

    /** @var array<int, array{id:int,label:string,values:array<int, array{id:int,label:string,code:string,is_active:bool}>}> */
    public array $assignedOptions = [];

    /** @var array<int, array{id:int,label:string,selected_value_id:int|null,values:array<int, array{id:int,label:string,is_active:bool}>}> */
    public array $filterOnlyOptions = [];

    /** @var array<int, array{id:int|null,option_value_id:int|null,parent_option_value_id:int|null,sku:string,stock_qty:int,price_override:string,is_active:bool}> */
    public array $rows = [];

    public function mount(int $productId): void
    {
        $this->productId = $productId;
        $this->locale = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        $this->loadProductContext();
        $this->loadExistingRows();
        $this->loadFilterOnlyOptions();
    }

    public function updatedMode(): void
    {
        $this->rows = [];
        $this->applyModeDefaults();
        $this->resetValidation();
    }

    public function updatedSingleOptionId(): void
    {
        if ($this->mode === 'single') {
            $this->rows = [];
        }
    }

    public function updatedPrimaryOptionId(): void
    {
        if ($this->mode === 'linked') {
            $this->rows = [];
        }
    }

    public function updatedSecondaryOptionId(): void
    {
        if ($this->mode === 'linked') {
            $this->rows = [];
        }
    }

    public function updatedSelectedOptionIds(): void
    {
        $this->selectedOptionIds = $this->normalizeSelectedOptionIds($this->selectedOptionIds);
    }

    public function addRow(): void
    {
        if ($this->mode === 'single') {
            $this->rows[] = $this->rowTemplate(
                $this->firstValueIdForOption($this->singleOptionId),
                null
            );

            return;
        }

        $this->rows[] = $this->rowTemplate(
            $this->firstValueIdForOption($this->secondaryOptionId),
            $this->firstValueIdForOption($this->primaryOptionId)
        );
    }

    public function removeRow(int $index): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function clearRows(): void
    {
        $this->rows = [];
    }

    public function addAllSingleValues(): void
    {
        if ($this->mode !== 'single' || ! $this->singleOptionId) {
            return;
        }

        $existing = [];
        foreach ($this->rows as $row) {
            $id = (int) ($row['option_value_id'] ?? 0);
            if ($id > 0) {
                $existing[$id] = true;
            }
        }

        foreach ($this->valuesForOption($this->singleOptionId) as $value) {
            $valueId = (int) $value['id'];
            if (isset($existing[$valueId])) {
                continue;
            }

            $this->rows[] = $this->rowTemplate($valueId, null);
            $existing[$valueId] = true;
        }
    }

    public function generateLinkedMatrix(): void
    {
        if ($this->mode !== 'linked' || ! $this->primaryOptionId || ! $this->secondaryOptionId) {
            return;
        }

        if ($this->primaryOptionId === $this->secondaryOptionId) {
            $this->dispatch('notify', type: 'warning', message: __('Choose different primary and secondary options.'));

            return;
        }

        $existing = [];
        foreach ($this->rows as $row) {
            $parentId = (int) ($row['parent_option_value_id'] ?? 0);
            $valueId = (int) ($row['option_value_id'] ?? 0);
            if ($parentId > 0 && $valueId > 0) {
                $existing[$parentId.':'.$valueId] = true;
            }
        }

        foreach ($this->valuesForOption($this->primaryOptionId) as $primaryValue) {
            foreach ($this->valuesForOption($this->secondaryOptionId) as $secondaryValue) {
                $parentId = (int) $primaryValue['id'];
                $valueId = (int) $secondaryValue['id'];
                $key = $parentId.':'.$valueId;

                if (isset($existing[$key])) {
                    continue;
                }

                $this->rows[] = $this->rowTemplate($valueId, $parentId);
                $existing[$key] = true;
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'mode' => ['required', 'in:single,linked'],
            'singleOptionId' => ['nullable', 'integer'],
            'primaryOptionId' => ['nullable', 'integer'],
            'secondaryOptionId' => ['nullable', 'integer'],
        ]);

        if (empty($this->assignedOptions)) {
            $this->dispatch('notify', type: 'warning', message: __('Assign option groups first.'));

            return;
        }

        $allowedOptionIds = array_flip($this->optionIds());
        $rowsToInsert = [];
        $seenCombinations = [];
        $hasRowErrors = false;

        if ($this->mode === 'single') {
            if (! $this->singleOptionId || ! isset($allowedOptionIds[$this->singleOptionId])) {
                $this->addError('singleOptionId', __('Select one assigned option.'));
                $this->dispatch('notify', type: 'danger', message: __('Select a valid option for single mode.'));

                return;
            }

            $validValueIds = array_flip($this->valueIdsForOption($this->singleOptionId));
        } else {
            if (
                ! $this->primaryOptionId
                || ! $this->secondaryOptionId
                || ! isset($allowedOptionIds[$this->primaryOptionId])
                || ! isset($allowedOptionIds[$this->secondaryOptionId])
            ) {
                $this->addError('primaryOptionId', __('Select assigned options.'));
                $this->addError('secondaryOptionId', __('Select assigned options.'));
                $this->dispatch('notify', type: 'danger', message: __('Select valid primary and secondary options.'));

                return;
            }

            if ($this->primaryOptionId === $this->secondaryOptionId) {
                $this->addError('secondaryOptionId', __('Secondary option must differ from primary.'));
                $this->dispatch('notify', type: 'danger', message: __('Primary and secondary options must be different.'));

                return;
            }

            $validParentIds = array_flip($this->valueIdsForOption($this->primaryOptionId));
            $validValueIds = array_flip($this->valueIdsForOption($this->secondaryOptionId));
        }

        foreach ($this->rows as $index => $row) {
            $valueId = (int) ($row['option_value_id'] ?? 0);
            $parentId = $this->mode === 'linked'
                ? (int) ($row['parent_option_value_id'] ?? 0)
                : null;

            if ($valueId <= 0) {
                $this->addError('rows.'.$index.'.option_value_id', __('Value is required.'));
                $hasRowErrors = true;

                continue;
            }

            if (! isset($validValueIds[$valueId])) {
                $this->addError('rows.'.$index.'.option_value_id', __('Invalid value for selected option.'));
                $hasRowErrors = true;

                continue;
            }

            if ($this->mode === 'linked') {
                if (! $parentId || ! isset($validParentIds[$parentId])) {
                    $this->addError('rows.'.$index.'.parent_option_value_id', __('Invalid primary value.'));
                    $hasRowErrors = true;

                    continue;
                }
            }

            $combinationKey = $this->mode === 'single'
                ? 's:'.$valueId
                : 'l:'.$parentId.':'.$valueId;

            if (isset($seenCombinations[$combinationKey])) {
                $this->addError('rows.'.$index.'.option_value_id', __('Duplicate combination.'));
                $hasRowErrors = true;

                continue;
            }
            $seenCombinations[$combinationKey] = true;

            $stockRaw = $row['stock_qty'] ?? 0;
            if (! is_numeric($stockRaw) || (int) $stockRaw < 0) {
                $this->addError('rows.'.$index.'.stock_qty', __('Stock must be a non-negative integer.'));
                $hasRowErrors = true;

                continue;
            }

            $price = $this->normalizePrice($row['price_override'] ?? '', 'rows.'.$index.'.price_override');
            if ($price === false) {
                $hasRowErrors = true;

                continue;
            }

            $sku = trim((string) ($row['sku'] ?? ''));

            $rowsToInsert[] = [
                'product_id' => $this->productId,
                'option_value_id' => $valueId,
                'parent_option_value_id' => $parentId,
                'mode' => $this->mode,
                'sku' => $sku !== '' ? $sku : null,
                'stock_qty' => (int) $stockRaw,
                'price_override' => $price,
                'sort_order' => $index,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'combination_hash' => hash('sha256', $combinationKey),
            ];
        }

        if ($hasRowErrors) {
            $this->dispatch('notify', type: 'danger', message: __('Fix validation errors before saving.'));

            return;
        }

        $userId = auth()->id();
        $filterOnlyOptionIds = $this->filterOnlyOptionIds();

        DB::transaction(function () use ($rowsToInsert, $userId, $filterOnlyOptionIds): void {
            $this->deletePurchasableRows($filterOnlyOptionIds);

            foreach ($rowsToInsert as $row) {
                ProductOptionValue::query()->create($row + [
                    'payload' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }
        });

        $count = count($rowsToInsert);
        $message = $count > 0
            ? __('Product option values saved (:count rows).', ['count' => $count])
            : __('All product option values cleared.');

        $this->dispatch('notify', type: $count > 0 ? 'success' : 'info', message: $message);
        $this->loadExistingRows();
        $this->loadFilterOnlyOptions();
    }

    public function saveFilterOnlyOptions(): void
    {
        if (empty($this->filterOnlyOptions)) {
            $this->dispatch('notify', type: 'warning', message: __('No filter-only option groups are assigned to this product.'));

            return;
        }

        $rowsToInsert = [];
        $hasErrors = false;

        foreach ($this->filterOnlyOptions as $index => $option) {
            $optionId = (int) ($option['id'] ?? 0);
            $selectedValueId = (int) ($option['selected_value_id'] ?? 0);

            if ($selectedValueId <= 0) {
                continue;
            }

            $allowedValueIds = array_flip(array_map(
                fn (array $value): int => (int) $value['id'],
                $option['values'] ?? []
            ));

            if (! isset($allowedValueIds[$selectedValueId])) {
                $this->addError('filterOnlyOptions.'.$index.'.selected_value_id', __('Invalid value for selected option.'));
                $hasErrors = true;

                continue;
            }

            $rowsToInsert[] = [
                'product_id' => $this->productId,
                'option_value_id' => $selectedValueId,
                'parent_option_value_id' => null,
                'mode' => 'filter',
                'sku' => null,
                'stock_qty' => 0,
                'price_override' => null,
                'sort_order' => $index,
                'is_active' => true,
                'combination_hash' => hash('sha256', 'filter:'.$optionId.':'.$selectedValueId),
            ];
        }

        if ($hasErrors) {
            $this->dispatch('notify', type: 'danger', message: __('Fix validation errors before saving.'));

            return;
        }

        $userId = auth()->id();
        $filterOnlyOptionIds = $this->filterOnlyOptionIds();

        DB::transaction(function () use ($rowsToInsert, $userId, $filterOnlyOptionIds): void {
            $this->deleteFilterOnlyRows($filterOnlyOptionIds);

            foreach ($rowsToInsert as $row) {
                ProductOptionValue::query()->create($row + [
                    'payload' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }
        });

        $this->dispatch(
            'notify',
            type: 'success',
            message: __('Filter-only option values saved.')
        );

        $this->loadExistingRows();
        $this->loadFilterOnlyOptions();
    }

    public function saveOptionGroups(): void
    {
        $this->selectedOptionIds = $this->normalizeSelectedOptionIds($this->selectedOptionIds);

        $this->validate([
            'selectedOptionIds' => ['array'],
            'selectedOptionIds.*' => [
                'integer',
                Rule::exists('catalog_options', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
        ]);

        $product = Product::query()
            ->with(['options' => fn ($q) => $q->orderBy('catalog_option_product.sort_order')])
            ->findOrFail($this->productId);

        $previousIds = $product->options->pluck('id')->map(fn ($id) => (int) $id)->all();
        $changed = $previousIds !== $this->selectedOptionIds;

        $syncPayload = [];
        foreach ($this->selectedOptionIds as $index => $optionId) {
            $syncPayload[(int) $optionId] = [
                'sort_order' => $index,
                'is_required' => false,
            ];
        }

        DB::transaction(function () use ($product, $syncPayload, $changed): void {
            $product->options()->sync($syncPayload);

            if ($changed) {
                ProductOptionValue::query()
                    ->where('product_id', $this->productId)
                    ->delete();
            }
        });

        $this->loadProductContext();
        $this->loadExistingRows();
        $this->loadFilterOnlyOptions();

        $this->dispatch(
            'notify',
            type: 'success',
            message: $changed
                ? 'Option groups saved. Existing option-value rows were reset.'
                : 'Option groups saved.'
        );
    }

    public function backToProduct()
    {
        return redirect()->route('admin.products.edit', [
            'product' => $this->productId,
            'locale' => $this->locale,
        ]);
    }

    public function render()
    {
        return view('livewire.admin.catalog.product.option-values-manager', [
            'singleValues' => $this->valuesForOption($this->singleOptionId),
            'primaryValues' => $this->valuesForOption($this->primaryOptionId),
            'secondaryValues' => $this->valuesForOption($this->secondaryOptionId),
        ]);
    }

    private function loadProductContext(): void
    {
        $product = Product::query()
            ->with('translations')
            ->with([
                'options' => fn ($q) => $q->orderBy('catalog_option_product.sort_order'),
            ])
            ->findOrFail($this->productId);

        $translation = $product->translations->firstWhere('locale', $this->locale)
            ?? $product->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $product->translations->first();

        $this->productCode = $product->code;
        $this->productSku = (string) ($product->sku ?? '');
        $this->productName = $translation?->name ?? $product->code;
        $this->selectedOptionIds = $this->normalizeSelectedOptionIds(
            $product->options->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
        $this->loadAvailableOptions();
        $this->loadAssignedOptions();
        $this->applyModeDefaults();
    }

    private function loadExistingRows(): void
    {
        $existing = ProductOptionValue::query()
            ->where('product_id', $this->productId)
            ->with(['optionValue.option', 'parentOptionValue.option'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(static fn (ProductOptionValue $row): bool => $row->showsOnProductPage())
            ->values();

        if ($existing->isEmpty()) {
            $this->rows = [];
            $this->applyModeDefaults();

            return;
        }

        $hasLinkedRows = $existing->contains(fn (ProductOptionValue $row): bool => (int) ($row->parent_option_value_id ?? 0) > 0);
        $this->mode = $hasLinkedRows ? 'linked' : 'single';

        if ($this->mode === 'single') {
            $this->singleOptionId = $this->optionIdForValue((int) $existing->first()->option_value_id);
            if (! $this->singleOptionId) {
                $this->singleOptionId = isset($this->assignedOptions[0]['id']) ? (int) $this->assignedOptions[0]['id'] : null;
            }
            $this->primaryOptionId = null;
            $this->secondaryOptionId = null;
        } else {
            $first = $existing->first();
            $this->primaryOptionId = $this->optionIdForValue((int) ($first->parent_option_value_id ?? 0));
            $this->secondaryOptionId = $this->optionIdForValue((int) $first->option_value_id);
            $this->singleOptionId = null;
            $this->normalizeLinkedOptionSelection();
        }

        $this->rows = $existing
            ->map(function (ProductOptionValue $row): array {
                return [
                    'id' => (int) $row->id,
                    'option_value_id' => (int) $row->option_value_id,
                    'parent_option_value_id' => $row->parent_option_value_id ? (int) $row->parent_option_value_id : null,
                    'sku' => (string) ($row->sku ?? ''),
                    'stock_qty' => (int) $row->stock_qty,
                    'price_override' => $row->price_override !== null ? (string) $row->price_override : '',
                    'is_active' => (bool) $row->is_active,
                ];
            })
            ->values()
            ->all();

        $this->sanitizeRowsForCurrentMode();
    }

    private function applyModeDefaults(): void
    {
        if (empty($this->assignedOptions)) {
            $this->singleOptionId = null;
            $this->primaryOptionId = null;
            $this->secondaryOptionId = null;

            return;
        }

        if ($this->mode === 'single') {
            $this->singleOptionId ??= $this->assignedOptions[0]['id'];
            $this->primaryOptionId = null;
            $this->secondaryOptionId = null;

            return;
        }

        $this->singleOptionId = null;
        $this->primaryOptionId ??= $this->assignedOptions[0]['id'];
        $this->secondaryOptionId ??= $this->assignedOptions[1]['id'] ?? null;
        $this->normalizeLinkedOptionSelection();
    }

    private function loadAvailableOptions(): void
    {
        $options = Option::query()
            ->where('is_active', true)
            ->withCount('values')
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $this->availableOptions = $options
            ->map(function (Option $option): array {
                $translation = $option->translations->first();
                $label = $translation?->name ?? ($option->code ?: 'Option #'.$option->id);

                return [
                    'id' => (int) $option->id,
                    'label' => $label,
                    'values_count' => (int) $option->values_count,
                ];
            })
            ->values()
            ->all();
    }

    private function loadAssignedOptions(): void
    {
        if (empty($this->selectedOptionIds)) {
            $this->assignedOptions = [];

            return;
        }

        $options = Option::query()
            ->whereIn('id', $this->selectedOptionIds)
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
                'values' => fn ($vq) => $vq
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with([
                        'translations' => fn ($vtq) => $vtq->where('locale', $this->locale),
                    ]),
            ])
            ->get()
            ->keyBy('id');

        $assigned = [];
        foreach ($this->selectedOptionIds as $optionId) {
            $option = $options->get($optionId);
            if (! $option) {
                continue;
            }

            if (! $option->showsOnProductPage()) {
                continue;
            }

            $optionTranslation = $option->translations->first();
            $optionLabel = $optionTranslation?->name ?? ($option->code ?: 'Option #'.$option->id);

            $values = $option->values
                ->map(function ($value): array {
                    $valueTranslation = $value->translations->first();
                    $label = $valueTranslation?->name ?? ($value->code ?: 'Value #'.$value->id);
                    if (! $value->is_active) {
                        $label .= ' (inactive)';
                    }

                    return [
                        'id' => (int) $value->id,
                        'label' => $label,
                        'code' => (string) $value->code,
                        'is_active' => (bool) $value->is_active,
                    ];
                })
                ->values()
                ->all();

            $assigned[] = [
                'id' => (int) $option->id,
                'label' => $optionLabel,
                'values' => $values,
            ];
        }

        $this->assignedOptions = $assigned;
    }

    private function loadFilterOnlyOptions(): void
    {
        if (empty($this->selectedOptionIds)) {
            $this->filterOnlyOptions = [];

            return;
        }

        $options = Option::query()
            ->whereIn('id', $this->selectedOptionIds)
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
                'values' => fn ($vq) => $vq
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->with([
                        'translations' => fn ($vtq) => $vtq->where('locale', $this->locale),
                    ]),
            ])
            ->get()
            ->filter(static fn (Option $option): bool => ! $option->showsOnProductPage())
            ->keyBy('id');

        if ($options->isEmpty()) {
            $this->filterOnlyOptions = [];

            return;
        }

        $currentRows = ProductOptionValue::query()
            ->where('product_id', $this->productId)
            ->where('is_active', true)
            ->whereNull('parent_option_value_id')
            ->whereHas('optionValue', fn ($query) => $query->whereIn('option_id', $options->keys()->all()))
            ->with('optionValue')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ProductOptionValue $row): array => [
                (int) $row->optionValue->option_id => (int) $row->option_value_id,
            ]);

        $filterOnlyOptions = [];
        foreach ($this->selectedOptionIds as $optionId) {
            $option = $options->get($optionId);
            if (! $option) {
                continue;
            }

            $optionTranslation = $option->translations->first();
            $optionLabel = $optionTranslation?->name ?? ($option->code ?: 'Option #'.$option->id);

            $values = $option->values
                ->map(function ($value): array {
                    $valueTranslation = $value->translations->first();
                    $label = $valueTranslation?->name ?? ($value->code ?: 'Value #'.$value->id);
                    if (! $value->is_active) {
                        $label .= ' (inactive)';
                    }

                    return [
                        'id' => (int) $value->id,
                        'label' => $label,
                        'is_active' => (bool) $value->is_active,
                    ];
                })
                ->values()
                ->all();

            $filterOnlyOptions[] = [
                'id' => (int) $option->id,
                'label' => $optionLabel,
                'selected_value_id' => $currentRows->get((int) $option->id),
                'values' => $values,
            ];
        }

        $this->filterOnlyOptions = $filterOnlyOptions;
    }

    private function normalizeLinkedOptionSelection(): void
    {
        if ($this->primaryOptionId && $this->secondaryOptionId && $this->primaryOptionId !== $this->secondaryOptionId) {
            return;
        }

        $ids = $this->optionIds();
        if (count($ids) < 2) {
            $this->secondaryOptionId = null;

            return;
        }

        if (! $this->primaryOptionId || ! in_array($this->primaryOptionId, $ids, true)) {
            $this->primaryOptionId = $ids[0];
        }

        $this->secondaryOptionId = null;
        foreach ($ids as $id) {
            if ($id !== $this->primaryOptionId) {
                $this->secondaryOptionId = $id;
                break;
            }
        }
    }

    private function sanitizeRowsForCurrentMode(): void
    {
        if (empty($this->rows)) {
            return;
        }

        if ($this->mode === 'single') {
            $validValueIds = array_flip($this->valueIdsForOption($this->singleOptionId));
            $this->rows = array_values(array_filter(array_map(function (array $row) use ($validValueIds): ?array {
                $valueId = (int) ($row['option_value_id'] ?? 0);
                if ($valueId <= 0 || ! isset($validValueIds[$valueId])) {
                    return null;
                }

                return $row;
            }, $this->rows)));

            return;
        }

        $validParentIds = array_flip($this->valueIdsForOption($this->primaryOptionId));
        $validValueIds = array_flip($this->valueIdsForOption($this->secondaryOptionId));

        $this->rows = array_values(array_filter(array_map(function (array $row) use ($validParentIds, $validValueIds): ?array {
            $parentId = (int) ($row['parent_option_value_id'] ?? 0);
            $valueId = (int) ($row['option_value_id'] ?? 0);

            if ($parentId <= 0 || ! isset($validParentIds[$parentId])) {
                return null;
            }

            if ($valueId <= 0 || ! isset($validValueIds[$valueId])) {
                return null;
            }

            return $row;
        }, $this->rows)));
    }

    private function rowTemplate(?int $valueId, ?int $parentValueId): array
    {
        return [
            'id' => null,
            'option_value_id' => $valueId,
            'parent_option_value_id' => $parentValueId,
            'sku' => $this->buildSku($parentValueId, $valueId),
            'stock_qty' => 0,
            'price_override' => '',
            'is_active' => true,
        ];
    }

    private function buildSku(?int $parentValueId, ?int $valueId): string
    {
        $baseSku = trim($this->productSku);
        if ($baseSku === '') {
            return '';
        }

        $parts = [$baseSku];
        $parentCode = $parentValueId ? $this->valueCode($parentValueId) : null;
        $valueCode = $valueId ? $this->valueCode($valueId) : null;

        if ($parentCode) {
            $parts[] = strtoupper($parentCode);
        }
        if ($valueCode) {
            $parts[] = strtoupper($valueCode);
        }

        return implode('-', $parts);
    }

    private function normalizePrice(mixed $value, string $field): float|null|false
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $raw);
        if (! is_numeric($normalized)) {
            $this->addError($field, __('Price must be numeric.'));

            return false;
        }

        $price = (float) $normalized;
        if ($price < 0) {
            $this->addError($field, __('Price cannot be negative.'));

            return false;
        }

        return round($price, 2);
    }

    /**
     * @return array<int, int>
     */
    private function optionIds(): array
    {
        return array_values(array_map(fn (array $option): int => (int) $option['id'], $this->assignedOptions));
    }

    /**
     * @return array<int, array{id:int,label:string,code:string,is_active:bool}>
     */
    private function valuesForOption(?int $optionId): array
    {
        if (! $optionId) {
            return [];
        }

        foreach ($this->assignedOptions as $option) {
            if ((int) $option['id'] === (int) $optionId) {
                return $option['values'];
            }
        }

        return [];
    }

    /**
     * @return array<int, int>
     */
    private function valueIdsForOption(?int $optionId): array
    {
        return array_values(array_map(
            fn (array $value): int => (int) $value['id'],
            $this->valuesForOption($optionId)
        ));
    }

    private function firstValueIdForOption(?int $optionId): ?int
    {
        $values = $this->valuesForOption($optionId);

        return isset($values[0]['id']) ? (int) $values[0]['id'] : null;
    }

    private function optionIdForValue(int $valueId): ?int
    {
        foreach ($this->assignedOptions as $option) {
            foreach ($option['values'] as $value) {
                if ((int) $value['id'] === $valueId) {
                    return (int) $option['id'];
                }
            }
        }

        return null;
    }

    private function valueCode(int $valueId): ?string
    {
        foreach ($this->assignedOptions as $option) {
            foreach ($option['values'] as $value) {
                if ((int) $value['id'] === $valueId) {
                    return (string) $value['code'];
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    private function filterOnlyOptionIds(): array
    {
        return array_values(array_map(
            fn (array $option): int => (int) $option['id'],
            $this->filterOnlyOptions
        ));
    }

    /**
     * @param  array<int, int>  $filterOnlyOptionIds
     */
    private function deletePurchasableRows(array $filterOnlyOptionIds): void
    {
        $query = ProductOptionValue::query()
            ->where('product_id', $this->productId);

        if ($filterOnlyOptionIds !== []) {
            $query->where(function ($query) use ($filterOnlyOptionIds): void {
                $query
                    ->whereDoesntHave('optionValue', fn ($optionValueQuery) => $optionValueQuery->whereIn('option_id', $filterOnlyOptionIds))
                    ->orWhereNotNull('parent_option_value_id');
            });
        }

        $query->delete();
    }

    /**
     * @param  array<int, int>  $filterOnlyOptionIds
     */
    private function deleteFilterOnlyRows(array $filterOnlyOptionIds): void
    {
        if ($filterOnlyOptionIds === []) {
            return;
        }

        ProductOptionValue::query()
            ->where('product_id', $this->productId)
            ->whereNull('parent_option_value_id')
            ->whereHas('optionValue', fn ($query) => $query->whereIn('option_id', $filterOnlyOptionIds))
            ->delete();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function normalizeSelectedOptionIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $intId = (int) $id;
            if ($intId > 0 && ! in_array($intId, $normalized, true)) {
                $normalized[] = $intId;
            }
        }

        return $normalized;
    }
}
