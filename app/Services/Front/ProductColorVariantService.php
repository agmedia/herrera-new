<?php

namespace App\Services\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Services\Catalog\CatalogFeatureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductColorVariantService
{
    private const MAX_VARIANTS = 24;

    /**
     * @return Collection<int, array{
     *     product_id:int,
     *     color_value_id:int,
     *     color_key:string,
     *     label:string,
     *     url:string,
     *     sku:string,
     *     is_current:bool,
     *     sort_order:int,
     *     swatch_image_url:?string,
     *     swatch_style:string
     * }>
     */
    public function variantsFor(Product $product, string $locale, string $fallbackLocale): Collection
    {
        $currentColor = $this->colorValueForProduct($product, $locale, $fallbackLocale);
        if (! $currentColor) {
            return collect();
        }

        $group = $this->variantGroup($product, $locale, $fallbackLocale);
        if ($group === null) {
            return collect();
        }

        $variants = $this->variantProducts($group, $locale, $fallbackLocale)
            ->map(function (Product $variantProduct) use ($product, $locale, $fallbackLocale): ?array {
                $colorValue = $this->colorValueForProduct($variantProduct, $locale, $fallbackLocale);
                if (! $colorValue) {
                    return null;
                }

                $translation = $this->productTranslation($variantProduct, $locale, $fallbackLocale);
                $slug = trim((string) ($translation?->slug ?? ''));
                if ($slug === '') {
                    return null;
                }

                $label = $this->valueLabel($colorValue, $locale, $fallbackLocale);
                $colorKey = $this->normalizeSwatchKey($label);
                if ($colorKey === '') {
                    $colorKey = 'value-'.$colorValue->id;
                }

                return [
                    'product_id' => (int) $variantProduct->id,
                    'color_value_id' => (int) $colorValue->id,
                    'color_key' => $colorKey,
                    'label' => $label !== '' ? $label : (string) $colorValue->code,
                    'url' => route('products.show', ['slug' => $slug]),
                    'sku' => (string) ($variantProduct->sku ?: $variantProduct->code),
                    'is_current' => (int) $variantProduct->id === (int) $product->id,
                    'sort_order' => (int) ($colorValue->sort_order ?? 0),
                    'swatch_image_url' => $this->swatchImageUrl($colorValue),
                    'swatch_style' => $this->swatchStyle($label),
                ];
            })
            ->filter()
            ->sortBy(fn (array $variant): string => implode('-', [
                $variant['is_current'] ? '0' : '1',
                str_pad((string) max(0, (int) $variant['sort_order']), 6, '0', STR_PAD_LEFT),
                str_pad((string) max(0, (int) $variant['product_id']), 10, '0', STR_PAD_LEFT),
            ]))
            ->unique('color_key')
            ->values();

        return $variants->count() > 1 ? $variants : collect();
    }

