<?php

namespace App\Data\Import;

final readonly class CatalogAdoptionPlan
{
    /** @param list<CatalogAdoptionOperation> $operations */
    public function __construct(
        public string $source,
        public string $batchChecksum,
        public array $operations,
    ) {}

    public function hasConflicts(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->action === CatalogAdoptionAction::Conflict) {
                return true;
            }
        }

        return false;
    }

    /** @return list<CatalogAdoptionOperation> */
    public function conflicts(): array
    {
        return array_values(array_filter(
            $this->operations,
            static fn (CatalogAdoptionOperation $operation): bool => $operation->action === CatalogAdoptionAction::Conflict,
        ));
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $summary = [];
        foreach (CatalogAdoptionAction::cases() as $action) {
            $summary[$action->value] = 0;
        }

        foreach ($this->operations as $operation) {
            $summary[$operation->action->value]++;
        }

        foreach (['categories' => 'category', 'products' => 'product', 'attributes' => 'attribute'] as $key => $entityType) {
            $summary[$key] = count(array_filter(
                $this->operations,
                static fn (CatalogAdoptionOperation $operation): bool => $operation->entityType === $entityType,
            ));
        }

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
                static fn (CatalogAdoptionOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
        ];
    }
}
