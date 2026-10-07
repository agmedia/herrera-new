<?php

namespace Tests\Feature\Content;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Content\FeaturedCategoriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeaturedCategoriesSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_source_loads_visible_catalog_roots_in_catalog_order(): void
    {
        $last = $this->category('last', 20);
        $first = $this->category('first', 10);
        $this->category('child', 0, ['parent_id' => $first->id]);
        $this->category('inactive', 0, ['is_active' => false]);
        $this->category('future', 0, ['starts_at' => now()->addDay()]);
        $this->category('blog', 0, ['scope' => Category::SCOPE_BLOG]);
        $block = $this->block(['category_source' => 'all_root']);

        $categories = app(FeaturedCategoriesService::class)->forBlock($block, 'en', 'en');

        $this->assertSame([$first->id, $last->id], $categories->pluck('id')->all());
    }

    public function test_legacy_and_manual_sources_keep_selected_order_without_adding_other_roots(): void
    {
        $first = $this->category('first', 10);
        $second = $this->category('second', 20);
        $this->category('not-selected', 0);
        $block = $this->block(null);
        $block->items()->create(['item_type' => 'category', 'item_id' => $second->id, 'sort_order' => 0]);
        $block->items()->create(['item_type' => 'category', 'item_id' => $first->id, 'sort_order' => 1]);

        $categories = app(FeaturedCategoriesService::class)->forBlock($block, 'en', 'en');

        $this->assertSame([$second->id, $first->id], $categories->pluck('id')->all());
        $this->assertSame('manual', FeaturedCategoriesService::source(['category_source' => 'unknown']));
    }

    public function test_tile_layout_can_load_categories_without_product_or_descendant_totals(): void
    {
        $root = $this->category('root', 0);
        $this->category('child', 0, ['parent_id' => $root->id]);
        $block = $this->block(['category_source' => 'all_root']);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $categories = app(FeaturedCategoriesService::class)->forBlock($block, 'en', 'en', includeCounts: false);
        $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertSame([$root->id], $categories->pluck('id')->all());
        $this->assertArrayNotHasKey('products_count', $categories->first()->getAttributes());
        $this->assertArrayNotHasKey('subcategories_count', $categories->first()->getAttributes());
        $this->assertStringNotContainsString('products', $sql);
        $this->assertStringNotContainsString('nested_set_', $sql);
    }

    public function test_default_layout_keeps_product_totals_including_visible_descendants(): void
    {
        $root = $this->category('root', 0);
        $child = $this->category('child', 0, ['parent_id' => $root->id]);
        $product = Product::query()->create(['code' => 'child-product', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 1]);
        $product->categories()->attach($child);

        $categories = app(FeaturedCategoriesService::class)->forBlock($this->block(['category_source' => 'all_root']), 'en', 'en');

        $this->assertSame(1, $categories->first()->products_count);
        $this->assertSame(1, $categories->first()->subcategories_count);
    }

    private function category(string $code, int $order, array $attributes = []): Category
    {
        return Category::query()->create($attributes + [
            'scope' => Category::SCOPE_CATALOG,
            'code' => $code,
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => $order,
        ]);
    }

    private function block(?array $payload): ContentBlock
    {
        return ContentBlock::query()->create([
            'code' => 'category-source-test',
            'name' => 'Category source test',
            'type' => 'featured_categories',
            'is_active' => true,
            'payload' => $payload,
        ]);
    }
}
