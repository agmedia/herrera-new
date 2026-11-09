<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductPackage;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Services\Front\CartService;
use App\Support\Ean13Barcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class EanAndBoxOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false]);
    }

    public function test_ean13_uses_valid_check_digit_and_standard_guard_and_digit_patterns(): void
    {
        $barcode = new Ean13Barcode;
        $this->assertSame(
            '101'.'0001101'.'0100111'.'0101111'.'0111101'.'0001001'.'0110011'.'01010'
            .'1000010'.'1000010'.'1000010'.'1110100'.'1000010'.'1100110'.'101',
            $barcode->modules('4006381333931'),
        );
        $this->assertSame(95, strlen($barcode->modules('0123456789012')));
        foreach (['4006381333932', '123', '4e006381333931', '<script>', null] as $invalid) {
            $this->assertNull($barcode->modules($invalid));
        }
        $invalid = Blade::render('<x-ean-barcode value="4006381333932" />');
        $this->assertStringContainsString('4006381333932', $invalid);
        $this->assertStringNotContainsString('<svg', $invalid);
    }

    public function test_customer_can_toggle_scannable_codes_only_for_their_purchased_products(): void
    {
        $customer = User::factory()->create();
        B2BAccount::query()->create([
            'user_id' => $customer->id, 'company_name' => 'Kupac', 'status' => B2BAccount::STATUS_APPROVED,
            'oib' => '12345678901', 'country_code' => 'HR',
        ]);
        $product = $this->product(['barcode' => '4006381333931']);
        $other = $this->product(['code' => 'OTHER', 'sku' => 'OTHER', 'barcode' => '0123456789012']);
        $order = Order::query()->create(['order_number' => 'WEB-EAN-1', 'user_id' => $customer->id, 'customer_name' => $customer->name, 'customer_email' => $customer->email]);
        $order->items()->create(['product_id' => $product->id, 'name' => 'Baterija', 'quantity' => 4, 'unit_price' => 2.5, 'line_total' => 10]);
        $this->actingAs($customer)->get(route('account.b2b.frequent-products'))->assertOk()
            ->assertSee('Prikaži EAN crtične kodove')
            ->assertSee('data-ean-barcode="4006381333931"', false)
            ->assertDontSee($other->barcode);
        $this->actingAs(User::factory()->create())->get(route('account.b2b.frequent-products'))->assertForbidden();
    }

    public function test_box_action_adds_whole_pieces_at_unit_price_and_ignores_piece_quantity_control(): void
    {
        $product = $this->product(['minimum_order_quantity' => 4, 'order_quantity_step' => 4]);
        $package = $this->box($product, 24);
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'product_package_id' => $package->id, 'quantity' => 4])
            ->assertOk()->assertJsonPath('added_quantity', 24);
        $line = app(CartService::class)->lines()->sole();
        $this->assertSame(24, $line['quantity']);
        $this->assertSame(2.5, (float) $line['unit_price']);
        $this->assertSame(60.0, (float) $line['line_total']);
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'product_package_id' => $package->id, 'package_count' => 2])
            ->assertOk()->assertJsonPath('added_quantity', 48);
        $this->assertSame(72, app(CartService::class)->lines()->sole()['quantity']);
    }

    public function test_insufficient_stock_cannot_silently_add_a_partial_box_or_change_the_cart(): void
    {
        $product = $this->product(['stock_qty' => 30]);
        $package = $this->box($product, 24);
        $data = ['product_id' => $product->id, 'product_package_id' => $package->id];
        $this->postJson(route('cart.items.store'), $data)->assertOk();
        $before = app(CartService::class)->raw();
        $this->postJson(route('cart.items.store'), $data)->assertUnprocessable()->assertJsonPath('ok', false);
        $this->assertSame($before, app(CartService::class)->raw());
    }

    public function test_box_respects_pack_steps_and_cart_limits_without_rounding(): void
    {
        $product = $this->product(['minimum_order_quantity' => 4, 'order_quantity_step' => 4, 'stock_qty' => 10000]);
        $bad = $this->box($product, 6);
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'product_package_id' => $bad->id])
            ->assertUnprocessable();
        $this->assertSame([], app(CartService::class)->raw());
        $valid = $this->box($product, 8);
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'product_package_id' => $valid->id, 'package_count' => 125])
            ->assertUnprocessable();
        $this->assertSame([], app(CartService::class)->raw());
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'product_package_id' => $valid->id, 'package_count' => 2])->assertOk();
        $this->assertSame(16, app(CartService::class)->lines()->sole()['quantity']);
    }

    public function test_foreign_inactive_fractional_or_wrong_unit_packages_are_not_purchasable(): void
    {
        $product = $this->product();
        $other = $this->product(['code' => 'OTHER', 'sku' => 'OTHER']);
        $foreign = $this->box($other, 24);
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'product_package_id' => $foreign->id])->assertNotFound();
        $package = $this->box($product, 24);
        foreach ([['quantity' => 2.5], ['quantity' => 24, 'unit_of_measure' => 'm'], ['unit_of_measure' => 'pcs', 'package_type' => 'pack'], ['package_type' => 'box', 'is_active' => false]] as $change) {
            $package->update($change);
            $this->assertFalse(app(CartService::class)->addPackage($product, $package));
        }
        $this->assertSame([], app(CartService::class)->raw());
    }

    public function test_box_buttons_show_on_product_detail_on_mobile_and_desktop_without_exact_stock(): void
    {
        $product = $this->product();
        $package = $this->box($product, 24);
        foreach (['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148'] as $agent) {
            $this->withHeaders(['User-Agent' => $agent])->get(route('products.show', ['slug' => 'battery']))->assertOk()
                ->assertSee('data-product-box-ordering', false)
                ->assertSee('name="product_package_id" value="'.$package->id.'"', false)
                ->assertSee('data-product-box-quantity="24"', false)
                ->assertDontSee('data-product-default-local-stock', false);
        }
    }

    private function product(array $values = []): Product
    {
        $product = Product::query()->create(array_replace([
            'code' => 'BAT', 'sku' => 'BAT', 'is_active' => true, 'base_price' => 2.5,
            'stock_qty' => 100, 'minimum_order_quantity' => 1, 'order_quantity_step' => 1, 'unit_of_measure' => 'pcs',
        ], $values));
        $product->translations()->create(['locale' => 'hr', 'name' => 'Baterija', 'slug' => $product->code === 'BAT' ? 'battery' : 'battery-'.strtolower($product->code)]);

        return $product;
    }

    private function box(Product $product, float $quantity): ProductPackage
    {
        return $product->packages()->create(['code' => 'box-'.$quantity, 'name' => 'Kutija', 'quantity' => $quantity, 'package_type' => 'box', 'unit_of_measure' => 'pcs', 'is_active' => true]);
    }
}
