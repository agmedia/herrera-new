<?php

namespace App\Services\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use App\Services\Pricing\ProductPricePresentationService;
use Illuminate\Support\Collection;

class B2BQuickOrderSearchService
{
    public function __construct(
        private readonly ProductPricePresentationService $prices,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function search(string $search, User $user, int $limit = 12): Collection
    {
        $search = trim($search);
        $limit = max(1, min(20, $limit));

        if (mb_strlen($search) < 2) {
            return collect();
        }

        $locale = (string) app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $like = '%'.$search.'%';
        $normalizedSearch = mb_strtolower($search);

        $products = Product::query()
            ->where('products.is_active', true)
            ->where(function ($query): void {
                $query
                    ->where('products.stock_qty', '>', 0)
                    ->orWhereHas('optionValues', function ($optionQuery): void {
                        $optionQuery
                            ->where('is_active', true)
                            ->where('stock_qty', '>', 0);
                    });
            })
            ->where(function ($query) use ($like, $locale, $fallbackLocale): void {
                $query
                    ->where('products.code', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)
                    ->orWhere('products.barcode', 'like', $like)
                    ->orWhereHas('translations', function ($translationQuery) use ($like, $locale, $fallbackLocale): void {
                        $translationQuery
                            ->whereIn('locale', [$locale, $fallbackLocale])
                            ->where('name', 'like', $like);
                    })
                    ->orWhereHas('optionValues', function ($optionQuery) use ($like): void {
                        $optionQuery
                            ->where('is_active', true)
                            ->where('stock_qty', '>', 0)
                            ->where('sku', 'like', $like);
                    });
            })
            ->with([
                'media',
                'taxRate',
                'categories:id',
                'translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValues' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'optionValues.optionValue.option:id,payload',
                'optionValues.optionValue.option.translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValues.optionValue.translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValues.parentOptionValue.option:id,payload',
                'optionValues.parentOptionValue.option.translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValues.parentOptionValue.translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
            ])
            ->orderByRaw(
                'CASE
                    WHEN LOWER(products.code) = ? OR LOWER(products.sku) = ? OR LOWER(products.barcode) = ? THEN 0
                    WHEN LOWER(products.code) LIKE ? OR LOWER(products.sku) LIKE ? OR LOWER(products.barcode) LIKE ? THEN 1
                    ELSE 2
                END',
                [
                    $normalizedSearch,
                    $normalizedSearch,
                    $normalizedSearch,
                    '%'.$normalizedSearch.'%',
                    '%'.$normalizedSearch.'%',
                    '%'.$normalizedSearch.'%',
                ],
            )
            ->orderByDesc('products.id')
            ->limit($limit)
            ->get();

        $results = collect();

        foreach ($products as $product) {
            $name = $this->localizedProductName($product, $locale, $fallbackLocale);
            $productMatches = $this->contains($product->code, $normalizedSearch)
                || $this->contains($product->sku, $normalizedSearch)
                || $this->contains($product->barcode, $normalizedSearch)
                || $this->contains($name, $normalizedSearch);
            $visibleOptions = $product->optionValues
                ->filter(static fn (ProductOptionValue $option): bool => $option->showsOnProductPage())
                ->values();

            if ($visibleOptions->isNotEmpty()) {
                foreach ($visibleOptions as $option) {
                    if ((int) $option->stock_qty <= 0) {
                        continue;
                    }

                    if (! $productMatches && ! $this->contains($option->sku, $normalizedSearch)) {
                        continue;
                    }

                    $results->push($this->present($product, $option, $user));

                    if ($results->count() >= $limit) {
                        return $results;
                    }
                }

                continue;
            }

            if ((int) $product->stock_qty > 0) {
                $results->push($this->present($product, null, $user));
            }

            if ($results->count() >= $limit) {
                break;
            }
        }

        return $results->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(
        Product $product,
        ?ProductOptionValue $option,
        User $user,
        ?int $quantity = null,
    ): array {
        $locale = (string) app()->getLocale();
        $fallbackLocale = (string) config('app.locale');

        $product->loadMissing([
            'media',
            'taxRate',
            'categories:id',
            'translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
        ]);

        if ($option) {
            $option->loadMissing([
                'optionValue.option:id,payload',
                'optionValue.option.translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValue.translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
                'parentOptionValue.option:id,payload',
                'parentOptionValue.option.translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
                'parentOptionValue.translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
            ]);
        }

        $minimum = max(1, (int) ($product->minimum_order_quantity ?? 1));
        $step = max(1, (int) ($product->order_quantity_step ?? 1));
        $stock = max(0, (int) ($option?->stock_qty ?? $product->stock_qty));
        $selectedQuantity = max($minimum, (int) ($quantity ?? $minimum));
        $storedBase = $option?->price_override !== null
            ? (float) $option->price_override
            : (float) $product->base_price;
        $price = $this->prices->forStoredBase($product, $storedBase, $user, $selectedQuantity);
        $media = $product->getFirstMedia('product_main')
            ?? $product->getFirstMedia('product_gallery');
        $imageUrl = null;

        if ($media) {
            $imageUrl = $media->hasGeneratedConversion('thumb_100x100')
                ? $media->getUrl('thumb_100x100')
                : $media->getUrl();
        }

        return [
            'key' => (int) $product->getKey().':'.(int) ($option?->getKey() ?? 0),
            'product_id' => (int) $product->getKey(),
            'product_option_value_id' => $option ? (int) $option->getKey() : null,
            'identifier' => (string) ($option?->sku ?: $product->sku ?: $product->code),
            'code' => (string) $product->code,
            'sku' => (string) ($option?->sku ?: $product->sku ?: ''),
            'barcode' => (string) ($product->barcode ?: ''),
            'name' => $this->localizedProductName($product, $locale, $fallbackLocale),
            'option_label' => $this->optionLabel($option, $locale, $fallbackLocale),
            'image_url' => $imageUrl,
            'unit_price' => round((float) ($price['current_gross'] ?? 0), 2),
            'base_unit_price' => round((float) ($price['base_gross'] ?? 0), 2),
            'price_source' => (string) ($price['price_source'] ?? 'base'),
            'is_b2b_price' => (bool) ($price['is_b2b_price'] ?? false),
            'has_promotional_discount' => (bool) ($price['has_promotional_discount'] ?? false),
            'minimum_quantity' => $minimum,
            'quantity_step' => $step,
            'maximum_quantity' => min(999, $stock),
            'quantity' => min(min(999, $stock), $selectedQuantity),
        ];
    }

    private function localizedProductName(Product $product, string $locale, string $fallbackLocale): string
    {
        $translation = $product->translations->firstWhere('locale', $locale)
            ?? $product->translations->firstWhere('locale', $fallbackLocale)
            ?? $product->translations->first();

        return (string) ($translation?->name ?: $product->code);
    }

    private function contains(mixed $value, string $normalizedSearch): bool
    {
        $value = trim((string) $value);

        return $value !== '' && str_contains(mb_strtolower($value), $normalizedSearch);
    }

    private function optionLabel(
        ?ProductOptionValue $option,
        string $locale,
        string $fallbackLocale,
    ): ?string {
        if (! $option) {
            return null;
        }

        $child = $option->optionValue?->translations?->firstWhere('locale', $locale)
            ?? $option->optionValue?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $option->optionValue?->translations?->first();
        $parent = $option->parentOptionValue?->translations?->firstWhere('locale', $locale)
            ?? $option->parentOptionValue?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $option->parentOptionValue?->translations?->first();
        $childOption = $option->optionValue?->option?->translations?->firstWhere('locale', $locale)
            ?? $option->optionValue?->option?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $option->optionValue?->option?->translations?->first();
        $parentOption = $option->parentOptionValue?->option?->translations?->firstWhere('locale', $locale)
            ?? $option->parentOptionValue?->option?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $option->parentOptionValue?->option?->translations?->first();

        $parts = [];
        $parentName = trim((string) ($parentOption?->name ?? ''));
        $parentValue = trim((string) ($parent?->name ?? $option->parentOptionValue?->code ?? ''));
        $childName = trim((string) ($childOption?->name ?? ''));
        $childValue = trim((string) ($child?->name ?? $option->optionValue?->code ?? ''));

        if ($parentValue !== '') {
            $parts[] = $parentName !== '' ? $parentName.': '.$parentValue : $parentValue;
        }

        if ($childValue !== '') {
            $parts[] = $childName !== '' ? $childName.': '.$childValue : $childValue;
        }

        return $parts !== [] ? implode(' / ', $parts) : null;
    }
}
