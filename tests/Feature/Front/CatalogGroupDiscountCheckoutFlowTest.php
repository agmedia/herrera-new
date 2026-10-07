<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
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
use App\Services\Pricing\CatalogGroupDiscountService;
use App\Services\Pricing\PriceCatalogQuery;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CatalogGroupDiscountCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
    }

    public static function taxScopes(): array
    {
        return [
            'taxable_contract_and_product' => [[11], 11, '21.5040', '107.5200'],
            'contract_without_vat' => [[], 11, '0.0000', '86.0200'],
            'untaxed_product_class' => [[11], 12, '0.0000', '86.0200'],
        ];
    }

    #[DataProvider('taxScopes')]
    public function test_group_rule_flows_through_cart_quantity_contract_vat_checkout_and_exact_order_snapshot_without_stacking(array $taxableClasses, int $productClass, string $taxTotal, string $grandTotal): void
    {
        [$admin, $catalog, $customer, $group, $other, $product] = $this->fixture();
        $group->update(['payload' => ['opencart' => ['tax_scope_defined' => true, 'taxable_class_ids' => $taxableClasses]]]);
        $product->update(['payload' => ['opencart' => ['tax_class_id' => $productClass]]]);
        $customer->customerGroups()->attach($other);
        $imported = $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '4.0000', $group);
        $this->entry($catalog, $product, PriceCatalogEntry::QUANTITY, '3.0000', $group, ['minimum_quantity' => 5]);
        $this->entry($catalog, $product, PriceCatalogEntry::CUSTOMER, '2.0000', extra: ['user_id' => $customer->id]);
        $service = app(CatalogGroupDiscountService::class);
        $rule = $service->saveRule($catalog, $this->ruleData($group, '40'), $admin);
        $service->saveRule($catalog, $this->ruleData($other, '90'), $admin);
        // Neither inherited product actions nor cart coupon actions may multiply a compiled final price again.
        foreach (['product', 'cart'] as $scope) {
            CatalogAction::query()->create(['code' => 'UNUSED-'.$scope, 'scope' => $scope, 'type' => 'percentage', 'discount_value' => 50, 'target_type' => 'all', 'audience_type' => 'all', 'coupon_code' => $scope === 'cart' ? 'UNUSED50' : null, 'is_active' => true]);
        }
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $this->actingAs($customer);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 7));
        $this->assertFalse($cart->applyCoupon('UNUSED50'));
        session()->put('front.cart.coupon_code', 'UNUSED50');
        $line = $cart->lines()->sole();
        $this->assertSame(12.288, $line['unit_price']);
        $this->assertSame(7, $line['quantity']);
        $this->assertSame(86.016, $line['line_total']);
        $this->assertSame((float) $taxTotal, $line['line_tax_total']);
        $this->assertSame('price_catalog_group_discount', $line['b2b_source_type']);
        $this->assertSame($rule->id, $line['b2b_rule_id']);
        $this->assertTrue($line['price_is_final']);
        $this->assertSame(4, $line['monetary_precision']);
        $this->assertFalse($line['display_includes_tax']);
        $this->assertNull($line['action_code']);
        $summary = $cart->summary();
        $this->assertSame(86.016, $summary['raw_subtotal_after_discount']);
        $this->assertSame((float) $taxTotal, $summary['raw_tax_total']);
        $this->assertSame(0.0, $summary['discount_total']);
        $this->assertSame(0.0, $summary['cart_discount_total']);
        $this->assertSame((float) $grandTotal, $summary['grand_total']);
        $this->checkoutMethods();
        $order = app(CheckoutService::class)->placeOrder($this->checkoutPayload($customer), $customer);
        $item = $order->items()->sole();
        $this->assertSame('12.2880', $item->unit_price);
        $this->assertSame('86.0160', $item->line_total);
        $this->assertSame($taxTotal, $item->tax_amount);
        $this->assertSame('86.0160', $order->subtotal);
        $this->assertSame($taxTotal, $order->tax_total);
        $this->assertSame($grandTotal, $order->grand_total);
        if ((float) $taxTotal > 0) {
            $this->assertSame($taxTotal, $order->totals()->where('code', 'tax')->sole()->value);
        } else {
            $this->assertSame(0, $order->totals()->where('code', 'tax')->count());
        }
        $this->assertSame('price_catalog_group_discount', $item->payload['pricing']['source']);
        $this->assertSame($group->id, $item->payload['pricing']['customer_group_id']);
        $this->assertSame($catalog->id, $item->payload['pricing']['catalog_id']);
        $this->assertSame($line['price_catalog_entry_id'], $item->payload['pricing']['catalog_entry_id']);
        $this->assertTrue($item->payload['pricing']['final']);
        $this->assertSame(993, $product->fresh()->stock_qty);
        $this->assertSame('4.0000', $imported->fresh()->price);
    }

    public function test_primary_group_rule_controls_price_filters_and_sorting_not_secondary_membership(): void
    {
        [$admin, $catalog, $customer, $group, $other, $first] = $this->fixture();
        $second = $this->product('SECOND', '40.0000', $first->tax_rate_id);
        $this->entry($catalog, $second, PriceCatalogEntry::BASE, '40.0000');
        $this->entry($catalog, $first, PriceCatalogEntry::SPECIAL, '4.0000', $group);
        $customer->customerGroups()->attach($other);
        app(CatalogGroupDiscountService::class)->saveRule($catalog, $this->ruleData($group, '40'), $admin);
        app(CatalogGroupDiscountService::class)->saveRule($catalog, $this->ruleData($other, '90'), $admin);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $this->actingAs($customer)->get(route('shop.index', ['sort' => 'price_low']))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->pluck('id')->all() === [$first->id, $second->id])
            ->assertViewHas('priceBounds', ['min' => 12.29, 'max' => 24.0]);
        $this->get(route('shop.index', ['price_max' => 13]))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->pluck('id')->all() === [$first->id]);
        $this->get(route('shop.index', ['promo_only' => 1]))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->isEmpty());
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($first, 1));
        $this->assertSame(12.288, $cart->lines()->sole()['unit_price']);
        $otherBuyer = $this->customer($other);
        $this->assertSame(2.048, app(PriceCatalogResolver::class)->resolve($first, $otherBuyer)?->price);
    }

    public function test_account_revocation_hides_compiled_rule_prices_and_blocks_preexisting_cart_checkout(): void
    {
        [$admin, $catalog, $customer, $group, , $product] = $this->fixture();
        app(CatalogGroupDiscountService::class)->saveRule($catalog, $this->ruleData($group, '40'), $admin);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $this->actingAs($customer);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 7));
        $this->assertSame(12.288, $cart->lines()->sole()['unit_price']);
        $customer->b2bAccount()->update(['status' => 'suspended']);
        $customer->unsetRelation('b2bAccount');
        $this->assertNull(app(PriceCatalogResolver::class)->resolve($product, $customer));
        $this->assertNull(app(PriceCatalogQuery::class)->storedPrice($customer));
        $this->assertTrue($cart->lines()->isEmpty());
        try {
            app(CheckoutService::class)->placeOrder($this->checkoutPayload($customer), $customer);
            $this->fail('Revoked customers cannot order from a preexisting rule-priced cart.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Order::query()->count());
            $this->assertSame(1000, $product->fresh()->stock_qty);
        }
    }

    private function fixture(): array
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        $catalog = app(PriceCatalogService::class)->createDraft('Rule checkout', $admin);
        $group = CustomerGroup::query()->create(['code' => 'primary', 'name' => 'Primary', 'is_active' => true]);
        $other = CustomerGroup::query()->create(['code' => 'secondary', 'name' => 'Secondary', 'is_active' => true]);
        $customer = $this->customer($group);
        $tax = TaxRate::query()->create(['code' => 'VAT25', 'name' => 'PDV25', 'rate_type' => 'percent', 'rate' => 25, 'is_active' => true, 'is_default' => true]);
        $product = $this->product('FIRST', '20.4800', $tax->id);
        $this->entry($catalog, $product, PriceCatalogEntry::BASE, '20.4800');

        return [$admin, $catalog, $customer, $group, $other, $product];
    }

    private function customer(CustomerGroup $group): User
    {
        $customer = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $customer->id, 'company_name' => 'Partner', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);

        return $customer;
    }

    private function product(string $code, string $price, int $taxId): Product
    {
        $product = Product::query()->create(['code' => $code, 'sku' => $code, 'base_price' => $price, 'stock_qty' => 1000, 'is_active' => true, 'tax_rate_id' => $taxId]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Product '.$code, 'slug' => strtolower($code)]);

        return $product;
    }

    private function ruleData(CustomerGroup $group, string $percent): array
    {
        return ['name' => 'Rule '.$group->code, 'customer_group_ids' => [$group->id], 'percent' => $percent];
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, ?CustomerGroup $group = null, array $extra = []): PriceCatalogEntry
    {
        return $catalog->entries()->create($extra + ['source_key' => 'fixture:'.uniqid(), 'product_id' => $product->id, 'kind' => $kind, 'price' => $price, 'customer_group_id' => $group?->id, 'minimum_quantity' => 1, 'is_active' => true]);
    }

    private function checkoutMethods(): void
    {
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 0, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    private function checkoutPayload(User $user): array
    {
        return ['customer_email' => $user->email, 'billing_country_code' => 'HR', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'billing_address_line_1' => 'Test1', 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank'];
    }
}
