<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderItem;
use App\Models\Sales\Order\OrderTotal;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class HerreraMonetaryPrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
    }

    public static function precisePrices(): array
    {
        return [
            'contract4dp' => ['91.2344', '9123.4400', '2280.8600', '11404.3000'],
            'tax_after_quantity_not_rounded_unit_tax' => ['1.2345', '123.4500', '30.8625', '154.3100'],
        ];
    }

    #[DataProvider('precisePrices')]
    public function test_contract_unit_price_and_tax_keep_precision_until_currency_total(string $price, string $netTotal, string $taxTotal, string $grandTotal): void
    {
        [$user, $group, $product, $catalog] = $this->fixture($price);
        $this->activate($catalog);
        $this->actingAs($user);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 100));
        $line = $cart->lines()->sole();
        $summary = $cart->summary();
        $this->assertSame((float) $price, $line['unit_price']);
        $this->assertSame((float) $netTotal, $line['line_total']);
        $this->assertSame((float) $taxTotal, $line['line_tax_total']);
        $this->assertSame((float) $price, $line['display_unit_price']);
        $this->assertFalse($line['display_includes_tax']);
        $this->assertSame(4, $line['monetary_precision']);
        $this->assertSame((float) $netTotal, $summary['raw_subtotal']);
        $this->assertSame((float) $taxTotal, $summary['raw_tax_total']);
        $this->assertSame((float) $grandTotal, $summary['grand_total']);

        $this->checkoutMethods();
        $order = app(CheckoutService::class)->placeOrder($this->checkoutPayload($user), $user);
        $item = $order->items()->sole();
        $this->assertSame($price, $item->unit_price);
        $this->assertSame($netTotal, $item->line_total);
        $this->assertSame($taxTotal, $item->tax_amount);
        $this->assertSame($netTotal, $order->subtotal);
        $this->assertSame($taxTotal, $order->tax_total);
        $this->assertSame($grandTotal, $order->grand_total);
        $this->assertSame($taxTotal, $order->totals()->where('code', 'tax')->sole()->value);
        $this->assertSame(4, $item->payload['pricing']['monetary_precision']);
        $this->assertSame($catalog->id, $item->payload['pricing']['catalog_id']);
    }

    public function test_options_add_source_delta_after_contract_and_quantity_tier_uses_all_variants(): void
    {
        [$user, $group, $product, $catalog] = $this->fixture('91.2344');
        $catalog->entries()->create(['source_key' => 'qty', 'product_id' => $product->id, 'kind' => 'quantity', 'customer_group_id' => $group->id, 'minimum_quantity' => 5, 'price' => '80.1234', 'priority' => 0, 'is_active' => true]);
        $this->activate($catalog);
        $option = Option::query()->create(['code' => 'size', 'type' => 'select', 'is_active' => true]);
        $firstValue = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'a', 'is_active' => true]);
        $secondValue = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'b', 'is_active' => true]);
        $first = ProductOptionValue::query()->create(['product_id' => $product->id, 'option_value_id' => $firstValue->id, 'sku' => 'PREC-A', 'combination_hash' => sha1('PREC-A'), 'stock_qty' => 100, 'price_override' => '104.6901', 'is_active' => true, 'payload' => ['opencart' => ['price' => '4.5667', 'price_prefix' => '+']]]);
        $second = ProductOptionValue::query()->create(['product_id' => $product->id, 'option_value_id' => $secondValue->id, 'sku' => 'PREC-B', 'combination_hash' => sha1('PREC-B'), 'stock_qty' => 100, 'price_override' => '98.8889', 'is_active' => true, 'payload' => ['opencart' => ['price' => '1.2345', 'price_prefix' => '-']]]);
        $this->actingAs($user);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 3, $first->id));
        $this->assertTrue($cart->add($product, 2, $second->id));
        $lines = $cart->lines()->keyBy('product_option_value_id');
        $this->assertSame(84.6901, $lines[$first->id]['unit_price']);
        $this->assertSame(78.8889, $lines[$second->id]['unit_price']);
        $this->assertSame('price_catalog_quantity', $lines[$first->id]['b2b_source_type']);
        $this->assertSame('price_catalog_quantity', $lines[$second->id]['b2b_source_type']);
        $this->assertSame(254.0703, $lines[$first->id]['line_total']);
        $this->assertSame(157.7778, $lines[$second->id]['line_total']);
    }

    public function test_source_history_and_base_price_are_not_truncated_but_retail_casts_remain_compatible(): void
    {
        [, , $product] = $this->fixture('91.2344');
        $this->assertSame('100.1234', $product->fresh()->base_price);
        $order = Order::query()->create(['order_number' => 'HIST-1', 'source' => 'opencart', 'customer_name' => 'Historical', 'customer_email' => 'historical@example.test', 'subtotal' => '123.4567', 'tax_total' => '30.8642', 'grand_total' => '154.3209']);
        $item = OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'name' => 'Historical item', 'unit_price' => '1.2345', 'quantity' => 100, 'line_total' => '123.4500', 'tax_amount' => '30.8625']);
        $total = OrderTotal::query()->create(['order_id' => $order->id, 'code' => 'tax', 'title' => 'Tax', 'value' => '30.8642']);
        $this->assertSame('123.4567', $order->fresh()->subtotal);
        $this->assertSame('154.3209', $order->fresh()->grand_total);
        $this->assertSame('1.2345', $item->fresh()->unit_price);
        $this->assertSame('30.8625', $item->fresh()->tax_amount);
        $this->assertSame('30.8642', $total->fresh()->value);
        config(['commerce.b2b_only' => false]);
        $this->assertSame('100.12', $product->base_price);
        $this->assertSame('123.46', $order->subtotal);
        $this->assertSame('1.23', $item->unit_price);
        $this->assertSame('30.86', $total->value);
        // Switching presentation mode never rewrites the stored financial values.
        config(['commerce.b2b_only' => true]);
        $this->assertSame('123.4567', $order->fresh()->subtotal);
    }

    private function fixture(string $price): array
    {
        $group = CustomerGroup::query()->create(['code' => 'partners', 'name' => 'Partneri', 'is_active' => true]);
        $user = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Partner', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);
        $tax = TaxRate::query()->create(['code' => 'VAT25', 'name' => 'PDV 25%', 'rate_type' => 'percent', 'rate' => 25, 'is_active' => true, 'is_default' => true]);
        $product = Product::query()->create(['code' => 'PREC', 'sku' => 'PREC', 'is_active' => true, 'base_price' => '100.1234', 'tax_rate_id' => $tax->id, 'stock_qty' => 1000]);
        $catalog = PriceCatalog::query()->create(['name' => 'Precision snapshot', 'status' => 'draft', 'currency_code' => 'EUR']);
        $catalog->entries()->create(['source_key' => 'group', 'product_id' => $product->id, 'kind' => 'group', 'customer_group_id' => $group->id, 'minimum_quantity' => 1, 'price' => $price, 'priority' => 0, 'is_active' => true]);

        return [$user, $group, $product, $catalog];
    }

    private function activate(PriceCatalog $catalog): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        app(PriceCatalogService::class)->activate($catalog, $admin);
    }

    private function checkoutMethods(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 0, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Bank transfer', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    private function checkoutPayload(User $user): array
    {
        return ['customer_email' => $user->email, 'billing_country_code' => 'HR', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'billing_address_line_1' => 'Test 1', 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank'];
    }
}
