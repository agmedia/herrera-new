<?php

namespace Tests\Feature\Front;

use App\Http\Controllers\Front\CatalogController;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class StorefrontSourceCacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->run();
        config([
            'commerce.b2b_only' => true,
            'storefront_cache.enabled' => true,
            'storefront_cache.store' => 'array',
            'cache.default' => 'array',
        ]);
        Cache::store('array')->flush();
    }

    public function test_catalog_category_source_refreshes_after_a_committed_bulk_translation_update(): void
    {
        $category = $this->categoryWithProduct();
        $this->assertSame('Original category', $this->categories()->first()->translations->first()->name);
        $revision = app(GuestStorefrontCache::class)->revision();

        DB::transaction(function () use ($category, $revision): void {
            DB::table('category_translations')->where('category_id', $category->id)
                ->update(['name' => 'Renamed category']);
            $this->assertSame($revision, app(GuestStorefrontCache::class)->revision());
            $this->assertSame('Original category', $this->categories()->first()->translations->first()->name);
        });

        $this->assertNotSame($revision, app(GuestStorefrontCache::class)->revision());
        $this->assertSame('Renamed category', $this->categories()->first()->translations->first()->name);
    }

    public function test_content_items_refresh_after_a_committed_bulk_update_without_a_block_observer(): void
    {
        $block = $this->blockWithItem();
        $resolver = app(ContentBlockResolver::class);
        $this->assertSame(11, $resolver->forPlacement('home.main', 'hr')->first()['block']->items->first()->item_id);
        $contentVersion = Cache::get('content_blocks:version');
        $revision = app(GuestStorefrontCache::class)->revision();

        DB::transaction(function () use ($block, $revision, $resolver): void {
            DB::table('content_block_items')->where('content_block_id', $block->id)->update(['item_id' => 22]);
            $this->assertSame($revision, app(GuestStorefrontCache::class)->revision());
            $this->assertSame(11, $resolver->forPlacement('home.main', 'hr')->first()['block']->items->first()->item_id);
        });

        $this->assertSame($contentVersion, Cache::get('content_blocks:version'));
        $this->assertNotSame($revision, app(GuestStorefrontCache::class)->revision());
        $this->assertSame(22, $resolver->forPlacement('home.main', 'hr')->first()['block']->items->first()->item_id);
    }

    public function test_disabling_guest_caching_preserves_existing_source_cache_behavior(): void
    {
        config(['storefront_cache.enabled' => false]);
        $category = $this->categoryWithProduct();
        $this->assertSame('Original category', $this->categories()->first()->translations->first()->name);

        DB::table('category_translations')->where('category_id', $category->id)->update(['name' => 'Renamed category']);
        app(GuestStorefrontCache::class)->invalidate();
        $this->travel(3)->seconds();

        $this->assertSame('Original category', $this->categories()->first()->translations->first()->name);
        $this->travelBack();
    }

    public function test_scheduled_category_and_block_expiry_are_rechecked_within_the_source_ttl(): void
    {
        config(['storefront_cache.ttl_seconds' => 2]);
        $category = $this->categoryWithProduct();
        $category->update(['ends_at' => now()->addSecond()]);
        $block = $this->blockWithItem();
        $block->slots()->first()->update(['ends_at' => now()->addSecond()]);
        $resolver = app(ContentBlockResolver::class);
        $this->assertCount(1, $this->categories());
        $this->assertCount(1, $resolver->forPlacement('home.main', 'hr'));
        $revision = app(GuestStorefrontCache::class)->revision();

        $this->travel(3)->seconds();

        $this->assertSame($revision, app(GuestStorefrontCache::class)->revision());
        $this->assertCount(0, $this->categories());
        $this->assertCount(0, $resolver->forPlacement('home.main', 'hr'));
        $this->travelBack();
    }

    public function test_unavailable_guest_cache_does_not_prevent_source_queries(): void
    {
        config(['storefront_cache.store' => 'missing-source-cache-store']);
        $this->categoryWithProduct();
        $this->blockWithItem();

        $this->assertSame('Original category', $this->categories()->first()->translations->first()->name);
        $this->assertSame(11, app(ContentBlockResolver::class)->forPlacement('home.main', 'hr')->first()['block']->items->first()->item_id);
    }

    private function categories(): Collection
    {
        return (new ReflectionMethod(CatalogController::class, 'cachedCatalogCategories'))
            ->invoke(app(CatalogController::class), 'hr', 'hr');
    }

    private function categoryWithProduct(): Category
    {
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG, 'code' => 'source-cache-category', 'is_active' => true,
        ]);
        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG, 'locale' => 'hr',
            'name' => 'Original category', 'slug' => 'source-cache-category',
        ]);
        $product = Product::query()->create([
            'code' => 'source-cache-product', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 5,
        ]);
        $product->categories()->attach($category);

        return $category;
    }

    private function blockWithItem(): ContentBlock
    {
        $block = ContentBlock::query()->create([
            'code' => 'source-cache-block', 'name' => 'Source cache block', 'type' => 'products', 'is_active' => true,
        ]);
        $block->items()->create(['item_type' => 'product', 'item_id' => 11, 'sort_order' => 0]);
        $block->slots()->create(['placement' => 'home.main', 'frontend_variant' => 'all', 'is_active' => true]);

        return $block;
    }
}
