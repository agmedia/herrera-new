<?php

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Http\Resources\Api\V1\Wholesale\ProductResource;
use App\Models\Catalog\Product\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProductController extends BaseWholesaleController
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:12'],
            'q' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'in:active,inactive,all'],
            'manufacturer_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'updated_since' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'string', 'in:newest,oldest,updated,price_asc,price_desc,code_asc,code_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);

        [$locale, $fallbackLocale] = $this->resolveLocale($request);
        $updatedSince = $this->resolveUpdatedSince($validated['updated_since'] ?? null);
        $perPage = $this->resolvePerPage($request, 50, 250);
        $sort = (string) ($validated['sort'] ?? 'newest');
        $search = trim((string) ($validated['q'] ?? ''));
        $state = (string) ($validated['state'] ?? 'active');
        $manufacturerId = isset($validated['manufacturer_id']) ? (int) $validated['manufacturer_id'] : null;
        $categoryId = isset($validated['category_id']) ? (int) $validated['category_id'] : null;

        $query = Product::query()
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
                'manufacturer.translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
                'categories' => fn ($q) => $q->orderBy('category_product.sort_order'),
                'categories.translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
                'packages' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            ]);

        if ($state === 'active') {
            $query->where('is_active', true);
        } elseif ($state === 'inactive') {
            $query->where('is_active', false);
        }

        if ($manufacturerId) {
            $query->where('manufacturer_id', $manufacturerId);
        }

        if ($categoryId) {
            $query->whereHas('categories', fn (Builder $q) => $q->where('categories.id', $categoryId));
        }

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('code', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('barcode', 'like', '%'.$search.'%')
                    ->orWhereHas('translations', function (Builder $translationQuery) use ($search): void {
                        $translationQuery->where('name', 'like', '%'.$search.'%')
                            ->orWhere('slug', 'like', '%'.$search.'%');
                    });
            });
        }

        if ($updatedSince) {
            $query->where(function (Builder $q) use ($updatedSince): void {
                $q->where('products.updated_at', '>=', $updatedSince)
                    ->orWhereHas('translations', fn (Builder $tq) => $tq->where('updated_at', '>=', $updatedSince));
            });
        }

        match ($sort) {
            'oldest' => $query->orderBy('products.created_at'),
            'updated' => $query->orderByDesc('products.updated_at'),
            'price_asc' => $query->orderBy('products.base_price')->orderByDesc('products.id'),
            'price_desc' => $query->orderByDesc('products.base_price')->orderByDesc('products.id'),
            'code_asc' => $query->orderBy('products.code')->orderByDesc('products.id'),
            'code_desc' => $query->orderByDesc('products.code')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.created_at'),
        };

        $rows = $query->paginate($perPage)->withQueryString();

        return ProductResource::collection($rows);
    }

    public function show(Request $request, string $identifier): ProductResource
    {
        [$locale, $fallbackLocale] = $this->resolveLocale($request);

        $product = Product::query()
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
                'manufacturer.translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
                'categories' => fn ($q) => $q->orderBy('category_product.sort_order'),
                'categories.translations' => fn ($q) => $q->whereIn('locale', array_unique([$locale, $fallbackLocale])),
                'packages' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            ])
            ->where(function (Builder $q) use ($identifier): void {
                $q->where('products.code', $identifier)
                    ->orWhere('products.sku', $identifier)
                    ->orWhereHas('translations', fn (Builder $tq) => $tq->where('slug', $identifier));

                if (ctype_digit($identifier)) {
                    $q->orWhere('products.id', (int) $identifier);
                }
            })
            ->firstOrFail();

        return new ProductResource($product);
    }
}
