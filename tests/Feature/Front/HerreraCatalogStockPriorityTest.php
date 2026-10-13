<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraCatalogStockPriorityTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'catalog_hide_out_of_stock_products' => true,
        ]);
        $buyer = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'stock-priority', 'name' => 'Approved', 'is_active' => true]);
        B2BAccount::query()->create([
            'user_id' => $buyer->id, 'status' => B2BAccount::STATUS_APPROVED,
            'company_name' => 'Test d.o.o.', 'oib' => '12345678901', 'customer_group_id' => $group->id,
        ]);
        $this->actingAs($buyer);
        $this->category = Category::query()->create([
            'code' => 'stock-priority', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true,
        ]);
        $this->category->translations()->create([
            'locale' => 'hr', 'scope' => Category::SCOPE_CATALOG,
            'name' => 'Reflektori', 'slug' => 'stock-priority',
        ]);
    }

    #[DataProvider('selectedSorts')]
    public function test_local_and_active_variant_stock_precede_supplier_only_stock_while_preserving_selected_sort(string $sort, array $order): void
    {
        $products = [
            'supplier-cheap' => $this->product('supplier-cheap', 1, 0, 100),
            'local-expensive' => $this->product('local-expensive', 30, 1, 0),
            'active-variant' => $this->product('active-variant', 10, 0, 0),
            'supplier-expensive' => $this->product('supplier-expensive', 20, 0, 10),
            'local-cheap' => $this->product('local-cheap', 5, 2, 0),
            'inactive-variant' => $this->product('inactive-variant', 12, 0, 8),
        ];
        $this->variant($products['active-variant'], 2, true);
        $this->variant($products['inactive-variant'], 9, false);
        $this->product('empty', 0.50, 0, 0);
        $expected = array_map(fn (string $key): int => $products[$key]->id, $order);

        foreach ($this->catalogUrls(['sort' => $sort]) as $url) {
            $this->get($url)->assertOk()->assertViewHas('products',
                fn ($page): bool => $page->pluck('id')->all() === $expected && $page->total() === 6);
        }
    }

    public static function selectedSorts(): array
    {
        return [
            'oldest' => ['oldest', ['local-expensive', 'active-variant', 'local-cheap', 'supplier-cheap', 'supplier-expensive', 'inactive-variant']],
            'newest' => ['newest', ['local-cheap', 'active-variant', 'local-expensive', 'inactive-variant', 'supplier-expensive', 'supplier-cheap']],
            'price ascending' => ['price_low', ['local-cheap', 'active-variant', 'local-expensive', 'supplier-cheap', 'inactive-variant', 'supplier-expensive']],
            'price descending' => ['price_high', ['local-expensive', 'active-variant', 'local-cheap', 'supplier-expensive', 'inactive-variant', 'supplier-cheap']],
            'stock descending' => ['stock_high', ['local-cheap', 'local-expensive', 'active-variant', 'supplier-cheap', 'supplier-expensive', 'inactive-variant']],
        ];
    }

    public function test_search_relevance_is_preserved_inside_each_availability_group(): void
    {
        $supplierExact = $this->product('supplier-exact', 5, 0, 4, 'Reflektor');
        $localPartial = $this->product('local-partial', 10, 1, 0, 'Zidni reflektor');
        $localExact = $this->product('local-exact', 15, 1, 0, 'Reflektor');
        $supplierPartial = $this->product('supplier-partial', 1, 0, 8, 'Zidni reflektor');
        $expected = [$localExact->id, $localPartial->id, $supplierExact->id, $supplierPartial->id];

        foreach ($this->catalogUrls(['q' => 'reflektor']) as $url) {
            $this->get($url)->assertOk()->assertViewHas('products',
                fn ($page): bool => $page->pluck('id')->all() === $expected);
        }
    }

    public function test_category_default_sort_preserves_category_order_within_availability_groups(): void
    {
        $supplier = $this->product('supplier-first', 5, 0, 5);
        $localLater = $this->product('local-later', 5, 1, 0);
        $localEarlier = $this->product('local-earlier', 5, 1, 0);
        $supplier->categories()->updateExistingPivot($this->category->id, ['sort_order' => 0]);
        $localLater->categories()->updateExistingPivot($this->category->id, ['sort_order' => 20]);
        $localEarlier->categories()->updateExistingPivot($this->category->id, ['sort_order' => 10]);

        $this->get(route('categories.show', ['slug' => 'stock-priority']))->assertOk()
            ->assertViewHas('products', fn ($page): bool => $page->pluck('id')->all() === [$localEarlier->id, $localLater->id, $supplier->id]);
    }

    #[DataProvider('paginationSorts')]
    public function test_pagination_crosses_availability_groups_without_duplicates_or_missing_products(string $sort): void
    {
        app(SystemSettingsService::class)->put('front_category_products_per_page_desktop', 4);
        $locals = [];
        $suppliers = [];
        foreach (range(1, 9) as $index) {
            $local = $index % 2 === 0;
            $product = $this->product('page-'.$index, 10, $local ? 1 : 0, $local ? 0 : 1);
            if ($local) {
                $locals[] = $product->id;
            } else {
                $suppliers[] = $product->id;
            }
        }
        $expected = [...$locals, ...$suppliers];

        foreach ($this->catalogUrls(['sort' => $sort]) as $url) {
            $actual = [];
            do {
                $page = $this->get($url)->assertOk()->viewData('products');
                $this->assertSame(9, $page->total());
                $actual = [...$actual, ...$page->pluck('id')->all()];
                $url = $page->nextPageUrl();
            } while ($url !== null);
            $this->assertSame($expected, $actual);
            $this->assertCount(9, array_unique($actual));
        }
    }

    public static function paginationSorts(): array
    {
        return [
            'oldest' => ['oldest'],
            'equal prices' => ['price_low'],
        ];
    }

    public function test_empty_products_hide_and_return_when_local_supplier_or_active_variant_stock_returns(): void
    {
        $local = $this->product('return-local', 10, 0, 0);
        $supplier = $this->product('return-supplier', 10, 0, 0);
        $variant = $this->product('return-variant', 10, 0, 0);
        $inactive = $this->product('inactive-stock', 10, 0, 0);
        $this->variant($inactive, 10, false);
        foreach ($this->catalogUrls() as $url) {
            $this->get($url)->assertOk()->assertViewHas('products', fn ($page): bool => $page->isEmpty());
        }

        $local->update(['stock_qty' => 1]);
        $supplier->update(['supplier_stock_qty' => 5]);
        $variantStock = $this->variant($variant, 3, true);
        foreach ($this->catalogUrls(['sort' => 'oldest']) as $url) {
            $this->get($url)->assertOk()->assertViewHas('products',
                fn ($page): bool => $page->pluck('id')->all() === [$local->id, $variant->id, $supplier->id]);
        }

        $local->update(['stock_qty' => 0]);
        $supplier->update(['supplier_stock_qty' => 0]);
        $variantStock->update(['stock_qty' => 0]);
        foreach ($this->catalogUrls() as $url) {
            $this->get($url)->assertOk()->assertViewHas('products', fn ($page): bool => $page->isEmpty());
        }
    }

    public function test_other_stores_keep_the_selected_sort_without_herrera_stock_priority(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Other store');
        $supplier = $this->product('other-supplier', 1, 0, 10);
        $local = $this->product('other-local', 10, 1, 0);
        foreach ($this->catalogUrls(['sort' => 'price_low']) as $url) {
            $this->get($url)->assertOk()->assertViewHas('products',
                fn ($page): bool => $page->pluck('id')->all() === [$supplier->id, $local->id]);
        }
    }

    public function test_unavailable_active_product_keeps_its_direct_url_while_lists_and_search_follow_stock(): void
    {
        // Exercise live stock queries independently of the search aggregate's bounded TTL.
        config(['storefront-search.count_cache_seconds' => 0]);
        app(SystemSettingsService::class)->put('store_search_autocomplete_enabled', true);
        $product = $this->product('url-stock-empty', 20, 0, 0);
        $this->variant($product, 0, true);
        $inactive = $this->product('inactive-direct-url', 20, 5, 0);
        $inactive->update(['is_active' => false]);

        $this->get(route('products.show', ['slug' => $product->code]))->assertOk()
            ->assertViewHas('product', fn (Product $shown): bool => $shown->id === $product->id)
            ->assertSee('product-detail-cart-button-disabled', false)->assertSee(__('ui.product.unavailable'));
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(422)->assertJsonPath('ok', false);
        $this->get(route('products.show', ['slug' => $inactive->code]))->assertNotFound();

        $urls = [...$this->catalogUrls(), ...$this->catalogUrls(['q' => $product->code])];
        foreach ($urls as $url) {
            $this->get($url)->assertOk()->assertViewHas('products', fn ($page): bool => ! $page->pluck('id')->contains($product->id));
        }
        $this->getJson(route('search.autocomplete', ['q' => $product->code]))->assertOk()->assertJsonPath('total', 0);

        $product->update(['supplier_stock_qty' => 4]);
        foreach ($urls as $url) {
            $page = $this->get($url)->assertOk()->viewData('products');
            $this->assertContains($product->id, $page->pluck('id')->all(), $url);
        }
        $this->getJson(route('search.autocomplete', ['q' => $product->code]))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $product->id);
        $this->get(route('products.show', ['slug' => $product->code]))->assertOk();
        $this->get(route('products.show', ['slug' => $inactive->code]))->assertNotFound();
    }

    private function product(string $code, float $price, int $local, int $supplier, ?string $name = null): Product
    {
        $product = Product::query()->create([
            'code' => $code, 'sku' => strtoupper($code), 'is_active' => true,
            'base_price' => $price, 'stock_qty' => $local, 'supplier_stock_qty' => $supplier,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => $name ?? 'Reflektor '.$code, 'slug' => $code]);
        $product->categories()->attach($this->category, ['sort_order' => 0, 'is_primary' => true]);

        return $product;
    }

    private function variant(Product $product, int $stock, bool $active): ProductOptionValue
    {
        $option = Option::query()->firstOrCreate(['code' => 'stock-option'], ['type' => Option::TYPE_SELECT, 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'stock-'.$product->id, 'is_active' => true]);

        return ProductOptionValue::query()->create([
            'product_id' => $product->id, 'option_value_id' => $value->id,
            'sku' => 'VARIANT-'.$product->id, 'stock_qty' => $stock, 'is_active' => $active,
            'combination_hash' => hash('sha256', 'stock-'.$product->id),
        ]);
    }

    private function catalogUrls(array $query = []): array
    {
        return [
            route('shop.index', $query),
            route('categories.show', ['slug' => 'stock-priority', ...$query]),
        ];
    }
}
