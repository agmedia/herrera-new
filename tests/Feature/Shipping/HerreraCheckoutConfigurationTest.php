<?php

namespace Tests\Feature\Shipping;

use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Import\HerreraCheckoutConfigurationService;
use App\Services\Shipping\ShippingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraCheckoutConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false]);
        $this->configure();
    }

    public function test_only_source_payment_is_active_and_reconfiguration_does_not_duplicate_records(): void
    {
        $this->assertSame(['bank'], PaymentMethod::query()->where('is_active', true)->pluck('code')->all());
        $wspay = PaymentMethod::query()->where('code', 'wspay')->sole();
        $this->assertFalse($wspay->is_active);
        $this->assertSame('test', $wspay->settings['wspay_mode']);
        $this->assertSame('fixture-secret', $wspay->settings['wspay_secret_key']);
        $this->assertSame('Virman - Uplatnica – Za tvrtke šaljemo ponudu.', PaymentMethod::query()->where('code', 'bank')->value('description'));
        $counts = [ShippingMethod::query()->count(), GeoZoneCountry::query()->count(), PaymentMethod::query()->count()];
        $this->configure();
        $this->assertSame($counts, [ShippingMethod::query()->count(), GeoZoneCountry::query()->count(), PaymentMethod::query()->count()]);
        $this->assertSame(4, ShippingMethod::query()->where('is_active', true)->count());
    }

    public static function destinations(): array
    {
        return [
            'hr_paid_boundary' => ['HR', 150.0, 150.0, 'standard', 5.0],
            'hr_free_boundary' => ['HR', 150.01, 140.0, 'herrera-shipping-1-2', 0.0],
            'hr_original_range_gap' => ['HR', 150.005, 150.005, null, null],
            'si_paid_boundary' => ['SI', 200.0, 200.0, 'standard_eu', 15.0],
            'si_original_range_gap' => ['SI', 200.50, 200.50, null, null],
            'si_free_boundary' => ['SI', 201.0, 100.0, 'herrera-shipping-2-2', 0.0],
            'ch_source_destination' => ['CH', 50.0, 50.0, 'standard_eu', 15.0],
            'unconfigured_destination' => ['US', 50.0, 50.0, null, null],
        ];
    }

    #[DataProvider('destinations')]
    public function test_shipping_preserves_source_ranges_destinations_and_before_discount_basis(string $country, float $beforeDiscount, float $afterDiscount, ?string $code, ?float $price): void
    {
        $checkout = $this->checkout($beforeDiscount);
        $methods = $checkout->availableShippingMethods($afterDiscount, $country);
        if ($code === null) {
            $this->assertCount(0, $methods);
        } else {
            $this->assertCount(1, $methods);
            $this->assertSame($code, $methods->sole()->code);
            $this->assertSame($price, $methods->sole()->getAttribute('resolved_price'));
        }
    }

    public function test_estimate_includes_shipping_vat_once_and_foreign_shipping_remains_untaxed(): void
    {
        $checkout = $this->checkout(100);
        $hr = $checkout->estimateCheckoutTotals(100, 0, 100, 25, 'standard', 'bank', 'HR', null, '10000', 'HR', null, '10000');
        $this->assertSame(5.0, $hr['shipping_total']);
        $this->assertSame(1.25, $hr['shipping_tax_total']);
        $this->assertSame(26.25, $hr['tax_total']);
        $this->assertSame(131.25, $hr['grand_total']);
        $si = $checkout->estimateCheckoutTotals(100, 0, 100, 0, 'standard_eu', 'bank', 'SI', null, '1000', 'SI', null, '1000');
        $this->assertSame(0.0, $si['shipping_tax_total']);
        $this->assertSame(115.0, $si['grand_total']);
    }

    public function test_shipping_tax_respects_the_imported_contract_tax_classes(): void
    {
        config(['commerce.b2b_only' => true]);
        $group = CustomerGroup::query()->create(['code' => 'zero-vat', 'name' => 'Zero VAT', 'is_active' => true,
            'payload' => ['opencart' => ['tax_scope_defined' => true, 'taxable_class_ids' => []]]]);
        $user = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $user->id, 'company_name' => 'Partner', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);
        $this->actingAs($user);
        $checkout = $this->checkout(100);
        $totals = $checkout->estimateCheckoutTotals(100, 0, 100, 0, 'standard', 'bank', 'HR', null, '10000', 'HR', null, '10000');
        $this->assertSame(0.0, $totals['shipping_tax_total']);
        $this->assertSame(105.0, $totals['grand_total']);
    }

    public function test_placed_order_persists_shipping_tax_and_matching_total_rows(): void
    {
        $tax = TaxRate::query()->create(['code' => 'shipping-test-vat', 'name' => 'VAT', 'rate_type' => 'percent', 'rate' => 25, 'is_active' => true, 'is_default' => true]);
        $product = Product::query()->create(['code' => 'SHIPPING-TEST', 'sku' => 'SHIPPING-TEST', 'base_price' => 100, 'stock_qty' => 2, 'weight_kg' => 1, 'is_active' => true, 'tax_rate_id' => $tax->id]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Shipping test', 'slug' => 'shipping-test']);
        $this->assertTrue(app(CartService::class)->add($product, 1));
        $order = app(CheckoutService::class)->placeOrder(['customer_email' => 'shipping@example.test', 'billing_country_code' => 'HR',
            'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank']);
        $this->assertSame(5.0, (float) $order->shipping_total);
        $this->assertSame(26.25, (float) $order->tax_total);
        $this->assertSame(131.25, (float) $order->grand_total);
        $this->assertSame(1.25, data_get($order->payload, 'shipping.tax_total'));
        $this->assertSame(26.25, (float) $order->totals()->where('code', 'tax')->sole()->value);
        $this->assertSame(131.25, (float) $order->totals()->where('code', 'grand_total')->sole()->value);
    }

    private function checkout(float $beforeDiscount): CheckoutService
    {
        $cart = Mockery::mock(CartService::class);
        $cart->shouldReceive('lines')->andReturn(collect([['product' => new Product(['weight_kg' => 1]), 'quantity' => 1]]));
        $cart->shouldReceive('summary')->andReturn(['raw_subtotal' => $beforeDiscount]);

        return new CheckoutService($cart, app(ShippingCalculator::class));
    }

    private function configure(): void
    {
        app(HerreraCheckoutConfigurationService::class)->configure([
            ['source_id' => 1, 'name' => 'Dostava', 'description' => '', 'countries' => ['HR'],
                'tax_class_id' => 11, 'tax_rate_percent' => 25, 'is_active' => true, 'sort_order' => 1,
                'ranges' => [['start' => 0, 'end' => 150, 'cost' => 5], ['start' => 150.01, 'end' => 10000000, 'cost' => 0]]],
            ['source_id' => 2, 'name' => 'Dostava', 'description' => '', 'countries' => ['CH', 'SI'],
                'tax_class_id' => 0, 'tax_rate_percent' => 0, 'is_active' => true, 'sort_order' => 2,
                'ranges' => [['start' => 0, 'end' => 200, 'cost' => 15], ['start' => 201, 'end' => 10000000, 'cost' => 0]]],
        ], ['payment_bank_transfer_status' => 1, 'payment_bank_transfer_bank3' => 'Virman - Uplatnica – Za tvrtke šaljemo ponudu.',
            'payment_wspay_status' => 0, 'payment_wspay_test' => 100, 'payment_wspay_merchant' => 'fixture-shop', 'payment_wspay_password' => 'fixture-secret']);
    }
}
