<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Front\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CartRequestSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['commerce.b2b_only' => false]);
    }

    public function test_independent_services_share_lines_only_within_the_same_request(): void
    {
        $product = $this->product();
        $cart = app(CartService::class);
        $cart->replaceRaw([['product_id' => $product->id, 'quantity' => 3]]);
        $lines = $cart->lines();
        $lines->pop();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(1, app(CartService::class)->lines());
        $this->assertSame(30.0, app(CartService::class)->summary()['subtotal']);
        $this->assertCount(0, DB::getQueryLog());

        $this->app->instance('request', Request::create('/next-cart-request'));
        $this->assertCount(1, $cart->lines());
        $this->assertNotEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_direct_session_changes_and_cart_mutations_refresh_quantities(): void
    {
        $product = $this->product();
        $cart = app(CartService::class);
        $cart->replaceRaw([['product_id' => $product->id, 'quantity' => 2]]);
        $this->assertSame(2, $cart->lines()->sole()['quantity']);

        Session::put('front.cart.items', [$product->id.':0' => [
            'product_id' => $product->id, 'product_option_value_id' => null, 'quantity' => 4,
        ]]);
        $this->assertSame(4, $cart->lines()->sole()['quantity']);
        $this->assertTrue($cart->set($product, 5));
        $this->assertSame(5, $cart->lines()->sole()['quantity']);
        $cart->remove($product->id);
        $this->assertTrue($cart->lines()->isEmpty());
        $this->assertTrue($cart->add($product, 2));
        $this->assertSame(2, $cart->lines()->sole()['quantity']);
        $cart->clear();
        $this->assertTrue($cart->lines()->isEmpty());
    }

    public function test_database_stock_and_price_writes_refresh_the_same_request_snapshot(): void
    {
        $product = $this->product();
        $cart = app(CartService::class);
        $cart->replaceRaw([['product_id' => $product->id, 'quantity' => 8]]);
        $this->assertSame(8, $cart->lines()->sole()['quantity']);

        DB::table($product->getTable())->where('id', $product->id)->update(['stock_qty' => 3, 'base_price' => 12]);
        $line = $cart->lines()->sole();
        $this->assertSame(3, $line['quantity']);
        $this->assertSame(12.0, $line['unit_price']);
        $this->assertSame(8, $cart->raw()[$product->id.':0']['quantity']);

        $product->update(['stock_qty' => 0]);
        $this->assertTrue($cart->lines()->isEmpty());
    }

    public function test_coupon_and_locale_are_part_of_the_snapshot_context(): void
    {
        $product = $this->product();
        $product->translations()->create(['locale' => 'en', 'name' => 'English product', 'slug' => 'english-product']);
        CatalogAction::query()->create([
            'code' => 'snapshot-coupon', 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_ALL, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'coupon_code' => 'SAVE10', 'is_active' => true,
        ]);
        $cart = app(CartService::class);
        $cart->replaceRaw([['product_id' => $product->id, 'quantity' => 1]]);
        $this->assertSame(10.0, $cart->lines('hr')->sole()['unit_price']);
        $this->assertSame('English product', $cart->lines('en')->sole()['translation']->name);
        $this->assertTrue($cart->applyCoupon('save10'));
        $this->assertSame(9.0, $cart->lines('hr')->sole()['unit_price']);
        $cart->clearCoupon();
        $this->assertSame(10.0, $cart->lines('hr')->sole()['unit_price']);
    }

    public function test_switching_customer_cannot_reuse_another_customers_promotion(): void
    {
        $product = $this->product();
        $first = User::factory()->create();
        $second = User::factory()->create();
        CatalogAction::query()->create([
            'code' => 'snapshot-customer', 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 20,
            'target_type' => CatalogAction::TARGET_ALL, 'audience_type' => CatalogAction::AUDIENCE_USER,
            'user_id' => $first->id, 'is_active' => true,
        ]);
        $cart = app(CartService::class);
        $cart->replaceRaw([['product_id' => $product->id, 'quantity' => 1]]);
        $this->actingAs($first);
        $this->assertSame(8.0, $cart->lines()->sole()['unit_price']);
        $this->actingAs($second);
        $this->assertSame(10.0, $cart->lines()->sole()['unit_price']);
    }

    private function product(): Product
    {
        $product = Product::query()->create([
            'code' => 'SNAPSHOT', 'sku' => 'SNAPSHOT', 'is_active' => true,
            'base_price' => 10, 'stock_qty' => 20,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Testni proizvod', 'slug' => 'snapshot']);

        return $product;
    }
}
