<?php

namespace App\Services\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\OrderItem;
use App\Models\User;
use App\Services\Pricing\B2BAccessService;
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
        app(B2BAccessService::class)->ensureCanPurchase($user);

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
            ->visibleOnStorefront(true)
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
                    if (! $this->canSelect($product, $option)) {
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

            if ($this->canSelect($product)) {
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
        app(B2BAccessService::class)->ensureCanPurchase($user);

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
        $stock = $option ? max(0, (int) $option->stock_qty) : $product->availableStockQuantity();
        $available = min(999, $stock);
        $maximum = $available < $minimum
            ? 0
            : $minimum + (int) floor(($available - $minimum) / $step) * $step;
        $selectedQuantity = min($maximum, $minimum + (int) ceil((max($minimum, (int) ($quantity ?? $minimum)) - $minimum) / $step) * $step);
        $storedBase = $option?->price_override !== null
            ? (float) $option->price_override
            : (float) $product->base_price;
        $price = $this->prices->forStoredBase($product, $storedBase, $user, $selectedQuantity);
        $media = $product->getFirstMedia('product_main')
            ?? $product->getFirstMedia('product_gallery');
        $imageUrl = null;

        $imageUrl = \App\Support\Media\LegacyCatalogImage::first($product, ['thumb_100x100']);

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
            'unit_price' => (float) ($price['display_current'] ?? $price['current_gross'] ?? 0),
            'base_unit_price' => ($price['display_includes_tax'] ?? true) === false
                ? app(\App\Services\Pricing\TaxPricingService::class)->netFromGross((float) ($price['base_gross'] ?? 0), $product, user: $user)
                : round((float) ($price['base_gross'] ?? 0), 2),
            'display_includes_tax' => (bool) ($price['display_includes_tax'] ?? true),
            'price_source' => (string) ($price['price_source'] ?? 'base'),
            'is_b2b_price' => (bool) ($price['is_b2b_price'] ?? false),
            'has_promotional_discount' => (bool) ($price['has_promotional_discount'] ?? false),
            'minimum_quantity' => $minimum,
            'quantity_step' => $step,
            'maximum_quantity' => $maximum,
            'quantity' => $selectedQuantity,
        ];
    }

    public function canSelect(Product $product, ?ProductOptionValue $option = null): bool
    {
        if (! $product->is_active) {
            return false;
        }

        if ($option && (! $option->is_active || (int) $option->product_id !== (int) $product->getKey() || ! $option->showsOnProductPage())) {
            return false;
        }

        if (! $option && $product->hasVisibleOptionRows()) {
            return false;
        }

        $stock = $option ? (int) $option->stock_qty : $product->availableStockQuantity();

        return min(999, $stock) >= max(1, (int) ($product->minimum_order_quantity ?? 1));
    }

    /** Resolve exact identifiers only: a product with variants requires a variant SKU. */
    public function resolve(string $identifier, User $user, int $quantity = 1): array
    {
        app(B2BAccessService::class)->ensureCanPurchase($user);
        $identifier = trim($identifier);
        $products = Product::query()->visibleOnStorefront()
            ->where(fn ($query) => $query->where('code', $identifier)->orWhere('sku', $identifier)->orWhere('barcode', $identifier))
            ->limit(2)->get();
        $options = ProductOptionValue::query()->where('is_active', true)->where('sku', $identifier)
            ->whereHas('product', fn ($query) => $query->visibleOnStorefront())
            ->with('product')->limit(2)->get();

        if ($products->count() + $options->count() > 1) {
            return ['error' => __('Šifra nije jednoznačna. Odaberite artikl u pretraživanju.')];
        }

        $option = $options->first();
        $product = $option?->product ?? $products->first();
        if (! $product) {
            return ['error' => __('Artikl nije pronađen.')];
        }
        if (! $option && $product->hasVisibleOptionRows()) {
            return ['error' => __('Artikl ima varijante. Unesite SKU varijante ili je odaberite u pretraživanju.')];
        }
        if (! $this->canSelect($product, $option)) {
            return ['error' => __('Artikl trenutno nema dovoljnu raspoloživu zalihu.')];
        }

        $item = $this->present($product, $option, $user, $quantity);

        return [
            'item' => $item,
            'warning' => $item['quantity'] !== $quantity
                ? __('Količina je prilagođena pakiranju i raspoloživoj zalihi: :quantity.', ['quantity' => $item['quantity']])
                : null,
        ];
    }

    /** @return array<string, Collection<int, array<string, mixed>>> */
    public function suggestions(User $user): array
    {
        $history = OrderItem::query()->whereNotNull('product_id')
            ->whereHas('order', fn ($query) => $query->where('user_id', $user->id)
                ->withoutCancelled());
        $frequent = (clone $history)->select('product_id', 'product_option_value_id')
            ->selectRaw('SUM(quantity) as ordered_quantity')->groupBy('product_id', 'product_option_value_id')
            ->orderByDesc('ordered_quantity')->limit(18)->get();
        $recent = (clone $history)->select('product_id', 'product_option_value_id')
            ->selectRaw('MAX(id) as last_item_id')->groupBy('product_id', 'product_option_value_id')
            ->orderByDesc('last_item_id')->limit(18)->get();
        $favoriteIds = $user->wishlistItems()->latest('id')->limit(18)->pluck('product_id');
        $products = Product::query()->visibleOnStorefront()
            ->whereIn('id', $frequent->pluck('product_id')->merge($recent->pluck('product_id'))->merge($favoriteIds)->unique())
            ->with(['optionValues.optionValue.option', 'optionValues.parentOptionValue.option'])
            ->get()->keyBy('id');
        $presentHistory = function (Collection $rows) use ($products, $user): Collection {
            return $rows->map(function (OrderItem $row) use ($products, $user): ?array {
                $product = $products->get($row->product_id);
                $option = $product?->optionValues->firstWhere('id', $row->product_option_value_id);
                if (! $product || ($row->product_option_value_id && ! $option) || ! $this->canSelect($product, $option)) {
                    return null;
                }

                return $this->present($product, $option, $user);
            })->filter()->take(6)->values();
        };
        $favorites = $favoriteIds->map(function ($id) use ($products, $user): ?array {
            $product = $products->get($id);
            if (! $product) {
                return null;
            }
            if ($product->hasVisibleOptionRows()) {
                if (! $product->optionValues->contains(fn ($option) => $this->canSelect($product, $option))) {
                    return null;
                }

                return [...$this->present($product, null, $user), 'requires_variant' => true];
            }

            return $this->canSelect($product) ? $this->present($product, null, $user) : null;
        })->filter()->take(6)->values();

        return ['frequent' => $presentHistory($frequent), 'favorites' => $favorites, 'recent' => $presentHistory($recent)];
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
