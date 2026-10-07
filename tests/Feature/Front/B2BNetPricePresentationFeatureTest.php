<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\B2BQuickOrderSearchService;
use App\Services\Front\CartService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class B2BNetPricePresentationFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
    }

    public function test_approved_detail_catalog_search_schema_and_quick_order_display_net_prices(): void
    {
        [$product, $user] = $this->fixture();
        $this->actingAs($user);
        $price = app(ProductPricePresentationService::class)->forProduct($product, $user);
        $this->assertSame(91.23, $price['display_current']);
        $this->assertSame(114.04, $price['current_gross']);
        $this->assertFalse($price['display_includes_tax']);

        $this->get(route('products.show', ['slug' => 'net-contract-product']))->assertOk()
            ->assertSee('data-product-price-current>91.23 €', false)
            ->assertSee('data-price-excludes-tax', false)->assertSee('bez PDV-a')
            ->assertSee('"price":"91.23"', false)->assertSee('"valueAddedTaxIncluded":false', false)
            ->assertDontSee('114.04', false)->assertDontSee('U cijenu je uključen PDV');
        $this->get(route('shop.index'))->assertOk()->assertSee('91.23 €')->assertSee('bez PDV-a')->assertDontSee('114.04 €');
        app(SystemSettingsService::class)->put('store_search_autocomplete_enabled', true);
        $this->getJson(route('search.autocomplete', ['q' => 'Net contract']))->assertOk()
            ->assertJsonPath('groups.products.items.0.price', '91.23 € bez PDV-a');
        $item = app(B2BQuickOrderSearchService::class)->present($product, null, $user);
        $this->assertSame(91.23, $item['unit_price']);
        $this->assertFalse($item['display_includes_tax']);
        $this->get(route('account.b2b.quick-order'))->assertOk()->assertSee('bez PDV-a')->assertSee('data-price-excludes-tax-label', false);
    }

    #[DataProvider('cardLayouts')]
    public function test_every_product_card_layout_labels_the_net_contract_price(bool $flat, bool $lined): void
    {
        [$product, $user] = $this->fixture();
        $this->actingAs($user);
        view()->share('errors', new ViewErrorBag);
        $html = Blade::render('<x-front.desktop.product-card :product="$product" :flat="$flat" :lined="$lined" />', compact('product', 'flat', 'lined'));
        $this->assertStringContainsString('91.23 €', $html);
        $this->assertStringContainsString('data-price-excludes-tax', $html);
        $this->assertStringNotContainsString('114.04', $html);
    }

    public static function cardLayouts(): array
    {
        return [[false, false], [true, false], [true, true]];
    }

    public function test_variant_price_data_uses_the_same_net_contract_amount_as_the_main_price(): void
    {
        [$product, $user] = $this->fixture();
        $option = Option::query()->create(['code' => 'net-size', 'type' => 'radio', 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'net-size-a', 'is_active' => true]);
        $product->optionValues()->create(['option_value_id' => $value->id, 'mode' => 'variant', 'stock_qty' => 10, 'price_override' => 200, 'is_active' => true, 'combination_hash' => hash('sha256', 'net-size-a')]);
        $this->actingAs($user)->get(route('products.show', ['slug' => 'net-contract-product']))->assertOk()
            ->assertSee('data-option-price-current="91.23 €"', false)
            ->assertSee('data-option-price-current-value="91.23"', false)
            ->assertSee('bez PDV-a')->assertDontSee('114.04', false);
    }

    public function test_cart_and_checkout_display_net_subtotal_with_vat_separately(): void
    {
        [$product, $user] = $this->fixture();
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 0, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
        $this->actingAs($user)->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $cart = app(CartService::class);
        $this->assertSame(91.23, (float) $cart->lines()->first()['display_unit_price']);
        $this->assertFalse($cart->lines()->first()['display_includes_tax']);
        $this->assertSame(91.23, (float) $cart->summary()['subtotal']);
        $this->assertSame(22.81, (float) $cart->summary()['tax_total']);
        $this->get(route('cart.index'))->assertOk()->assertSee('Međuzbroj bez PDV-a')->assertSee('PDV')->assertSee('91.23 €');
        $this->get(route('checkout.create'))->assertOk()->assertSee('Međuzbroj bez PDV-a')->assertSee('PDV')->assertSee('data-summary-tax', false);
    }

    public function test_inherited_retail_display_remains_gross_when_b2b_mode_is_disabled(): void
    {
        [$product, $user] = $this->fixture();
        config(['commerce.b2b_only' => false]);
        $this->actingAs($user);
        $this->get(route('products.show', ['slug' => 'net-contract-product']))->assertOk()
            ->assertSee('data-product-price-current>114.04 €', false)
            ->assertDontSee('data-price-excludes-tax', false)->assertSee('U cijenu je uključen PDV');
    }

    private function fixture(): array
    {
        $tax = TaxRate::query()->create(['code' => 'vat25', 'name' => 'PDV 25%', 'rate_type' => 'percent', 'rate' => 25, 'is_default' => true, 'is_active' => true]);
        $product = Product::query()->create(['code' => 'NET-CONTRACT', 'sku' => 'NET-CONTRACT', 'base_price' => 137.49, 'stock_qty' => 10, 'is_active' => true, 'tax_rate_id' => $tax->id]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Net contract product', 'slug' => 'net-contract-product']);
        $group = CustomerGroup::query()->create(['code' => 'net-group', 'name' => 'Net group', 'is_active' => true]);
        $user = User::factory()->create();
        $user->customerGroups()->attach($group);
        B2BAccount::query()->create(['user_id' => $user->id, 'status' => B2BAccount::STATUS_APPROVED, 'company_name' => 'Net company', 'oib' => '12345678901', 'country_code' => 'HR', 'customer_group_id' => $group->id]);
        ProductGroupPrice::query()->create(['product_id' => $product->id, 'customer_group_id' => $group->id, 'price' => 91.23, 'minimum_quantity' => 1, 'currency_code' => 'EUR', 'is_active' => true]);

        return [$product, $user];
    }
}
