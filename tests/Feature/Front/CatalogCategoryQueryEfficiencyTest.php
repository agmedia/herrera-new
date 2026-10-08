<?php

namespace Tests\Feature\Front;

use App\Http\Controllers\Front\CatalogController;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class CatalogCategoryQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'catalog_hide_out_of_stock_products' => false,
            'catalog_use_manufacturers' => true,
        ]);
        app(CatalogFeatureService::class)->hideOutOfStockProducts();
    }

    public function test_shop_root_counts_use_one_batch_and_count_products_once_per_visible_tree(): void
    {
        $roots = collect();
        foreach (range(1, 6) as $number) {
            $root = $this->category('batch-root-'.$number);
            $leaf = $this->category('batch-leaf-'.$number, $root);
            $product = $this->product('batch-product-'.$number, $leaf);
            $product->categories()->attach($root);
            $roots->push($root);
        }
        $emptyRoot = $this->category('batch-empty');
        foreach ([
            ['is_active' => false],
            ['starts_at' => now()->addHour()],
            ['ends_at' => now()->subHour()],
        ] as $index => $visibility) {
            $hidden = $this->category('batch-hidden-'.$index, $emptyRoot);
            $hidden->update($visibility);
            $this->product('batch-hidden-product-'.$index, $hidden);
        }
        $inactive = $this->product('batch-inactive-product', $emptyRoot);
        $inactive->update(['is_active' => false]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $categories = $this->catalogCategories('cachedShopCatalogCategories');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame($roots->pluck('id')->all(), $categories->pluck('id')->all());
        $this->assertSame([1, 1, 1, 1, 1, 1], $categories->pluck('products_count')->all());
        $this->assertCount(3, $queries, 'Root navigation needs only categories, translations and one grouped product count.');
    }

    public function test_manufacturer_root_counts_use_one_batch_after_general_navigation_is_warm(): void
    {
        $manufacturer = Manufacturer::query()->create(['code' => 'batch-brand', 'is_active' => true]);
        $roots = collect();
        foreach (range(1, 6) as $number) {
            $root = $this->category('brand-root-'.$number);
            $leaf = $this->category('brand-leaf-'.$number, $root);
            $product = $this->product('brand-product-'.$number, $leaf, $manufacturer);
            $product->categories()->attach($root);
            $roots->push($root);
        }
        $otherRoot = $this->category('brand-unrelated');
        $this->product('brand-unrelated-product', $otherRoot);
        $controller = app(CatalogController::class);
        $this->catalogCategories('cachedShopCatalogCategories', controller: $controller);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $categories = $this->catalogCategories('cachedManufacturerCatalogCategories', [$manufacturer->id, 'hr', 'hr'], $controller);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame($roots->pluck('id')->all(), $categories->pluck('id')->all());
        $this->assertSame([1, 1, 1, 1, 1, 1], $categories->pluck('products_count')->all());
        $this->assertCount(1, $queries, 'Changing manufacturer must batch all recursive root counts together.');
        $this->assertSame(1, (int) $this->catalogCategories('cachedShopCatalogCategories', controller: $controller)->firstWhere('id', $otherRoot->id)->products_count);
    }

    public function test_category_children_share_one_count_query_and_keep_empty_children(): void
    {
        $root = $this->category('child-batch-root');
        $children = collect();
        foreach (range(1, 6) as $number) {
            $child = $this->category('child-batch-'.$number, $root);
            $leaf = $this->category('child-batch-leaf-'.$number, $child);
            $product = $this->product('child-batch-product-'.$number, $leaf);
            $product->categories()->attach($child);
            $children->push($child);
        }
        $empty = $this->category('child-batch-empty', $root);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->get(route('categories.show', ['slug' => 'child-batch-root']))->assertOk();
            $countQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'count(distinct')
                && str_contains(strtolower($query['query']), 'category_product'));
        } finally {
            DB::disableQueryLog();
        }

        $subcategories = $response->viewData('subcategories');
        $this->assertSame([...$children->pluck('id')->all(), $empty->id], $subcategories->pluck('id')->all());
        $this->assertSame([1, 1, 1, 1, 1, 1, 0], $subcategories->pluck('products_count')->all());
        $this->assertCount(1, $countQueries, 'All children must share a recursive count query, even on uncached category pages.');
    }

    public function test_unknown_manufacturer_keeps_empty_child_counts_without_running_an_impossible_count(): void
    {
        $root = $this->category('unknown-brand-root');
        $child = $this->category('unknown-brand-child', $root);
        $this->product('unknown-brand-product', $child);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->get(route('categories.show', ['slug' => 'unknown-brand-root', 'manufacturer' => 'missing-brand']))->assertOk();
            $recursiveQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'recursive_filter_categories'));
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(0, $response->viewData('products')->total());
        $this->assertSame([$child->id], $response->viewData('subcategories')->pluck('id')->all());
        $this->assertSame([0], $response->viewData('subcategories')->pluck('products_count')->all());
        $this->assertCount(0, $recursiveQueries);
    }

    private function catalogCategories(string $method, array $arguments = ['hr', 'hr'], ?CatalogController $controller = null): Collection
    {
        return (new ReflectionMethod(CatalogController::class, $method))->invoke($controller ?? app(CatalogController::class), ...$arguments);
    }

    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = Category::query()->create([
            'code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true, 'parent_id' => $parent?->id,
        ]);
        $category->translations()->create(['scope' => Category::SCOPE_CATALOG, 'locale' => 'hr', 'name' => $slug, 'slug' => $slug]);

        return $category;
    }

    private function product(string $code, Category $category, ?Manufacturer $manufacturer = null): Product
    {
        $product = Product::query()->create([
            'code' => $code, 'base_price' => 10, 'stock_qty' => 1, 'is_active' => true, 'manufacturer_id' => $manufacturer?->id,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        $product->categories()->attach($category);

        return $product;
    }
}
