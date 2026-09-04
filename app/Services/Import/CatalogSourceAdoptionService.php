<?php

namespace App\Services\Import;

use App\Data\Import\CatalogAdoptionAction;
use App\Data\Import\CatalogAdoptionOperation;
use App\Data\Import\CatalogAdoptionPlan;
use App\Data\Import\CatalogAttributeData;
use App\Data\Import\CatalogCategoryData;
use App\Data\Import\CatalogImportBatch;
use App\Data\Import\CatalogProductData;
use App\Exceptions\Import\CatalogAdoptionConflictException;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Import\CatalogSourceMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

class CatalogSourceAdoptionService
{
    /**
     * Build a strictly read-only ownership plan. Names, translations and slugs
     * are deliberately excluded from identity matching.
     *
     * @throws JsonException
     */
    public function plan(CatalogImportBatch $batch): CatalogAdoptionPlan
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

        $operations = $this->duplicateTargetConflicts($operations);

        return new CatalogAdoptionPlan(
            source: $batch->source,
            batchChecksum: $batch->checksum(),
            operations: $operations,
        );
    }

    /**
     * Persist only unambiguous ownership mappings. Catalog rows and import runs
     * are never modified or created by adoption.
     *
     * @throws JsonException
     * @throws CatalogAdoptionConflictException
     */
    public function apply(CatalogImportBatch $batch): CatalogAdoptionPlan
    {
        $plan = $this->plan($batch);
        $this->rejectConflicts($plan);

        return DB::transaction(function () use ($batch): CatalogAdoptionPlan {
            // Re-read all identifiers and ownership inside the transaction so a
            // dry-run cannot be used to claim a record after its identity moves.
            $freshPlan = $this->plan($batch);
            $this->rejectConflicts($freshPlan);

            $adoptedAt = now();

            foreach ($freshPlan->operations as $operation) {
                if ($operation->action !== CatalogAdoptionAction::Adopt || $operation->localId === null) {
                    continue;
                }

                $record = $this->record($batch, $operation);
                $local = $this->lockLocal($operation->entityType, $operation->localId);

                if (! $local) {
                    throw $this->changedStateConflict(
                        $batch,
                        $operation,
                        "Local {$operation->entityType} [{$operation->localId}] disappeared before adoption.",
                    );
                }

                $revalidated = $this->planRecord($batch, $record, true);
                if ($revalidated->action === CatalogAdoptionAction::AlreadyMapped
                    && $revalidated->localId === $operation->localId) {
                    continue;
                }
                if ($revalidated->action !== CatalogAdoptionAction::Adopt
                    || $revalidated->localId !== $operation->localId) {
                    throw $this->changedStateConflict(
                        $batch,
                        $operation,
                        'Catalog identity or ownership changed while the adoption was being applied.',
                    );
                }
                $operation = $revalidated;

                $existing = CatalogSourceMapping::query()
                    ->where('source', $batch->source)
                    ->where('entity_type', $operation->entityType)
                    ->where('source_id', $operation->sourceId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ((int) $existing->local_id === $operation->localId) {
                        continue;
                    }

                    throw $this->changedStateConflict(
                        $batch,
                        $operation,
                        "Source record [{$operation->sourceId}] was mapped concurrently to another local record.",
                    );
                }

                $owner = CatalogSourceMapping::query()
                    ->where('entity_type', $operation->entityType)
                    ->where('local_id', $operation->localId)
                    ->lockForUpdate()
                    ->first();

                if ($owner) {
                    throw $this->changedStateConflict(
                        $batch,
                        $operation,
                        "Local {$operation->entityType} [{$operation->localId}] was claimed concurrently by source [{$owner->source}].",
                    );
                }

                try {
                    CatalogSourceMapping::query()->create([
                        'source' => $batch->source,
                        'entity_type' => $operation->entityType,
                        'source_id' => $operation->sourceId,
                        'local_id' => $operation->localId,
                        'lifecycle_status' => $record->status->value,
                        'source_checksum' => $this->recordChecksum($record->toArray()),
                        'last_seen_at' => $adoptedAt,
                        'tombstoned_at' => null,
                        'last_import_run_id' => null,
                        'metadata' => [
                            'adoption' => [
                                'rule_version' => 1,
                                'adopted_at' => $adoptedAt->toIso8601String(),
                                'batch_checksum' => $freshPlan->batchChecksum,
                                'match_basis' => array_values(array_intersect(
                                    ['code', 'sku', 'barcode'],
                                    array_keys($operation->identifiers),
                                )),
                                'matched_identifiers' => $operation->identifiers,
                                'local_identity_before' => $this->localIdentity($operation->entityType, $local),
                            ],
                        ],
                    ]);
                } catch (QueryException) {
                    throw $this->changedStateConflict(
                        $batch,
                        $operation,
                        'Catalog ownership changed before the adoption mapping could be stored.',
                    );
                }
            }

            return $freshPlan;
        });
    }

    private function planCategory(
        CatalogImportBatch $batch,
        CatalogCategoryData $record,
        bool $lock = false,
    ): CatalogAdoptionOperation {
        $mapped = $this->mappedOperation($batch, CatalogSourceMapping::ENTITY_CATEGORY, $record->sourceId, $lock);
        if ($mapped) {
            return $mapped;
        }

        if ($record->status->isTombstone()) {
            return $this->skippedTombstone(CatalogSourceMapping::ENTITY_CATEGORY, $record->sourceId);
        }

        $candidates = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('code', $record->code)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get();

        $exact = $candidates->filter(
            static fn (Category $category): bool => $category->code === $record->code,
        )->values();

        if ($candidates->count() !== $exact->count()) {
            return $this->identityConflict(
                CatalogSourceMapping::ENTITY_CATEGORY,
                $record->sourceId,
                null,
                ['code' => (string) $record->code],
                'Category code collides under the database collation but is not an exact match.',
            );
        }

        if ($exact->isEmpty()) {
            return $this->unmatched(CatalogSourceMapping::ENTITY_CATEGORY, $record->sourceId, ['code' => (string) $record->code]);
        }

        if ($exact->count() !== 1) {
            return $this->identityConflict(
                CatalogSourceMapping::ENTITY_CATEGORY,
                $record->sourceId,
                null,
                ['code' => (string) $record->code],
                'Category code resolves to more than one catalog category.',
            );
        }

        return $this->adoptOrOwned(
            CatalogSourceMapping::ENTITY_CATEGORY,
            $record->sourceId,
            (int) $exact->first()->getKey(),
            ['code' => (string) $record->code],
            lock: $lock,
        );
    }

    private function planAttribute(
        CatalogImportBatch $batch,
        CatalogAttributeData $record,
        bool $lock = false,
    ): CatalogAdoptionOperation {
        $mapped = $this->mappedOperation($batch, CatalogSourceMapping::ENTITY_ATTRIBUTE, $record->sourceId, $lock);
        if ($mapped) {
            return $mapped;
        }

        if ($record->status->isTombstone()) {
            return $this->skippedTombstone(CatalogSourceMapping::ENTITY_ATTRIBUTE, $record->sourceId);
        }

        $candidates = Attribute::query()
            ->where('code', $record->code)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get();
        $exact = $candidates->filter(
            static fn (Attribute $attribute): bool => $attribute->code === $record->code,
        )->values();

        if ($candidates->count() !== $exact->count()) {
            return $this->identityConflict(
                CatalogSourceMapping::ENTITY_ATTRIBUTE,
                $record->sourceId,
                null,
                ['code' => (string) $record->code],
                'Attribute code collides under the database collation but is not an exact match.',
            );
        }

        if ($exact->isEmpty()) {
            return $this->unmatched(CatalogSourceMapping::ENTITY_ATTRIBUTE, $record->sourceId, ['code' => (string) $record->code]);
        }

        if ($exact->count() !== 1) {
            return $this->identityConflict(
                CatalogSourceMapping::ENTITY_ATTRIBUTE,
                $record->sourceId,
                null,
                ['code' => (string) $record->code],
                'Attribute code resolves to more than one local attribute.',
            );
        }

        return $this->adoptOrOwned(
            CatalogSourceMapping::ENTITY_ATTRIBUTE,
            $record->sourceId,
            (int) $exact->first()->getKey(),
            ['code' => (string) $record->code],
            lock: $lock,
        );
    }

    private function planProduct(
        CatalogImportBatch $batch,
        CatalogProductData $record,
        bool $lock = false,
    ): CatalogAdoptionOperation {
        $mapped = $this->mappedOperation($batch, CatalogSourceMapping::ENTITY_PRODUCT, $record->sourceId, $lock);
        if ($mapped) {
            return $mapped;
        }

        if ($record->status->isTombstone()) {
            return $this->skippedTombstone(CatalogSourceMapping::ENTITY_PRODUCT, $record->sourceId);
        }

        /** @var array<int, Product> $matches */
        $matches = [];
        /** @var array<int, array<string, string>> $matchedBy */
        $matchedBy = [];
        $messages = [];

        foreach (['code' => $record->code, 'sku' => $record->sku, 'barcode' => $record->barcode] as $column => $value) {
            if ($value === null) {
                continue;
            }

            $candidates = Product::query()
                ->where($column, $value)
                ->when($lock, static fn ($query) => $query->lockForUpdate())
                ->get();
            foreach ($candidates as $candidate) {
                if ($candidate->getAttribute($column) !== $value) {
                    $messages[] = "Product {$column} [{$value}] collides under the database collation but is not an exact match.";

                    continue;
                }

                $localId = (int) $candidate->getKey();
                $matches[$localId] = $candidate;
                $matchedBy[$localId][$column] = $value;
            }
        }

        if ($messages !== []) {
            return new CatalogAdoptionOperation(
                entityType: CatalogSourceMapping::ENTITY_PRODUCT,
                sourceId: $record->sourceId,
                action: CatalogAdoptionAction::Conflict,
                localId: count($matches) === 1 ? (int) array_key_first($matches) : null,
                messages: array_values(array_unique($messages)),
            );
        }

        if ($matches === []) {
            return $this->unmatched(
                CatalogSourceMapping::ENTITY_PRODUCT,
                $record->sourceId,
                array_filter([
                    'code' => $record->code,
                    'sku' => $record->sku,
                    'barcode' => $record->barcode,
                ], static fn (?string $value): bool => $value !== null),
            );
        }

        if (count($matches) !== 1) {
            $resolved = [];
            foreach ($matchedBy as $localId => $identifiers) {
                $resolved[] = '#'.$localId.' via '.implode(', ', array_keys($identifiers));
            }

            return $this->identityConflict(
                CatalogSourceMapping::ENTITY_PRODUCT,
                $record->sourceId,
                null,
                [],
                'Product identifiers resolve to different local records: '.implode('; ', $resolved).'.',
            );
        }

        $localId = (int) array_key_first($matches);
        $product = $matches[$localId];
        $identifiers = $matchedBy[$localId];
        $legacySourceUrl = $this->legacyTermolSourceUrl($product);
        $legacySyntheticCode = $batch->source === 'konto'
            && isset($identifiers['sku'])
            && $product->sku === $record->sku
            && $product->code === 'termol-'.$product->sku
            && $product->code !== $record->code
            && $legacySourceUrl !== null;

        foreach (['code' => $record->code, 'sku' => $record->sku, 'barcode' => $record->barcode] as $column => $value) {
            $localValue = $product->getAttribute($column);
            if ($localValue === $value || $localValue === null) {
                continue;
            }

            if ($column === 'code' && $legacySyntheticCode) {
                continue;
            }

            if ($value === null) {
                $messages[] = "Product {$column} is null in the source snapshot and would clear local value [{$localValue}].";
            } else {
                $messages[] = "Product {$column} differs: source [{$value}], local [{$localValue}].";
            }
        }

        if ($messages !== []) {
            return new CatalogAdoptionOperation(
                entityType: CatalogSourceMapping::ENTITY_PRODUCT,
                sourceId: $record->sourceId,
                action: CatalogAdoptionAction::Conflict,
                localId: $localId,
                identifiers: $identifiers,
                messages: $messages,
            );
        }

        if ($legacySyntheticCode) {
            $identifiers['legacy_synthetic_code'] = (string) $product->code;
            $identifiers['legacy_source'] = 'termol.hr';
            $identifiers['legacy_source_url'] = $legacySourceUrl;
            $messages[] = "Exact SKU matched legacy synthetic code [{$product->code}]; the later catalog import may replace that code.";
        }

        return $this->adoptOrOwned(
            CatalogSourceMapping::ENTITY_PRODUCT,
            $record->sourceId,
            $localId,
            $identifiers,
            $messages,
            $lock,
        );
    }

    private function planRecord(
        CatalogImportBatch $batch,
        CatalogCategoryData|CatalogAttributeData|CatalogProductData $record,
        bool $lock = false,
    ): CatalogAdoptionOperation {
        return match (true) {
            $record instanceof CatalogCategoryData => $this->planCategory($batch, $record, $lock),
            $record instanceof CatalogAttributeData => $this->planAttribute($batch, $record, $lock),
            $record instanceof CatalogProductData => $this->planProduct($batch, $record, $lock),
        };
    }

    /**
     * A batch is not allowed to alias two source records to one local record,
     * even when only one of the individual identity checks was otherwise safe.
     *
     * @param  list<CatalogAdoptionOperation>  $operations
     * @return list<CatalogAdoptionOperation>
     */
    private function duplicateTargetConflicts(array $operations): array
    {
        $targets = [];
        foreach ($operations as $index => $operation) {
            if ($operation->localId !== null) {
                $targets[$operation->entityType.':'.$operation->localId][] = $index;
            }
        }

        foreach ($targets as $indices) {
            $sourceIds = array_values(array_unique(array_map(
                static fn (int $index): string => $operations[$index]->sourceId,
                $indices,
            )));
            if (count($sourceIds) < 2) {
                continue;
            }

            $message = 'Multiple source records target the same local record: '.implode(', ', $sourceIds).'.';
            foreach ($indices as $index) {
                $operation = $operations[$index];
                $operations[$index] = new CatalogAdoptionOperation(
                    entityType: $operation->entityType,
                    sourceId: $operation->sourceId,
                    action: CatalogAdoptionAction::Conflict,
                    localId: $operation->localId,
                    identifiers: $operation->identifiers,
                    messages: array_values(array_unique([...$operation->messages, $message])),
                );
            }
        }

        return array_values($operations);
    }

    private function legacyTermolSourceUrl(Product $product): ?string
    {
        $payload = is_array($product->payload) ? $product->payload : [];
        if (($payload['source'] ?? null) !== 'termol.hr') {
            return null;
        }

        $sourceUrl = $payload['source_url'] ?? null;
        if (! is_string($sourceUrl) || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
        if ($scheme !== 'https' || ($host !== 'termol.hr' && ! str_ends_with($host, '.termol.hr'))) {
            return null;
        }

        return $sourceUrl;
    }

    private function mappedOperation(
        CatalogImportBatch $batch,
        string $entityType,
        string $sourceId,
        bool $lock = false,
    ): ?CatalogAdoptionOperation {
        $mapping = CatalogSourceMapping::query()
            ->where('source', $batch->source)
            ->where('entity_type', $entityType)
            ->where('source_id', $sourceId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $mapping) {
            return null;
        }

        if ($mapping->local_id === null) {
            return $this->identityConflict(
                $entityType,
                $sourceId,
                null,
                [],
                'Existing source mapping has no local record ID.',
            );
        }

        $local = $this->findLocal($entityType, (int) $mapping->local_id);
        if (! $local || ($local instanceof Category && $local->scope !== Category::SCOPE_CATALOG)) {
            return $this->identityConflict(
                $entityType,
                $sourceId,
                (int) $mapping->local_id,
                [],
                'Existing source mapping points to a missing or incompatible local record.',
            );
        }

        return new CatalogAdoptionOperation(
            entityType: $entityType,
            sourceId: $sourceId,
            action: CatalogAdoptionAction::AlreadyMapped,
            localId: (int) $mapping->local_id,
        );
    }

    /** @param array<string, string> $identifiers */
    private function adoptOrOwned(
        string $entityType,
        string $sourceId,
        int $localId,
        array $identifiers,
        array $messages = [],
        bool $lock = false,
    ): CatalogAdoptionOperation {
        $owner = CatalogSourceMapping::query()
            ->where('entity_type', $entityType)
            ->where('local_id', $localId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->first();

        if ($owner) {
            return $this->identityConflict(
                $entityType,
                $sourceId,
                $localId,
                $identifiers,
                "Local record is already owned by source [{$owner->source}] as [{$owner->source_id}].",
            );
        }

        return new CatalogAdoptionOperation(
            entityType: $entityType,
            sourceId: $sourceId,
            action: CatalogAdoptionAction::Adopt,
            localId: $localId,
            identifiers: $identifiers,
            messages: $messages,
        );
    }

    /** @param array<string, string> $identifiers */
    private function unmatched(string $entityType, string $sourceId, array $identifiers): CatalogAdoptionOperation
    {
        return new CatalogAdoptionOperation(
            entityType: $entityType,
            sourceId: $sourceId,
            action: CatalogAdoptionAction::Unmatched,
            identifiers: $identifiers,
            messages: ['No exact unmanaged local record was found; a later catalog import may create it.'],
        );
    }

    private function skippedTombstone(string $entityType, string $sourceId): CatalogAdoptionOperation
    {
        return new CatalogAdoptionOperation(
            entityType: $entityType,
            sourceId: $sourceId,
            action: CatalogAdoptionAction::SkipTombstone,
            messages: ['Unmapped tombstones are not adopted.'],
        );
    }

    /** @param array<string, string> $identifiers */
    private function identityConflict(
        string $entityType,
        string $sourceId,
        ?int $localId,
        array $identifiers,
        string $message,
    ): CatalogAdoptionOperation {
        return new CatalogAdoptionOperation(
            entityType: $entityType,
            sourceId: $sourceId,
            action: CatalogAdoptionAction::Conflict,
            localId: $localId,
            identifiers: $identifiers,
            messages: [$message],
        );
    }

    private function rejectConflicts(CatalogAdoptionPlan $plan): void
    {
        if ($plan->hasConflicts()) {
            throw new CatalogAdoptionConflictException($plan);
        }
    }

    private function changedStateConflict(
        CatalogImportBatch $batch,
        CatalogAdoptionOperation $operation,
        string $message,
    ): CatalogAdoptionConflictException {
        return new CatalogAdoptionConflictException(new CatalogAdoptionPlan(
            source: $batch->source,
            batchChecksum: $batch->checksum(),
            operations: [new CatalogAdoptionOperation(
                entityType: $operation->entityType,
                sourceId: $operation->sourceId,
                action: CatalogAdoptionAction::Conflict,
                localId: $operation->localId,
                identifiers: $operation->identifiers,
                messages: [$message],
            )],
        ));
    }

    private function findLocal(string $entityType, int $localId): ?Model
    {
        return match ($entityType) {
            CatalogSourceMapping::ENTITY_CATEGORY => Category::query()->find($localId),
            CatalogSourceMapping::ENTITY_ATTRIBUTE => Attribute::query()->find($localId),
            CatalogSourceMapping::ENTITY_PRODUCT => Product::query()->find($localId),
            default => null,
        };
    }

    private function lockLocal(string $entityType, int $localId): ?Model
    {
        return match ($entityType) {
            CatalogSourceMapping::ENTITY_CATEGORY => Category::query()->lockForUpdate()->find($localId),
            CatalogSourceMapping::ENTITY_ATTRIBUTE => Attribute::query()->lockForUpdate()->find($localId),
            CatalogSourceMapping::ENTITY_PRODUCT => Product::query()->lockForUpdate()->find($localId),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function localIdentity(string $entityType, Model $model): array
    {
        return match ($entityType) {
            CatalogSourceMapping::ENTITY_CATEGORY => [
                'scope' => $model->getAttribute('scope'),
                'code' => $model->getAttribute('code'),
            ],
            CatalogSourceMapping::ENTITY_ATTRIBUTE => [
                'code' => $model->getAttribute('code'),
            ],
            CatalogSourceMapping::ENTITY_PRODUCT => [
                'code' => $model->getAttribute('code'),
                'sku' => $model->getAttribute('sku'),
                'barcode' => $model->getAttribute('barcode'),
            ],
            default => [],
        };
    }

    private function record(
        CatalogImportBatch $batch,
        CatalogAdoptionOperation $operation,
    ): CatalogCategoryData|CatalogAttributeData|CatalogProductData {
        $records = match ($operation->entityType) {
            CatalogSourceMapping::ENTITY_CATEGORY => $batch->categories,
            CatalogSourceMapping::ENTITY_ATTRIBUTE => $batch->attributes,
            CatalogSourceMapping::ENTITY_PRODUCT => $batch->products,
            default => [],
        };

        foreach ($records as $record) {
            if ($record->sourceId === $operation->sourceId) {
                return $record;
            }
        }

        throw new \LogicException("Missing normalized record [{$operation->entityType}:{$operation->sourceId}].");
    }

    /** @param array<string, mixed> $record */
    private function recordChecksum(array $record): string
    {
        return hash('sha256', json_encode(
            $record,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
