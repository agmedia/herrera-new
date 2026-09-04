<?php

namespace App\Support;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\Product;
use Closure;

class ProductMaterialLabel
{
    /**
     * @return array<int, string>
     */
    public static function groupCodes(): array
    {
        return ['material', 'materijal', 'sastav'];
    }

    public static function eagerLoadAttributes(string $locale, string $fallbackLocale): Closure
    {
        return static function ($query) use ($locale, $fallbackLocale): void {
            $query
                ->where('catalog_attributes.is_active', true)
                ->whereIn('catalog_attributes.group_code', self::groupCodes())
                ->orderBy('catalog_attribute_product.sort_order')
                ->orderBy('catalog_attributes.sort_order')
                ->orderBy('catalog_attributes.id')
                ->with([
                    'translations' => fn ($translationQuery) => $translationQuery
                        ->select(['id', 'attribute_id', 'locale', 'group_name', 'name'])
                        ->whereIn('locale', [$locale, $fallbackLocale]),
                ]);
        };
    }

    public static function resolve(Product $product, ?string $locale = null, ?string $fallbackLocale = null): string
    {
        $locale = $locale ?: app()->getLocale();
        $fallbackLocale = $fallbackLocale ?: (string) config('app.locale');

        if (! $product->relationLoaded('attributes')) {
            $product->loadMissing([
                'attributes' => self::eagerLoadAttributes($locale, $fallbackLocale),
            ]);
        } else {
            $product->loadMissing([
                'attributes.translations' => fn ($translationQuery) => $translationQuery
                    ->select(['id', 'attribute_id', 'locale', 'group_name', 'name'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
            ]);
        }

        $labels = $product->attributes
            ->filter(static fn (Attribute $attribute): bool => (bool) $attribute->is_active
                && in_array((string) $attribute->group_code, self::groupCodes(), true))
            ->sortBy(static fn (Attribute $attribute): string => sprintf(
                '%010d:%010d:%010d',
                (int) ($attribute->pivot?->sort_order ?? 0),
                (int) $attribute->sort_order,
                (int) $attribute->id
            ))
            ->map(static function (Attribute $attribute) use ($locale, $fallbackLocale): string {
                $translation = $attribute->translations->firstWhere('locale', $locale)
                    ?? $attribute->translations->firstWhere('locale', $fallbackLocale)
                    ?? $attribute->translations->first();

                return trim((string) ($translation?->name ?? $attribute->code));
            })
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique();

        $label = $labels->implode(', ');

        return self::dominantMaterialName($labels->implode(' / ')) ?? $label;
    }

    public static function dominantMaterialName(string $label): ?string
    {
        $label = trim($label);

        if ($label === '') {
            return null;
        }

        $components = self::percentageFirstComponents($label);

        if ($components === []) {
            $components = self::percentageLastComponents($label);
        }

        $dominant = null;
        foreach ($components as $component) {
            if ($component['name'] === '') {
                continue;
            }

            if ($dominant === null || $component['percentage'] > $dominant['percentage']) {
                $dominant = $component;
            }
        }

        return $dominant['name'] ?? null;
    }

    /**
     * @return array<int, array{percentage:float,name:string}>
     */
    private static function percentageFirstComponents(string $label): array
    {
        preg_match_all(
            '/(\d+(?:[.,]\d+)?)\s*%\s*([^%\/,;|+]+?)(?=\s*(?:[\/,;|+]|\d+(?:[.,]\d+)?\s*%|$))/u',
            $label,
            $matches,
            PREG_SET_ORDER
        );

        return self::normalizeComponents($matches, 1, 2);
    }

    /**
     * @return array<int, array{percentage:float,name:string}>
     */
    private static function percentageLastComponents(string $label): array
    {
        preg_match_all(
            '/(?:^|[\/,;|+])\s*([^%\/,;|+]+?)\s+(\d+(?:[.,]\d+)?)\s*%/u',
            $label,
            $matches,
            PREG_SET_ORDER
        );

        return self::normalizeComponents($matches, 2, 1);
    }

    /**
     * @param  array<int, array<int, string>>  $matches
     * @return array<int, array{percentage:float,name:string}>
     */
    private static function normalizeComponents(array $matches, int $percentageIndex, int $nameIndex): array
    {
        return collect($matches)
            ->map(static fn (array $match): array => [
                'percentage' => (float) str_replace(',', '.', $match[$percentageIndex] ?? '0'),
                'name' => self::cleanMaterialName((string) ($match[$nameIndex] ?? '')),
            ])
            ->filter(static fn (array $component): bool => $component['name'] !== '')
            ->values()
            ->all();
    }

    private static function cleanMaterialName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+(?:i|and)\s*$/iu', '', $name) ?? $name;

        return trim($name, " \t\n\r\0\x0B/,+;|.-");
    }
}
