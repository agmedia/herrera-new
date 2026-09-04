<?php

namespace App\Data\Import;

final readonly class CatalogImportOperation
{
    /**
     * @param  array<string, array{from:mixed,to:mixed}>  $changes
     * @param  list<string>  $messages
     */
    public function __construct(
        public string $entityType,
        public string $sourceId,
        public CatalogImportAction $action,
        public ?int $localId = null,
        public array $changes = [],
        public array $messages = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'source_id' => $this->sourceId,
            'action' => $this->action->value,
            'local_id' => $this->localId,
            'changes' => $this->changes,
            'messages' => $this->messages,
        ];
    }
}
