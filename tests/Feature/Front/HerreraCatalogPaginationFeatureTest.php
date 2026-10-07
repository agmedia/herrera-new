<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraCatalogPaginationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_sixty_products_per_page_preserves_filters_order_and_unique_results_on_all_catalog_routes(): void
    {
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'catalog_use_manufacturers' => true,
            'front_category_products_per_page_desktop' => 60,
            'front_manufacturer_products_per_page_desktop' => 60,
            'store_product_desktop_default_cols' => 5,
        ]);
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'pagination-approved', 'name' => 'Approved', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        B2BAccount::query()->create([
            'user_id' => $user->id, 'company_name' => 'Test d.o.o.', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
        ]);
        $this->actingAs($user);

        $category = Category::query()->create([
            'code' => 'pagination-category', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true,
        ]);
        $category->translations()->create([
            'locale' => 'hr', 'scope' => Category::SCOPE_CATALOG,
            'name' => 'Pagination category', 'slug' => 'pagination-category',
        ]);
        $manufacturer = Manufacturer::query()->create(['code' => 'pagination-brand', 'is_active' => true]);
        $manufacturer->translations()->create([
            'locale' => 'hr', 'name' => 'Pagination brand', 'slug' => 'pagination-brand',
        ]);

        $expectedIds = [];
        foreach (range(1, 67) as $index) {
            $product = Product::query()->create([
                'code' => 'pagination-product-'.$index, 'is_active' => true,
                'base_price' => 25, 'stock_qty' => $index === 66 ? 0 : 5,
                'supplier_stock_qty' => $index === 66 ? 5 : 0, 'manufacturer_id' => $manufacturer->id,
            ]);
            $product->translations()->create([
                'locale' => 'hr', 'name' => ($index <= 66 ? 'Batch product ' : 'Unrelated item ').$index,
                'slug' => 'pagination-product-'.$index,
            ]);
            $product->categories()->attach($category, ['is_primary' => true]);
            if ($index <= 65) {
                $expectedIds[] = $product->id;
            }
        }

        foreach ([
            route('shop.index'),
            route('categories.show', ['slug' => 'pagination-category']),
            route('manufacturers.show', ['slug' => 'pagination-brand']),
        ] as $url) {
            $response = $this->get($url.'?cols=5&sort=oldest&q=Batch&available_only=1')->assertOk();
            $products = $response->viewData('products');
            $firstIds = $products->pluck('id')->all();

            $this->assertSame(60, $products->perPage());
            $this->assertSame(65, $products->total());
            $this->assertCount(60, $products->items());
            $this->assertSame(array_slice($expectedIds, 0, 60), $firstIds);
            $this->assertSame(2, $products->lastPage());
            parse_str((string) parse_url($products->nextPageUrl(), PHP_URL_QUERY), $nextQuery);
            $this->assertSame(['cols' => '5', 'sort' => 'oldest', 'q' => 'Batch', 'available_only' => '1', 'page' => '2'], $nextQuery);

            $lastProducts = $this->get($products->nextPageUrl())->assertOk()->viewData('products');
            $lastIds = $lastProducts->pluck('id')->all();
            $this->assertSame(60, $lastProducts->perPage());
            $this->assertSame(65, $lastProducts->total());
            $this->assertCount(5, $lastProducts->items());
            $this->assertSame(array_slice($expectedIds, 60), $lastIds);
            $this->assertSame([], array_values(array_intersect($firstIds, $lastIds)));
            $this->assertSame($expectedIds, array_merge($firstIds, $lastIds));
            $this->assertNull($lastProducts->nextPageUrl());
        }
    }
}
