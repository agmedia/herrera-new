<?php

namespace App\Data\Import;

use InvalidArgumentException;

final readonly class CatalogCategoryData
{
    public string $sourceId;

    public CatalogLifecycleStatus $status;

    public ?string $code;

    /** @var list<CatalogTranslationData> */
    public array $translations;

    public ?string $parentSourceId;

    public int $sortOrder;

    public bool $showInMenu;

    /** @var array<string, mixed> */
    public array $payload;

    /**
     * Deleted records may contain only sourceId and status. All other lifecycle
     * states are complete normalized snapshots.
     *
     * @param  list<CatalogTranslationData>  $translations
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $sourceId,
        CatalogLifecycleStatus|string $status = CatalogLifecycleStatus::Web,
        ?string $code = null,
        array $translations = [],
        ?string $parentSourceId = null,
        int $sortOrder = 0,
        bool $showInMenu = true,
        array $payload = [],
    ) {
        $sourceId = trim($sourceId);
        $status = CatalogLifecycleStatus::normalize($status);
        $code = self::nullableTrim($code);
        $parentSourceId = self::nullableTrim($parentSourceId);

        if ($sourceId === '' || strlen($sourceId) > 191) {
            throw new InvalidArgumentException('A normalized category requires a source ID of at most 191 characters.');
        }

        if (! $status->isTombstone() && ($code === null || $translations === [])) {
            throw new InvalidArgumentException('A non-deleted category requires a code and at least one translation.');
        }

        if ($code !== null && mb_strlen($code) > 255) {
            throw new InvalidArgumentException('A category code may contain at most 255 characters.');
        }

        if ($parentSourceId !== null && mb_strlen($parentSourceId) > 191) {
            throw new InvalidArgumentException('A category parent source ID may contain at most 191 characters.');
        }

        if ($parentSourceId === $sourceId) {
            throw new InvalidArgumentException("Category [{$sourceId}] cannot be its own parent.");
        }

        self::assertTranslations($translations);

        $this->sourceId = $sourceId;
        $this->status = $status;
        $this->code = $code;
        $this->translations = array_values($translations);
        $this->parentSourceId = $parentSourceId;
        $this->sortOrder = max(0, $sortOrder);
        $this->showInMenu = $showInMenu;
        $this->payload = $payload;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $translations = array_map(
            static fn (mixed $translation): CatalogTranslationData => $translation instanceof CatalogTranslationData
                ? $translation
                : CatalogTranslationData::fromArray(is_array($translation) ? $translation : []),
            is_array($data['translations'] ?? null) ? $data['translations'] : [],
        );

        return new self(
            sourceId: (string) ($data['source_id'] ?? ''),
            status: (string) ($data['status'] ?? CatalogLifecycleStatus::Web->value),
            code: isset($data['code']) ? (string) $data['code'] : null,
            translations: $translations,
            parentSourceId: isset($data['parent_source_id']) ? (string) $data['parent_source_id'] : null,
            sortOrder: (int) ($data['sort_order'] ?? 0),
            showInMenu: (bool) ($data['show_in_menu'] ?? true),
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'status' => $this->status->value,
            'code' => $this->code,
            'translations' => array_map(
                static fn (CatalogTranslationData $translation): array => $translation->toArray(),
                $this->translations,
            ),
            'parent_source_id' => $this->parentSourceId,
            'sort_order' => $this->sortOrder,
            'show_in_menu' => $this->showInMenu,
            'payload' => $this->payload,
        ];
    }

    /** @param list<CatalogTranslationData> $translations */
    private static function assertTranslations(array $translations): void
    {
        $locales = [];

        foreach ($translations as $translation) {
            if (! $translation instanceof CatalogTranslationData) {
                throw new InvalidArgumentException('Category translations must be CatalogTranslationData values.');
            }

            if (isset($locales[$translation->locale])) {
                throw new InvalidArgumentException("Duplicate category translation locale [{$translation->locale}].");
            }

            $locales[$translation->locale] = true;
        }
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
