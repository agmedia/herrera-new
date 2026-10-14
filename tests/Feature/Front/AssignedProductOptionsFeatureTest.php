<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\User;
use App\Services\Front\B2BQuickOrderSearchService;
use App\Services\Front\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignedProductOptionsFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('livewire')->flushState();
        app()->setLocale('hr');
        config(['app.locale' => 'hr', 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false]);
    }

    public function test_plain_products_and_products_with_empty_assigned_groups_keep_normal_ordering(): void
    {
        $product = $this->product();
        $option = $this->option();
        $product->options()->attach($option->id);

        $this->get(route('products.show', ['slug' => 'testna-svjetiljka']))->assertOk()
            ->assertDontSee('name="product_option_value_id"', false);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 2));
        $this->assertSame(2, $cart->lines()->sole()['quantity']);
        $this->assertNull($cart->lines()->sole()['product_option_value_id']);
    }

    public function test_assigned_option_uses_its_group_name_sku_price_and_own_stock(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $this->get(route('products.show', ['slug' => 'testna-svjetiljka']))->assertOk()
            ->assertSee('Snaga')->assertSee('20 W')
            ->assertSee('data-option-sku="LAMP-20W"', false)
            ->assertSee('data-option-stock="3"', false)
            ->assertDontSee('Odaberite veličinu');

        $cart = app(CartService::class);
        $this->assertFalse($cart->add($product));
        $this->assertFalse($cart->set($product, 1));
        $this->assertTrue($cart->add($product, 10, $variant->id));
        $line = $cart->lines()->sole();
        $this->assertSame($variant->id, $line['product_option_value_id']);
        $this->assertSame('LAMP-20W', $line['sku']);
        $this->assertSame(3, $line['quantity']);
        $this->assertSame(25.0, $line['unit_price']);
        $this->assertSame('20 W', $line['option_value_label']);
    }

    public function test_inactive_variant_groups_and_values_are_hidden_and_cannot_enter_cart_or_quick_order(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        $cart = app(CartService::class);
        $search = app(B2BQuickOrderSearchService::class);

        foreach ([$variant, $variant->optionValue, $variant->childOption()] as $subject) {
            $subject->update(['is_active' => false]);
            $freshProduct = $product->fresh();
            $this->assertFalse($cart->add($freshProduct, 1, $variant->id));
            $this->assertFalse($cart->set($freshProduct, 1, $variant->id));
            $this->assertTrue($search->search('LAMP-20W', $buyer)->isEmpty());
            $this->assertArrayHasKey('error', $search->resolve('LAMP-20W', $buyer));
            $this->get(route('products.show', ['slug' => 'testna-svjetiljka']))->assertOk()
                ->assertDontSee('name="product_option_value_id"', false);
            $subject->update(['is_active' => true]);
        }
    }

    public function test_linked_light_temperature_and_power_keep_the_exact_combination_in_quick_order_and_cart(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $temperature = Option::query()->create(['code' => 'temperature', 'type' => 'select', 'is_active' => true]);
        $temperature->translations()->create(['locale' => 'hr', 'name' => 'Boja svjetla', 'slug' => 'boja-svjetla']);
        $value = OptionValue::query()->create(['option_id' => $temperature->id, 'code' => '4000k', 'is_active' => true]);
        $value->translations()->create(['locale' => 'hr', 'name' => '4000 K', 'slug' => '4000k']);
        $product->options()->attach($temperature->id);
        $variant->update(['mode' => 'linked', 'parent_option_value_id' => $value->id]);
        $buyer = User::factory()->create();
        $this->actingAs($buyer);

        $this->get(route('products.show', ['slug' => 'testna-svjetiljka']))->assertOk()
            ->assertSee('data-linked-option-primary', false)->assertSee('data-linked-option-secondary', false)
            ->assertSee('Boja svjetla')->assertSee('4000 K')->assertSee('Snaga');
        $item = app(B2BQuickOrderSearchService::class)->resolve('LAMP-20W', $buyer, 2)['item'];
        $this->assertSame($variant->id, $item['product_option_value_id']);
        $this->assertSame('Boja svjetla: 4000 K / Snaga: 20 W', $item['option_label']);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 2, $variant->id));
        $this->assertSame('Boja svjetla: 4000 K / Snaga: 20 W', $cart->lines()->sole()['option_label']);
        $value->update(['is_active' => false]);
        $this->assertFalse($cart->add($product->fresh(), 1, $variant->id));
    }

    public function test_removed_or_foreign_variant_never_silently_becomes_the_base_product_in_a_saved_cart(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $other = Product::query()->create(['code' => 'OTHER', 'is_active' => true, 'stock_qty' => 20, 'base_price' => 10]);
        $cart = app(CartService::class);
        $this->assertFalse($cart->add($other, 1, $variant->id));
        $cart->replaceRaw([['product_id' => $other->id, 'product_option_value_id' => $variant->id, 'quantity' => 1]]);
        $this->assertTrue($cart->lines()->isEmpty());

        $cart->replaceRaw([['product_id' => $product->id, 'product_option_value_id' => $variant->id, 'quantity' => 1]]);
        $variant->delete();
        $this->assertTrue($cart->lines()->isEmpty());
        $this->assertFalse($cart->add($product->fresh(), 1, $variant->id));
    }

    public function test_a_saved_base_cart_line_requires_selection_when_variants_are_assigned_later(): void
    {
        $product = $this->product();
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product));
        $this->assertSame(1, $cart->lines()->count());
        $this->variant($product);
        CartService::forgetRequestSnapshot();
        $this->assertTrue($cart->lines()->isEmpty());
    }

    private function product(): Product
    {
        $product = Product::query()->create(['code' => 'LAMP', 'sku' => 'LAMP', 'base_price' => 10, 'stock_qty' => 50, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'slug' => 'testna-svjetiljka', 'name' => 'Testna svjetiljka']);

        return $product;
    }

    private function option(): Option
    {
        $option = Option::query()->create(['code' => 'power', 'type' => 'select', 'is_active' => true]);
        $option->translations()->create(['locale' => 'hr', 'name' => 'Snaga', 'slug' => 'snaga']);

        return $option;
    }

    private function variant(Product $product): ProductOptionValue
    {
        $option = $this->option();
        $product->options()->attach($option->id);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => '20w', 'is_active' => true]);
        $value->translations()->create(['locale' => 'hr', 'name' => '20 W', 'slug' => '20w']);

        return $product->optionValues()->create([
            'option_value_id' => $value->id, 'mode' => 'single', 'sku' => 'LAMP-20W',
            'stock_qty' => 3, 'price_override' => 25, 'is_active' => true,
            'combination_hash' => hash('sha256', 's:'.$value->id),
        ]);
    }
}
