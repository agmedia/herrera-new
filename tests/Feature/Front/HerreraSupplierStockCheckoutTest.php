<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderItem;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Front\OrderStockAllocationService;
use App\Services\Payments\CorvusPayFormService;
use App\Services\Payments\KeksPayService;
use App\Services\Payments\WSPayFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraSupplierStockCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false]);
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Cancelled', 'is_cancelled' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 0, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    #[DataProvider('stockPools')]
    public function test_checkout_records_actual_local_first_reservation(int $local, int $supplier, int $quantity, int $localTake, int $supplierTake): void
    {
        $product = $this->product('BOOK', $local, $supplier);
        $this->assertTrue(app(CartService::class)->add($product, $quantity));

        $order = app(CheckoutService::class)->placeOrder($this->checkoutPayload());
        $item = $order->items()->sole();

        $this->assertSame($local - $localTake, $product->fresh()->stock_qty);
        $this->assertSame($supplier - $supplierTake, $product->fresh()->supplier_stock_qty);
        $this->assertSame($localTake, data_get($item->payload, 'inventory.local_quantity'));
        $this->assertSame($supplierTake, data_get($item->payload, 'inventory.supplier_quantity'));
        $this->assertSame(0, data_get($item->payload, 'inventory.option_quantity'));
        $this->assertNotEmpty(data_get($item->payload, 'inventory.reserved_at'));
        $this->assertNull(data_get($item->payload, 'inventory.restored_at'));
        $this->assertSame('book', data_get($item->payload, 'product_slug'));
        $this->assertIsArray(data_get($item->payload, 'pricing'));
        $this->assertTrue(app(OrderStockAllocationService::class)->restore($item));
        $this->assertSame($local, $product->fresh()->stock_qty);
        $this->assertSame($supplier, $product->fresh()->supplier_stock_qty);
    }

    public static function stockPools(): array
    {
        return ['local only' => [7, 12, 4, 4, 0], 'mixed' => [2, 8, 5, 2, 3], 'supplier only' => [0, 7, 4, 0, 4], 'negative local unchanged' => [-2, 7, 4, 0, 4], 'negative supplier unchanged' => [7, -2, 4, 4, 0]];
    }

    #[DataProvider('cancellationProviders')]
    public function test_cancellation_restores_original_pools_once_after_other_inventory_updates(string $provider): void
    {
        $product = $this->product('REFUND', 2, 8);
        app(CartService::class)->add($product, 5);
        $order = app(CheckoutService::class)->placeOrder($this->checkoutPayload());
        $payload = $order->payload;
        if ($provider === 'corvuspay') {
            $payload['corvuspay'] = ['status' => 'cancelled', 'callback_authorized' => true];
        }
        $order->forceFill(['payment_method_code' => $provider === 'kekspay' ? 'keks' : ($provider === 'corvuspay' ? 'corvus' : $provider), 'payload' => $payload])->save();
        $product->forceFill(['stock_qty' => 5, 'supplier_stock_qty' => 9])->save();

        $this->cancel($provider, $order);
        $this->assertSame(7, $product->fresh()->stock_qty);
        $this->assertSame(12, $product->fresh()->supplier_stock_qty);
        $item = $order->items()->sole();
        $this->assertNotEmpty(data_get($item->payload, 'inventory.restored_at'));
        $this->assertIsArray(data_get($item->payload, 'pricing'));
        $this->assertNotEmpty(data_get($order->fresh()->payload, $provider.'.cancel_restocked_at'));

        $this->cancel($provider, $order->fresh());
        $this->assertFalse(app(OrderStockAllocationService::class)->restore($item));
        $this->assertSame(7, $product->fresh()->stock_qty);
        $this->assertSame(12, $product->fresh()->supplier_stock_qty);
    }

    public static function cancellationProviders(): array
    {
        return ['Keks' => ['kekspay'], 'WSPay' => ['wspay'], 'CorvusPay' => ['corvuspay']];
    }

    public function test_variants_use_only_their_own_inventory_and_restore_it_once(): void
    {
        $product = $this->product('VARIANT', 6, 30);
        $option = $this->variant($product, 5);
        $allocation = DB::transaction(fn () => app(OrderStockAllocationService::class)->reserve($product, 3, $option));
        $item = $this->item($product, 3, $option, ['inventory' => $allocation, 'pricing' => ['source' => 'base']]);

        $this->assertSame(2, $option->fresh()->stock_qty);
        $this->assertSame(6, $product->fresh()->stock_qty);
        $this->assertSame(30, $product->fresh()->supplier_stock_qty);
        $this->assertSame(0, $allocation['local_quantity']);
        $this->assertSame(0, $allocation['supplier_quantity']);
        $this->assertSame(3, $allocation['option_quantity']);
        $this->assertTrue(app(OrderStockAllocationService::class)->restore($item));
        $this->assertFalse(app(OrderStockAllocationService::class)->restore($item));
        $this->assertSame(5, $option->fresh()->stock_qty);
        $this->assertSame(6, $product->fresh()->stock_qty);
        $this->assertSame(30, $product->fresh()->supplier_stock_qty);
    }

    public function test_supplier_inventory_cannot_cover_an_unavailable_variant(): void
    {
        $product = $this->product('EMPTY-VARIANT', 6, 30);
        $option = $this->variant($product, 0);
        $this->assertRejected(fn () => DB::transaction(fn () => app(OrderStockAllocationService::class)->reserve($product, 1, $option)));
        $this->assertSame(0, $option->fresh()->stock_qty);
        $this->assertSame(6, $product->fresh()->stock_qty);
        $this->assertSame(30, $product->fresh()->supplier_stock_qty);
    }

    public function test_fresh_product_or_variant_deactivation_blocks_stale_inventory_reservations(): void
    {
        $product = $this->product('INACTIVE', 6, 30);
        $option = $this->variant($product, 5);
        Product::query()->whereKey($product->id)->update(['is_active' => false]);
        $this->assertRejected(fn () => DB::transaction(fn () => app(OrderStockAllocationService::class)->reserve($product, 1)));
        Product::query()->whereKey($product->id)->update(['is_active' => true]);
        ProductOptionValue::query()->whereKey($option->id)->update(['is_active' => false]);
        $this->assertRejected(fn () => DB::transaction(fn () => app(OrderStockAllocationService::class)->reserve($product, 1, $option)));
        $this->assertSame(5, $option->fresh()->stock_qty);
        $this->assertSame(6, $product->fresh()->stock_qty);
        $this->assertSame(30, $product->fresh()->supplier_stock_qty);
    }

    public function test_checkout_stock_shortage_rolls_back_the_whole_order_and_earlier_reservations(): void
    {
        $first = $this->product('FIRST', 2, 3);
        $second = $this->product('SECOND', 0, 1);
        $lines = collect([
            ['product' => $first, 'translation' => null, 'quantity' => 4, 'unit_price' => 10, 'line_total' => 40],
            ['product' => $second, 'translation' => null, 'quantity' => 2, 'unit_price' => 10, 'line_total' => 20],
        ]);
        $this->mock(CartService::class, function (MockInterface $mock) use ($lines): void {
            $mock->shouldReceive('raw')->andReturn($lines->map(fn (array $line): array => [
                'product_id' => $line['product']->id,
                'product_option_value_id' => null,
                'quantity' => $line['quantity'],
            ])->all());
            $mock->shouldReceive('lines')->andReturn($lines);
            $mock->shouldReceive('summary')->andReturn(['subtotal' => 60]);
        });

        $this->assertRejected(fn () => app(CheckoutService::class)->placeOrder($this->checkoutPayload()));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(2, $first->fresh()->stock_qty);
        $this->assertSame(3, $first->fresh()->supplier_stock_qty);
        $this->assertSame(1, $second->fresh()->supplier_stock_qty);
    }

    public function test_pre_allocation_orders_retain_local_only_restoration_without_touching_supplier_stock(): void
    {
        $product = $this->product('LEGACY', 2, 8);
        $item = $this->item($product, 3, null, ['pricing' => ['source' => 'legacy']]);

        $this->assertTrue(app(OrderStockAllocationService::class)->restore($item));
        $this->assertFalse(app(OrderStockAllocationService::class)->restore($item));
        $this->assertSame(5, $product->fresh()->stock_qty);
        $this->assertSame(8, $product->fresh()->supplier_stock_qty);
        $this->assertTrue(data_get($item->fresh()->payload, 'inventory.legacy_local_fallback'));
        $this->assertSame('legacy', data_get($item->fresh()->payload, 'pricing.source'));
    }

    private function assertRejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Unavailable inventory must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
    }

    private function cancel(string $provider, Order $order): void
    {
        match ($provider) {
            'kekspay' => app(KeksPayService::class)->handleFailureEffects($order),
            'wspay' => app(WSPayFormService::class)->handleCancellationEffects($order),
            'corvuspay' => app(CorvusPayFormService::class)->handleCancellationEffects($order),
        };
    }

    private function product(string $code, int $local, int $supplier): Product
    {
        $product = Product::query()->create(['code' => $code, 'sku' => $code, 'base_price' => 10, 'stock_qty' => $local, 'supplier_stock_qty' => $supplier, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => strtolower($code)]);

        return $product;
    }

    private function variant(Product $product, int $stock): ProductOptionValue
    {
        $option = Option::query()->create(['code' => 'size', 'type' => Option::TYPE_SELECT, 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'large', 'is_active' => true]);

        return ProductOptionValue::query()->create(['product_id' => $product->id, 'option_value_id' => $value->id, 'combination_hash' => 'single:'.$value->id, 'sku' => $product->sku.'-L', 'stock_qty' => $stock, 'is_active' => true]);
    }

    private function item(Product $product, int $quantity, ?ProductOptionValue $option, array $payload): OrderItem
    {
        $order = Order::query()->create(['order_number' => 'STOCK-TEST', 'source' => 'web', 'currency_code' => 'EUR', 'customer_name' => 'Stock Test', 'customer_email' => 'stock-test@example.test', 'item_qty' => $quantity, 'grand_total' => 30]);

        return $order->items()->create(['product_id' => $product->id, 'product_option_value_id' => $option?->id, 'sku' => $product->sku, 'code' => $product->code, 'name' => $product->code, 'quantity' => $quantity, 'unit_price' => 10, 'line_total' => $quantity * 10, 'payload' => $payload]);
    }

    private function checkoutPayload(): array
    {
        return ['customer_email' => 'stock-test@example.test', 'billing_country_code' => 'HR', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'billing_address_line_1' => 'Test1', 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank'];
    }
}
