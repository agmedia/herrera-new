<?php

namespace App\Data\Import;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CatalogTranslationData
{
    public string $locale;

    public string $name;

    public string $slug;

    public ?string $excerpt;

    public ?string $description;

    public ?string $metaTitle;

    public ?string $metaDescription;

    /** @var array<string, mixed> */
    public array $payload;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $locale,
        string $name,
        ?string $slug = null,
        ?string $excerpt = null,
        ?string $description = null,
        ?string $metaTitle = null,
        ?string $metaDescription = null,
        array $payload = [],
    ) {
        $locale = strtolower(trim($locale));
        $name = trim($name);
        $slug = Str::slug(trim((string) $slug) ?: $name);

        if ($locale === '' || strlen($locale) > 12) {
            throw new InvalidArgumentException('A catalog translation requires a locale of at most 12 characters.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('A catalog translation requires a name.');
        }

        if ($slug === '') {
            throw new InvalidArgumentException('A catalog translation requires a usable slug.');
        }

        if (mb_strlen($name) > 255 || mb_strlen($slug) > 255) {
            throw new InvalidArgumentException('Catalog translation name and slug may contain at most 255 characters.');
        }

        if ($metaTitle !== null && mb_strlen($metaTitle) > 255) {
            throw new InvalidArgumentException('Catalog translation meta title may contain at most 255 characters.');
        }

        $this->locale = $locale;
        $this->name = $name;
        $this->slug = $slug;
        $this->excerpt = self::nullableTrim($excerpt);
        $this->description = self::nullableTrim($description);
        $this->metaTitle = self::nullableTrim($metaTitle);
        $this->metaDescription = self::nullableTrim($metaDescription);
        $this->payload = $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            locale: (string) ($data['locale'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            slug: isset($data['slug']) ? (string) $data['slug'] : null,
            excerpt: isset($data['excerpt']) ? (string) $data['excerpt'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            metaTitle: isset($data['meta_title']) ? (string) $data['meta_title'] : null,
            metaDescription: isset($data['meta_description']) ? (string) $data['meta_description'] : null,
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'name' => $this->name,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'description' => $this->description,
            'meta_title' => $this->metaTitle,
            'meta_description' => $this->metaDescription,
            'payload' => $this->payload,
        ];
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
