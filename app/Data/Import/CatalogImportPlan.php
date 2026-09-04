<?php

namespace App\Data\Import;

final readonly class CatalogImportPlan
{
    /** @param list<CatalogImportOperation> $operations */
    public function __construct(
        public string $source,
        public string $batchChecksum,
        public array $operations,
    ) {}

    public function hasConflicts(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->action === CatalogImportAction::Conflict) {
                return true;
            }
        }

        return false;
    }

    /** @return list<CatalogImportOperation> */
    public function conflicts(): array
    {
        return array_values(array_filter(
            $this->operations,
            static fn (CatalogImportOperation $operation): bool => $operation->action === CatalogImportAction::Conflict,
        ));
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $summary = [];
        foreach (CatalogImportAction::cases() as $action) {
            $summary[$action->value] = 0;
        }

        foreach ($this->operations as $operation) {
            $summary[$operation->action->value]++;
        }

        $summary['categories'] = count(array_filter(
            $this->operations,
            static fn (CatalogImportOperation $operation): bool => $operation->entityType === 'category',
        ));
        $summary['products'] = count(array_filter(
            $this->operations,
            static fn (CatalogImportOperation $operation): bool => $operation->entityType === 'product',
        ));
        $summary['attributes'] = count(array_filter(
            $this->operations,
            static fn (CatalogImportOperation $operation): bool => $operation->entityType === 'attribute',
        ));

        return $summary;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'batch_checksum' => $this->batchChecksum,
            'summary' => $this->summary(),
            'operations' => array_map(
                static fn (CatalogImportOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
        ];
    }
}