    /**
     * @param  array{type:string,column?:string,value:string}  $group
     * @return Collection<int, Product>
     */
    private function variantProducts(array $group, string $locale, string $fallbackLocale): Collection
    {
        $hideOutOfStock = app(CatalogFeatureService::class)->hideOutOfStockProducts();

        $query = Product::query()
            ->select(['id', 'code', 'sku', 'is_active', 'payload'])
            ->visibleOnStorefront($hideOutOfStock)
            ->with([
                'translations' => fn ($q) => $q
                    ->select(['id', 'product_id', 'locale', 'slug', 'name'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValues' => fn ($q) => $q
                    ->select(['id', 'product_id', 'option_value_id', 'parent_option_value_id', 'is_active', 'sort_order'])
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'optionValues.optionValue.option.translations' => fn ($q) => $q
                    ->select(['id', 'option_id', 'locale', 'name'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValues.optionValue.translations' => fn ($q) => $q
                    ->select(['id', 'option_value_id', 'locale', 'name'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValues.parentOptionValue.option.translations' => fn ($q) => $q
                    ->select(['id', 'option_id', 'locale', 'name'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValues.parentOptionValue.translations' => fn ($q) => $q
                    ->select(['id', 'option_value_id', 'locale', 'name'])
                    ->whereIn('locale', [$locale, $fallbackLocale]),
            ])
            ->orderBy('id')
            ->limit(self::MAX_VARIANTS);

        if (($group['type'] ?? '') === 'payload') {
            $query->where((string) $group['column'], (string) $group['value']);
        } else {
            $query->whereHas('translations', function ($translationQuery) use ($group, $locale, $fallbackLocale): void {
                $translationQuery
                    ->whereIn('locale', [$locale, $fallbackLocale])
                    ->where('name', (string) $group['value']);
            });
        }

        return $query->get();
    }

    /**
     * @return array{type:string,column?:string,value:string}|null
     */
    private function variantGroup(Product $product, string $locale, string $fallbackLocale): ?array
    {
        $payloadPaths = [
            'color_variant_group' => 'payload->color_variant_group',
            'variant_group' => 'payload->variant_group',
            'source.color_variant_group' => 'payload->source->color_variant_group',
            'source.variant_group' => 'payload->source->variant_group',
            'source.mpn' => 'payload->source->mpn',
            'source.model' => 'payload->source->model',
        ];

        foreach ($payloadPaths as $path => $column) {
            $value = trim((string) data_get($product->payload, $path, ''));
            if ($value !== '') {
                return [
                    'type' => 'payload',
                    'column' => $column,
                    'value' => $value,
                ];
            }
        }

        $translation = $this->productTranslation($product, $locale, $fallbackLocale);
        $name = trim((string) ($translation?->name ?? ''));

        return $name !== ''
            ? ['type' => 'translation_name', 'value' => $name]
            : null;
    }

    private function colorValueForProduct(Product $product, string $locale, string $fallbackLocale): ?OptionValue
    {
        $product->loadMissing([
            'optionValues.optionValue.option.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
            'optionValues.optionValue.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
            'optionValues.parentOptionValue.option.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
            'optionValues.parentOptionValue.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
        ]);

        foreach ($product->optionValues->where('is_active', true) as $row) {
            $parentValue = $row->parentOptionValue;
            if ($parentValue && $this->isColorOption($parentValue->option)) {
                return $parentValue;
            }

            $value = $row->optionValue;
            if ($value && $this->isColorOption($value->option)) {
                return $value;
            }
        }

        return null;
    }

    private function isColorOption(?Option $option): bool
    {
        if (! $option) {
            return false;
        }

        $code = Str::lower(trim((string) $option->code));
        if (Str::startsWith($code, ['color', 'colour', 'boja'])) {
            return true;
        }

        foreach ($option->translations ?? [] as $translation) {
            $name = $this->normalizeSwatchKey((string) ($translation->name ?? ''));
            if (in_array($name, ['color', 'colour', 'boja'], true)) {
                return true;
            }
        }

        return false;
    }

    private function productTranslation(Product $product, string $locale, string $fallbackLocale): mixed
    {
        $product->loadMissing([
            'translations' => fn ($q) => $q
                ->select(['id', 'product_id', 'locale', 'slug', 'name'])
                ->whereIn('locale', [$locale, $fallbackLocale]),
        ]);

        return $product->translations->firstWhere('locale', $locale)
            ?? $product->translations->firstWhere('locale', $fallbackLocale)
            ?? $product->translations->first();
    }

    private function valueLabel(OptionValue $value, string $locale, string $fallbackLocale): string
    {
        $translation = $value->translations?->firstWhere('locale', $locale)
            ?? $value->translations?->firstWhere('locale', $fallbackLocale)
            ?? $value->translations?->first();

        return trim((string) ($translation?->name ?? $value->code));
    }

    private function swatchImageUrl(OptionValue $value): ?string
    {
        $path = trim((string) data_get($value->payload, 'swatch_image_path', ''));

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//', '/'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function swatchStyle(string $label): string
    {
        $key = $this->normalizeSwatchKey($label);
        $styles = [
            'crna' => 'background:#050505;',
            'black' => 'background:#050505;',
            'bijela' => 'background:#fcfcfc;',
            'white' => 'background:#fcfcfc;',
            'crvena' => 'background:#d62828;',
            'red' => 'background:#d62828;',
            'siva' => 'background:#8f96a3;',
            'gray' => 'background:#8f96a3;',
            'grey' => 'background:#8f96a3;',
            'tamno plava' => 'background:#1e3a8a;',
            'tamno-plava' => 'background:#1e3a8a;',
            'dark blue' => 'background:#1e3a8a;',
            'navy' => 'background:#1e3a8a;',
            'boja koze' => 'background:#d2a181;',
            'boja-koze' => 'background:#d2a181;',
            'nude' => 'background:#d2a181;',
            'skin' => 'background:#d2a181;',
            'kokos' => 'background:linear-gradient(135deg, #ceb08f 0%, #b98d62 48%, #7b5637 100%);',
            'geometric' => 'background:linear-gradient(135deg, #101828 0 28%, #e11d48 28% 50%, #f8fafc 50% 62%, #101828 62% 100%);',
            'squares' => 'background:linear-gradient(45deg, #111827 25%, #f8fafc 25% 50%, #111827 50% 75%, #f8fafc 75% 100%);background-size:12px 12px;',
            'web' => 'background:radial-gradient(circle at center, transparent 0 11px, rgba(15,23,42,.18) 11px 12px, transparent 12px), repeating-linear-gradient(45deg, #111827 0 2px, transparent 2px 8px), #f8fafc;',
            'red flowers' => 'background:radial-gradient(circle at 30% 35%, #fca5a5 0 18%, transparent 19%), radial-gradient(circle at 72% 68%, #ef4444 0 16%, transparent 17%), linear-gradient(135deg, #fff1f2 0%, #fecdd3 100%);',
            'black roses' => 'background:radial-gradient(circle at 30% 35%, #fbcfe8 0 18%, transparent 19%), radial-gradient(circle at 72% 68%, #111827 0 16%, transparent 17%), linear-gradient(135deg, #111827 0%, #475569 100%);',
            'butterfly' => 'background:linear-gradient(135deg, #fb7185 0%, #fecdd3 35%, #111827 35% 45%, #fecdd3 45% 65%, #e11d48 65% 100%);',
            'footprint' => 'background:radial-gradient(circle at 30% 30%, #111827 0 13%, transparent 14%), radial-gradient(circle at 52% 52%, #111827 0 10%, transparent 11%), radial-gradient(circle at 70% 28%, #111827 0 7%, transparent 8%), linear-gradient(135deg, #f1e3d3 0%, #d4b08b 100%);',
            'stars' => 'background:radial-gradient(circle at 22% 28%, #fef08a 0 6%, transparent 7%), radial-gradient(circle at 68% 36%, #fff7ae 0 5%, transparent 6%), radial-gradient(circle at 48% 70%, #fef08a 0 6%, transparent 7%), #1d4ed8;',
        ];

        if (isset($styles[$key])) {
            return $styles[$key];
        }

        if (Str::contains($key, 'karirano') && Str::contains($key, 'crvena')) {
            return 'background:repeating-linear-gradient(45deg, rgba(17,24,39,.45) 0 2px, transparent 2px 8px), repeating-linear-gradient(-45deg, rgba(17,24,39,.45) 0 2px, transparent 2px 8px), #dc2626;background-size:12px 12px;';
        }

        if (Str::contains($key, 'karirano') && (Str::contains($key, 'crna') || Str::contains($key, 'black'))) {
            return 'background:repeating-linear-gradient(45deg, rgba(248,250,252,.45) 0 2px, transparent 2px 8px), repeating-linear-gradient(-45deg, rgba(248,250,252,.45) 0 2px, transparent 2px 8px), #111827;background-size:12px 12px;';
        }

        $palette = [
            ['#111827', '#475569'],
            ['#7c2d12', '#fb923c'],
            ['#1d4ed8', '#38bdf8'],
            ['#6d28d9', '#c084fc'],
            ['#be123c', '#fda4af'],
            ['#166534', '#86efac'],
        ];
        $colors = $palette[abs((int) crc32($key)) % count($palette)];

        return sprintf('background:linear-gradient(135deg, %s 0%%, %s 100%%);', $colors[0], $colors[1]);
    }

    private function normalizeSwatchKey(string $value): string
    {
        $normalized = Str::lower(Str::ascii(trim($value)));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: '';

        return trim($normalized);
    }
}
