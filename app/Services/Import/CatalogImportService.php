<?php

namespace App\Services\Import;

use App\Data\Import\CatalogAttributeData;
use App\Data\Import\CatalogCategoryData;
use App\Data\Import\CatalogImportAction;
use App\Data\Import\CatalogImportBatch;
use App\Data\Import\CatalogImportOperation;
use App\Data\Import\CatalogImportPlan;
use App\Data\Import\CatalogLifecycleStatus;
use App\Data\Import\CatalogProductData;
use App\Exceptions\Import\CatalogImportConflictException;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeTranslation;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Category\CategoryTranslation;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductTranslation;
use App\Models\Import\CatalogImportRun;
use App\Models\Import\CatalogSourceMapping;
use App\Support\ImportedDescriptionHtmlCleaner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Throwable;

class CatalogImportService
{
    /** @var array<string, bool> */
    private array $productColumnCache = [];

    public function __construct(
        private readonly ImportedDescriptionHtmlCleaner $descriptionCleaner,
    ) {}

    /**
     * Build a read-only plan. No import run, mapping, or catalog row is written.
     *
     * @throws JsonException
     */
    public function plan(CatalogImportBatch $batch): CatalogImportPlan
    {
        $operations = [];

        foreach ($batch->orderedCategories() as $category) {
            $operations[] = $this->planCategory($batch, $category);
        }

        foreach ($batch->attributes as $attribute) {
            $operations[] = $this->planAttribute($batch, $attribute);
        }

        foreach ($batch->products as $product) {
            $operations[] = $this->planProduct($batch, $product);
        }

        return new CatalogImportPlan(
            source: $batch->source,
            batchChecksum: $batch->checksum(),
            operations: $operations,
        );
    }

