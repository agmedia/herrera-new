<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Action\Form as ActionForm;
use App\Livewire\Admin\Sales\Order\Manager as OrderManager;
use App\Livewire\Admin\User\Manager as CustomerManager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class B2CRetailManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_backfill_preserves_checkout_snapshot_and_recognizes_legacy_company_profiles(): void
    {
        $formerRetail = User::factory()->create();
        $formerRetail->b2bAccount()->create(['company_name' => 'Later Business', 'oib' => '11000000001']);
        $legacyCompany = User::factory()->create();
        $legacyCompany->profile()->create(['company' => 'Legacy Company']);
        $migration = require database_path('migrations/2026_11_09_110000_add_commerce_classification_to_orders.php');
        $migration->down();
        foreach ([['SNAPSHOT', $formerRetail, ['customer_type' => 'B2C']], ['LEGACY', $legacyCompany, []]] as [$number, $buyer, $payload]) {
            DB::table('orders')->insert(['order_number' => $number, 'user_id' => $buyer->id, 'customer_name' => $buyer->name, 'customer_email' => $buyer->email, 'payload' => json_encode($payload)]);
        }
        $migration->up();
        $this->assertSame('B2C', Order::query()->where('order_number', 'SNAPSHOT')->sole()->customer_type);
        $this->assertSame('Web prodaja', Order::query()->where('order_number', 'SNAPSHOT')->sole()->cost_centre);
        $this->assertSame('B2B', Order::query()->where('order_number', 'LEGACY')->sole()->customer_type);
        $this->assertNull(Order::query()->where('order_number', 'LEGACY')->sole()->cost_centre);
    }

    public function test_admin_can_set_a_retail_product_price_and_brand_promotion_without_changing_b2b_prices(): void
    {
        config(['commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->put('catalog_use_actions', true);
        $admin = $this->admin();
        $manufacturer = Manufacturer::query()->create(['code' => 'retail-brand', 'is_active' => true]);
        $product = Product::query()->create(['code' => 'RETAIL', 'sku' => 'RETAIL', 'base_price' => 100, 'manufacturer_id' => $manufacturer->id, 'stock_qty' => 10, 'is_active' => true]);
        $otherBrand = Product::query()->create(['code' => 'OTHER', 'sku' => 'OTHER', 'base_price' => 100, 'stock_qty' => 10, 'is_active' => true]);

        Livewire::actingAs($admin)->test(ActionForm::class)
            ->set('form.code', 'retail-brand-10')->set('form.locale', 'hr')->set('form.title', 'B2C brand 10%')
            ->set('form.type', CatalogAction::TYPE_PERCENTAGE)->set('form.discount_value', '10')
            ->set('form.target_type', CatalogAction::TARGET_MANUFACTURER)->set('form.target_ids', [$manufacturer->id])
            ->set('form.audience_type', CatalogAction::AUDIENCE_B2C)->set('form.is_active', true)
            ->call('save')->assertHasNoErrors();

        $retail = User::factory()->create();
        $business = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'business', 'name' => 'Business', 'is_active' => true]);
        $business->b2bAccount()->create(['company_name' => 'Business', 'oib' => '11000000001', 'status' => 'approved', 'customer_group_id' => $group->id]);
        $business->customerGroups()->attach($group);
        ProductGroupPrice::query()->create(['product_id' => $product->id, 'customer_group_id' => $group->id, 'minimum_quantity' => 1, 'price' => 80, 'currency_code' => 'EUR', 'is_active' => true]);
        $prices = app(ProductPricePresentationService::class);
        $this->assertSame(90.0, $prices->forProduct($product, $retail)['current_net']);
        $this->assertSame(90.0, $prices->forProduct($product, null)['current_net']);
        $this->assertSame(100.0, $prices->forProduct($otherBrand, $retail)['current_net']);
        $this->assertSame(80.0, $prices->forProduct($product, $business)['current_net']);

        Livewire::actingAs($admin)->test(ActionForm::class)
            ->set('form.code', 'retail-bait')->set('form.locale', 'hr')->set('form.title', 'B2C proizvod')
            ->set('form.type', CatalogAction::TYPE_FIXED_PRICE)->set('form.discount_value', '70')
            ->set('form.target_type', CatalogAction::TARGET_PRODUCT)->set('form.target_ids', [$product->id])
            ->set('form.audience_type', CatalogAction::AUDIENCE_B2C)->set('form.is_active', true)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(70.0, $prices->forProduct($product, $retail)['current_net']);
        $this->assertSame(70.0, $prices->forProduct($product, null)['current_net']);
        $this->assertSame(80.0, $prices->forProduct($product, $business)['current_net']);
        $this->assertSame(100.0, (float) $product->fresh()->base_price);
    }

    public function test_b2b_statistics_default_excludes_retail_and_b2c_filters_keep_its_own_records(): void
    {
        $admin = $this->admin();
        $retail = User::factory()->create(['name' => 'Retail Buyer']);
        $business = User::factory()->create(['name' => 'Business Contact']);
        $business->b2bAccount()->create(['company_name' => 'Business Company', 'oib' => '11000000001']);
        $b2c = $this->order($retail, 'RETAIL-ORDER', 25);
        $b2b = $this->order($business, 'BUSINESS-ORDER', 100);
        // A later account upgrade must not move the original sale to B2B.
        $retail->b2bAccount()->create(['company_name' => 'Later Company', 'oib' => '11000000002']);
        $this->assertSame('B2C', $b2c->fresh()->commerceCustomerType());
        $this->assertSame('Web prodaja', $b2c->fresh()->commerceCostCentre());

        $this->actingAs($admin)->get(route('admin.users.statistics', ['dateFrom' => '', 'dateTo' => '']))
            ->assertOk()->assertViewHas('filters', fn ($filters) => $filters['customer_type'] === 'B2B')
            ->assertViewHas('summary', fn ($summary) => $summary['orders'] === 1 && $summary['order_value'] === 100.0);
        $this->get(route('admin.users.statistics', ['dateFrom' => '', 'dateTo' => '', 'customer_type' => 'B2C']))
            ->assertOk()->assertSee('Web prodaja')->assertSee('Retail Buyer')
            ->assertViewHas('summary', fn ($summary) => $summary['orders'] === 1 && $summary['order_value'] === 25.0);
        $this->get(route('admin.users.statistics', ['dateFrom' => '', 'dateTo' => '', 'customer_type' => 'all']))
            ->assertOk()->assertViewHas('summary', fn ($summary) => $summary['orders'] === 2 && $summary['order_value'] === 125.0);
        Livewire::actingAs($admin)->test(OrderManager::class)->set('customerType', 'B2C')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$b2c->id]);
        Livewire::actingAs($admin)->test(OrderManager::class)->set('customerType', 'B2B')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$b2b->id]);
        Livewire::actingAs($admin)->test(CustomerManager::class)->set('search', 'Retail Buyer')
            ->assertViewHas('rows', fn ($rows) => (float) $rows->sole()->webshop_turnover === 0.0)
            ->set('turnoverCustomerType', 'B2C')
            ->assertViewHas('rows', fn ($rows) => (float) $rows->sole()->webshop_turnover === 25.0);
    }

    public function test_retail_checkout_records_cost_centre_tax_and_promotional_price_without_external_payment_or_mail(): void
    {
        config(['commerce.b2b_only' => true, 'commerce.local_safe_mode' => true]);
        app(SystemSettingsService::class)->put('store_pricing_b2c_enabled', true);
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 0, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Test bank transfer', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
        $tax = TaxRate::query()->create(['code' => 'vat25', 'name' => 'PDV', 'rate_type' => 'percent', 'rate' => 25, 'is_default' => true, 'is_active' => true]);
        $product = Product::query()->create(['code' => 'CHECKOUT-RETAIL', 'sku' => 'CHECKOUT-RETAIL', 'base_price' => 10, 'tax_rate_id' => $tax->id, 'stock_qty' => 100, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Checkout retail', 'slug' => 'checkout-retail']);
        CatalogAction::query()->create(['code' => 'checkout-retail', 'scope' => 'product', 'type' => 'fixed_price', 'discount_value' => 5, 'target_type' => 'all', 'audience_type' => 'b2c', 'is_active' => true]);
        $retail = User::factory()->create(['email' => 'retail-checkout@example.test']);
        $this->actingAs($retail)->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2])->assertSuccessful();
        $this->post(route('checkout.store'), [
            'customer_email' => $retail->email, 'customer_phone' => '+385911234567',
            'billing_first_name' => 'Retail', 'billing_last_name' => 'Test',
            'billing_address_line_1' => 'Test 1', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'billing_country_code' => 'HR',
            'use_billing_for_shipping' => true, 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank', 'accept_terms' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::query()->sole();
        $this->assertSame($retail->id, $order->user_id);
        $this->assertSame('B2C', $order->customer_type);
        $this->assertSame('Web prodaja', $order->cost_centre);
        $this->assertSame(12.5, (float) $order->grand_total);
        $this->assertSame(2.5, (float) $order->tax_total);
        $this->assertSame(10.0, (float) $order->items()->sole()->line_total);
        $this->assertSame(10.0, (float) $order->items()->sole()->discount_amount);
        Http::assertNothingSent();
    }

    public function test_b2c_reconciliation_export_keeps_confirmed_erp_cost_centre_separate_from_webshop_label(): void
    {
        $buyer = User::factory()->create();
        $this->order($buyer, 'WEB-TEST-1', 75);
        $order = Order::where('order_number', 'WEB-TEST-1')->sole();
        $this->assertSame('web', $order->erp_cost_centre);
        $this->assertSame('Web prodaja', $order->cost_centre);
        $admin = $this->admin();
        Bouncer::allow($admin)->to('integrations.eracuni.manage');
        Bouncer::allow($admin)->to('sales.orders.view');
        Bouncer::refresh();
        $stream = $this->actingAs($admin)->get(route('admin.orders.erp-cost-centres', ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()->streamedContent();
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($stream)));
        $this->assertSame('erp_cost_centre', $rows[0][2]);
        $this->assertSame(['WEB-TEST-1', '', 'web'], array_slice($rows[1], 0, 3));
    }

    public function test_category_action_combines_selected_brand_and_current_supplier(): void
    {
        config(['commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->put('catalog_use_actions', true);
        $category = \App\Models\Catalog\Category\Category::create(['code' => 'indoor', 'scope' => 'catalog', 'is_active' => true]);
        $brand = Manufacturer::create(['code' => 'lamp', 'is_active' => true]);
        $products = [];
        foreach (['match', 'wrong-brand', 'old-supplier', 'other-category'] as $code) {
            $product = Product::create(['code' => $code, 'sku' => $code, 'stock_qty' => 10, 'is_active' => true,
                'base_price' => 100, 'manufacturer_id' => $code === 'wrong-brand' ? null : $brand->id]);
            if ($code !== 'other-category') {
                $product->categories()->attach($category);
            }
            DB::table('supplier_catalog_items')->insert(['product_id' => $product->id, 'supplier' => 'videx',
                'name' => $code, 'sku' => $code, 'external_id' => $code, 'is_current' => $code !== 'old-supplier']);
            $products[$code] = $product;
        }
        Livewire::actingAs($this->admin())->test(ActionForm::class)
            ->set('form.code', 'videx-lamp')->set('form.locale', 'hr')->set('form.title', 'Unutarnja rasvjeta')
            ->set('form.type', CatalogAction::TYPE_PERCENTAGE)->set('form.discount_value', '10')
            ->set('form.target_type', CatalogAction::TARGET_CATEGORY)->set('form.target_ids', [$category->id])
            ->set('form.filter_manufacturer_id', $brand->id)->set('form.filter_supplier', 'videx')
            ->set('form.is_active', true)->call('save')->assertHasNoErrors();
        foreach ($products as $code => $product) {
            $price = app(ProductPricePresentationService::class)->forProduct($product, null);
            $this->assertSame($code === 'match' ? 90.0 : 100.0, $price['current_net'], $code);
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('superadmin')->to($admin);

        return $admin;
    }

    private function order(User $buyer, string $number, float $total): Order
    {
        return Order::query()->create(['order_number' => $number, 'user_id' => $buyer->id, 'customer_name' => $buyer->name, 'customer_email' => $buyer->email, 'grand_total' => $total, 'currency_code' => 'EUR', 'item_qty' => 1, 'placed_at' => now()]);
    }
}
