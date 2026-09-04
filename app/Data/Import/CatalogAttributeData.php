<?php

namespace App\Data\Import;

use InvalidArgumentException;

final readonly class CatalogAttributeData
{
    public const TYPE_SELECT = 'select';

    public const TYPE_MULTI = 'multi';

    public string $sourceId;

    public CatalogLifecycleStatus $status;

    public ?string $code;

    public ?string $groupCode;

    public string $type;

    /** @var list<CatalogAttributeTranslationData> */
    public array $translations;

    public int $sortOrder;

    /** @var array<string, mixed> */
    public array $payload;

    /**
     * @param  list<CatalogAttributeTranslationData>  $translations
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $sourceId,
        CatalogLifecycleStatus|string $status = CatalogLifecycleStatus::Web,
        ?string $code = null,
        ?string $groupCode = null,
        string $type = self::TYPE_SELECT,
        array $translations = [],
        int $sortOrder = 0,
        array $payload = [],
    ) {
        $sourceId = trim($sourceId);
        $status = CatalogLifecycleStatus::normalize($status);
        $code = self::nullableTrim($code);
        $groupCode = self::nullableTrim($groupCode);
        $type = strtolower(trim($type));

        if ($sourceId === '' || strlen($sourceId) > 191) {
            throw new InvalidArgumentException('A normalized attribute requires a source ID of at most 191 characters.');
        }

        if (! in_array($type, [self::TYPE_SELECT, self::TYPE_MULTI], true)) {
            throw new InvalidArgumentException("Unsupported catalog attribute type [{$type}].");
        }

        if (! $status->isTombstone() && ($code === null || $groupCode === null || $translations === [])) {
            throw new InvalidArgumentException('A non-deleted attribute requires code, group code, and translations.');
        }

        if (($code !== null && mb_strlen($code) > 120) || ($groupCode !== null && mb_strlen($groupCode) > 120)) {
            throw new InvalidArgumentException('Attribute code and group code may contain at most 120 characters.');
        }

        $locales = [];
        foreach ($translations as $translation) {
            if (! $translation instanceof CatalogAttributeTranslationData) {
                throw new InvalidArgumentException('Attribute translations must be CatalogAttributeTranslationData values.');
            }
            if (isset($locales[$translation->locale])) {
                throw new InvalidArgumentException("Duplicate attribute translation locale [{$translation->locale}].");
            }
            $locales[$translation->locale] = true;
        }

        $this->sourceId = $sourceId;
        $this->status = $status;
        $this->code = $code;
        $this->groupCode = $groupCode;
        $this->type = $type;
        $this->translations = array_values($translations);
        $this->sortOrder = max(0, $sortOrder);
        $this->payload = $payload;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $translations = array_map(
            static fn (mixed $translation): CatalogAttributeTranslationData => $translation instanceof CatalogAttributeTranslationData
                ? $translation
                : CatalogAttributeTranslationData::fromArray(is_array($translation) ? $translation : []),
            is_array($data['translations'] ?? null) ? $data['translations'] : [],
        );

        return new self(
            sourceId: (string) ($data['source_id'] ?? ''),
            status: (string) ($data['status'] ?? CatalogLifecycleStatus::Web->value),
            code: isset($data['code']) ? (string) $data['code'] : null,
            groupCode: isset($data['group_code']) ? (string) $data['group_code'] : null,
            type: (string) ($data['type'] ?? self::TYPE_SELECT),
            translations: $translations,
            sortOrder: (int) ($data['sort_order'] ?? 0),
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
            'group_code' => $this->groupCode,
            'type' => $this->type,
            'translations' => array_map(
                static fn (CatalogAttributeTranslationData $translation): array => $translation->toArray(),
                $this->translations,
            ),
            'sort_order' => $this->sortOrder,
            'payload' => $this->payload,
        ];
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
