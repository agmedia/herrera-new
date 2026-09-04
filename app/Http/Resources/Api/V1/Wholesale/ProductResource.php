<?php

namespace App\Http\Resources\Api\V1\Wholesale;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = strtolower((string) $request->query('locale', config('app.locale', 'en')));
        $fallbackLocale = strtolower((string) config('app.fallback_locale', config('app.locale', 'en')));

        $translation = $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', $fallbackLocale)
            ?? $this->translations->first();

        $manufacturer = null;
        if ($this->relationLoaded('manufacturer') && $this->manufacturer) {
            $manufacturerTranslation = $this->manufacturer->translations->firstWhere('locale', $locale)
                ?? $this->manufacturer->translations->firstWhere('locale', $fallbackLocale)
                ?? $this->manufacturer->translations->first();

            $manufacturer = [
                'id' => $this->manufacturer->id,
                'code' => $this->manufacturer->code,
                'name' => $manufacturerTranslation?->name,
                'slug' => $manufacturerTranslation?->slug,
            ];
        }

        $categories = [];
        if ($this->relationLoaded('categories')) {
            $categories = $this->categories
                ->sortBy(fn ($category) => (int) ($category->pivot?->sort_order ?? 0))
                ->values()
                ->map(function ($category) use ($locale, $fallbackLocale): array {
                    $categoryTranslation = $category->translations->firstWhere('locale', $locale)
                        ?? $category->translations->firstWhere('locale', $fallbackLocale)
                        ?? $category->translations->first();

                    return [
                        'id' => $category->id,
                        'scope' => $category->scope,
                        'code' => $category->code,
                        'name' => $categoryTranslation?->name,
                        'slug' => $categoryTranslation?->slug,
                        'sort_order' => (int) ($category->pivot?->sort_order ?? 0),
                        'is_primary' => (bool) ($category->pivot?->is_primary ?? false),
                    ];
                })
                ->all();
        }

        $packages = [];
        if ($this->relationLoaded('packages')) {
            $packages = $this->packages
                ->map(static fn ($package): array => [
                    'id' => $package->id,
                    'code' => $package->code,
                    'name' => $package->name,
                    'barcode' => $package->barcode,
                    'package_type' => $package->package_type,
                    'unit_of_measure' => $package->unit_of_measure,
                    'quantity' => (float) $package->quantity,
                    'weight_kg' => $package->weight_kg !== null ? (float) $package->weight_kg : null,
                    'dimensions_cm' => [
                        'length' => $package->length_cm !== null ? (float) $package->length_cm : null,
                        'width' => $package->width_cm !== null ? (float) $package->width_cm : null,
                        'height' => $package->height_cm !== null ? (float) $package->height_cm : null,
                    ],
                    'is_default' => (bool) $package->is_default,
                    'is_active' => (bool) $package->is_active,
                ])
                ->all();
        }

        return [
            'id' => $this->id,
            'code' => $this->code,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'is_active' => (bool) $this->is_active,
            'base_price' => (float) $this->base_price,
            'stock_qty' => (int) $this->stock_qty,
            'unit_of_measure' => $this->unit_of_measure,
            'minimum_order_quantity' => (int) $this->minimum_order_quantity,
            'order_quantity_step' => (int) $this->order_quantity_step,
            'weight_kg' => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            'dimensions_cm' => [
                'length' => $this->length_cm !== null ? (float) $this->length_cm : null,
                'width' => $this->width_cm !== null ? (float) $this->width_cm : null,
                'height' => $this->height_cm !== null ? (float) $this->height_cm : null,
            ],
            'shipping_labels' => $this->shipping_labels ?? [],
            'packages' => $packages,
            'locale' => $translation?->locale,
            'name' => $translation?->name,
            'slug' => $translation?->slug,
            'excerpt' => $translation?->excerpt,
            'description' => $translation?->description,
            'meta_title' => $translation?->meta_title,
            'meta_description' => $translation?->meta_description,
            'payload' => $this->payload,
            'manufacturer' => $manufacturer,
            'categories' => $categories,
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
