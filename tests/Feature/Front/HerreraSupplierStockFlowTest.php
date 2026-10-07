<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraSupplierStockFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
    }

    public function test_supplier_only_product_can_be_added_and_updated_without_fabricating_local_stock(): void
    {
        $this->actingAs($this->customer());
        $product = $this->product('SUPPLIER-ONLY', 0, 17);
        $cart = app(CartService::class);

        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 4])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(4, $cart->lines()->sole()['quantity']);
        $this->assertFalse($cart->set($product, 50));
        $this->assertSame(17, $cart->lines()->sole()['quantity']);
        $this->assertSame(0, $product->fresh()->stock_qty);
        $this->assertSame(17, $product->fresh()->supplier_stock_qty);
    }

    public function test_combined_stock_respects_minimum_and_step_and_zero_stock_remains_unavailable(): void
    {
        $this->actingAs($this->customer());
        $product = $this->product('COMBINED', 2, 7);
        $product->update(['minimum_order_quantity' => 4, 'order_quantity_step' => 2]);
        $cart = app(CartService::class);

        $this->assertTrue($cart->add($product, 7));
        $this->assertSame(8, $cart->lines()->sole()['quantity']);
        $this->assertFalse($cart->set($product, 99));
        $this->assertSame(8, $cart->lines()->sole()['quantity']);
        $this->assertFalse($cart->add($this->product('NONE', 0, 0), 1));
    }

    public function test_supplier_stock_is_included_in_category_filters_sorting_and_detail_projection(): void
    {
        $this->actingAs($this->customer());
        $category = Category::query()->create(['code' => 'construction-lights', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => 'Reflektori', 'slug' => 'supplier-reflektori']);
        $supplier = $this->product('SUPPLIER-LARGE', 0, 123);
        $local = $this->product('LOCAL', 2, 0);
        $empty = $this->product('EMPTY', 0, 0);
        foreach ([$supplier, $local, $empty] as $product) {
            $product->categories()->attach($category->id, ['sort_order' => 0, 'is_primary' => true]);
        }

        $this->get('/category/supplier-reflektori?available_only=1&sort=stock_high')->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$supplier->id, $local->id]);
        $this->get(route('products.show', ['slug' => 'supplier-large']))->assertOk()
            ->assertViewHas('product', fn (Product $product) => $product->supplier_stock_qty === 123 && $product->storefrontIsPurchasable());
        $this->get(route('shop.index', ['available_only' => 1, 'sort' => 'stock_high']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$supplier->id, $local->id]);
    }

    public function test_quick_order_search_includes_supplier_only_stock_but_remains_private(): void
    {
        $customer = $this->customer();
        $product = $this->product('SUPPLIER-SEARCH', 0, 38);
        $this->product('SUPPLIER-EMPTY', 0, 0);

        $this->actingAs($customer)->getJson(route('account.b2b.quick-order.search', ['q' => 'SUPPLIER']))
            ->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.0.maximum_quantity', 38);
        $this->app['auth']->forgetGuards();
        $this->getJson(route('account.b2b.quick-order.search', ['q' => 'SUPPLIER']))->assertUnauthorized();
    }

    public function test_sidebar_uses_actual_header_height_instead_of_a_fixed_offset(): void
    {
        $script = file_get_contents(public_path('front-theme/scripts/category-catalog.js'));
        $style = file_get_contents(public_path('front-theme/styles/category-catalog.css'));
        $herreraStyle = file_get_contents(public_path('front-theme/styles/herrera.css'));
        $this->assertStringContainsString('header.getBoundingClientRect().bottom', $script);
        $this->assertStringContainsString("layout.style.setProperty('--catalog-sidebar-sticky-top'", $script);
        $this->assertStringContainsString('new ResizeObserver(requestMeasure).observe(header)', $script);
        $this->assertStringNotContainsString('--catalog-sidebar-sticky-top: 96px', $style);
        $this->assertStringContainsString('overflow-x: clip;', $herreraStyle);
    }

    private function product(string $code, int $local, int $supplier): Product
    {
        $product = Product::query()->create([
            'code' => $code, 'sku' => $code, 'barcode' => null, 'base_price' => 20,
            'stock_qty' => $local, 'supplier_stock_qty' => $supplier, 'is_active' => true,
            'minimum_order_quantity' => 1, 'order_quantity_step' => 1,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Reflektor '.$code, 'slug' => strtolower($code)]);

        return $product;
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'B2B40', 'name' => 'B2B40', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        B2BAccount::query()->create([
            'user_id' => $user->id, 'status' => B2BAccount::STATUS_APPROVED,
            'company_name' => 'Test d.o.o.', 'oib' => '12345678901', 'customer_group_id' => $group->id,
        ]);

        return $user;
    }
}
