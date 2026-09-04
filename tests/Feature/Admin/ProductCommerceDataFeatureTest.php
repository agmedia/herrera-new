<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Catalog\Product\ProductPriceHistory;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Pricing\ProductGroupPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCommerceDataFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_product_form_saves_logistics_packages_and_b2b_prices(): void
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create([
            'code' => 'b2b',
            'name' => 'B2B kupci',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 10,
        ]);

        $component = Livewire::actingAs($user)
            ->test(ProductForm::class)
            ->set('form.code', 'termol-commerce-1')
            ->set('form.sku', 'TERMOL-COMMERCE-1')
            ->set('form.barcode', '3850000000017')
            ->set('form.unit_of_measure', 'pcs')
            ->set('form.minimum_order_quantity', 5)
            ->set('form.order_quantity_step', 5)
            ->set('form.is_active', true)
            ->set('form.base_price', 120)
            ->set('form.stock_qty', 100)
            ->set('form.weight_kg', 2.75)
            ->set('form.length_cm', 40)
            ->set('form.width_cm', 30)
            ->set('form.height_cm', 20)
            ->set('form.shipping_labels', ['fragile', 'heavy'])
            ->set('form.locale', 'en')
            ->set('form.name', 'Commerce Product')
            ->set('form.slug', 'commerce-product')
            ->set('packages', [[
                'id' => null,
                'code' => 'BOX-10',
                'name' => 'Kutija 10 komada',
                'barcode' => '3850000000024',
                'package_type' => 'box',
                'unit_of_measure' => 'pcs',
                'quantity' => 10,
                'weight_kg' => 27.5,
                'length_cm' => 80,
                'width_cm' => 60,
                'height_cm' => 50,
                'is_default' => true,
                'is_active' => true,
            ]])
            ->set('groupPrices', [[
                'id' => null,
                'customer_group_id' => $group->id,
                'package_code' => '',
                'minimum_quantity' => 5,
                'price' => 89.5,
                'currency_code' => 'EUR',
                'starts_at' => '',
                'ends_at' => '',
                'is_active' => true,
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->where('code', 'termol-commerce-1')->firstOrFail();

        $component->assertRedirect(route('admin.products.edit', [
            'product' => $product->id,
            'locale' => 'en',
        ]));
        $this->assertSame('3850000000017', $product->barcode);
        $this->assertSame(5, $product->minimum_order_quantity);
        $this->assertSame(5, $product->order_quantity_step);
        $this->assertSame(['fragile', 'heavy'], $product->shipping_labels);
        $this->assertDatabaseHas('catalog_product_packages', [
            'product_id' => $product->id,
            'code' => 'BOX-10',
            'barcode' => '3850000000024',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('catalog_product_group_prices', [
            'product_id' => $product->id,
            'customer_group_id' => $group->id,
            'minimum_quantity' => 5,
            'price' => 89.5,
        ]);
        $this->assertDatabaseHas('catalog_product_price_history', [
            'product_id' => $product->id,
            'price_type' => 'base',
            'new_price' => 120,
        ]);
        $this->assertDatabaseHas('catalog_product_price_history', [
            'product_id' => $product->id,
            'price_type' => 'b2b',
            'new_price' => 89.5,
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'logistics')
            ->assertSet('activeTab', 'logistics')
            ->assertSet('packages.0.code', 'BOX-10')
            ->call('setTab', 'b2b')
            ->assertSet('activeTab', 'b2b')
            ->assertSet('groupPrices.0.customer_group_id', $group->id);
    }

    public function test_price_changes_are_written_to_immutable_history(): void
    {
        $product = Product::query()->create([
            'code' => 'history-product',
            'sku' => 'HISTORY-PRODUCT',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 10,
        ]);

        $product->update(['base_price' => 115]);

        $history = ProductPriceHistory::query()
            ->where('product_id', $product->id)
            ->where('price_type', 'base')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(100.0, (float) $history->old_price);
        $this->assertSame(115.0, (float) $history->new_price);
    }

    public function test_b2b_resolver_and_cart_apply_group_tier_and_order_rules(): void
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create([
            'code' => 'wholesale',
            'name' => 'Wholesale',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 10,
        ]);
        $user->customerGroups()->attach($group);

        $product = Product::query()->create([
            'code' => 'tier-product',
            'sku' => 'TIER-PRODUCT',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 100,
            'minimum_order_quantity' => 5,
            'order_quantity_step' => 5,
        ]);
        ProductGroupPrice::query()->create([
            'product_id' => $product->id,
            'customer_group_id' => $group->id,
            'minimum_quantity' => 1,
            'price' => 90,
            'currency_code' => 'EUR',
            'is_active' => true,
        ]);
        $tier = ProductGroupPrice::query()->create([
            'product_id' => $product->id,
            'customer_group_id' => $group->id,
            'minimum_quantity' => 10,
            'price' => 75,
            'currency_code' => 'EUR',
            'is_active' => true,
        ]);

        $resolved = app(ProductGroupPriceResolver::class)->resolve($product, $user, 12);
        $this->assertSame($tier->id, $resolved?->id);

        $this->actingAs($user);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 1));
        $this->assertSame(5, (int) collect($cart->raw())->first()['quantity']);

        $this->assertTrue($cart->set($product, 12));
        $line = $cart->lines()->first();

        $this->assertSame(15, (int) $line['quantity']);
        $this->assertSame(75.0, (float) $line['unit_price']);
        $this->assertSame('b2b', $line['price_source']);
        $this->assertSame($tier->id, $line['group_price_id']);
    }
}
