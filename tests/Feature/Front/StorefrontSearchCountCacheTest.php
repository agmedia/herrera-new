<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Services\Front\StorefrontSearchCountCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorefrontSearchCountCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_search_pages_reuse_only_the_count_and_keep_cards_current(): void
    {
        config(['storefront-search.count_cache_seconds' => 30]);
        $product = Product::query()->create([
            'code' => 'count-fan', 'sku' => 'FAN-1', 'is_active' => true,
            'base_price' => 10, 'stock_qty' => 5,
        ]);
        $search = Product::query()->where('sku', 'like', 'FAN-%');
        $service = app(StorefrontSearchCountCache::class);
        DB::enableQueryLog();

        $this->assertSame(1, $service->total($search));
        DB::flushQueryLog();
        $product->update(['base_price' => 25, 'stock_qty' => 3]);
        DB::flushQueryLog();

        $page = (clone $search)->orderByDesc('id')->paginate(60, total: $service->total($search));
        $this->assertSame(1, $page->total());
        $this->assertSame(25.0, (float) $page->first()->base_price);
        $this->assertSame(3, $page->first()->stock_qty);
        $this->assertSame([], $this->countQueries());
    }

    public function test_filters_and_customer_audiences_do_not_share_cached_counts(): void
    {
        Product::query()->create(['code' => 'count-one', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 5]);
        Product::query()->create(['code' => 'count-two', 'is_active' => true, 'base_price' => 20, 'stock_qty' => 0]);
        $service = app(StorefrontSearchCountCache::class);
        $search = Product::query()->where('code', 'like', 'count-%');
        $this->assertSame(2, $service->total($search));
        $this->assertSame(1, $service->total((clone $search)->where('stock_qty', '>', 0)));
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame(2, $service->total($search, 42));
        $this->assertCount(1, $this->countQueries());
        DB::flushQueryLog();
        $this->assertSame(2, $service->total($search, 42));
        $this->assertSame([], $this->countQueries());
        $this->assertSame(2, $service->total($search, 43));
        $this->assertCount(1, $this->countQueries());
    }

    public function test_search_count_expires_and_caching_can_be_disabled(): void
    {
        config(['storefront-search.count_cache_seconds' => 30]);
        $service = app(StorefrontSearchCountCache::class);
        $search = Product::query()->where('code', 'like', 'expiry-%');
        $this->assertSame(0, $service->total($search));
        Product::query()->create(['code' => 'expiry-one', 'is_active' => true, 'base_price' => 10]);
        $this->assertSame(0, $service->total($search));
        $this->travel(31)->seconds();
        $this->assertSame(1, $service->total($search));
        $this->travelBack();
        config(['storefront-search.count_cache_seconds' => 0]);
        Product::query()->create(['code' => 'expiry-two', 'is_active' => true, 'base_price' => 10]);
        $this->assertSame(2, $service->total($search));
    }

    private function countQueries(): array
    {
        return array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains(strtolower($query['query']), 'count(*)')));
    }
}