    /**
     * Apply a normalized batch atomically. Conflicted plans are rejected before
     * any import audit row is created.
     *
     * @throws JsonException
     * @throws CatalogImportConflictException
     */
    public function apply(CatalogImportBatch $batch): CatalogImportRun
    {
        $plan = $this->plan($batch);
        if ($plan->hasConflicts()) {
            throw new CatalogImportConflictException($plan);
        }

        $run = CatalogImportRun::query()->create([
            'source' => $batch->source,
            'status' => CatalogImportRun::STATUS_RUNNING,
            'batch_checksum' => $plan->batchChecksum,
            'started_at' => now(),
            'summary' => $plan->summary(),
        ]);

        try {
            DB::transaction(function () use ($batch, $run): void {
                // Re-plan inside the transaction so a collision introduced after
                // the public dry-run cannot silently claim an unmanaged record.
                $freshPlan = $this->plan($batch);
                if ($freshPlan->hasConflicts()) {
                    throw new CatalogImportConflictException($freshPlan);
                }

                $seenAt = now();

                foreach ($batch->orderedCategories() as $category) {
                    $this->applyCategory($batch, $category, $run, $seenAt);
                }

                foreach ($batch->attributes as $attribute) {
                    $this->applyAttribute($batch, $attribute, $run, $seenAt);
                }

                foreach ($batch->products as $product) {
                    $this->applyProduct($batch, $product, $run, $seenAt);
                }
            });

            $run->forceFill([
                'status' => CatalogImportRun::STATUS_COMPLETED,
                'completed_at' => now(),
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => CatalogImportRun::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        return $run->refresh();
    }

    private function planCategory(CatalogImportBatch $batch, CatalogCategoryData $record): CatalogImportOperation
    {
        $mapping = $this->mapping($batch->source, CatalogSourceMapping::ENTITY_CATEGORY, $record->sourceId);
        $category = $mapping?->local_id ? Category::query()->find($mapping->local_id) : null;

        if ($record->status->isTombstone()) {
            return $this->planTombstone(
                CatalogSourceMapping::ENTITY_CATEGORY,
                $record->sourceId,
                $mapping,
                $category,
            );
        }

        $messages = [];
        $currentId = $category?->getKey();

        $collision = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('code', $record->code)
            ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
            ->first();
        if ($collision) {
            $messages[] = $this->collisionMessage('category code', (string) $record->code, CatalogSourceMapping::ENTITY_CATEGORY, (int) $collision->getKey());
        }

        foreach ($record->translations as $translation) {
            $translationCollision = CategoryTranslation::query()
                ->where('scope', Category::SCOPE_CATALOG)
                ->where('locale', $translation->locale)
                ->where('slug', $translation->slug)
                ->when($currentId, fn ($query) => $query->where('category_id', '!=', $currentId))
                ->first();
            if ($translationCollision) {
                $messages[] = $this->collisionMessage('category slug', $translation->locale.':'.$translation->slug, CatalogSourceMapping::ENTITY_CATEGORY, (int) $translationCollision->category_id);
            }
        }

        if ($record->parentSourceId !== null && ! $this->categoryDependencyExists($batch, $record->parentSourceId)) {
            $messages[] = "Parent category [{$record->parentSourceId}] is not managed by source [{$batch->source}].";
        }

        if ($messages !== []) {
            return new CatalogImportOperation(
                entityType: CatalogSourceMapping::ENTITY_CATEGORY,
                sourceId: $record->sourceId,
                action: CatalogImportAction::Conflict,
                localId: $currentId ? (int) $currentId : null,
                messages: array_values(array_unique($messages)),
            );
        }

        if (! $category) {
            return new CatalogImportOperation(
                entityType: CatalogSourceMapping::ENTITY_CATEGORY,
                sourceId: $record->sourceId,
                action: CatalogImportAction::Create,
                changes: ['record' => ['from' => null, 'to' => $record->toArray()]],
            );
        }

        $changes = [];
        $this->change($changes, 'code', $category->code, $record->code);
        $this->change($changes, 'is_active', (bool) $category->is_active, $record->status->isActive());
        $this->change($changes, 'show_in_menu', (bool) $category->show_in_menu, $record->status->isActive() && $record->showInMenu);
        $this->change($changes, 'sort_order', (int) $category->sort_order, $record->sortOrder);
        $this->change(
            $changes,
            'parent_source_id',
            $this->sourceIdForLocal($batch->source, CatalogSourceMapping::ENTITY_CATEGORY, $category->parent_id),
            $record->parentSourceId,
        );
        $this->change($changes, 'payload', $category->payload ?? [], $this->categoryPayload($category, $batch->source, $record));
        $this->translationChanges($changes, $category->translations()->get()->keyBy('locale'), $record->translations, false, $batch->source);
        $this->change($changes, 'lifecycle_status', $mapping?->lifecycle_status, $record->status->value);

        return new CatalogImportOperation(
            entityType: CatalogSourceMapping::ENTITY_CATEGORY,
            sourceId: $record->sourceId,
            action: $this->actionForExisting($record->status, (bool) $category->is_active, $changes),
            localId: (int) $category->getKey(),
            changes: $changes,
        );
    }

    private function planAttribute(CatalogImportBatch $batch, CatalogAttributeData $record): CatalogImportOperation
    {
        $mapping = $this->mapping($batch->source, CatalogSourceMapping::ENTITY_ATTRIBUTE, $record->sourceId);
        $attribute = $mapping?->local_id ? Attribute::query()->find($mapping->local_id) : null;

        if ($record->status->isTombstone()) {
            return $this->planTombstone(
                CatalogSourceMapping::ENTITY_ATTRIBUTE,
                $record->sourceId,
                $mapping,
                $attribute,
            );
        }

        $messages = [];
        $currentId = $attribute?->getKey();

        $collision = Attribute::query()
            ->where('code', $record->code)
            ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
            ->first();
        if ($collision) {
            $messages[] = $this->collisionMessage('attribute code', (string) $record->code, CatalogSourceMapping::ENTITY_ATTRIBUTE, (int) $collision->getKey());
        }

        foreach ($record->translations as $translation) {
            $translationCollision = AttributeTranslation::query()
                ->where('locale', $translation->locale)
                ->where('slug', $translation->slug)
                ->when($currentId, fn ($query) => $query->where('attribute_id', '!=', $currentId))
                ->first();
            if ($translationCollision) {
                $messages[] = $this->collisionMessage('attribute slug', $translation->locale.':'.$translation->slug, CatalogSourceMapping::ENTITY_ATTRIBUTE, (int) $translationCollision->attribute_id);
            }
        }

        if ($messages !== []) {
            return new CatalogImportOperation(
                entityType: CatalogSourceMapping::ENTITY_ATTRIBUTE,
                sourceId: $record->sourceId,
                action: CatalogImportAction::Conflict,
                localId: $currentId ? (int) $currentId : null,
                messages: array_values(array_unique($messages)),
            );
        }

        if (! $attribute) {
            return new CatalogImportOperation(
                entityType: CatalogSourceMapping::ENTITY_ATTRIBUTE,
                sourceId: $record->sourceId,
                action: CatalogImportAction::Create,
                changes: ['record' => ['from' => null, 'to' => $record->toArray()]],
            );
        }

        $changes = [];
        $this->change($changes, 'code', $attribute->code, $record->code);
        $this->change($changes, 'group_code', $attribute->group_code, $record->groupCode);
        $this->change($changes, 'type', $attribute->type, $record->type);
        $this->change($changes, 'is_active', (bool) $attribute->is_active, $record->status->isActive());
        $this->change($changes, 'sort_order', (int) $attribute->sort_order, $record->sortOrder);
        $this->change($changes, 'payload', $attribute->payload ?? [], $this->mergedPayload($attribute->payload, $batch->source, $record->payload));
        $this->attributeTranslationChanges($changes, $attribute->translations()->get()->keyBy('locale'), $record->translations, $batch->source);
        $this->change($changes, 'lifecycle_status', $mapping?->lifecycle_status, $record->status->value);

        return new CatalogImportOperation(
            entityType: CatalogSourceMapping::ENTITY_ATTRIBUTE,
            sourceId: $record->sourceId,
            action: $this->actionForExisting($record->status, (bool) $attribute->is_active, $changes),
            localId: (int) $attribute->getKey(),
            changes: $changes,
        );
    }

    private function planProduct(CatalogImportBatch $batch, CatalogProductData $record): CatalogImportOperation
    {
        $mapping = $this->mapping($batch->source, CatalogSourceMapping::ENTITY_PRODUCT, $record->sourceId);
        $product = $mapping?->local_id ? Product::query()->find($mapping->local_id) : null;

        if ($record->status->isTombstone()) {
            return $this->planTombstone(
                CatalogSourceMapping::ENTITY_PRODUCT,
                $record->sourceId,
                $mapping,
                $product,
            );
        }

        $messages = [];
        $currentId = $product?->getKey();

        foreach (['code' => $record->code, 'sku' => $record->sku, 'barcode' => $record->barcode] as $column => $value) {
            if ($value === null) {
                continue;
            }

            $collision = Product::query()
                ->where($column, $value)
                ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
                ->first();
            if ($collision) {
                $messages[] = $this->collisionMessage("product {$column}", $value, CatalogSourceMapping::ENTITY_PRODUCT, (int) $collision->getKey());
            }
        }

        foreach ($record->translations as $translation) {
            $translationCollision = ProductTranslation::query()
                ->where('locale', $translation->locale)
                ->where('slug', $translation->slug)
                ->when($currentId, fn ($query) => $query->where('product_id', '!=', $currentId))
                ->first();
            if ($translationCollision) {
                $messages[] = $this->collisionMessage('product slug', $translation->locale.':'.$translation->slug, CatalogSourceMapping::ENTITY_PRODUCT, (int) $translationCollision->product_id);
            }
        }

        foreach ($record->categorySourceIds as $categorySourceId) {
            if (! $this->categoryDependencyExists($batch, $categorySourceId)) {
                $messages[] = "Product category [{$categorySourceId}] is not managed by source [{$batch->source}].";
            }
        }

        foreach ($record->attributeSourceIds as $attributeSourceId) {
            if (! $this->attributeDependencyExists($batch, $attributeSourceId)) {
                $messages[] = "Product attribute [{$attributeSourceId}] is not managed by source [{$batch->source}].";
            }
        }

        foreach ($this->selectAttributeGroupConflicts($batch, $record) as $message) {
            $messages[] = $message;
        }

        if ($messages !== []) {
            return new CatalogImportOperation(
                entityType: CatalogSourceMapping::ENTITY_PRODUCT,
                sourceId: $record->sourceId,
                action: CatalogImportAction::Conflict,
                localId: $currentId ? (int) $currentId : null,
                messages: array_values(array_unique($messages)),
            );
        }

        if (! $product) {
            return new CatalogImportOperation(
                entityType: CatalogSourceMapping::ENTITY_PRODUCT,
                sourceId: $record->sourceId,
                action: CatalogImportAction::Create,
                changes: ['record' => ['from' => null, 'to' => $record->toArray()]],
            );
        }

        $changes = [];
        foreach ($this->productAttributes($record) as $column => $value) {
            $current = $product->getAttribute($column);
            if (in_array($column, ['base_price', 'erp_gross_list_price', 'erp_cash_discount_percent', 'erp_cash_selling_price'], true)) {
                $scale = $column === 'base_price' ? 2 : 4;
                $current = $current !== null ? number_format((float) $current, $scale, '.', '') : null;
            }
            $this->change($changes, $column, $current, $value);
        }
        $this->change($changes, 'payload', $product->payload ?? [], $this->mergedPayload($product->payload, $batch->source, $record->payload));
        $this->translationChanges($changes, $product->translations()->get()->keyBy('locale'), $record->translations, true, $batch->source);
        $this->change(
            $changes,
            'category_source_ids',
            $this->mappedSourceIds(
                $batch->source,
                CatalogSourceMapping::ENTITY_CATEGORY,
                $product->categories()->orderBy('category_product.sort_order')->pluck('categories.id')->all(),
            ),
            $record->categorySourceIds,
        );
        $this->change(
            $changes,
            'attribute_source_ids',
            $this->mappedSourceIds(
                $batch->source,
                CatalogSourceMapping::ENTITY_ATTRIBUTE,
                $product->attributes()->orderBy('catalog_attribute_product.sort_order')->pluck('catalog_attributes.id')->all(),
            ),
            $record->attributeSourceIds,
        );
        $this->change($changes, 'lifecycle_status', $mapping?->lifecycle_status, $record->status->value);

        return new CatalogImportOperation(
            entityType: CatalogSourceMapping::ENTITY_PRODUCT,
            sourceId: $record->sourceId,
            action: $this->actionForExisting($record->status, (bool) $product->is_active, $changes),
            localId: (int) $product->getKey(),
            changes: $changes,
        );
    }

    private function planTombstone(
        string $entityType,
        string $sourceId,
        ?CatalogSourceMapping $mapping,
        ?Model $model,
    ): CatalogImportOperation {
        $isAlreadyTombstoned = $mapping?->lifecycle_status === CatalogLifecycleStatus::Deleted->value
            && $mapping->tombstoned_at !== null
            && (! $model || ! (bool) $model->getAttribute('is_active'));

        return new CatalogImportOperation(
            entityType: $entityType,
            sourceId: $sourceId,
            action: $isAlreadyTombstoned ? CatalogImportAction::Noop : CatalogImportAction::Tombstone,
            localId: $model ? (int) $model->getKey() : null,
            changes: $isAlreadyTombstoned ? [] : [
                'lifecycle_status' => [
                    'from' => $mapping?->lifecycle_status,
                    'to' => CatalogLifecycleStatus::Deleted->value,
                ],
                'is_active' => [
                    'from' => $model ? (bool) $model->getAttribute('is_active') : null,
                    'to' => false,
                ],
            ],
        );
    }

    private function applyCategory(
        CatalogImportBatch $batch,
        CatalogCategoryData $record,
        CatalogImportRun $run,
        mixed $seenAt,
    ): void {
        $mapping = $this->lockedMapping($batch->source, CatalogSourceMapping::ENTITY_CATEGORY, $record->sourceId);
        $category = $mapping?->local_id ? Category::query()->find($mapping->local_id) : null;

        if ($record->status->isTombstone()) {
            if ($category) {
                $category->forceFill(['is_active' => false, 'show_in_menu' => false])->save();
            }
            $this->writeMapping($mapping, $batch->source, CatalogSourceMapping::ENTITY_CATEGORY, $record->sourceId, $category?->getKey(), $record->status, $record->toArray(), $record->payload, $run, $seenAt);

            return;
        }

        $parent = null;
        if ($record->parentSourceId !== null) {
            $parentMapping = $this->mapping($batch->source, CatalogSourceMapping::ENTITY_CATEGORY, $record->parentSourceId);
            $parent = $parentMapping?->local_id ? Category::query()->findOrFail($parentMapping->local_id) : null;
        }

        $category ??= new Category;
        $isNew = ! $category->exists;
        $desiredParentId = $parent?->getKey();

        $category->forceFill([
            'scope' => Category::SCOPE_CATALOG,
            'code' => $record->code,
            'is_active' => $record->status->isActive(),
            'show_in_menu' => $record->status->isActive() && $record->showInMenu,
            'sort_order' => $record->sortOrder,
            'payload' => $this->categoryPayload($category, $batch->source, $record),
        ]);

        if ($isNew) {
            $parent ? $category->appendToNode($parent)->save() : $category->saveAsRoot();
        } else {
            if ((int) ($category->parent_id ?? 0) !== (int) ($desiredParentId ?? 0)) {
                $parent ? $category->appendToNode($parent) : $category->makeRoot();
            }
            $category->save();
        }

        foreach ($record->translations as $translation) {
            $existing = $category->translations()->where('locale', $translation->locale)->first();
            $category->translations()->updateOrCreate(
                ['locale' => $translation->locale],
                [
                    'scope' => Category::SCOPE_CATALOG,
                    'name' => $translation->name,
                    'slug' => $translation->slug,
                    'description' => $this->cleanDescription($translation->description),
                    'meta_title' => $translation->metaTitle,
                    'meta_description' => $translation->metaDescription,
                    'payload' => $this->mergedPayload($existing?->payload, $batch->source, $translation->payload),
                ],
            );
        }

        $this->writeMapping($mapping, $batch->source, CatalogSourceMapping::ENTITY_CATEGORY, $record->sourceId, $category->getKey(), $record->status, $record->toArray(), $record->payload, $run, $seenAt);
    }

    private function applyAttribute(
        CatalogImportBatch $batch,
        CatalogAttributeData $record,
        CatalogImportRun $run,
        mixed $seenAt,
    ): void {
        $mapping = $this->lockedMapping($batch->source, CatalogSourceMapping::ENTITY_ATTRIBUTE, $record->sourceId);
        $attribute = $mapping?->local_id ? Attribute::query()->find($mapping->local_id) : null;

        if ($record->status->isTombstone()) {
            if ($attribute) {
                $attribute->forceFill(['is_active' => false])->save();
            }
            $this->writeMapping($mapping, $batch->source, CatalogSourceMapping::ENTITY_ATTRIBUTE, $record->sourceId, $attribute?->getKey(), $record->status, $record->toArray(), $record->payload, $run, $seenAt);

            return;
        }

        $attribute ??= new Attribute;
        $attribute->forceFill([
            'code' => $record->code,
            'group_code' => $record->groupCode,
            'type' => $record->type,
            'is_active' => $record->status->isActive(),
            'sort_order' => $record->sortOrder,
            'payload' => $this->mergedPayload($attribute->payload, $batch->source, $record->payload),
        ])->save();

        foreach ($record->translations as $translation) {
            $existing = $attribute->translations()->where('locale', $translation->locale)->first();
            $attribute->translations()->updateOrCreate(
                ['locale' => $translation->locale],
                [
                    'group_name' => $translation->groupName,
                    'name' => $translation->name,
                    'slug' => $translation->slug,
                    'description' => $this->cleanDescription($translation->description),
                    'payload' => $this->mergedPayload($existing?->payload, $batch->source, $translation->payload),
                ],
            );
        }

        $this->writeMapping($mapping, $batch->source, CatalogSourceMapping::ENTITY_ATTRIBUTE, $record->sourceId, $attribute->getKey(), $record->status, $record->toArray(), $record->payload, $run, $seenAt);
    }

    private function applyProduct(
        CatalogImportBatch $batch,
        CatalogProductData $record,
        CatalogImportRun $run,
        mixed $seenAt,
    ): void {
        $mapping = $this->lockedMapping($batch->source, CatalogSourceMapping::ENTITY_PRODUCT, $record->sourceId);
        $product = $mapping?->local_id ? Product::query()->find($mapping->local_id) : null;

        if ($record->status->isTombstone()) {
            if ($product) {
                // Eloquent is intentional: price observers/history remain intact,
                // and the product is never hard-deleted.
                $product->forceFill(['is_active' => false])->save();
            }
            $this->writeMapping($mapping, $batch->source, CatalogSourceMapping::ENTITY_PRODUCT, $record->sourceId, $product?->getKey(), $record->status, $record->toArray(), $record->payload, $run, $seenAt);

            return;
        }

        $product ??= new Product;
        $product->forceFill($this->productAttributes($record) + [
            'payload' => $this->mergedPayload($product->payload, $batch->source, $record->payload),
        ])->save();

        foreach ($record->translations as $translation) {
            $existing = $product->translations()->where('locale', $translation->locale)->first();
            $product->translations()->updateOrCreate(
                ['locale' => $translation->locale],
                [
                    'name' => $translation->name,
                    'slug' => $translation->slug,
                    'excerpt' => $translation->excerpt,
                    'description' => $this->cleanDescription($translation->description),
                    'meta_title' => $translation->metaTitle,
                    'meta_description' => $translation->metaDescription,
                    'payload' => $this->mergedPayload($existing?->payload, $batch->source, $translation->payload),
                ],
            );
        }

        $this->writeMapping($mapping, $batch->source, CatalogSourceMapping::ENTITY_PRODUCT, $record->sourceId, $product->getKey(), $record->status, $record->toArray(), $record->payload, $run, $seenAt);
        $this->syncProductCategories($batch, $record, $product);
        $this->syncProductAttributes($batch, $record, $product);
    }

    /** @return array<string, mixed> */
    private function productAttributes(CatalogProductData $record): array
    {
        $attributes = [
            'code' => $record->code,
            'sku' => $record->sku,
            'barcode' => $record->barcode,
            'unit_of_measure' => $record->unitOfMeasure,
            'minimum_order_quantity' => $record->minimumOrderQuantity,
            'order_quantity_step' => $record->orderQuantityStep,
            'is_active' => $record->status->isActive(),
            'base_price' => $record->basePrice,
            'stock_qty' => $record->stockQty,
            'weight_kg' => $record->weightKg,
            'length_cm' => $record->lengthCm,
            'width_cm' => $record->widthCm,
            'height_cm' => $record->heightCm,
            'shipping_labels' => $record->shippingLabels,
        ];

        foreach ([
            'erp_gross_list_price' => $record->erpGrossListPrice,
            'erp_cash_discount_percent' => $record->erpCashDiscountPercent,
            'erp_cash_selling_price' => $record->erpCashSellingPrice,
        ] as $column => $value) {
            if ($this->productHasColumn($column)) {
                $attributes[$column] = $value;
            }
        }

        return $attributes;
    }

    private function syncProductCategories(CatalogImportBatch $batch, CatalogProductData $record, Product $product): void
    {
        $managedIds = CatalogSourceMapping::query()
            ->where('source', $batch->source)
            ->where('entity_type', CatalogSourceMapping::ENTITY_CATEGORY)
            ->whereNotNull('local_id')
            ->pluck('local_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $desired = [];
        foreach ($record->categorySourceIds as $index => $sourceId) {
            $mapping = $this->mapping($batch->source, CatalogSourceMapping::ENTITY_CATEGORY, $sourceId);
            if ($mapping?->local_id) {
                $desired[(int) $mapping->local_id] = [
                    'sort_order' => ($index + 1) * 10,
                    'is_primary' => $index === 0,
                ];
            }
        }

        $detach = array_values(array_diff($managedIds, array_keys($desired)));
        if ($detach !== []) {
            $product->categories()->detach($detach);
        }
        if ($desired !== []) {
            $product->categories()->syncWithoutDetaching($desired);
        }
    }

    private function syncProductAttributes(CatalogImportBatch $batch, CatalogProductData $record, Product $product): void
    {
        $managedIds = CatalogSourceMapping::query()
            ->where('source', $batch->source)
            ->where('entity_type', CatalogSourceMapping::ENTITY_ATTRIBUTE)
            ->whereNotNull('local_id')
            ->pluck('local_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $desired = [];
        foreach ($record->attributeSourceIds as $index => $sourceId) {
            $mapping = $this->mapping($batch->source, CatalogSourceMapping::ENTITY_ATTRIBUTE, $sourceId);
            if ($mapping?->local_id) {
                $desired[(int) $mapping->local_id] = ['sort_order' => ($index + 1) * 10];
            }
        }

        $detach = array_values(array_diff($managedIds, array_keys($desired)));
        if ($detach !== []) {
            $product->attributes()->detach($detach);
        }
        if ($desired !== []) {
            $product->attributes()->syncWithoutDetaching($desired);
        }
    }

    private function writeMapping(
        ?CatalogSourceMapping $mapping,
        string $source,
        string $entityType,
        string $sourceId,
        mixed $localId,
        CatalogLifecycleStatus $status,
        array $normalizedRecord,
        array $recordPayload,
        CatalogImportRun $run,
        mixed $seenAt,
    ): void {
        $mapping ??= new CatalogSourceMapping;
        $metadata = is_array($mapping->metadata) ? $mapping->metadata : [];
        if ($recordPayload !== []) {
            $metadata['source_payload'] = $recordPayload;
        }

        $mapping->forceFill([
            'source' => $source,
            'entity_type' => $entityType,
            'source_id' => $sourceId,
            'local_id' => $localId,
            'lifecycle_status' => $status->value,
            'source_checksum' => $this->recordChecksum($normalizedRecord),
            'last_seen_at' => $seenAt,
            'tombstoned_at' => $status->isTombstone() ? ($mapping->tombstoned_at ?? $seenAt) : null,
            'last_import_run_id' => $run->getKey(),
            'metadata' => $metadata,
        ])->save();
    }

    private function lockedMapping(string $source, string $entityType, string $sourceId): ?CatalogSourceMapping
    {
        return CatalogSourceMapping::query()
            ->where('source', $source)
            ->where('entity_type', $entityType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();
    }

    private function mapping(string $source, string $entityType, string $sourceId): ?CatalogSourceMapping
    {
        return CatalogSourceMapping::query()
            ->where('source', $source)
            ->where('entity_type', $entityType)
            ->where('source_id', $sourceId)
            ->first();
    }

    private function categoryDependencyExists(CatalogImportBatch $batch, string $sourceId): bool
    {
        foreach ($batch->categories as $record) {
            if ($record->sourceId === $sourceId) {
                return ! $record->status->isTombstone();
            }
        }

        $mapping = $this->mapping($batch->source, CatalogSourceMapping::ENTITY_CATEGORY, $sourceId);

        return $mapping?->local_id
            && $mapping->lifecycle_status !== CatalogLifecycleStatus::Deleted->value
            && Category::query()->whereKey($mapping->local_id)->exists();
    }

    private function attributeDependencyExists(CatalogImportBatch $batch, string $sourceId): bool
    {
        foreach ($batch->attributes as $record) {
            if ($record->sourceId === $sourceId) {
                return ! $record->status->isTombstone();
            }
        }

        $mapping = $this->mapping($batch->source, CatalogSourceMapping::ENTITY_ATTRIBUTE, $sourceId);

        return $mapping?->local_id
            && $mapping->lifecycle_status !== CatalogLifecycleStatus::Deleted->value
            && Attribute::query()->whereKey($mapping->local_id)->exists();
    }

    /** @return list<string> */
    private function selectAttributeGroupConflicts(CatalogImportBatch $batch, CatalogProductData $product): array
    {
        $groups = [];

        foreach ($product->attributeSourceIds as $sourceId) {
            $definition = null;
            foreach ($batch->attributes as $attribute) {
                if ($attribute->sourceId === $sourceId && ! $attribute->status->isTombstone()) {
                    $definition = ['group' => $attribute->groupCode, 'type' => $attribute->type];
                    break;
                }
            }

            if ($definition === null) {
                $mapping = $this->mapping($batch->source, CatalogSourceMapping::ENTITY_ATTRIBUTE, $sourceId);
                $attribute = $mapping?->local_id ? Attribute::query()->find($mapping->local_id) : null;
                if ($attribute) {
                    $definition = ['group' => $attribute->group_code, 'type' => $attribute->type];
                }
            }

            if ($definition && $definition['type'] === CatalogAttributeData::TYPE_SELECT) {
                $groups[(string) $definition['group']][] = $sourceId;
            }
        }

        $messages = [];
        foreach ($groups as $group => $sourceIds) {
            if (count($sourceIds) > 1) {
                $messages[] = "Product [{$product->sourceId}] selects multiple values for single-value attribute group [{$group}].";
            }
        }

        return $messages;
    }

    private function collisionMessage(string $field, string $value, string $entityType, int $localId): string
    {
        $owner = CatalogSourceMapping::query()
            ->where('entity_type', $entityType)
            ->where('local_id', $localId)
            ->first();

        if ($owner) {
            return ucfirst($field)." [{$value}] is owned by source [{$owner->source}] as [{$owner->source_id}].";
        }

        return ucfirst($field)." [{$value}] belongs to an unmanaged local record and cannot be claimed by an import.";
    }

    private function actionForExisting(CatalogLifecycleStatus $status, bool $currentlyActive, array $changes): CatalogImportAction
    {
        if ($status === CatalogLifecycleStatus::Web && ! $currentlyActive) {
            return CatalogImportAction::Activate;
        }

        if ($status === CatalogLifecycleStatus::Inactive && $currentlyActive) {
            return CatalogImportAction::Deactivate;
        }

        return $changes === [] ? CatalogImportAction::Noop : CatalogImportAction::Update;
    }

    /** @param array<string, array{from:mixed,to:mixed}> $changes */
    private function change(array &$changes, string $field, mixed $from, mixed $to): void
    {
        $from = $this->comparable($from);
        $to = $this->comparable($to);

        if ($from !== $to) {
            $changes[$field] = ['from' => $from, 'to' => $to];
        }
    }

    private function comparable(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->comparable($item), array_values($value));
            }

            ksort($value);
            foreach ($value as $key => $item) {
                $value[$key] = $this->comparable($item);
            }
        }

        return $value;
    }

    private function translationChanges(array &$changes, mixed $existing, array $translations, bool $product, string $source): void
    {
        foreach ($translations as $translation) {
            $current = $existing->get($translation->locale);
            $desired = $translation->toArray();
            $desired['description'] = $this->cleanDescription($translation->description);
            if (! $product) {
                // Category translations have no excerpt column.
                unset($desired['excerpt']);
            }
            $currentData = $current ? [
                'locale' => $current->locale,
                'name' => $current->name,
                'slug' => $current->slug,
                'description' => $current->description,
                'meta_title' => $current->meta_title,
                'meta_description' => $current->meta_description,
                'payload' => $current->payload ?? [],
            ] : null;

            if ($product && $currentData !== null) {
                $currentData['excerpt'] = $current->excerpt;
            }

            if ($currentData !== null) {
                $sources = is_array($currentData['payload']['import_sources'] ?? null)
                    ? $currentData['payload']['import_sources']
                    : [];
                $currentData['payload'] = is_array($sources[$source] ?? null) ? $sources[$source] : [];
            }

            $this->change($changes, "translation.{$translation->locale}", $currentData, $desired);
        }
    }

    private function attributeTranslationChanges(array &$changes, mixed $existing, array $translations, string $source): void
    {
        foreach ($translations as $translation) {
            $current = $existing->get($translation->locale);
            $currentData = $current ? [
                'locale' => $current->locale,
                'group_name' => $current->group_name,
                'name' => $current->name,
                'slug' => $current->slug,
                'description' => $current->description,
                'payload' => $current->payload ?? [],
            ] : null;
            $desired = $translation->toArray();
            $desired['description'] = $this->cleanDescription($translation->description);

            if ($currentData !== null) {
                $sources = is_array($currentData['payload']['import_sources'] ?? null)
                    ? $currentData['payload']['import_sources']
                    : [];
                $currentData['payload'] = is_array($sources[$source] ?? null) ? $sources[$source] : [];
            }

            $this->change($changes, "translation.{$translation->locale}", $currentData, $desired);
        }
    }

    /** @return array<string, mixed> */
    private function mergedPayload(mixed $existing, string $source, array $sourcePayload): array
    {
        $payload = is_array($existing) ? $existing : [];
        $payload['import_sources'] = is_array($payload['import_sources'] ?? null) ? $payload['import_sources'] : [];
        $payload['import_sources'][$source] = $sourcePayload;

        return $payload;
    }

    /** @return array<string, mixed> */
    private function categoryPayload(Category $category, string $source, CatalogCategoryData $record): array
    {
        $payload = $this->mergedPayload($category->payload, $source, $record->payload);
        $quoteCodes = config('termol_shipping.quote_shipping_category_codes', []);

        if (is_array($quoteCodes) && in_array((string) $record->code, array_map('strval', $quoteCodes), true)) {
            $labels = is_array($payload[Category::PAYLOAD_SHIPPING_LABELS] ?? null)
                ? $payload[Category::PAYLOAD_SHIPPING_LABELS]
                : [];
            $labels[] = 'quote_shipping';
            $payload[Category::PAYLOAD_SHIPPING_LABELS] = array_values(array_unique($labels));
        }

        return $payload;
    }

    private function sourceIdForLocal(string $source, string $entityType, mixed $localId): ?string
    {
        if (! $localId) {
            return null;
        }

        $sourceId = CatalogSourceMapping::query()
            ->where('source', $source)
            ->where('entity_type', $entityType)
            ->where('local_id', $localId)
            ->value('source_id');

        return $sourceId !== null ? (string) $sourceId : '@local:'.(int) $localId;
    }

    /** @return list<string> */
    private function mappedSourceIds(string $source, string $entityType, array $localIds): array
    {
        if ($localIds === []) {
            return [];
        }

        $mapping = CatalogSourceMapping::query()
            ->where('source', $source)
            ->where('entity_type', $entityType)
            ->whereIn('local_id', $localIds)
            ->pluck('source_id', 'local_id');

        $sourceIds = [];
        foreach ($localIds as $localId) {
            if (isset($mapping[$localId])) {
                $sourceIds[] = (string) $mapping[$localId];
            }
        }

        return $sourceIds;
    }

    private function productHasColumn(string $column): bool
    {
        return $this->productColumnCache[$column] ??= Schema::hasColumn('products', $column);
    }

    private function cleanDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $cleaned = $this->descriptionCleaner->clean($description);

        return $cleaned !== '' ? $cleaned : null;
    }

    private function recordChecksum(array $record): string
    {
        return hash('sha256', json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
