<?php

namespace App\Data\Import;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CatalogAttributeTranslationData
{
    public string $locale;

    public string $groupName;

    public string $name;

    public string $slug;

    public ?string $description;

    /** @var array<string, mixed> */
    public array $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(
        string $locale,
        string $groupName,
        string $name,
        ?string $slug = null,
        ?string $description = null,
        array $payload = [],
    ) {
        $locale = strtolower(trim($locale));
        $groupName = trim($groupName);
        $name = trim($name);
        $slug = Str::slug(trim((string) $slug) ?: $groupName.'-'.$name);

        if ($locale === '' || strlen($locale) > 12) {
            throw new InvalidArgumentException('An attribute translation requires a locale of at most 12 characters.');
        }

        if ($groupName === '' || $name === '' || $slug === '') {
            throw new InvalidArgumentException('An attribute translation requires group name, name, and usable slug.');
        }

        if (mb_strlen($groupName) > 255 || mb_strlen($slug) > 255) {
            throw new InvalidArgumentException('Attribute translation group name and slug may contain at most 255 characters.');
        }

        $description = trim((string) $description);

        $this->locale = $locale;
        $this->groupName = $groupName;
        $this->name = $name;
        $this->slug = $slug;
        $this->description = $description !== '' ? $description : null;
        $this->payload = $payload;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            locale: (string) ($data['locale'] ?? ''),
            groupName: (string) ($data['group_name'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            slug: isset($data['slug']) ? (string) $data['slug'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'group_name' => $this->groupName,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'payload' => $this->payload,
        ];
    }
}
