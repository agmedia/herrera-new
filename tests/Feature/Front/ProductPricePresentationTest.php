<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPricePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_that_already_includes_tax_is_not_changed_by_a_tax_round_trip(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);

        $product = Product::query()->create([
            'code' => 'gross-price-rounding-test',
            'sku' => 'GROSS-PRICE-ROUNDING-TEST',
            'is_active' => true,
            'base_price' => 229.57,
            'stock_qty' => 1,
        ]);

        $price = app(ProductPricePresentationService::class)->forProduct($product);

        $this->assertSame(229.57, $price['current_gross']);
        $this->assertSame(229.57, $price['current_net']);
        $this->assertSame(229.57, $price['base_gross']);
        $this->assertFalse($price['has_discount']);
    }

    public function test_contracted_b2b_price_is_not_presented_as_a_promotional_discount(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);

        [$user, $product] = $this->makeB2BProduct();
        $price = app(ProductPricePresentationService::class)->forProduct($product, $user);

        $this->assertSame(90.0, $price['current_gross']);
        $this->assertSame(90.0, $price['current_net']);
        $this->assertSame(90.0, $price['base_gross']);
        $this->assertSame(100.0, $price['catalog_gross']);
        $this->assertNull($price['old_gross']);
        $this->assertNull($price['discount_percent']);
        $this->assertNull($price['lowest_30_days_gross']);
        $this->assertFalse($price['has_discount']);
        $this->assertFalse($price['has_promotional_discount']);
        $this->assertTrue($price['is_b2b_price']);
        $this->assertSame('b2b', $price['price_source']);

        $this->actingAs($user);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product));
        $line = $cart->lines()->first();
        $summary = $cart->summary();

        $this->assertSame(90.0, (float) $line['display_unit_price']);
        $this->assertSame(90.0, (float) $line['display_base_unit_price']);
        $this->assertSame(100.0, (float) $line['display_catalog_unit_price']);
        $this->assertSame(0.0, (float) $line['line_discount_total']);
        $this->assertTrue($line['is_b2b_price']);
        $this->assertFalse($line['has_promotional_discount']);
        $this->assertSame(0.0, (float) $summary['discount_total']);

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('cart-price-cell', false)
            ->assertSee('cart-b2b-price-label', false)
            ->assertSee('Vaša ugovorena B2B cijena');

        $this->get(route('products.show', ['slug' => 'b2b-presentation']))
            ->assertOk()
            ->assertSee('Vaša ugovorena B2B cijena')
            ->assertSee('90.00 €')
            ->assertSee('Cijena bez PDV-a')
            ->assertDontSee('Najniža cijena u prethodnih 30 dana')
            ->assertDontSee('-10%');
    }

    public function test_real_action_on_top_of_b2b_price_keeps_promotional_presentation(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);

        [$user, $product] = $this->makeB2BProduct();
        $action = CatalogAction::query()->create([
            'code' => 'B2B-ACTION-10',
            'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $action->targets()->create([
            'target_type' => CatalogAction::TARGET_PRODUCT,
            'target_id' => $product->id,
        ]);

        $price = app(ProductPricePresentationService::class)->forProduct($product, $user);

        $this->assertSame(81.0, $price['current_gross']);
        $this->assertSame(90.0, $price['base_gross']);
        $this->assertSame(90.0, $price['old_gross']);
        $this->assertSame(10, $price['discount_percent']);
        $this->assertTrue($price['has_discount']);
        $this->assertTrue($price['has_promotional_discount']);
        $this->assertTrue($price['is_b2b_price']);
        $this->assertSame('b2b_action', $price['price_source']);
    }

    /**
     * @return array{0:User,1:Product}
     */
    private function makeB2BProduct(): array
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create([
            'code' => 'price-presentation-b2b',
            'name' => 'Price presentation B2B',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 1,
        ]);
        $user->customerGroups()->attach($group);

        $product = Product::query()->create([
            'code' => 'B2B-PRESENTATION',
            'sku' => 'B2B-PRESENTATION',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 10,
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => 'B2B prezentacijski artikl',
            'slug' => 'b2b-presentation',
        ]);
        ProductGroupPrice::query()->create([
            'product_id' => $product->id,
            'customer_group_id' => $group->id,
            'minimum_quantity' => 1,
            'price' => 90,
            'currency_code' => 'EUR',
            'is_active' => true,
        ]);

        return [$user, $product];
    }
}
