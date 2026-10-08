<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductRecommendationQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'app.locale' => 'hr']);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'catalog_hide_out_of_stock_products' => true,
        ]);
    }

    public function test_recommendation_sources_keep_their_order_and_share_one_card_data_batch_with_recent_history(): void
    {
        $root = $this->category('root');
        $leaf = $this->category('leaf', $root);
        $sibling = $this->category('sibling', $root);
        $otherRoot = $this->category('other-root');
        $main = $this->product('main', $leaf);
        $explicit = $this->product('explicit', $otherRoot);
        $sameLeaf = collect(range(1, 3))->map(fn ($index) => $this->product('leaf-'.$index, $leaf));
        $sameRoot = collect(range(1, 5))->map(fn ($index) => $this->product('sibling-'.$index, $sibling));
        $recentOnly = $this->product('recent-only', $otherRoot);
        $latest = collect(range(1, 3))->map(fn ($index) => $this->product('latest-'.$index, $otherRoot));
        $inactive = $this->product('inactive', $leaf, active: false);
        $emptyStock = $this->product('empty-stock', $leaf, stock: 0);
        $main->update(['payload' => ['related_product_ids' => [$inactive->id, $emptyStock->id, $explicit->id]]]);

        $cardBatches = [];
        DB::listen(function (QueryExecuted $query) use (&$cardBatches): void {
            if (preg_match('/\bfrom\s+["`]?product_energy_declarations["`]?\b/i', $query->sql)) {
                $cardBatches[] = $this->energyQueryProductIds($query->sql);
            }
        });

        $recentIds = [$main->id, $explicit->id, $inactive->id, $emptyStock->id, 999999, $recentOnly->id, $latest->first()->id, $explicit->id];
        $expectedRelated = collect([$explicit->id])
            ->concat($sameLeaf->reverse()->pluck('id'))
            ->concat($sameRoot->reverse()->pluck('id'))
            ->concat($latest->reverse()->pluck('id'))
            ->all();

        $response = $this->withSession(['front_recently_viewed_products' => $recentIds])
            ->get(route('products.show', ['slug' => 'recommendation-main']))
            ->assertOk()
            ->assertViewHas('related', fn ($products) => $products->pluck('id')->all() === $expectedRelated)
            ->assertViewHas('recentlyViewed', fn ($products) => $products->pluck('id')->all() === [$explicit->id, $recentOnly->id, $latest->first()->id])
            ->assertSessionHas('front_recently_viewed_products', [$main->id, $explicit->id, $inactive->id, $emptyStock->id, 999999, $recentOnly->id, $latest->first()->id]);

        $response->assertViewHas('related', fn ($products) => $products->every(fn (Product $product) => $product->relationLoaded('energyDeclarations')
            && $product->relationLoaded('media')
            && $product->relationLoaded('translations')
            && $product->relationLoaded('optionValues')));
        $this->assertCount(2, $cardBatches, 'Load energy data once for the main product and once for all recommendation cards.');
        $this->assertEqualsCanonicalizing([...$expectedRelated, $recentOnly->id], $cardBatches[1]);
    }

    public function test_a_full_explicit_list_only_hydrates_displayed_products_and_skips_category_ranking(): void
    {
        $category = $this->category('full');
        $main = $this->product('main', $category);
        $explicit = collect(range(1, 16))->map(fn ($index) => $this->product('explicit-'.$index, $category))->reverse()->values();
        $main->update(['payload' => ['related_product_ids' => $explicit->pluck('id')->all()]]);

        $cardBatches = [];
        $depthQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$cardBatches, &$depthQueries): void {
            if (preg_match('/\bfrom\s+["`]?product_energy_declarations["`]?\b/i', $query->sql)) {
                $cardBatches[] = $this->energyQueryProductIds($query->sql);
            }
            if (str_contains(strtolower($query->sql), ' as "depth"') || str_contains(strtolower($query->sql), ' as `depth`')) {
                $depthQueries[] = $query->sql;
            }
        });

        $expected = $explicit->take(12)->pluck('id')->all();
        $this->get(route('products.show', ['slug' => 'recommendation-main']))
            ->assertOk()
            ->assertViewHas('related', fn ($products) => $products->pluck('id')->all() === $expected);

        $this->assertCount(2, $cardBatches);
        $this->assertEqualsCanonicalizing($expected, $cardBatches[1], 'Unused explicit recommendations must not load card relations.');
        $this->assertSame([], $depthQueries, 'A complete imported recommendation list does not need category ranking.');
    }

    /** @return array<int, int> */
    private function energyQueryProductIds(string $sql): array
    {
        preg_match('/["`]?product_id["`]?\s+in\s*\(([\d,\s]+)\)/i', $sql, $matches);

        return array_map('intval', explode(',', $matches[1] ?? ''));
    }

    private function category(string $code, ?Category $parent = null): Category
    {
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'recommendation-'.$code,
            'is_active' => true,
            'parent_id' => $parent?->id,
        ]);
        $category->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => 'recommendation-'.$code]);

        return $category;
    }

    private function product(string $code, Category $category, bool $active = true, int $stock = 3): Product
    {
        $product = Product::query()->create([
            'code' => 'recommendation-'.$code,
            'is_active' => $active,
            'base_price' => 50,
            'stock_qty' => $stock,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => 'recommendation-'.$code]);
        $product->categories()->attach($category, ['is_primary' => true]);

        return $product;
    }
}
