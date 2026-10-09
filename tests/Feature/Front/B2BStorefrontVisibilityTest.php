<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\WishlistService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class B2BStorefrontVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('deniedViewers')]
    public function test_catalog_detail_and_wishlist_hide_prices_stock_and_purchase_controls(string $viewer, string $device): void
    {
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        $product = $this->makeProduct();
        if ($viewer !== 'guest') {
            $this->actingAs($this->makeCustomer($viewer));
        }
        $this->withHeaders(['User-Agent' => $device]);

        $detail = $this->get(route('products.show', ['slug' => 'visibility-product']));
        $detail->assertOk()
            ->assertSee('data-b2b-price-access', false)
            ->assertDontSee('1234.56', false)
            ->assertDontSee('1,234.56', false)
            ->assertDontSee('9876.54', false)
            ->assertDontSee('9,876.54', false)
            ->assertDontSee('data-product-price-current', false)
            ->assertDontSee('data-option-price-current', false)
            ->assertDontSee('data-ga4-item-price', false)
            ->assertDontSee('data-product-detail-form', false)
            ->assertDontSee('schema.org/InStock', false)
            ->assertDontSee('schema.org/OutOfStock', false)
            ->assertDontSee('"offers":', false);
        $detail->assertSee(__($viewer === 'guest' ? 'ui.b2b.pricing.login_required' : 'ui.b2b.pricing.approval_required'));

        $this->get(route('shop.index'))->assertOk()
            ->assertSee('data-product-card', false)
            ->assertSee('data-b2b-price-access', false)
            ->assertDontSee('1234.56', false)
            ->assertDontSee('1,234.56', false)
            ->assertDontSee('data-ga4-item-price', false)
            ->assertDontSee('data-product-card-form', false)
            ->assertDontSee('data-price-range-root', false)
            ->assertDontSee('value="price_low"', false)
            ->assertDontSee('value="stock_high"', false)
            ->assertDontSee('name="available_only"', false);

        app(WishlistService::class)->add($product);
        $this->get(route('wishlist.index'))->assertOk()
            ->assertSee('data-product-card', false)
            ->assertSee('data-b2b-price-access', false)
            ->assertDontSee('1234.56', false)
            ->assertDontSee('1,234.56', false)
            ->assertDontSee('data-ga4-item-price', false)
            ->assertDontSee('data-product-card-form', false);
    }

    #[DataProvider('devices')]
    public function test_approved_customer_sees_contract_price_and_can_purchase(string $device): void
    {
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        $product = $this->makeProduct();
        $user = $this->makeCustomer(B2BAccount::STATUS_APPROVED);
        ProductGroupPrice::query()->create([
            'product_id' => $product->id,
            'customer_group_id' => $user->b2bAccount->customer_group_id,
            'minimum_quantity' => 1,
            'price' => 876.54,
            'currency_code' => 'EUR',
            'is_active' => true,
        ]);

        $this->actingAs($user)->withHeaders(['User-Agent' => $device])
            ->get(route('products.show', ['slug' => 'visibility-product']))
            ->assertOk()
            ->assertSee('876.54 €')
            ->assertSee('data-product-detail-form', false)
            ->assertSee('data-option-price-current', false)
            ->assertSee('data-ga4-item-price="876.54"', false)
            ->assertSee('"price":"876.54"', false)
            ->assertDontSee('data-b2b-price-access', false);

        $this->get(route('shop.index'))->assertOk()
            ->assertSee('876.54 €')
            ->assertSee('data-product-card-form', false)
            ->assertSee('data-price-range-root', false);
    }

    public function test_retail_mode_preserves_public_prices_and_purchase_controls(): void
    {
        config(['commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        $this->makeProduct();

        $this->get(route('products.show', ['slug' => 'visibility-product']))->assertOk()
            ->assertSee('1,234.56 €')
            ->assertSee('data-product-detail-form', false)
            ->assertSee('data-option-price-current="9,876.54 €"', false)
            ->assertSee('"offers":', false)
            ->assertDontSee('data-b2b-price-access', false);
    }

    #[DataProvider('cardLayouts')]
    public function test_all_card_layouts_hide_guest_prices_without_claiming_product_is_unavailable(bool $flat, bool $lined): void
    {
        config(['commerce.b2b_only' => true]);
        $product = $this->makeProduct();
        view()->share('errors', new ViewErrorBag);

        $html = Blade::render('<x-front.desktop.product-card :product="$product" :flat="$flat" :lined="$lined" />', compact('product', 'flat', 'lined'));

        $this->assertStringNotContainsString('data-b2b-price-access', $html);
        $this->assertStringNotContainsString(__('ui.b2b.pricing.login_required'), $html);
        $this->assertStringNotContainsString('1,234.56', $html);
        $this->assertStringNotContainsString('0.00 €', $html);
        $this->assertStringNotContainsString('data-ga4-item-price', $html);
        $this->assertStringNotContainsString('data-product-card-form', $html);
        $this->assertStringNotContainsString(__('ui.product.unavailable'), $html);
    }

    public static function cardLayouts(): array
    {
        return ['standard' => [false, false], 'flat' => [true, false], 'lined' => [true, true]];
    }

    public static function devices(): array
    {
        return [
            'desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36'],
            'mobile' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'],
        ];
    }

    public static function deniedViewers(): array
    {
        $cases = [];
        foreach (['guest', B2BAccount::STATUS_PENDING, B2BAccount::STATUS_SUSPENDED] as $viewer) {
            foreach (self::devices() as $device => [$agent]) {
                $cases[$viewer.' '.$device] = [$viewer, $agent];
            }
        }

        return $cases;
    }

    private function makeProduct(): Product
    {
        $product = Product::query()->create([
            'code' => 'VISIBILITY-PRODUCT', 'sku' => 'VISIBILITY-PRODUCT',
            'base_price' => 1234.56, 'stock_qty' => 7, 'is_active' => true,
        ]);
        $product->translations()->create([
            'locale' => 'hr', 'name' => 'Artikl za provjeru B2B vidljivosti', 'slug' => 'visibility-product',
        ]);
        $option = Option::query()->create(['code' => 'visibility-option', 'type' => 'radio', 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'visibility-value', 'is_active' => true]);
        $product->optionValues()->create([
            'option_value_id' => $value->id, 'mode' => 'variant',
            'stock_qty' => 4, 'price_override' => 9876.54, 'is_active' => true,
            'combination_hash' => hash('sha256', 'visibility-variant'),
        ]);

        return $product;
    }

    private function makeCustomer(string $status): User
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'visibility-group', 'name' => 'Test B2B grupa', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        B2BAccount::query()->create([
            'user_id' => $user->id, 'status' => $status, 'company_name' => 'Test tvrtka',
            'oib' => '12345678901', 'country_code' => 'HR', 'customer_group_id' => $group->id,
        ]);

        return $user;
    }
}
