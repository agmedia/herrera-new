<?php

namespace App\Data\Import;

final readonly class CatalogAdoptionOperation
{
    /**
     * @param  array<string, string>  $identifiers
     * @param  list<string>  $messages
     */
    public function __construct(
        public string $entityType,
        public string $sourceId,
        public CatalogAdoptionAction $action,
        public ?int $localId = null,
        public array $identifiers = [],
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
            'identifiers' => $this->identifiers,
            'messages' => $this->messages,
        ];
    }
}
