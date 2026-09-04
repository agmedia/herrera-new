<?php

namespace App\Data\Import;

use InvalidArgumentException;
use JsonException;

final readonly class CatalogImportBatch
{
    public string $source;

    /** @var list<CatalogCategoryData> */
    public array $categories;

    /** @var list<CatalogProductData> */
    public array $products;

    /** @var list<CatalogAttributeData> */
    public array $attributes;

    /**
     * @param  list<CatalogCategoryData>  $categories
     * @param  list<CatalogProductData>  $products
     * @param  list<CatalogAttributeData>  $attributes
     */
    public function __construct(string $source, array $categories = [], array $products = [], array $attributes = [])
    {
        $source = strtolower(trim($source));

        if ($source === '' || strlen($source) > 100) {
            throw new InvalidArgumentException('A catalog import source of at most 100 characters is required.');
        }

        $this->assertUniqueRecords($categories, CatalogCategoryData::class, 'category');
        $this->assertUniqueRecords($products, CatalogProductData::class, 'product');
        $this->assertUniqueRecords($attributes, CatalogAttributeData::class, 'attribute');
        $this->assertUniqueNaturalKeys($categories, $products, $attributes);
        $this->assertAcyclicCategories($categories);

        $this->source = $source;
        $this->categories = array_values($categories);
        $this->products = array_values($products);
        $this->attributes = array_values($attributes);
    }

    /**
     * @param  list<array<string, mixed>|CatalogCategoryData>  $categories
     * @param  list<array<string, mixed>|CatalogProductData>  $products
     * @param  list<array<string, mixed>|CatalogAttributeData>  $attributes
     */
    public static function fromArrays(string $source, array $categories = [], array $products = [], array $attributes = []): self
    {
        return new self(
            source: $source,
            categories: array_map(
                static fn (array|CatalogCategoryData $record): CatalogCategoryData => $record instanceof CatalogCategoryData
                    ? $record
                    : CatalogCategoryData::fromArray($record),
                $categories,
            ),
            products: array_map(
                static fn (array|CatalogProductData $record): CatalogProductData => $record instanceof CatalogProductData
                    ? $record
                    : CatalogProductData::fromArray($record),
                $products,
            ),
            attributes: array_map(
                static fn (array|CatalogAttributeData $record): CatalogAttributeData => $record instanceof CatalogAttributeData
                    ? $record
                    : CatalogAttributeData::fromArray($record),
                $attributes,
            ),
        );
    }

    /** @return list<CatalogCategoryData> */
    public function orderedCategories(): array
    {
        $records = [];
        foreach ($this->categories as $category) {
            $records[$category->sourceId] = $category;
        }

        $ordered = [];
        $visited = [];

        $visit = function (CatalogCategoryData $category) use (&$visit, &$ordered, &$visited, $records): void {
            if (isset($visited[$category->sourceId])) {
                return;
            }

            if ($category->parentSourceId !== null && isset($records[$category->parentSourceId])) {
                $visit($records[$category->parentSourceId]);
            }

            $visited[$category->sourceId] = true;
            $ordered[] = $category;
        };

        foreach ($this->categories as $category) {
            $visit($category);
        }

        return $ordered;
    }

    /** @throws JsonException */
    public function checksum(): string
    {
        $categories = array_map(
            static fn (CatalogCategoryData $record): array => $record->toArray(),
            $this->categories,
        );
        $products = array_map(
            static fn (CatalogProductData $record): array => $record->toArray(),
            $this->products,
        );
        $attributes = array_map(
            static fn (CatalogAttributeData $record): array => $record->toArray(),
            $this->attributes,
        );

        usort($categories, static fn (array $a, array $b): int => $a['source_id'] <=> $b['source_id']);
        usort($products, static fn (array $a, array $b): int => $a['source_id'] <=> $b['source_id']);
        usort($attributes, static fn (array $a, array $b): int => $a['source_id'] <=> $b['source_id']);

        return hash('sha256', json_encode([
            'source' => $this->source,
            'categories' => $categories,
            'products' => $products,
            'attributes' => $attributes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<int, mixed>  $records
     * @param  class-string  $expectedClass
     */
    private function assertUniqueRecords(array $records, string $expectedClass, string $entity): void
    {
        $sourceIds = [];

        foreach ($records as $record) {
            if (! $record instanceof $expectedClass) {
                throw new InvalidArgumentException("Normalized {$entity} records have an invalid value.");
            }

            if (isset($sourceIds[$record->sourceId])) {
                throw new InvalidArgumentException("Duplicate {$entity} source ID [{$record->sourceId}].");
            }

            $sourceIds[$record->sourceId] = true;
        }
    }

    /**
     * @param  list<CatalogCategoryData>  $categories
     * @param  list<CatalogProductData>  $products
     * @param  list<CatalogAttributeData>  $attributes
     */
    private function assertUniqueNaturalKeys(array $categories, array $products, array $attributes): void
    {
        $categoryCodes = [];
        $categorySlugs = [];
        foreach ($categories as $category) {
            if ($category->status->isTombstone()) {
                continue;
            }

            $this->assertUniqueKey($categoryCodes, (string) $category->code, 'category code');
            foreach ($category->translations as $translation) {
                $this->assertUniqueKey(
                    $categorySlugs,
                    $translation->locale.':'.$translation->slug,
                    'category locale/slug',
                );
            }
        }

        $productCodes = [];
        $productSkus = [];
        $productBarcodes = [];
        $productSlugs = [];
        foreach ($products as $product) {
            if ($product->status->isTombstone()) {
                continue;
            }

            $this->assertUniqueKey($productCodes, (string) $product->code, 'product code');
            if ($product->sku !== null) {
                $this->assertUniqueKey($productSkus, $product->sku, 'product SKU');
            }
            if ($product->barcode !== null) {
                $this->assertUniqueKey($productBarcodes, $product->barcode, 'product barcode');
            }
            foreach ($product->translations as $translation) {
                $this->assertUniqueKey(
                    $productSlugs,
                    $translation->locale.':'.$translation->slug,
                    'product locale/slug',
                );
            }
        }

        $attributeCodes = [];
        $attributeSlugs = [];
        foreach ($attributes as $attribute) {
            if (! $attribute->status->isTombstone()) {
                $this->assertUniqueKey($attributeCodes, (string) $attribute->code, 'attribute code');
                foreach ($attribute->translations as $translation) {
                    $this->assertUniqueKey(
                        $attributeSlugs,
                        $translation->locale.':'.$translation->slug,
                        'attribute locale/slug',
                    );
                }
            }
        }
    }

    /** @param array<string, true> $owners */
    private function assertUniqueKey(array &$owners, string $value, string $label): void
    {
        $key = mb_strtolower($value);
        if (isset($owners[$key])) {
            throw new InvalidArgumentException("Duplicate {$label} [{$value}] in catalog import batch.");
        }

        $owners[$key] = true;
    }

    /** @param list<CatalogCategoryData> $categories */
    private function assertAcyclicCategories(array $categories): void
    {
        $parents = [];
        foreach ($categories as $category) {
            $parents[$category->sourceId] = $category->parentSourceId;
        }

        foreach (array_keys($parents) as $sourceId) {
            $seen = [];
            $current = $sourceId;

            while ($current !== null && isset($parents[$current])) {
                if (isset($seen[$current])) {
                    throw new InvalidArgumentException("Category hierarchy contains a cycle at [{$current}].");
                }

                $seen[$current] = true;
                $current = $parents[$current];
            }
        }
    }
}
