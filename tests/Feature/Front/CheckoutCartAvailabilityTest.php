<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutCartAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false, 'commerce.local_safe_mode' => true]);
        app()->setLocale('hr');

        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 0, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    #[DataProvider('availabilityChanges')]
    public function test_an_availability_change_rejects_the_whole_requested_cart_before_order_writes(string $change): void
    {
        $unchanged = $this->product('UNCHANGED');
        $changed = $this->product('CHANGED');
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($unchanged, 4));
        $this->assertTrue($cart->add($changed, 5));
        $requested = $cart->raw();
        $this->assertCount(2, $cart->lines());

        match ($change) {
            'shortage' => $changed->update(['stock_qty' => 2]),
            'depleted' => $changed->update(['stock_qty' => 0]),
            'deactivated' => $changed->update(['is_active' => false]),
            'removed' => $changed->delete(),
        };

        $this->assertCartRejectedBeforeWrites();
        $this->assertSame($requested, $cart->raw());
        $this->assertSame(100, $unchanged->fresh()->stock_qty);
        if ($change !== 'removed') {
            $this->assertSame($change === 'shortage' ? 2 : ($change === 'depleted' ? 0 : 100), $changed->fresh()->stock_qty);
        }
    }

    public static function availabilityChanges(): array
    {
        return [
            'stock below requested quantity' => ['shortage'],
            'stock exhausted' => ['depleted'],
            'product deactivated' => ['deactivated'],
            'product removed' => ['removed'],
        ];
    }

    public function test_a_removed_variant_cannot_silently_be_replaced_with_the_base_product(): void
    {
        $product = $this->product('VARIANT');
        $option = Option::query()->create(['code' => 'size', 'type' => Option::TYPE_SELECT, 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'large', 'is_active' => true]);
        $variant = ProductOptionValue::query()->create([
            'product_id' => $product->id, 'option_value_id' => $value->id,
            'combination_hash' => 'single:'.$value->id, 'sku' => 'VARIANT-L', 'stock_qty' => 20, 'is_active' => true,
        ]);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 3, $variant->id));
        $requested = $cart->raw();
        $variant->delete();

        // The display still contains one line and the same quantity, but its identity changed.
        $this->assertCount(1, $cart->lines());
        $this->assertNull($cart->lines()->sole()['product_option_value_id']);
        $this->assertCartRejectedBeforeWrites();
        $this->assertSame($requested, $cart->raw());
        $this->assertSame(100, $product->fresh()->stock_qty);
    }

    public function test_order_placement_refreshes_a_request_snapshot_after_an_external_inventory_change(): void
    {
        $product = $this->product('EXTERNAL-STOCK-CHANGE');
        $cart = app(CartService::class);
        $cart->add($product, 5);
        $this->assertSame(5, $cart->lines()->sole()['quantity']);

        // A concurrent inventory writer cannot invalidate this request's cached display.
        $statement = DB::connection()->getPdo()->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
        $statement->execute([2, $product->id]);
        $this->assertSame(5, $cart->lines()->sole()['quantity']);

        $this->assertCartRejectedBeforeWrites();
        $this->assertSame(2, $product->fresh()->stock_qty);
    }

    public function test_matching_distinct_variants_of_the_same_product_can_be_ordered(): void
    {
        $product = $this->product('VALID-VARIANTS');
        $option = Option::query()->create(['code' => 'size', 'type' => Option::TYPE_SELECT, 'is_active' => true]);
        $variants = [];
        foreach (['small', 'large'] as $code) {
            $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => $code, 'is_active' => true]);
            $variants[] = ProductOptionValue::query()->create([
                'product_id' => $product->id, 'option_value_id' => $value->id,
                'combination_hash' => 'single:'.$value->id, 'sku' => 'VALID-VARIANTS-'.$code, 'stock_qty' => 20, 'is_active' => true,
            ]);
        }
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 3, $variants[0]->id));
        $this->assertTrue($cart->add($product, 4, $variants[1]->id));

        $order = app(CheckoutService::class)->placeOrder($this->checkoutPayload());
        $this->assertCount(2, $order->items);
        $this->assertSame([$variants[0]->id => 3, $variants[1]->id => 4], $order->items->pluck('quantity', 'product_option_value_id')->all());
        $this->assertSame(17, $variants[0]->fresh()->stock_qty);
        $this->assertSame(16, $variants[1]->fresh()->stock_qty);
        $this->assertSame(100, $product->fresh()->stock_qty);
    }

    public function test_a_valid_large_cart_preserves_every_requested_line_and_quantity(): void
    {
        $cart = app(CartService::class);
        $expected = [];
        $expectedQuantity = 0;
        for ($i = 1; $i <= 50; $i++) {
            $product = $this->product('VALID-'.$i);
            $quantity = $i % 7 + 1;
            $this->assertTrue($cart->add($product, $quantity));
            $expected[$product->id] = $quantity;
            $expectedQuantity += $quantity;
        }

        $order = app(CheckoutService::class)->placeOrder($this->checkoutPayload());
        $this->assertCount(50, $order->items);
        $this->assertSame($expected, $order->items->pluck('quantity', 'product_id')->all());
        $this->assertSame($expectedQuantity, (int) $order->item_qty);
        $this->assertSame((float) ($expectedQuantity * 10), (float) $order->grand_total);
        foreach ($order->items as $item) {
            $this->assertNull($item->product_option_value_id);
            $this->assertSame(100 - $expected[$item->product_id], Product::query()->findOrFail($item->product_id)->stock_qty);
        }
    }

    public function test_ajax_checkout_returns_a_visible_cart_validation_error_without_creating_an_order(): void
    {
        $this->changedCart();
        $this->postJson(route('checkout.store'), $this->checkoutPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart')
            ->assertJsonPath('errors.cart.0', __('ui.checkout.validation.cart_changed'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_standard_checkout_redirect_renders_the_cart_error_in_the_top_alert(): void
    {
        $this->changedCart();
        $this->from(route('checkout.create'))
            ->post(route('checkout.store'), $this->checkoutPayload())
            ->assertRedirect(route('checkout.create'))
            ->assertSessionHasErrors(['cart' => __('ui.checkout.validation.cart_changed')]);

        $response = $this->get(route('checkout.create'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $alert = (new \DOMXPath($document))->query('//*[@data-checkout-top-error]')->item(0);
        $this->assertNotNull($alert);
        $this->assertSame(__('ui.checkout.validation.cart_changed'), trim($alert->textContent));
        $this->assertNotContains('hidden', preg_split('/\s+/', trim($alert->getAttribute('class'))));
        $this->assertDatabaseCount('orders', 0);
    }

    private function assertCartRejectedBeforeWrites(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(CheckoutService::class)->placeOrder($this->checkoutPayload());
            $this->fail('Changed cart contents must be reviewed before ordering.');
        } catch (ValidationException $exception) {
            $this->assertSame(['cart' => [__('ui.checkout.validation.cart_changed')]], $exception->errors());
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query'], 'Availability rejection must happen before order or stock writes.');
        }
        foreach (['orders', 'order_items', 'order_totals', 'order_history'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function changedCart(): void
    {
        $cart = app(CartService::class);
        $cart->add($this->product('VALID'), 4);
        $changed = $this->product('SHORTAGE');
        $cart->add($changed, 5);
        $changed->update(['stock_qty' => 2]);
    }

    private function product(string $code): Product
    {
        $product = Product::query()->create(['code' => $code, 'sku' => $code, 'base_price' => 10, 'stock_qty' => 100, 'supplier_stock_qty' => 0, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => strtolower($code)]);

        return $product;
    }

    private function checkoutPayload(): array
    {
        return [
            'customer_email' => 'availability-test@example.test', 'customer_phone' => '+385911234567',
            'billing_first_name' => 'Test', 'billing_last_name' => 'Kupac',
            'billing_address_line_1' => 'Test 1', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'billing_country_code' => 'HR',
            'use_billing_for_shipping' => true, 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank', 'accept_terms' => true,
        ];
    }
}
