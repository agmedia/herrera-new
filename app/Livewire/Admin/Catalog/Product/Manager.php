<?php

namespace App\Livewire\Admin\Catalog\Product;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductTranslation;
use App\Models\Content\Support\Comment;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $locale = 'en';

    public string $stateFilter = 'all';

    public string $stockFilter = 'all';

    public string $manufacturerFilter = 'all';

    public string $categoryFilter = 'all';

    public string $sortBy = 'newest';

    public function mount(): void
    {
        $this->locale = (string) (request()->query('locale') ?: config('app.locale', 'en'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        $this->resetPage();
    }

    public function updatedStateFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStockFilter(): void
    {
        $this->resetPage();
    }

    public function updatedManufacturerFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSortBy(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->stateFilter = 'all';
        $this->stockFilter = 'all';
        $this->manufacturerFilter = 'all';
        $this->categoryFilter = 'all';
        $this->sortBy = 'newest';
        $this->resetPage();
    }

    public function delete(int $productId): void
    {
        $product = Product::query()->find($productId);
        if (! $product) {
            $this->dispatch('notify', type: 'warning', message: __('Product not found.'));

            return;
        }

        $properties = [
            'product_id' => $productId,
            'code' => $product->code,
            'sku' => $product->sku,
        ];

        DB::transaction(function () use ($product): void {
            DB::table('catalog_action_targets')
                ->where('target_type', CatalogAction::TARGET_PRODUCT)
                ->where('target_id', $product->id)
                ->delete();

            Comment::query()
                ->where('commentable_type', Product::class)
                ->where('commentable_id', $product->id)
                ->delete();

            $product->delete();
        });

        activity('catalog_products')
            ->performedOn($product)
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties($properties)
            ->log('Product deleted');

        $this->dispatch('notify', type: 'success', message: __('Product deleted.'));
        $this->resetPage();
    }

    public function getManufacturerOptionsProperty(): Collection
    {
        return Manufacturer::query()
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code']);
    }

    public function getCategoryOptionsProperty(): Collection
    {
        $categories = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('is_active', true)
            ->withDepth()
            ->defaultOrder()
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->where('locale', $this->locale),
            ])
            ->get(['id', 'code', 'parent_id', '_lft', '_rgt']);

        $nameById = $categories->mapWithKeys(function (Category $category): array {
            $name = (string) ($category->translations->first()?->name ?? ($category->code ?: ('#'.$category->id)));

            return [(int) $category->id => $name];
        });
        $byId = $categories->keyBy(fn (Category $category): int => (int) $category->id);
        $labels = [];

        $build = function (int $id) use (&$build, &$labels, $byId, $nameById): string {
            if (isset($labels[$id])) {
                return $labels[$id];
            }

            $current = $byId->get($id);
            if ($current === null) {
                return '#'.$id;
            }

            $name = (string) ($nameById[$id] ?? ('#'.$id));
            $parentId = (int) ($current->parent_id ?? 0);
            $labels[$id] = ($parentId > 0 && $byId->has($parentId))
                ? $build($parentId).' > '.$name
                : $name;

            return $labels[$id];
        };

        return $categories->map(function (Category $category) use (&$build): array {
            return [
                'id' => (int) $category->id,
                'label' => $build((int) $category->id),
            ];
        });
    }

    /**
     * @return array<string, string>
     */
    public function getStateOptionsProperty(): array
    {
        return [
            'all' => __('admin.common.all'),
            'active' => __('admin.common.active'),
            'inactive' => __('admin.common.inactive'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getStockOptionsProperty(): array
    {
        return [
            'all' => __('All'),
            'in_stock' => __('In stock'),
            'out_of_stock' => __('Out of stock'),
            'low_stock' => __('Low stock'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getSortOptionsProperty(): array
    {
        return [
            'newest' => __('Newest'),
            'oldest' => __('Oldest'),
            'name_asc' => __('Name A-Z'),
            'name_desc' => __('Name Z-A'),
            'price_asc' => __('Price Low-High'),
            'price_desc' => __('Price High-Low'),
            'stock_asc' => __('Stock Low-High'),
            'stock_desc' => __('Stock High-Low'),
            'code_asc' => __('Code A-Z'),
            'code_desc' => __('Code Z-A'),
        ];
    }

    public function render()
    {
        $features = app(CatalogFeatureService::class)->all();
        $useAttributes = (bool) ($features['catalog_use_attributes'] ?? false);
        $useManufacturers = (bool) ($features['catalog_use_manufacturers'] ?? false);

        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );
        $search = trim($this->search);

        $query = Product::query()
            ->select('products.*')
            ->addSelect([
                'sort_name' => ProductTranslation::query()
                    ->select('name')
                    ->whereColumn('product_translations.product_id', 'products.id')
                    ->where('locale', $this->locale)
                    ->limit(1),
            ])
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
                'categories' => fn ($q) => $q
                    ->with([
                        'translations' => fn ($translationQuery) => $translationQuery
                            ->where('scope', Category::SCOPE_CATALOG)
                            ->where('locale', $this->locale),
                    ])
                    ->orderByDesc('category_product.is_primary')
                    ->orderBy('category_product.sort_order')
                    ->orderBy('categories.id'),
                'media' => fn ($q) => $q
                    ->whereIn('collection_name', ['product_main', 'product_gallery'])
                    ->orderBy('order_column')
                    ->orderBy('id'),
            ])
            ->when($useManufacturers, function ($query): void {
                $query->with([
                    'manufacturer.translations' => fn ($q) => $q->where('locale', $this->locale),
                ]);
            })
            ->when($search !== '', function ($query) use ($useManufacturers, $search): void {
                $query->where(function ($q) use ($useManufacturers, $search): void {
                    $q->where('code', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orWhereHas('translations', function ($tq) use ($search): void {
                            $tq->where('name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('categories.translations', function ($categoryTranslationQuery) use ($search): void {
                            $categoryTranslationQuery
                                ->where('scope', Category::SCOPE_CATALOG)
                                ->where('locale', $this->locale)
                                ->where('name', 'like', '%'.$search.'%');
                        });

                    if ($useManufacturers) {
                        $q->orWhereHas('manufacturer.translations', function ($mq) use ($search): void {
                            $mq->where('name', 'like', '%'.$search.'%');
                        });
                    }
                });
            })
            ->when($this->stateFilter === 'active', function ($query): void {
                $query->where('is_active', true);
            })
            ->when($this->stateFilter === 'inactive', function ($query): void {
                $query->where('is_active', false);
            })
            ->when($this->stockFilter === 'in_stock', function ($query): void {
                $query->where('stock_qty', '>', 0);
            })
            ->when($this->stockFilter === 'out_of_stock', function ($query): void {
                $query->where('stock_qty', '<=', 0);
            })
            ->when($this->stockFilter === 'low_stock', function ($query): void {
                $query->whereBetween('stock_qty', [1, 5]);
            })
            ->when($this->categoryFilter !== 'all', function ($query): void {
                $categoryId = (int) $this->categoryFilter;
                if ($categoryId > 0) {
                    $query->whereHas('categories', fn ($q) => $q->whereKey($categoryId));
                }
            })
            ->when($useManufacturers && $this->manufacturerFilter !== 'all', function ($query): void {
                $manufacturerId = (int) $this->manufacturerFilter;
                if ($manufacturerId > 0) {
                    $query->where('manufacturer_id', $manufacturerId);
                }
            });

        if ($useAttributes) {
            $query->withCount('attributes');
        }

        match ($this->sortBy) {
            'oldest' => $query->orderBy('id'),
            'name_asc' => $query->orderBy('sort_name')->orderByDesc('id'),
            'name_desc' => $query->orderByDesc('sort_name')->orderByDesc('id'),
            'price_asc' => $query->orderBy('base_price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('base_price')->orderByDesc('id'),
            'stock_asc' => $query->orderBy('stock_qty')->orderByDesc('id'),
            'stock_desc' => $query->orderByDesc('stock_qty')->orderByDesc('id'),
            'code_asc' => $query->orderBy('code')->orderByDesc('id'),
            'code_desc' => $query->orderByDesc('code')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $rows = $query
            ->paginate($perPage);

        return view('livewire.admin.catalog.product.manager', [
            'rows' => $rows,
            'features' => $features,
            'perPage' => $perPage,
            'stateOptions' => $this->stateOptions,
            'stockOptions' => $this->stockOptions,
            'sortOptions' => $this->sortOptions,
        ]);
    }
}
